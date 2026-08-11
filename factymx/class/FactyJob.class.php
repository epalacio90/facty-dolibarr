<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';
require_once __DIR__ . '/FactyCfdi.class.php';

/**
 * \file    class/FactyJob.class.php
 * \ingroup factymx
 * \brief   Bandeja de salida: reintentos y, sobre todo, RECONCILIACIÓN.
 *
 * La regla que gobierna este archivo: **un fallo de red no es un resultado
 * negativo.** Un timeout significa "no sé", no "no se timbró" — la petición
 * pudo haber llegado y el CFDI puede existir ya en Facty. Por eso el trabajo
 * pendiente por defecto es `reconcile` (preguntar por la llave de idempotencia
 * y converger), no `stamp` (volver a intentar). Reintentar a ciegas gasta
 * timbres de verdad, y ese dinero es del cliente.
 *
 * Se ejecuta desde el cron de Dolibarr cada 5 minutos.
 */
class FactyJob
{
    const KIND_RECONCILE = 'reconcile';
    const KIND_CANCEL    = 'cancel';
    const KIND_SAT_STATUS = 'sat_status';
    const KIND_CATALOG   = 'catalog_refresh';

    const STATUS_PENDING = 'pending';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    /** Después de esto se deja de reintentar y se pide intervención humana.
     *  Un trabajo que falló 8 veces no se va a arreglar por insistir. */
    const MAX_ATTEMPTS = 8;

    /** @var DoliDB */
    public $db;

    public string $error = '';
    public array $errors = array();
    public string $output = '';

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

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "factymx_job
                WHERE entity = " . $entity . " AND env = '" . $db->escape($env) . "'
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
     * @return int 0 si todo salió bien, <0 si hubo errores (Dolibarr lo marca).
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
            $this->output = 'Facty no está configurado para el ambiente ' . FactyConfig::label($env) . '; no hay nada que hacer.';

            return 0;
        }

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

        $done = 0;
        $failed = 0;

        while ($row = $this->db->fetch_object($res)) {
            try {
                $this->runOne($row);
                $this->finish((int) $row->rowid, self::STATUS_DONE, null);
                $done++;
            } catch (FactyTransportException $e) {
                // Sigue sin saberse el resultado. Se reintenta con espera
                // creciente; NO se marca como fallido, porque "no sé" no es
                // "no pasó".
                $this->reschedule($row, $e->getMessage());
                $failed++;
            } catch (FactyApiException $e) {
                if ($e->isRetryable()) {
                    $this->reschedule($row, $e->getMessage());
                } else {
                    // 401/403/402/422: insistir no lo arregla. Se detiene y se
                    // deja el error a la vista en el diagnóstico.
                    $this->finish((int) $row->rowid, self::STATUS_FAILED, $e->userMessage());
                }
                $failed++;
            } catch (Exception $e) {
                $this->finish((int) $row->rowid, self::STATUS_FAILED, $e->getMessage());
                $failed++;
            }
        }
        $this->db->free($res);

        $this->output = 'Trabajos procesados: ' . $done . ' correctos, ' . $failed . ' con incidencias (' . FactyConfig::label($env) . ').';

        return 0;
    }

    /** @throws Exception */
    private function runOne($row): void
    {
        switch ($row->kind) {
            case self::KIND_RECONCILE:
                $this->reconcileCfdi((int) $row->ref_id);
                break;
            case self::KIND_SAT_STATUS:
            case self::KIND_CANCEL:
            case self::KIND_CATALOG:
                // Se implementan en las sub-fases E, F e I. Un trabajo de un
                // tipo que este build no conoce se deja pendiente en lugar de
                // borrarse: puede ser de una versión más nueva del módulo.
                throw new Exception('Tipo de trabajo aún no implementado en esta versión: ' . $row->kind);
            default:
                throw new Exception('Tipo de trabajo desconocido: ' . $row->kind);
        }
    }

    /**
     * Resuelve un timbrado de resultado desconocido.
     *
     * Pregunta a Facty por la llave de idempotencia. Si el CFDI existe, se
     * adopta su resultado — no se vuelve a timbrar. Si no existe, la fila local
     * se marca fallida para que una persona decida, en vez de que el cron gaste
     * un timbre por su cuenta.
     */
    private function reconcileCfdi(int $cfdiRowId): void
    {
        $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'factymx_cfdi WHERE rowid = ' . ((int) $cfdiRowId);
        $res = $this->db->query($sql);
        if (!$res || !($row = $this->db->fetch_object($res))) {
            throw new Exception('No se encontró el registro de CFDI ' . $cfdiRowId . '.');
        }
        $this->db->free($res);

        if ($row->status !== FactyCfdi::STATUS_PENDING) {
            return; // Ya se resolvió por otra vía.
        }

        $client = new FactyClient($row->env);

        // Facty acepta buscar por llave de idempotencia; si el timbrado llegó,
        // la factura ya existe y viene con su UUID.
        $found = $client->request(
            'GET',
            $client->orgPath('invoices?idempotencyKey=' . rawurlencode($row->idempotency_key))
        );

        $invoices = isset($found['invoices']) && is_array($found['invoices']) ? $found['invoices'] : array();

        $cfdi = new FactyCfdi($this->db);
        $cfdi->id  = (int) $row->rowid;
        $cfdi->env = (string) $row->env;

        if ($invoices) {
            $cfdi->markStamped($invoices[0]);

            return;
        }

        $cfdi->markFailed(
            'El timbrado no se completó y Facty no tiene ningún CFDI con esta llave de idempotencia. '
            . 'Puedes volver a intentarlo desde la factura.'
        );
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
            . 'attempts = ' . $attempts . ", "
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
