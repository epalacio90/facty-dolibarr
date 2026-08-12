<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';
require_once __DIR__ . '/FactyCfdi.class.php';
require_once __DIR__ . '/FactyPayment.class.php';
require_once __DIR__ . '/FactyJob.class.php';

/**
 * \file    class/FactyRep.class.php
 * \ingroup factymx
 * \brief   Complemento de pago (REP).
 *
 * Aquí es donde la fila de mapeo se paga sola. El SAT identifica cada documento
 * relacionado por su folio fiscal, pero Facty los identifica por su id interno;
 * como `llx_factymx_cfdi` guardó los dos al timbrar, relacionar un pago con sus
 * facturas es una consulta local. Sin esa fila habría que pedirle a Facty que
 * tradujera folios a ids en cada pago, y para las facturas timbradas con otra
 * herramienta simplemente no habría traducción.
 *
 * **Sólo se pueden relacionar facturas timbradas por este módulo y en el mismo
 * ambiente.** No es una limitación caprichosa: el id que necesita Facty sólo
 * existe si nosotros la timbramos, y el de pruebas no significa nada en
 * producción. La pantalla lo dice en vez de mostrar una lista incompleta sin
 * explicación.
 */
class FactyRep
{
    /** @var DoliDB */
    private $db;
    private string $env;

    /** @var string[] */
    public array $problems = array();
    public string $error = '';

    public function __construct($db, ?string $env = null)
    {
        $this->db  = $db;
        $this->env = $env ?? FactyConfig::env();
    }

    /**
     * Arma los documentos relacionados de un pago de Dolibarr.
     *
     * Por cada factura que este pago abona calcula lo que el SAT exige:
     *
     *  - **numParcialidad**: cuántos pagos van, contando éste.
     *  - **importeSaldoAnterior**: lo que se debía ANTES de este pago.
     *  - **importePagado**: lo abonado en este pago.
     *  - **importeSaldoInsoluto**: lo que queda después.
     *
     * Los tres importes tienen que cuadrar entre sí o el SAT rechaza el
     * comprobante, así que se derivan del historial real de pagos y no de lo que
     * alguien teclee.
     *
     * @return array<int,array> lista de documentos, indexada por fk_facture
     */
    public function buildDocuments(Paiement $paiement): array
    {
        $this->problems = array();
        $docs = array();

        foreach ($paiement->amounts as $factureId => $amount) {
            $factureId = (int) $factureId;
            $amount    = (float) $amount;

            if ($amount <= 0) {
                continue;
            }

            $cfdi = FactyCfdi::fetchByFacture($this->db, $factureId, $this->env);

            if ($cfdi === null || $cfdi->status !== FactyCfdi::STATUS_STAMPED || !$cfdi->facty_invoice_id) {
                $facture = new Facture($this->db);
                $ref = $facture->fetch($factureId) > 0 ? $facture->ref : ('#' . $factureId);
                $this->problems[] = 'La factura ' . $ref . ' no está timbrada con Facty en el ambiente '
                    . FactyConfig::label($this->env) . ', así que no se puede incluir en el complemento de pago.';
                continue;
            }

            $facture = new Facture($this->db);
            if ($facture->fetch($factureId) <= 0) {
                continue;
            }

            $totals = $this->saldos($facture, $paiement, $amount);

            $docs[$factureId] = array(
                'invoiceId'            => $cfdi->facty_invoice_id,
                'numParcialidad'       => $totals['parcialidad'],
                'importeSaldoAnterior' => $totals['anterior'],
                'importePagado'        => $totals['pagado'],
                'importeSaldoInsoluto' => $totals['insoluto'],
                // Los ImpuestosDR NO se mandan: Facty los calcula proporcionales
                // al importe pagado, que es lo que el SAT espera y lo que ya
                // hace por su cuenta. Mandarlos desde aquí duplicaría el cálculo
                // en dos lugares que se irían separando.
            );

            // Referencia para la pantalla, no para el SAT.
            $docs[$factureId]['_ref'] = $facture->ref;
            $docs[$factureId]['_uuid'] = $cfdi->uuid;
        }

        if (!$docs && !$this->problems) {
            $this->problems[] = 'El pago no tiene facturas asociadas.';
        }

        return $docs;
    }

