<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';

/**
 * \file    class/FactyPayload.class.php
 * \ingroup factymx
 * \brief   Traduce una factura de Dolibarr al cuerpo JSON que espera Facty.
 *
 * **Esta clase es pura a propósito**: no escribe en la base, no llama a la red y
 * no depende del estado de la sesión. Recibe objetos y devuelve un arreglo. Esa
 * restricción es lo que la vuelve revisable — es el punto donde una unidad mal
 * convertida se transforma en un CFDI equivocado, y quiero poder razonar sobre
 * ella sin montar medio Dolibarr.
 *
 * Las conversiones que importan, y por qué:
 *
 *  - **IVA/IEPS**: Dolibarr guarda porcentaje (16), Facty espera tasa (0.16).
 *  - **Descuento**: Dolibarr guarda `remise_percent` (10 = 10%), Facty espera
 *    0.10.
 *  - **Retenciones**: en Dolibarr no existen como concepto propio; se capturan
 *    como extrafields por línea.
 *
 * Facty valida el IVA contra un conjunto cerrado (0, 8%, 16%). Una tasa fuera de
 * ese conjunto se detecta aquí, con nombre y número de línea, en vez de dejar
 * que el PAC devuelva un error genérico sobre importes.
 */
class FactyPayload
{
    /** Tasas de IVA que el SAT admite hoy. Facty rechaza cualquier otra. */
    private const IVA_VALIDO = array(0.0, 0.08, 0.16);

    /** @var string[] Problemas encontrados al mapear, en lenguaje del usuario. */
    public array $problems = array();

    /**
     * @param Facture     $facture   Factura validada de Dolibarr.
     * @param string      $clientId  Id del cliente en Facty (FactyClientSync).
     * @param array       $opts      usoCfdi, formaPago, metodoPago, serie,
     *                               cfdiRelacionados, productMap, exportacion.
     * @return array Cuerpo listo para POST /invoices.
     */
    public function fromFacture(Facture $facture, string $clientId, array $opts = array()): array
    {
        $this->problems = array();

        $isEgreso = ((int) $facture->type === Facture::TYPE_CREDIT_NOTE);

        $body = array(
            'type'           => $isEgreso ? 'egreso' : 'ingreso',
            'clientId'       => $clientId,
            'usoCfdi'        => (string) ($opts['usoCfdi'] ?? ''),
            'formaPago'      => (string) ($opts['formaPago'] ?? ''),
            'metodoPago'     => (string) ($opts['metodoPago'] ?? 'PUE'),
            'items'          => $this->mapLines($facture, $opts['productMap'] ?? array()),
            'idempotencyKey' => (string) ($opts['idempotencyKey'] ?? ''),
        );

        if (!empty($opts['serie'])) {
            $body['serie'] = (string) $opts['serie'];
        }

        // Método PPD: el SAT obliga a forma de pago 99 ("por definir"), porque
        // al emitir todavía no se sabe cómo se va a pagar. Se corrige en vez de
        // dejar que el PAC lo rechace — no hay otra opción válida.
        if ($body['metodoPago'] === 'PPD') {
            $body['formaPago'] = '99';
        }

        // Multidivisa: Dolibarr guarda el código y el tipo de cambio en la
        // factura. Sólo se mandan si la factura NO está en pesos; mandar
        // tipoCambio con moneda MXN es un error de validación en el SAT.
        $moneda = strtoupper((string) ($facture->multicurrency_code ?: 'MXN'));
        if ($moneda !== '' && $moneda !== 'MXN') {
            $body['moneda'] = $moneda;
            $tc = (float) $facture->multicurrency_tx;
            if ($tc > 0) {
                // Dolibarr guarda "cuántas divisas por peso"; el SAT pide lo
                // contrario: cuántos pesos vale una unidad de la divisa.
                $body['tipoCambio'] = (string) round(1 / $tc, 6);
            } else {
                $this->problems[] = 'La factura está en ' . $moneda . ' pero no tiene tipo de cambio.';
            }
        }

        if (!empty($opts['exportacion'])) {
            $body['exportacion'] = (string) $opts['exportacion'];
        }

        // Nota de crédito: el SAT exige relacionarla con el comprobante que
        // corrige. Sin esto el CFDI queda huérfano y no sirve para el propósito
        // por el que se emitió.
        if ($isEgreso) {
            $rel = $opts['cfdiRelacionados'] ?? null;
            if (!$rel || empty($rel['uuids'])) {
                $this->problems[] = 'Una nota de crédito debe relacionarse con el CFDI que corrige '
                    . '(folio fiscal de la factura original).';
            } else {
                $body['cfdiRelacionados'] = array(array(
                    'tipoRelacion' => (string) ($rel['tipoRelacion'] ?? '01'),
                    'uuids'        => array_values($rel['uuids']),
                ));
            }
        } elseif (!empty($opts['cfdiRelacionados']['uuids'])) {
            $body['cfdiRelacionados'] = array(array(
                'tipoRelacion' => (string) ($opts['cfdiRelacionados']['tipoRelacion'] ?? '01'),
                'uuids'        => array_values($opts['cfdiRelacionados']['uuids']),
            ));
        }

        if (!empty($opts['informacionGlobal'])) {
            $body['informacionGlobal'] = $opts['informacionGlobal'];
        }

        if ($body['usoCfdi'] === '') {
            $this->problems[] = 'Falta el uso del CFDI.';
        }
        if ($body['formaPago'] === '') {
            $this->problems[] = 'Falta la forma de pago. Revisa el mapeo en la configuración del módulo.';
        }
        if (!$body['items']) {
            $this->problems[] = 'La factura no tiene conceptos que se puedan timbrar.';
        }

        return $body;
    }

