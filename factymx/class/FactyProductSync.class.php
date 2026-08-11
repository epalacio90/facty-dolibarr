<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';

/**
 * \file    class/FactyProductSync.class.php
 * \ingroup factymx
 * \brief   Producto de Dolibarr → producto de Facty.
 *
 * Igual que con los terceros: el mapeo vive en `llx_factymx_product_map` POR
 * AMBIENTE, el alta es idempotente y actualizar es explícito.
 *
 * La llave de correspondencia es el **código** del producto: se manda la
 * referencia de Dolibarr (`ref`) como `code`, y Facty tiene un índice único por
 * organización sobre ese campo. Así, repetir una sincronización interrumpida
 * reconcilia en vez de duplicar el catálogo — que es exactamente lo que pasaría
 * si la correspondencia fuera por descripción.
 *
 * Sincronizar un producto NO es obligatorio para timbrar: Facty acepta
 * conceptos con clave, unidad y descripción en línea. El mapeo sirve para
 * mantener un catálogo compartido y para poder referirse al producto por id.
 */
class FactyProductSync
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
     * @throws FactyApiException|FactyTransportException|FactyConfigException
     * @throws InvalidArgumentException si faltan las claves del SAT
     */
    public function ensure(Product $product): string
    {
        $payload = $this->buildPayload($product);
        $hash    = $this->hashOf($payload);
        $mapped  = $this->readMap((int) $product->id);

        if ($mapped !== null && $mapped['hash'] === $hash) {
            return $mapped['facty_product_id'];
        }

        $client = new FactyClient($this->env);

        if ($mapped !== null) {
            $client->request(
                'PATCH',
                $client->orgPath('products/' . rawurlencode($mapped['facty_product_id'])),
                $payload
            );
            $this->writeMap((int) $product->id, $mapped['facty_product_id'], (string) $payload['code'], $hash);

            return $mapped['facty_product_id'];
        }

        $payload['upsert'] = true;
        $body = $client->request('POST', $client->orgPath('products'), $payload);

        $id = isset($body['id']) ? (string) $body['id'] : '';
        if ($id === '') {
            throw new RuntimeException('Facty no devolvió el identificador del producto.');
        }

        $this->writeMap((int) $product->id, $id, (string) $payload['code'], $hash);

        return $id;
    }

    public function buildPayload(Product $product): array
    {
        $clave  = trim((string) $this->extrafield($product, 'factymx_claveprodserv'));
        $unidad = trim((string) $this->extrafield($product, 'factymx_claveunidad'));

        if ($clave === '' || $unidad === '') {
            throw new InvalidArgumentException(
                'Al producto "' . $product->ref . '" le faltan la clave de producto/servicio o la clave de unidad del SAT. '
                . 'Captúralas en la pestaña "Datos SAT" del producto.'
            );
        }

        // `ref` es la referencia de Dolibarr: única por instalación y estable,
        // que es justo lo que se necesita como llave de correspondencia.
        $code = trim((string) $product->ref);
        if ($code === '') {
            throw new InvalidArgumentException('El producto no tiene referencia; no hay con qué identificarlo en Facty.');
        }

        $payload = array(
            'code'          => $code,
            'claveProdServ' => $clave,
            'claveUnidad'   => $unidad,
            'description'   => (string) ($product->label !== '' ? $product->label : $product->ref),
            'unitPrice'     => (string) $product->price,
        );

        $noIdent = trim((string) $this->extrafield($product, 'factymx_noidentificacion'));
        if ($noIdent !== '') {
            $payload['noIdentificacion'] = $noIdent;
        }

        // El IVA de Dolibarr viene en porcentaje (16), Facty lo espera como
        // tasa decimal (0.16). Mandarlo sin convertir produciría un CFDI con
        // 1600% de impuesto, que el PAC rechaza — pero el error diría algo
        // sobre el importe, no sobre las unidades.
        if ($product->tva_tx !== '' && $product->tva_tx !== null) {
            $payload['iva'] = (string) round(((float) $product->tva_tx) / 100, 4);
        }

        return $payload;
    }

    private function hashOf(array $payload): string
    {
        unset($payload['upsert']);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function extrafield(Product $product, string $key)
    {
        return isset($product->array_options['options_' . $key]) ? $product->array_options['options_' . $key] : '';
    }

    /** @return array{facty_product_id:string,hash:string}|null */
    private function readMap(int $productId): ?array
    {
        global $conf;

        $sql = 'SELECT facty_product_id, hash FROM ' . MAIN_DB_PREFIX . "factymx_product_map
                WHERE fk_product = " . $productId . " AND entity = " . ((int) $conf->entity) . "
                  AND env = '" . $this->db->escape($this->env) . "'";

        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $this->db->fetch_object($res);
        $this->db->free($res);

        if (!$row) {
            return null;
        }

        return array('facty_product_id' => (string) $row->facty_product_id, 'hash' => (string) $row->hash);
    }

    private function writeMap(int $productId, string $factyId, string $code, string $hash): void
    {
        global $conf;

        $entity = (int) $conf->entity;
        $env    = $this->db->escape($this->env);
        $now    = $this->db->idate(dol_now());

        $sql = 'DELETE FROM ' . MAIN_DB_PREFIX . "factymx_product_map
                WHERE fk_product = " . $productId . " AND entity = " . $entity . " AND env = '" . $env . "'";
        $this->db->query($sql);

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'factymx_product_map
                (fk_product, entity, env, facty_product_id, code, hash, synced_at) VALUES ('
                . $productId . ', ' . $entity . ", '" . $env . "', '"
                . $this->db->escape($factyId) . "', '"
                . $this->db->escape($code) . "', '"
                . $this->db->escape($hash) . "', '" . $now . "')";

        $this->db->query($sql);
    }
}
