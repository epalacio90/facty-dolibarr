<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';

/**
 * \file    class/FactyClientSync.class.php
 * \ingroup factymx
 * \brief   Tercero de Dolibarr → cliente de Facty.
 *
 * Antes de timbrar, el receptor tiene que existir en Facty. Esta clase lo
 * asegura y guarda el mapeo en `llx_factymx_client_map`, POR AMBIENTE: el id de
 * un cliente en pruebas no existe en producción.
 *
 * Dos reglas que evitan sorpresas:
 *
 *  - **El alta usa `upsert` por RFC.** Si el cliente ya está en Facty, se adopta
 *    el existente en lugar de fallar. Una sincronización interrumpida a la mitad
 *    tiene que poder repetirse y converger, no atorarse en "ya existe".
 *  - **Actualizar es explícito.** Si el tercero cambió en Dolibarr se manda un
 *    PATCH; si no cambió, no se toca. Nunca se sobrescribe a ciegas: alguien
 *    pudo haber corregido el régimen fiscal dentro de Facty, y una
 *    resincronización no debe deshacer esa corrección sin querer.
 */
class FactyClientSync
{
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
     * Devuelve el id del cliente en Facty para un tercero, creándolo o
     * actualizándolo si hace falta.
     *
     * @throws FactyApiException|FactyTransportException|FactyConfigException
     * @throws InvalidArgumentException si al tercero le faltan datos fiscales
     */
    public function ensure(Societe $soc): string
    {
        $payload = $this->buildPayload($soc);
        $hash    = $this->hashOf($payload);
        $mapped  = $this->readMap((int) $soc->id);

        if ($mapped !== null && $mapped['hash'] === $hash) {
            return $mapped['facty_client_id']; // Nada cambió.
        }

        $client = new FactyClient($this->env);

        if ($mapped !== null) {
            // Cambió en Dolibarr: se empuja el cambio.
            $client->request('PATCH', $client->orgPath('clients/' . rawurlencode($mapped['facty_client_id'])), $payload);
            $this->writeMap((int) $soc->id, $mapped['facty_client_id'], $payload['rfc'], $hash);

            return $mapped['facty_client_id'];
        }

        // Alta idempotente: si el RFC ya existe en Facty, devuelve el existente
        // con created=false en vez de 409.
        $payload['upsert'] = true;
        $body = $client->request('POST', $client->orgPath('clients'), $payload);

        $id = isset($body['id']) ? (string) $body['id'] : '';
        if ($id === '') {
            throw new RuntimeException('Facty no devolvió el identificador del cliente.');
        }

        $this->writeMap((int) $soc->id, $id, $payload['rfc'], $hash);

        return $id;
    }

    /**
     * Arma el cuerpo para Facty y valida lo que el SAT exige del receptor.
     *
     * Se valida aquí, con el tercero a la vista, y no al momento de timbrar:
     * enterarse de que falta el régimen fiscal cuando la factura ya está
     * validada obliga a abandonar la operación a medias.
     */
    public function buildPayload(Societe $soc): array
    {
        $rfc = strtoupper(trim((string) ($soc->idprof1 ?: $soc->tva_intra)));
        if ($rfc === '') {
            throw new InvalidArgumentException(
                'El tercero no tiene RFC. Captúralo en la ficha del tercero (campo de identificación profesional 1).'
            );
        }

        $regimen = trim((string) $this->extrafield($soc, 'factymx_regimenfiscal'));
        $cp      = trim((string) ($soc->zip ?: $this->extrafield($soc, 'factymx_cp')));

        // El RFC genérico nacional obliga a un nombre exacto; el SAT rechaza
        // cualquier otro. Se corrige en silencio porque no hay alternativa
        // válida — no es una preferencia, es la única forma aceptada.
        $nombre = trim((string) $soc->name);
        if ($rfc === 'XAXX010101000') {
            $nombre = 'PUBLICO EN GENERAL';
        }

        if ($cp === '') {
            throw new InvalidArgumentException(
                'El tercero no tiene código postal. El CFDI 4.0 exige el domicilio fiscal del receptor.'
            );
        }
        if ($regimen === '' && $rfc !== 'XAXX010101000') {
            throw new InvalidArgumentException(
                'Falta el régimen fiscal del receptor. Captúralo en la pestaña "Datos fiscales CFDI" del tercero.'
            );
        }

        $payload = array(
            'rfc'       => $rfc,
            'legalName' => $nombre,
            'cp'        => $cp,
        );

        if ($regimen !== '') {
            $payload['regimenFiscal'] = $regimen;
        }
        if (!empty($soc->email)) {
            $payload['email'] = (string) $soc->email;
        }

        $uso = trim((string) $this->extrafield($soc, 'factymx_usocfdi'));
        if ($uso !== '') {
            $payload['usoCfdiDefault'] = $uso;
        }

        return $payload;
    }

    /** Huella de lo que se mandó, para detectar cambios sin comparar campo por campo. */
    private function hashOf(array $payload): string
    {
        unset($payload['upsert']);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function extrafield(Societe $soc, string $key)
    {
        return isset($soc->array_options['options_' . $key]) ? $soc->array_options['options_' . $key] : '';
    }

    /** @return array{facty_client_id:string,hash:string}|null */
    private function readMap(int $socId): ?array
    {
        global $conf;

        $sql = 'SELECT facty_client_id, hash FROM ' . MAIN_DB_PREFIX . "factymx_client_map
                WHERE fk_soc = " . $socId . " AND entity = " . ((int) $conf->entity) . "
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

        return array('facty_client_id' => (string) $row->facty_client_id, 'hash' => (string) $row->hash);
    }

    private function writeMap(int $socId, string $factyId, string $rfc, string $hash): void
    {
        global $conf;

        $entity = (int) $conf->entity;
        $env    = $this->db->escape($this->env);
        $now    = $this->db->idate(dol_now());

        $sql = 'DELETE FROM ' . MAIN_DB_PREFIX . "factymx_client_map
                WHERE fk_soc = " . $socId . " AND entity = " . $entity . " AND env = '" . $env . "'";
        $this->db->query($sql);

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'factymx_client_map
                (fk_soc, entity, env, facty_client_id, rfc, hash, synced_at) VALUES ('
                . $socId . ', ' . $entity . ", '" . $env . "', '"
                . $this->db->escape($factyId) . "', '"
                . $this->db->escape($rfc) . "', '"
                . $this->db->escape($hash) . "', '" . $now . "')";

        $this->db->query($sql);
    }
}
