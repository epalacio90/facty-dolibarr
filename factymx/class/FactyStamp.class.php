<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';
require_once __DIR__ . '/FactyCfdi.class.php';
require_once __DIR__ . '/FactyJob.class.php';
require_once __DIR__ . '/FactyPayload.class.php';
require_once __DIR__ . '/FactyClientSync.class.php';
require_once __DIR__ . '/FactyProductSync.class.php';

// Se cargan aquí y no se dan por hecho: esta clase también se usa desde el
// trigger de timbrado automático, donde la página que la invoca puede no haber
// cargado las clases del núcleo.
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * \file    class/FactyStamp.class.php
 * \ingroup factymx
 * \brief   Timbrar una factura de Dolibarr.
 *
 * El orden de las operaciones es lo importante de este archivo, y no es
 * arbitrario:
 *
 *   1. **Reservar la fila local** en `pending` ANTES de llamar a Facty. Un
 *      segundo intento se topa con ella y se detiene.
 *   2. Asegurar cliente (y productos) en Facty.
 *   3. Armar el cuerpo y validarlo ANTES de gastar nada.
 *   4. Llamar a Facty con una llave de idempotencia determinista.
 *   5. Guardar el resultado.
 *
 * Y el caso que de verdad importa: **si la red falla, el resultado es
 * DESCONOCIDO.** La petición pudo haber llegado y el CFDI puede existir. En ese
 * caso la fila se queda en `pending` y se encola una reconciliación; no se
 * reintenta, no se marca como fallida, y sobre todo no se vuelve a timbrar.
 * Marcarla fallida invitaría a alguien a darle otra vez al botón, y eso sí
 * cuesta un timbre y produce un CFDI duplicado ante el SAT.
 */
class FactyStamp
{
    /** @var DoliDB */
    private $db;
    private string $env;

    /** @var string[] Motivos por los que la factura todavía no se puede timbrar. */
    public array $problems = array();

    public string $error = '';

    public function __construct($db, ?string $env = null)
    {
        $this->db  = $db;
        $this->env = $env ?? FactyConfig::env();
    }

    /**
     * Revisa si la factura está lista, SIN llamar a Facty ni gastar nada.
     *
     * Se usa para pintar el resumen previo: es mucho mejor enseñar los seis
     * problemas de una vez que descubrirlos de uno en uno a base de intentos
     * fallidos.
     *
     * @return bool true si se puede timbrar
     */
    public function precheck(Facture $facture, array $opts = array()): bool
    {
        $this->problems = array();

        if ((int) $facture->statut === Facture::STATUS_DRAFT) {
            $this->problems[] = 'La factura está en borrador. Valídala en Dolibarr antes de timbrarla.';
        }

        $existing = FactyCfdi::fetchByFacture($this->db, (int) $facture->id, $this->env);
        if ($existing !== null && $existing->status === FactyCfdi::STATUS_STAMPED) {
            $this->problems[] = 'Esta factura ya está timbrada (folio fiscal ' . $existing->uuid . ').';
        }
        if ($existing !== null && $existing->status === FactyCfdi::STATUS_PENDING) {
            $this->problems[] = 'Hay un timbrado en curso para esta factura. '
                . 'Espera a que termine o revisa el diagnóstico; no la vuelvas a timbrar.';
        }

        if (!FactyConfig::isConfigured($this->env)) {
            $this->problems[] = 'Facty no está configurado para el ambiente ' . FactyConfig::label($this->env) . '.';
        }

        // Receptor: se valida sin llamar a Facty.
        $soc = new Societe($this->db);
        if ($soc->fetch((int) $facture->socid) > 0) {
            try {
                $sync = new FactyClientSync($this->db, $this->env);
                $sync->buildPayload($soc);
            } catch (InvalidArgumentException $e) {
                $this->problems[] = $e->getMessage();
            }
        } else {
            $this->problems[] = 'No se pudo cargar el cliente de la factura.';
        }

        // Conceptos: se arma el cuerpo en seco para recoger todos los problemas.
        $payload = new FactyPayload();
        $payload->fromFacture(
            $facture,
            'precheck',
            $this->buildOpts($facture, $opts, array(), $this->collectProductData($facture))
        );
        foreach ($payload->problems as $p) {
            $this->problems[] = $p;
        }

        return !$this->problems;
    }

