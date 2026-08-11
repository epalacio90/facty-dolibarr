<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';
require_once __DIR__ . '/FactyCfdi.class.php';

/**
 * \file    class/FactyArtifacts.class.php
 * \ingroup factymx
 * \brief   Trae el XML, el PDF y el acuse desde Facty al disco de Dolibarr.
 *
 * **El PDF lo genera Facty; aquí sólo se descarga.** El módulo anterior traía
 * su propio generador de PDF de casi diez mil líneas: sello, cadena original,
 * código QR, importe con letra, plantillas. Reproducir eso significa mantener
 * dos representaciones del mismo comprobante que se van separando con cada
 * cambio del SAT, y una de las dos siempre va atrasada. El comprobante bueno es
 * el que emitió el PAC.
 *
 * Los archivos se guardan en el directorio de documentos de la factura, así que
 * aparecen en la pestaña "Documentos" estándar y los recoge cualquier cosa que
 * ya use el almacenamiento de Dolibarr: envíos por correo, respaldos, GED.
 */
class FactyArtifacts
{
    /** @var DoliDB */
    private $db;
    private string $env;

    public function __construct($db, ?string $env = null)
    {
        $this->db  = $db;
        $this->env = $env ?? FactyConfig::env();
    }

    /**
     * Descarga XML y PDF de una factura timbrada y actualiza el registro local.
     *
     * @param  bool $force Vuelve a descargar aunque ya estén en disco.
     * @return array<string,string> tipo => ruta relativa guardada
     * @throws FactyApiException|FactyTransportException|RuntimeException
     */
    public function fetchForInvoice(Facture $facture, FactyCfdi $cfdi, bool $force = false): array
    {
        if ($cfdi->facty_invoice_id === null || $cfdi->facty_invoice_id === '') {
            throw new RuntimeException('El CFDI no tiene identificador en Facty; no hay de dónde descargar.');
        }

        $dir = $this->invoiceDir($facture);
        $out = array();

        $base = 'CFDI_' . dol_sanitizeFileName((string) ($cfdi->uuid ?: $facture->ref));

        foreach (array('xml' => 'xml', 'pdf' => 'pdf') as $kind => $ext) {
            $rel = $this->relPath($facture, $base . '.' . $ext);
            $abs = $dir . '/' . $base . '.' . $ext;

            if (!$force && file_exists($abs)) {
                $out[$kind] = $rel;
                continue;
            }

            $client = new FactyClient($this->env);
            $res    = $client->download(
                $client->orgPath('invoices/' . rawurlencode($cfdi->facty_invoice_id) . '/' . $kind)
            );

            $this->write($abs, $res['body']);
            $out[$kind] = $rel;
        }

        $cfdi->xml_path = $out['xml'] ?? $cfdi->xml_path;
        $cfdi->pdf_path = $out['pdf'] ?? $cfdi->pdf_path;
        $cfdi->update();

        return $out;
    }

    /**
     * Descarga el acuse de cancelación.
     *
     * Se guarda en disco a propósito: es el comprobante de que la cancelación
     * ocurrió y con qué fecha. Ante una aclaración con el SAT o con el cliente,
     * ese archivo es la prueba, y depender de poder volver a pedirlo a un
     * servicio externo años después es una apuesta que no hace falta hacer.
     */
    public function fetchAcuse(Facture $facture, FactyCfdi $cfdi, bool $force = false): ?string
    {
        if ($cfdi->facty_invoice_id === null || $cfdi->facty_invoice_id === '') {
            return null;
        }

        $dir  = $this->invoiceDir($facture);
        $name = 'ACUSE_' . dol_sanitizeFileName((string) ($cfdi->uuid ?: $facture->ref)) . '.xml';
        $abs  = $dir . '/' . $name;
        $rel  = $this->relPath($facture, $name);

        if (!$force && file_exists($abs)) {
            return $rel;
        }

        try {
            $client = new FactyClient($this->env);
            $res    = $client->download(
                $client->orgPath('invoices/' . rawurlencode($cfdi->facty_invoice_id) . '/acuse')
            );
            $this->write($abs, $res['body']);
        } catch (FactyApiException $e) {
            // El acuse puede no existir todavía (cancelación en proceso ante el
            // SAT). No es un error del usuario ni algo que deba tumbar la
            // pantalla: se reintenta después.
            dol_syslog('FactyArtifacts: acuse no disponible aún: ' . $e->getMessage(), LOG_NOTICE);

            return null;
        }

        $cfdi->acuse_path = $rel;
        $cfdi->update();

        return $rel;
    }

    /** Directorio de documentos de la factura, creándolo si hace falta. */
    private function invoiceDir(Facture $facture): string
    {
        global $conf;

        $dir = $conf->facture->multidir_output[$facture->entity ?? $conf->entity]
            ?? $conf->facture->dir_output;
        $dir .= '/' . dol_sanitizeFileName($facture->ref);

        if (!dol_is_dir($dir)) {
            dol_mkdir($dir);
        }

        return $dir;
    }

    private function relPath(Facture $facture, string $filename): string
    {
        return dol_sanitizeFileName($facture->ref) . '/' . $filename;
    }

    /**
     * Escritura atómica: se escribe a un temporal y se renombra.
     *
     * Un PDF a medias por una descarga cortada es peor que no tenerlo — parece
     * un archivo válido en el listado y sólo falla cuando alguien intenta
     * abrirlo, probablemente al mandárselo a un cliente.
     */
    private function write(string $absPath, string $content): void
    {
        $tmp = $absPath . '.part';

        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('No se pudo escribir el archivo en ' . dirname($absPath) . '. Revisa permisos.');
        }
        if (!rename($tmp, $absPath)) {
            @unlink($tmp);

            throw new RuntimeException('No se pudo guardar el archivo descargado.');
        }

        dolChmod($absPath);
    }
}