    /**
     * Convierte las líneas. Cada línea puede venir de un producto del catálogo
     * o ser texto libre; en ambos casos el SAT exige clave y unidad, así que
     * las líneas libres las toman de sus propios extrafields.
     *
     * @param array<int,string> $productMap fk_product => id en Facty
     */
    private function mapLines(Facture $facture, array $productMap): array
    {
        $items = array();

        foreach ($facture->lines as $i => $line) {
            $n = $i + 1;

            // Las líneas de sólo texto (descripciones, subtotales visuales) no
            // son conceptos: se omiten en lugar de mandar un concepto de importe
            // cero que el SAT no espera.
            if ((int) $line->product_type === 9 || ((float) $line->qty == 0 && (float) $line->subprice == 0)) {
                continue;
            }

            $item = array(
                'quantity' => (float) $line->qty,
            );

            $fkProduct = (int) $line->fk_product;
            $clave     = $this->lineExtra($line, 'factymx_claveprodserv');
            $unidad    = $this->lineExtra($line, 'factymx_claveunidad');

            if ($fkProduct > 0 && isset($productMap[$fkProduct]) && $clave === '' && $unidad === '') {
                // Producto sincronizado y sin excepción por línea: Facty ya
                // tiene sus claves, así que basta con referirlo.
                $item['productId'] = $productMap[$fkProduct];
            } else {
                // Línea libre, o línea que sobreescribe las claves del producto.
                if ($clave === '' || $unidad === '') {
                    $this->problems[] = 'Línea ' . $n . ' ("' . dol_trunc((string) $line->desc, 40) . '"): '
                        . 'falta la clave de producto/servicio o la clave de unidad del SAT.';
                    continue;
                }
                $item['claveProdServ'] = $clave;
                $item['claveUnidad']   = $unidad;
                $item['description']   = (string) ($line->desc !== '' ? $line->desc : $line->product_label);
                $item['unitPrice']     = (float) $line->subprice;
            }

            // IVA. Dolibarr: 16 → Facty: 0.16.
            $tva = round(((float) $line->tva_tx) / 100, 4);
            if (!$this->isTasaValida($tva)) {
                $this->problems[] = 'Línea ' . $n . ': la tasa de IVA ' . ((float) $line->tva_tx)
                    . '% no es una tasa que el SAT admita (0%, 8% o 16%).';
                continue;
            }
            $item['iva'] = $tva;

            // Descuento: Dolibarr guarda 10 para "10%", Facty espera 0.10.
            $remise = (float) $line->remise_percent;
            if ($remise > 0) {
                $item['descuento'] = round($remise / 100, 4);
            }

            // Retenciones e IEPS: no existen como concepto propio en Dolibarr,
            // se capturan por línea como extrafields.
            foreach (array('ieps' => 'factymx_ieps', 'retIsr' => 'factymx_retisr', 'retIva' => 'factymx_retiva') as $key => $ef) {
                $val = $this->lineExtra($line, $ef);
                if ($val !== '') {
                    $item[$key] = round(((float) $val) / 100, 4);
                }
            }

            $objeto = $this->lineExtra($line, 'factymx_objetoimp');
            if ($objeto !== '') {
                $item['objetoImp'] = $objeto;
            }

            $items[] = $item;
        }

        return $items;
    }

    /** Comparación con tolerancia: 0.16 no siempre es exactamente 0.16 en coma flotante. */
    private function isTasaValida(float $tasa): bool
    {
        foreach (self::IVA_VALIDO as $ok) {
            if (abs($ok - $tasa) < 1e-9) {
                return true;
            }
        }

        return false;
    }

    private function lineExtra($line, string $key): string
    {
        if (isset($line->array_options['options_' . $key])) {
            return trim((string) $line->array_options['options_' . $key]);
        }

        return '';
    }
}