    /**
     * Timbra. Devuelve el registro local actualizado, o null si no se pudo.
     *
     * @throws nothing — los errores quedan en $this->error / $this->problems,
     *         porque quien llama es una pantalla y necesita seguir pintando.
     */
    public function stamp(Facture $facture, array $opts = array()): ?FactyCfdi
    {
        global $user;

        $this->error = '';

        if (!$this->precheck($facture, $opts)) {
            return null;
        }

        $isEgreso = ((int) $facture->type === Facture::TYPE_CREDIT_NOTE);

        // --- 1. Reservar la fila ANTES de tocar la red.
        $cfdi = new FactyCfdi($this->db);
        $cfdi->fk_facture      = (int) $facture->id;
        $cfdi->env             = $this->env;
        $cfdi->cfdi_type       = $isEgreso ? 'egreso' : 'ingreso';
        $cfdi->idempotency_key = factymxIdempotencyKey('facture', (int) $facture->id);

        $reserved = $cfdi->reserve();
        if ($reserved === 0) {
            $this->error = 'Ya existe un timbrado en curso o completado para esta factura.';

            return null;
        }
        if ($reserved < 0) {
            $this->error = 'No se pudo registrar el intento de timbrado en la base de datos.';

            return null;
        }

        // --- 2. Cliente y productos en Facty.
        try {
            $soc = new Societe($this->db);
            $soc->fetch((int) $facture->socid);

            $clientSync = new FactyClientSync($this->db, $this->env);
            $clientId   = $clientSync->ensure($soc);

            $productMap = $this->syncProducts($facture);
        } catch (FactyTransportException $e) {
            // Sincronizar no gasta timbres, pero tampoco sabemos si quedó a
            // medias. Se libera la reserva para que se pueda reintentar sin
            // arrastrar una fila `pending` que bloquee la factura.
            $cfdi->markFailed($e->getMessage());
            $this->error = $e->getMessage();

            return null;
        } catch (Exception $e) {
            $cfdi->markFailed($e->getMessage());
            $this->error = $e instanceof FactyApiException ? $e->userMessage() : $e->getMessage();

            return null;
        }

        // --- 3. Cuerpo.
        $payload = new FactyPayload();
        $body    = $payload->fromFacture(
            $facture,
            $clientId,
            $this->buildOpts($facture, $opts, $productMap, $this->collectProductData($facture))
        );

        if ($payload->problems) {
            $this->problems = $payload->problems;
            $cfdi->markFailed(implode(' ', $payload->problems));

            return null;
        }

        // --- 4. Timbrar.
        $client = new FactyClient($this->env);
        $client->setLogger(function (array $entry) {
            $this->writeLog($entry);
        });

        try {
            $response = $client->request('POST', $client->orgPath('invoices'), $body);
        } catch (FactyTransportException $e) {
            // EL CASO IMPORTANTE. No se sabe si se timbró. La fila se queda en
            // `pending` y se encola la reconciliación; el usuario ve "en
            // proceso", no "falló", porque decirle que falló lo invitaría a
            // intentarlo otra vez y a pagar dos veces.
            FactyJob::enqueue($this->db, FactyJob::KIND_RECONCILE, 'factymx_cfdi', $cfdi->id, null, 60);
            $this->error = 'No se pudo confirmar el timbrado con Facty. Quedó en proceso: '
                . 'el módulo va a verificar en unos minutos si el CFDI se emitió. '
                . 'NO vuelvas a timbrar esta factura mientras tanto.';

            return null;
        } catch (FactyApiException $e) {
            $msg = $e->userMessage();
            if ($e->fieldErrors) {
                $msg .= ' (' . implode('; ', $e->fieldErrors) . ')';
            }
            $cfdi->markFailed($msg, $e->requestId);
            $this->error = $msg;

            return null;
        } catch (Exception $e) {
            $cfdi->markFailed($e->getMessage());
            $this->error = $e->getMessage();

            return null;
        }

        // --- 5. Guardar.
        $cfdi->markStamped($response);

        return $cfdi;
    }

    /**
     * Sincroniza los productos del catálogo usados en la factura.
     *
     * Un producto que no se puede sincronizar NO detiene el timbrado: su línea
     * se manda con clave, unidad y descripción en línea, que es igual de válido
     * para el SAT. Cancelar una factura entera porque un producto del catálogo
     * está incompleto sería desproporcionado.
     *
     * @return array<int,string>
     */
    private function syncProducts(Facture $facture): array
    {
        $map  = array();
        $sync = new FactyProductSync($this->db, $this->env);

        foreach ($facture->lines as $line) {
            $fk = (int) $line->fk_product;
            if ($fk <= 0 || isset($map[$fk])) {
                continue;
            }

            $product = new Product($this->db);
            if ($product->fetch($fk) <= 0) {
                continue;
            }

            try {
                $map[$fk] = $sync->ensure($product);
            } catch (FactyTransportException $e) {
                throw $e; // La red está caída: eso sí detiene todo.
            } catch (Exception $e) {
                dol_syslog('FactyStamp: producto ' . $fk . ' sin sincronizar: ' . $e->getMessage(), LOG_NOTICE);
            }
        }

        return $map;
    }

