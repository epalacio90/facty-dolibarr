<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';
require_once __DIR__ . '/FactyCfdi.class.php';
require_once __DIR__ . '/FactyPayment.class.php';
require_once __DIR__ . '/FactyCatalog.class.php';

/**
 * \file    class/FactyJob.class.php
 * \ingroup factymx
 * \brief   Bandeja de salida: reconciliación y mantenimiento.
 *
 * La regla que gobierna este archivo: **un fallo de red no es un resultado
 * negativo.** Un timeout significa "no sé", no "no se timbró" — la petición pudo
 * haber llegado y el CFDI puede existir ya. Por eso el único trabajo que se
 * encola tras un fallo es `reconcile`: preguntar y converger. Nunca `stamp`,
 * porque reintentar a ciegas gasta timbres de verdad.
 *
 * Lo que este cron **no** hace, a propósito:
 *
 *  - **No consulta el estatus del SAT.** Cada consulta reenviada al PAC consume
 *    un folio de la cuenta del cliente; un cron que las hiciera solo sería un
 *    goteo de cargos que nadie pidió. El estatus se consulta cuando alguien lo
 *    pide, desde la pantalla.
 *  - **No reintenta cancelaciones.** Si una cancelación no se pudo confirmar,
 *    hay que verificar el estatus ante el SAT antes de volver a intentarla:
 *    insistir podría gastar un segundo timbre sobre un comprobante ya cancelado.
 *
 * Se ejecuta desde el cron de Dolibarr cada 5 minutos.
 */
class FactyJob
{
    const KIND_RECONCILE = 'reconcile';
    const KIND_CATALOG   = 'catalog_refresh';

    const STATUS_PENDING = 'pending';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    /** Después de esto se pide intervención humana. Un trabajo que falló ocho
     *  veces no se arregla por insistir una novena. */
    const MAX_ATTEMPTS = 8;

    /** Un registro en proceso más viejo que esto se reconcilia aunque nadie
     *  haya encolado el trabajo: cubre el caso de que PHP muriera entre reservar
     *  la fila y encolar, que si no dejaría la factura bloqueada para siempre. */
    const STALE_PENDING_MINUTES = 15;

    /** @var DoliDB */
    public $db;

    // SIN tipo, por la misma razón que en actions_factymx: el planificador de
    // tareas de Dolibarr escribe estas propiedades directamente y les asigna
    // null. Un tipo estricto aquí convertiría un cron en un fatal.
    public $error = '';
    public $errors = array();
    public $output = '';

    /**
     * Dolibarr construye la clase del cron sin argumentos, así que se admite
     * tanto la inyección explícita (pruebas) como el $db global (cron).
     * El parámetro se llama distinto a propósito: `global $db` dentro de una
     * función cuyo parámetro también se llama $db es legal pero ilegible.
     */
    public function __construct($dbInstance = null)
    {
        global $db;

        $this->db = $dbInstance ?? $db;
    }