    /**
     * Calcula parcialidad y saldos de una factura respecto a ESTE pago.
     *
     * "Antes" se define por fecha de pago y, a igualdad de fecha, por id: dos
     * abonos del mismo día tienen que numerarse de forma estable o la
     * parcialidad cambiaría según cómo ordene la base.
     */
    private function saldos(Facture $facture, Paiement $paiement, float $amount): array
    {
        $total = (float) $facture->total_ttc;

        $sql = 'SELECT pf.amount, p.rowid, p.datep
                FROM ' . MAIN_DB_PREFIX . 'paiement_facture pf
                INNER JOIN ' . MAIN_DB_PREFIX . 'paiement p ON p.rowid = pf.fk_paiement
                WHERE pf.fk_facture = ' . ((int) $facture->id) . '
                ORDER BY p.datep ASC, p.rowid ASC';

        $previo       = 0.0;
        $parcialidad  = 1;
        $esteId       = (int) $paiement->id;
        $esteEncontrado = false;

        $res = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                if ((int) $row->rowid === $esteId) {
                    $esteEncontrado = true;
                    break;
                }
                $previo += (float) $row->amount;
                $parcialidad++;
            }
            $this->db->free($res);
        }

        // Si el pago todavía no está en la tabla (se está creando), la
        // parcialidad calculada ya es la correcta: cuenta los anteriores + 1.
        unset($esteEncontrado);

        $anterior = round($total - $previo, 2);
        $insoluto = round($anterior - $amount, 2);

        // Un insoluto negativo significa que se está abonando más de lo que se
        // debe. El SAT rechaza eso, y aquí se puede decir con nombre y número
        // en vez de dejar que vuelva como un error de importes.
        if ($insoluto < -0.001) {
            $this->problems[] = 'El pago aplicado a la factura ' . $facture->ref . ' (' . price($amount)
                . ') es mayor que su saldo pendiente (' . price($anterior) . ').';
            $insoluto = 0.0;
        }

        return array(
            'parcialidad' => $parcialidad,
            'anterior'    => $anterior,
            'pagado'      => round($amount, 2),
            'insoluto'    => max(0.0, $insoluto),
        );
    }

    /**
     * Timbra el complemento de pago.
     *
     * Mismo orden que el timbrado de facturas: reservar, armar, llamar,
     * guardar. Y el mismo tratamiento del fallo de red — si no sabemos el
     * resultado, el registro se queda en proceso y se encola la verificación.
     */
    public function stamp(Paiement $paiement, array $opts = array()): ?FactyPayment
    {
        $this->error = '';

        $docs = $this->buildDocuments($paiement);
        if ($this->problems) {
            return null;
        }

        $accountId = $this->factyAccountId((int) $paiement->fk_account);
        if ($accountId === '') {
            $this->problems[] = 'La cuenta bancaria del pago no está asociada a una cuenta de Facty. '
                . 'Configúralo en Facty → Valores por omisión.';

            return null;
        }

        $formaPago = $this->formaPagoFor($paiement);
        if ($formaPago === '') {
            $this->problems[] = 'El modo de pago "' . ((string) $paiement->type_code)
                . '" no está mapeado a una clave del SAT. Configúralo en Facty → Valores por omisión.';

            return null;
        }

        $rec = new FactyPayment($this->db);
        $rec->fk_paiement     = (int) $paiement->id;
        $rec->env             = $this->env;
        $rec->monto           = (float) $paiement->amount;
        $rec->moneda          = (string) ($opts['moneda'] ?? 'MXN');
        $rec->fecha_pago      = dol_print_date($paiement->datepaye ?: $paiement->date, '%Y-%m-%d %H:%M:%S');
        $rec->idempotency_key = factymxIdempotencyKey('paiement', (int) $paiement->id);

        $reserved = $rec->reserve();
        if ($reserved === 0) {
            $this->error = 'Ya existe un complemento en curso o timbrado para este pago.';

            return null;
        }
        if ($reserved < 0) {
            $this->error = 'No se pudo registrar el intento en la base de datos.';

            return null;
        }

        $body = array(
            'accountId'      => $accountId,
            'fechaPago'      => dol_print_date($paiement->datepaye ?: $paiement->date, '%Y-%m-%dT%H:%M:%S'),
            'formaPago'      => $formaPago,
            'monto'          => round((float) $paiement->amount, 2),
            'documents'      => array_values(array_map(function ($d) {
                unset($d['_ref'], $d['_uuid']);

                return $d;
            }, $docs)),
            'idempotencyKey' => $rec->idempotency_key,
            'stamp'          => true,
        );

        if (!empty($paiement->num_paiement)) {
            $body['numOperacion'] = (string) $paiement->num_paiement;
        }

        $moneda = strtoupper((string) ($opts['moneda'] ?? 'MXN'));
        if ($moneda !== 'MXN') {
            $body['moneda'] = $moneda;
            if (!empty($opts['tipoCambio'])) {
                // Número, no texto (misma razón que en la factura).
                $body['tipoCambio'] = (float) $opts['tipoCambio'];
            }
        }

        try {
            $client = new FactyClient($this->env);
            $response = $client->request('POST', $client->orgPath('payments'), $body);
        } catch (FactyTransportException $e) {
            FactyJob::enqueue($this->db, FactyJob::KIND_RECONCILE, 'factymx_payment', $rec->id, null, 60);
            $this->error = 'No se pudo confirmar el timbrado del complemento. Quedó en proceso: '
                . 'el módulo verificará en unos minutos. NO lo vuelvas a timbrar mientras tanto.';

            return null;
        } catch (FactyApiException $e) {
            $msg = $e->userMessage();
            if ($e->fieldErrors) {
                $msg .= ' (' . $e->fieldErrorsText() . ')';
            }
            $rec->markFailed($msg, $e->requestId);
            $this->error = $msg;

            return null;
        } catch (Exception $e) {
            $rec->markFailed($e->getMessage());
            $this->error = $e->getMessage();

            return null;
        }

        $rec->markStamped($response);

        return $rec;
    }

    /**
     * Cancela el complemento de pago.
     *
     * Ojo con lo que esto significa del lado de Facty: cancelar el REP anula el
     * pago completo — la factura vuelve a quedar con saldo — y revierte el
     * abono a la cuenta bancaria. No es sólo anular un comprobante.
     */
    public function cancel(FactyPayment $rec, string $motivo, string $folioSustitucion = ''): bool
    {
        $this->error = '';

        if ($rec->status !== FactyPayment::STATUS_STAMPED) {
            $this->error = 'Sólo se puede cancelar un complemento timbrado.';

            return false;
        }
        if ($motivo === '01' && trim($folioSustitucion) === '') {
            $this->error = 'El motivo 01 exige el folio fiscal del complemento que sustituye a éste.';

            return false;
        }

        $body = array('motivo' => $motivo);
        if (trim($folioSustitucion) !== '') {
            $body['folioSustitucion'] = strtoupper(trim($folioSustitucion));
        }

        try {
            $client = new FactyClient($this->env);
            $client->request(
                'POST',
                $client->orgPath('payments/' . rawurlencode((string) $rec->facty_payment_id) . '/cancel'),
                $body
            );
        } catch (FactyTransportException $e) {
            $this->error = 'No se pudo confirmar la cancelación. Verifica el estatus antes de reintentar: '
                . 'la solicitud pudo haber llegado.';

            return false;
        } catch (FactyApiException $e) {
            $this->error = $e->userMessage();

            return false;
        }

        $rec->status        = FactyPayment::STATUS_CANCELLED;
        $rec->cancelled_at  = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
        $rec->cancel_motivo = $motivo;
        $rec->update();

        return true;
    }

    private function factyAccountId(int $bankAccountId): string
    {
        if ($bankAccountId <= 0) {
            return '';
        }

        return getDolGlobalString('FACTYMX_ACCOUNT_' . FactyConfig::suffix($this->env) . '_' . $bankAccountId);
    }

    private function formaPagoFor(Paiement $paiement): string
    {
        $code = (string) ($paiement->type_code ?: '');
        if ($code === '') {
            return '';
        }

        return getDolGlobalString('FACTYMX_FORMAPAGO_' . strtoupper(dol_sanitizeFileName($code)));
    }
}