    /** Opciones efectivas: lo que mandó la pantalla, con los valores por omisión debajo. */
    private function buildOpts(
        Facture $facture,
        array $opts,
        array $productMap,
        array $productData = array()
    ): array {
        $usoCfdi = $opts['usoCfdi'] ?? ($facture->array_options['options_factymx_usocfdi'] ?? '');
        if ($usoCfdi === '') {
            $usoCfdi = getDolGlobalString('FACTYMX_DEFAULT_USOCFDI');
        }

        $metodo = $opts['metodoPago'] ?? ($facture->array_options['options_factymx_metodopago'] ?? '');
        if ($metodo === '') {
            $metodo = getDolGlobalString('FACTYMX_DEFAULT_METODOPAGO') ?: 'PUE';
        }

        return array(
            'usoCfdi'           => $usoCfdi,
            'metodoPago'        => $metodo,
            'formaPago'         => $opts['formaPago'] ?? $this->formaPagoFor($facture),
            'serie'             => $opts['serie'] ?? getDolGlobalString('FACTYMX_DEFAULT_SERIE'),
            'exportacion'       => $opts['exportacion'] ?? ($facture->array_options['options_factymx_exportacion'] ?? ''),
            'cfdiRelacionados'  => $opts['cfdiRelacionados'] ?? null,
            'informacionGlobal' => $opts['informacionGlobal'] ?? null,
            'idempotencyKey'    => factymxIdempotencyKey('facture', (int) $facture->id),
            'productMap'        => $productMap,
            'productData'       => $productData,
        );
    }

    /**
     * Lee las claves del SAT de los productos que usa la factura.
     *
     * Es lo que permite que el usuario capture las claves UNA vez en la ficha
     * del producto y no en cada línea de cada factura. Antes el mapeador sólo
     * miraba los extrafields de la línea, así que un producto perfectamente
     * capturado se reportaba como incompleto — y peor, la revisión previa
     * corría siempre con esta información vacía, de modo que el aviso aparecía
     * incluso cuando todo estaba bien.
     *
     * Son lecturas a la base, no a la red: se puede hacer en la revisión previa
     * sin coste ni efectos.
     *
     * @return array<int,array{claveProdServ:string,claveUnidad:string,objetoImp:string}>
     */
    private function collectProductData(Facture $facture): array
    {
        $out = array();

        foreach ($facture->lines as $line) {
            $fk = (int) $line->fk_product;
            if ($fk <= 0 || isset($out[$fk])) {
                continue;
            }

            $product = new Product($this->db);
            if ($product->fetch($fk) <= 0) {
                continue;
            }
            // fetch() no siempre trae los extrafields; sin esto el arreglo llega
            // vacío y volveríamos al mismo fallo por otra vía.
            $product->fetch_optionals();

            $opt = $product->array_options ?? array();

            $out[$fk] = array(
                'claveProdServ' => trim((string) ($opt['options_factymx_claveprodserv'] ?? '')),
                'claveUnidad'   => trim((string) ($opt['options_factymx_claveunidad'] ?? '')),
                'objetoImp'     => trim((string) ($opt['options_factymx_objetoimp'] ?? '')),
            );
        }

        return $out;
    }

    /** Traduce el modo de pago de Dolibarr a la clave del SAT, según el mapeo configurado. */
    private function formaPagoFor(Facture $facture): string
    {
        $code = '';
        if (!empty($facture->mode_reglement_code)) {
            $code = (string) $facture->mode_reglement_code;
        }
        if ($code === '') {
            return '';
        }

        return getDolGlobalString('FACTYMX_FORMAPAGO_' . strtoupper(dol_sanitizeFileName($code)));
    }

    private function writeLog(array $entry): void
    {
        global $conf, $user;

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'factymx_log
                (entity, env, fk_user, action, method, path, http_status, facty_code, facty_request_id, duration_ms, message)
                VALUES ('
                . ((int) $conf->entity) . ", '" . $this->db->escape($this->env) . "', "
                . ((int) ($user->id ?? 0)) . ", 'stamp', '"
                . $this->db->escape((string) $entry['method']) . "', '"
                . $this->db->escape((string) $entry['path']) . "', "
                . ((int) $entry['http_status']) . ', '
                . ($entry['facty_code'] ? "'" . $this->db->escape((string) $entry['facty_code']) . "'" : 'NULL') . ', '
                . ($entry['facty_request_id'] ? "'" . $this->db->escape((string) $entry['facty_request_id']) . "'" : 'NULL') . ', '
                . ((int) $entry['duration_ms']) . ', '
                . ($entry['message'] ? "'" . $this->db->escape((string) $entry['message']) . "'" : 'NULL') . ')';

        $this->db->query($sql);
    }
}