    /**
     * Encola un trabajo. Idempotente por (entity, env, kind, ref): encolar dos
     * veces la misma reconciliación no crea dos.
     */
    public static function enqueue(
        $db,
        string $kind,
        string $refTable,
        int $refId,
        ?array $payload = null,
        int $delaySeconds = 0
    ): int {
        global $conf;

        $entity = (int) $conf->entity;
        $env    = FactyConfig::env();

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'factymx_job
                WHERE entity = ' . $entity . " AND env = '" . $db->escape($env) . "'
                  AND kind = '" . $db->escape($kind) . "'
                  AND ref_table = '" . $db->escape($refTable) . "' AND ref_id = " . ((int) $refId) . "
                  AND status = '" . $db->escape(self::STATUS_PENDING) . "'";
        $res = $db->query($sql);
        if ($res && ($row = $db->fetch_object($res))) {
            $db->free($res);

            return (int) $row->rowid;
        }

        $nextRun = dol_time_plus_duree(dol_now(), $delaySeconds, 's');

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'factymx_job
                (entity, env, kind, ref_table, ref_id, payload_json, attempts, next_run_at, status, datec)
                VALUES ('
                . $entity . ", '" . $db->escape($env) . "', '"
                . $db->escape($kind) . "', '"
                . $db->escape($refTable) . "', "
                . ((int) $refId) . ', '
                . ($payload === null ? 'NULL' : "'" . $db->escape(json_encode($payload, JSON_UNESCAPED_UNICODE)) . "'") . ', '
                . "0, '" . $db->idate($nextRun) . "', '"
                . $db->escape(self::STATUS_PENDING) . "', '"
                . $db->idate(dol_now()) . "')";

        return $db->query($sql) ? (int) $db->last_insert_id(MAIN_DB_PREFIX . 'factymx_job') : -1;
    }

    /**
     * Punto de entrada del cron de Dolibarr.
     *
     * @return int 0 si todo salió bien, <0 si hubo errores.
     */
    public function runPending(): int
    {
        global $conf;

        $this->output = '';
        $this->error  = '';

        // Sólo se procesa el ambiente ACTIVO. Un trabajo encolado en pruebas no
        // debe ejecutarse contra producción sólo porque alguien movió el switch:
        // las credenciales, los ids y las consecuencias son otras.
        $env = FactyConfig::env();
        if (!FactyConfig::isConfigured($env)) {
            $this->output = 'Facty no está configurado para el ambiente ' . FactyConfig::label($env) . '.';

            return 0;
        }

        $adopted = $this->sweepStalePending($env);

        $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . "factymx_job
                WHERE status = '" . $this->db->escape(self::STATUS_PENDING) . "'
                  AND entity = " . ((int) $conf->entity) . "
                  AND env = '" . $this->db->escape($env) . "'
                  AND (next_run_at IS NULL OR next_run_at <= '" . $this->db->idate(dol_now()) . "')
                ORDER BY next_run_at ASC
                LIMIT 50";

        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = 'No se pudo leer la cola de trabajos.';

            return -1;
        }

        $rows = array();
        while ($row = $this->db->fetch_object($res)) {
            $rows[] = $row;
        }
        $this->db->free($res);

        $done = 0;
        $incidencias = 0;

        foreach ($rows as $row) {
            try {
                $this->runOne($row);
                $this->finish((int) $row->rowid, self::STATUS_DONE, null);
                $done++;
            } catch (FactyTransportException $e) {
                // Sigue sin saberse el resultado. Se reintenta con espera
                // creciente; NO se marca como fallido, porque "no sé" no es
                // "no pasó".
                $this->reschedule($row, $e->getMessage());
                $incidencias++;
            } catch (FactyApiException $e) {
                if ($e->isRetryable()) {
                    $this->reschedule($row, $e->getMessage());
                } else {
                    // 401/403/402/422: insistir no lo arregla.
                    $this->finish((int) $row->rowid, self::STATUS_FAILED, $e->userMessage());
                }
                $incidencias++;
            } catch (Exception $e) {
                $this->finish((int) $row->rowid, self::STATUS_FAILED, $e->getMessage());
                $incidencias++;
            }
        }

        $this->output = 'Reconciliaciones adoptadas: ' . $adopted
            . '. Trabajos: ' . $done . ' completados, ' . $incidencias . ' con incidencias ('
            . FactyConfig::label($env) . ').';

        return 0;
    }

