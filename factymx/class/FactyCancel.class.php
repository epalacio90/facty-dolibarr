<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';
require_once __DIR__ . '/FactyCfdi.class.php';
require_once __DIR__ . '/FactyArtifacts.class.php';

/**
 * \file    class/FactyCancel.class.php
 * \ingroup factymx
 * \brief   Cancelación de un CFDI ante el SAT.
 *
 * Cancelar **gasta un timbre** y es irreversible: un comprobante cancelado no
 * se "descancela". Por eso la validación de los motivos se hace aquí y no sólo
 * en el formulario, y por eso un fallo de red deja el registro como está en vez
 * de suponer que no pasó nada.
 *
 * Motivos del SAT:
 *   01 — comprobante emitido con errores CON relación (exige el folio del que
 *        lo sustituye)
 *   02 — comprobante emitido con errores SIN relación
 *   03 — no se llevó a cabo la operación
 *   04 — operación nominativa relacionada en una factura global
 */
class FactyCancel
{
    public const MOTIVOS = array(
        '01' => 'Comprobante emitido con errores con relación',
        '02' => 'Comprobante emitido con errores sin relación',
        '03' => 'No se llevó a cabo la operación',
        '04' => 'Operación nominativa relacionada en una factura global',
    );

    /** @var DoliDB */
    private $db;
    private string $env;

    public string $error = '';

    public function __construct($db, ?string $env = null)
    {
        $this->db  = $db;
        $this->env = $env ?? FactyConfig::env();
    }

    /**
     * Cancela el CFDI de una factura.
     *
     * @param  string $motivo           01–04
     * @param  string $folioSustitucion UUID del que sustituye (sólo motivo 01)
     * @return bool
     */
    public function cancel(Facture $facture, FactyCfdi $cfdi, string $motivo, string $folioSustitucion = ''): bool
    {
        $this->error = '';

        if (!isset(self::MOTIVOS[$motivo])) {
            $this->error = 'Motivo de cancelación inválido.';

            return false;
        }

        if ($cfdi->status !== FactyCfdi::STATUS_STAMPED) {
            $this->error = 'Sólo se puede cancelar un CFDI timbrado.';

            return false;
        }

        $folioSustitucion = trim($folioSustitucion);

        // El folio de sustitución es obligatorio con motivo 01 y no válido con
        // los demás. Se rechaza aquí, antes de gastar el timbre, porque el PAC
        // devolvería el mismo error pero ya con el cobro hecho.
        if ($motivo === '01' && $folioSustitucion === '') {
            $this->error = 'El motivo 01 exige el folio fiscal del comprobante que sustituye a éste.';

            return false;
        }
        if ($motivo !== '01' && $folioSustitucion !== '') {
            $this->error = 'El folio de sustitución sólo aplica con el motivo 01.';

            return false;
        }
        if ($folioSustitucion !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $folioSustitucion)) {
            $this->error = 'El folio de sustitución no parece un folio fiscal (UUID) válido.';

            return false;
        }

        $body = array('motivo' => $motivo);
        if ($folioSustitucion !== '') {
            $body['folioSustitucion'] = strtoupper($folioSustitucion);
        }

        try {
            $client = new FactyClient($this->env);
            $client->request(
                'POST',
                $client->orgPath('invoices/' . rawurlencode((string) $cfdi->facty_invoice_id) . '/cancel'),
                $body
            );
        } catch (FactyTransportException $e) {
            // No se sabe si la cancelación llegó. NO se marca cancelado —
            // marcarlo dejaría una factura viva en el SAT que aquí se ve
            // cancelada, que es la peor de las dos mentiras posibles.
            $this->error = 'No se pudo confirmar la cancelación con Facty. '
                . 'Revisa el estatus ante el SAT antes de volver a intentarlo: la solicitud pudo haber llegado.';

            return false;
        } catch (FactyApiException $e) {
            $this->error = $e->userMessage();
            if ($e->requestId !== null) {
                $this->error .= ' (referencia ' . $e->requestId . ')';
            }

            return false;
        }

        $cfdi->status        = FactyCfdi::STATUS_CANCELLED;
        $cfdi->cancelled_at  = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
        $cfdi->cancel_motivo = $motivo;
        $cfdi->update();

        // El acuse es la prueba de la cancelación; se guarda de inmediato,
        // aunque puede no estar listo si el SAT dejó la solicitud en proceso.
        try {
            $artifacts = new FactyArtifacts($this->db, $this->env);
            $artifacts->fetchAcuse($facture, $cfdi, true);
        } catch (Exception $e) {
            dol_syslog('FactyCancel: no se pudo guardar el acuse: ' . $e->getMessage(), LOG_NOTICE);
        }

        return true;
    }

    /**
     * Consulta el estatus del CFDI ante el SAT.
     *
     * **Cada consulta reenviada al PAC cuesta un folio**, así que Facty sirve un
     * valor en caché y sólo consulta de verdad cuando se le pide expresamente.
     * Por eso `$force` existe pero no se usa al pintar la pantalla: se ofrece
     * como un botón que el usuario decide oprimir.
     *
     * @return array|null
     */
    public function satStatus(FactyCfdi $cfdi, bool $force = false): ?array
    {
        $this->error = '';

        if ($cfdi->facty_invoice_id === null || $cfdi->facty_invoice_id === '') {
            return null;
        }

        try {
            $client = new FactyClient($this->env);
            $path   = $client->orgPath('invoices/' . rawurlencode($cfdi->facty_invoice_id) . '/sat-status');
            if ($force) {
                $path .= '?force=true';
            }

            return $client->request('GET', $path);
        } catch (FactyTransportException $e) {
            $this->error = 'No se pudo consultar el estatus en el SAT.';

            return null;
        } catch (FactyApiException $e) {
            $this->error = $e->userMessage();

            return null;
        }
    }
}