    /**
     * Busca registros atorados en `pending` sin trabajo encolado y les encola
     * uno.
     *
     * Es la red de seguridad del caso peor: si PHP muere entre reservar la fila
     * y encolar la reconciliación, esa factura queda bloqueada —  no se puede
     * timbrar porque ya hay una fila en curso, y nadie va a resolverla. Sin este
     * barrido haría falta tocar la base a mano.
     *
     * @return int cuántos se encolaron
     */
    private function sweepStalePending(string $env): int
    {
        global $conf;

        $limite = $this->db->idate(dol_time_plus_duree(dol_now(), -self::STALE_PENDING_MINUTES, 'i'));
        $n = 0;

        foreach (array('factymx_cfdi', 'factymx_payment') as $table) {
            $sql = 'SELECT t.rowid FROM ' . MAIN_DB_PREFIX . $table . " t
                    LEFT JOIN " . MAIN_DB_PREFIX . "factymx_job j
                           ON j.ref_table = '" . $this->db->escape($table) . "' AND j.ref_id = t.rowid
                          AND j.status = '" . $this->db->escape(self::STATUS_PENDING) . "'
                    WHERE t.entity = " . ((int) $conf->entity) . "
                      AND t.env = '" . $this->db->escape($env) . "'
                      AND t.status = 'pending'
                      AND t.tms < '" . $limite . "'
                      AND j.rowid IS NULL
                    LIMIT 50";

            $res = $this->db->query($sql);
            if (!$res) {
                continue;
            }
            while ($row = $this->db->fetch_object($res)) {
                self::enqueue($this->db, self::KIND_RECONCILE, $table, (int) $row->rowid);
                $n++;
            }
            $this->db->free($res);
        }

        return $n;
    }

    /** @throws Exception */
    private function runOne($row): void
    {
        switch ($row->kind) {
            case self::KIND_RECONCILE:
                if ($row->ref_table === 'factymx_payment') {
                    $this->reconcilePayment((int) $row->ref_id);
                } else {
                    $this->reconcileCfdi((int) $row->ref_id);
                }
                break;
            case self::KIND_CATALOG:
                $this->refreshCatalogs();
                break;
            default:
                throw new Exception('Tipo de trabajo desconocido: ' . $row->kind);
        }
    }

    /**
     * Resuelve un timbrado de factura con resultado desconocido.
     *
     * Pregunta a Facty por la llave de idempotencia. Si el CFDI existe se adopta
     * su resultado — no se vuelve a timbrar. Si no existe, la fila se marca
     * fallida para que una persona decida, en lugar de que el cron gaste un
     * timbre por su cuenta.
     */
    private function reconcileCfdi(int $rowId): void
    {
        $row = $this->fetchRow('factymx_cfdi', $rowId);
        if ($row === null || $row->status !== FactyCfdi::STATUS_PENDING) {
            return; // Ya se resolvió por otra vía.
        }

        $invoice = $this->findByIdempotencyKey((string) $row->env, (string) $row->idempotency_key);

        $cfdi = new FactyCfdi($this->db);
        $cfdi->id  = (int) $row->rowid;
        $cfdi->env = (string) $row->env;

        if ($invoice !== null) {
            $cfdi->markStamped($invoice);

            return;
        }

        $cfdi->markFailed(
            'El timbrado no se completó: Facty no tiene ningún CFDI con esta solicitud. '
            . 'Puedes volver a intentarlo desde la pestaña CFDI de la factura.'
        );
    }

    /** Igual que el anterior, para el complemento de pago. */
    private function reconcilePayment(int $rowId): void
    {
        $row = $this->fetchRow('factymx_payment', $rowId);
        if ($row === null || $row->status !== FactyPayment::STATUS_PENDING) {
            return;
        }

        $invoice = $this->findByIdempotencyKey((string) $row->env, (string) $row->idempotency_key);

        $rec = new FactyPayment($this->db);
        $rec->id  = (int) $row->rowid;
        $rec->env = (string) $row->env;

        if ($invoice !== null) {
            // El REP es un comprobante de tipo P: el id que devuelve la búsqueda
            // es el del CFDI, no el del pago. Se guarda lo que se sabe con
            // certeza y se deja el id de pago en blanco antes que inventarlo.
            $rec->facty_invoice_id = isset($invoice['id']) ? (string) $invoice['id'] : null;
            $rec->uuid             = isset($invoice['uuid']) ? (string) $invoice['uuid'] : null;
            $rec->status           = FactyPayment::STATUS_STAMPED;
            $rec->stamped_at       = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
            $rec->last_error       = null;
            $rec->update();

            return;
        }

        $rec->markFailed(
            'El complemento no se timbró: Facty no tiene ningún comprobante con esta solicitud. '
            . 'Puedes volver a intentarlo desde la pestaña del pago.'
        );
    }

    /**
     * Busca en Facty el comprobante de una llave de idempotencia.
     *
     * **Verifica que lo que vuelve sea lo que se pidió.** No basta con tomar el
     * primer elemento: si el filtro dejara de aplicarse — por un cambio del lado
     * del servidor, por un proxy que se come el parámetro — la respuesta sería
     * la factura más reciente de la organización, y adoptarla marcaría este
     * documento con el folio fiscal de otro. Ante la duda se devuelve null, que
     * como mucho deja el registro para revisión manual.
     *
     * @return array|null
     */
    private function findByIdempotencyKey(string $env, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $client = new FactyClient($env);
        $found  = $client->request(
            'GET',
            $client->orgPath('invoices?idempotencyKey=' . rawurlencode($key))
        );

        $invoices = isset($found['invoices']) && is_array($found['invoices']) ? $found['invoices'] : array();

        foreach ($invoices as $inv) {
            if (isset($inv['idempotencyKey']) && (string) $inv['idempotencyKey'] === $key) {
                return $inv;
            }
        }

        if ($invoices) {
            // Llegó algo, pero no lo que se preguntó. Es exactamente el caso que
            // esta comprobación existe para atrapar, y merece quedar registrado.
            dol_syslog(
                'FactyJob: la búsqueda por llave de idempotencia devolvió comprobantes que no corresponden ('
                . $key . '). No se adopta ninguno.',
                LOG_WARNING
            );
        }

        return null;
    }

    /** Refresca los catálogos pequeños que se usan en los formularios. */
    private function refreshCatalogs(): void
    {
        $catalog = new FactyCatalog($this->db);

        foreach (array('UsoCfdi', 'FormaPago', 'RegimenFiscal', 'Moneda', 'ObjetoImp', 'TipoRelacion') as $type) {
            $catalog->all($type, true);
        }
    }

    private function fetchRow(string $table, int $rowId)
    {
        $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . $table . ' WHERE rowid = ' . $rowId;
        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $this->db->fetch_object($res);
        $this->db->free($res);

        return $row ?: null;
    }

    private function reschedule($row, string $error): void
    {
        $attempts = ((int) $row->attempts) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->finish(
                (int) $row->rowid,
                self::STATUS_FAILED,
                'Se agotaron los reintentos (' . self::MAX_ATTEMPTS . '). Último error: ' . $error
            );

            return;
        }

        // Espera creciente con tope de una hora: 1, 2, 4, 8… minutos. Insistir
        // cada 5 minutos contra un servicio caído sólo alarga el incidente.
        $delay = min(3600, 60 * (2 ** ($attempts - 1)));

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'factymx_job SET '
            . 'attempts = ' . $attempts . ', '
            . "next_run_at = '" . $this->db->idate(dol_time_plus_duree(dol_now(), $delay, 's')) . "', "
            . "last_error = '" . $this->db->escape($error) . "' "
            . 'WHERE rowid = ' . ((int) $row->rowid);

        $this->db->query($sql);
    }

    private function finish(int $rowId, string $status, ?string $error): void
    {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . "factymx_job SET status = '" . $this->db->escape($status) . "', "
            . 'last_error = ' . ($error === null ? 'NULL' : "'" . $this->db->escape($error) . "'") . ' '
            . 'WHERE rowid = ' . $rowId;

        $this->db->query($sql);
    }
}
