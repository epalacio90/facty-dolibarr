<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyClient.class.php';

/**
 * \file    class/FactyCatalog.class.php
 * \ingroup factymx
 * \brief   Catálogos del SAT leídos de Facty, con caché local.
 *
 * El módulo no trae los catálogos empaquetados. Se leen de Facty y se guardan en
 * `llx_factymx_catalog_cache` con un TTL, de modo que una actualización del SAT
 * llega sin publicar una versión nueva del módulo y la instalación no arrastra
 * decenas de megas de datos que envejecen solos.
 *
 * Dos tamaños, dos tratamientos:
 *
 *  - **Pequeños** (uso de CFDI, forma de pago, régimen…): se traen completos y
 *    se guardan. Un `<select>` normal.
 *  - **Grandes** (clave de producto/servicio ~50 mil filas, clave de unidad
 *    ~2 mil): NO se cachean completos. Se consultan por búsqueda contra Facty,
 *    que ya limita la respuesta. Bajar 50 mil filas a cada instalación para
 *    llenar un desplegable que nadie va a recorrer es justamente el patrón que
 *    este diseño evita.
 *
 * Degradación: si Facty no responde, se sirve la copia vieja y se avisa. Lo que
 * no se hace nunca es devolver una lista vacía sin decir nada — un desplegable
 * vacío parece "este catálogo no tiene valores" cuando en realidad significa
 * "no pude preguntar", y son dos problemas muy distintos para quien lo ve.
 */
class FactyCatalog
{
    /** Catálogos que se consultan por búsqueda, nunca completos. */
    public const SEARCH_ONLY = array('ClaveProdServ', 'ClaveUnidad');

    /** Estado de la última lectura, para que la UI pueda ser honesta. */
    public const STATE_FRESH       = 'fresh';
    public const STATE_STALE       = 'stale';
    public const STATE_UNAVAILABLE = 'unavailable';

    /** @var DoliDB */
    private $db;
    private string $env;

    public string $state = self::STATE_FRESH;
    public string $stateMessage = '';

    public function __construct($db, ?string $env = null)
    {
        $this->db  = $db;
        $this->env = $env ?? FactyConfig::env();
    }

    private function ttlSeconds(): int
    {
        $days = (int) getDolGlobalInt('FACTYMX_CATALOG_TTL', 7);

        return max(1, $days) * 86400;
    }

    /**
     * Devuelve un catálogo pequeño completo: array<string code, string label>.
     *
     * @param bool $forceRefresh Ignora el TTL (botón "actualizar catálogos").
     */
    public function all(string $catalog, bool $forceRefresh = false): array
    {
        if (in_array($catalog, self::SEARCH_ONLY, true)) {
            // Falla ruidosamente en vez de intentar traer 50 mil filas: es un
            // error de programación, no una condición de operación.
            throw new InvalidArgumentException(
                'El catálogo ' . $catalog . ' es demasiado grande para leerse completo; usa search().'
            );
        }

        $cached = $this->readCache($catalog);
        $age    = $this->cacheAge($catalog);

        if (!$forceRefresh && $cached && $age !== null && $age < $this->ttlSeconds()) {
            $this->state = self::STATE_FRESH;

            return $cached;
        }

        try {
            $items = $this->fetchFromFacty($catalog);
            $this->writeCache($catalog, $items);
            $this->state = self::STATE_FRESH;

            return $items;
        } catch (Exception $e) {
            if ($cached) {
                $this->state = self::STATE_STALE;
                $this->stateMessage = 'No se pudo actualizar el catálogo; se muestra la última copia guardada.';

                return $cached;
            }

            $this->state = self::STATE_UNAVAILABLE;
            $this->stateMessage = 'El catálogo no está disponible y no hay copia local. Reintenta más tarde.';

            return array();
        }
    }

    /**
     * Busca en un catálogo grande. La respuesta viene limitada por Facty.
     *
     * No se cachea el resultado: son consultas distintas cada vez y guardarlas
     * llenaría la tabla de fragmentos inconexos sin acelerar nada.
     */
    public function search(string $catalog, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return array();
        }

        try {
            $items = $this->fetchFromFacty($catalog, $query);
            $this->state = self::STATE_FRESH;

            return $items;
        } catch (Exception $e) {
            $this->state = self::STATE_UNAVAILABLE;
            $this->stateMessage = 'No se pudo consultar el catálogo en Facty.';

            return array();
        }
    }

    /**
     * ¿Existe esta clave en el catálogo? Se usa antes de timbrar.
     *
     * Si el catálogo no está disponible devuelve `true`: bloquear un timbrado
     * porque no pudimos validar contra una lista sería peor que dejar que Facty
     * —que tiene la lista buena— rechace la clave con un mensaje concreto.
     */
    public function isValidCode(string $catalog, string $code): bool
    {
        if ($code === '') {
            return false;
        }
        if (in_array($catalog, self::SEARCH_ONLY, true)) {
            $hits = $this->search($catalog, $code);
            if ($this->state === self::STATE_UNAVAILABLE) {
                return true;
            }

            return isset($hits[$code]);
        }

        $all = $this->all($catalog);
        if ($this->state === self::STATE_UNAVAILABLE) {
            return true;
        }

        return isset($all[$code]);
    }

    /** Renderiza un `<select>`; deja ver el estado del catálogo si no es fresco. */
    public function selectHtml(string $catalog, string $name, string $selected = '', bool $allowEmpty = true): string
    {
        $items = $this->all($catalog);

        if ($this->state === self::STATE_UNAVAILABLE) {
            // Campo de texto como plan B: sin catálogo, el usuario todavía puede
            // escribir la clave que ya conoce en lugar de quedarse atorado.
            return '<input type="text" name="' . dol_escape_htmltag($name) . '" value="'
                . dol_escape_htmltag($selected) . '" size="8">'
                . ' <span class="warning">' . dol_escape_htmltag($this->stateMessage) . '</span>';
        }

        $html = '<select name="' . dol_escape_htmltag($name) . '" class="flat">';
        if ($allowEmpty) {
            $html .= '<option value=""></option>';
        }
        foreach ($items as $code => $label) {
            $html .= '<option value="' . dol_escape_htmltag((string) $code) . '"'
                . ((string) $code === $selected ? ' selected' : '') . '>'
                . dol_escape_htmltag($code . ' — ' . $label) . '</option>';
        }
        $html .= '</select>';

        if ($this->state === self::STATE_STALE) {
            $html .= ' <span class="opacitymedium" title="' . dol_escape_htmltag($this->stateMessage) . '">(copia local)</span>';
        }

        return $html;
    }

    /** @return array<string,string> code => label */
    private function fetchFromFacty(string $catalog, string $query = ''): array
    {
        $client = new FactyClient($this->env);

        $path = '/authenticated/catalogs/sat?type=' . rawurlencode($catalog);
        if ($query !== '') {
            $path .= '&q=' . rawurlencode($query);
        }

        $body  = $client->request('GET', $path);
        $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();

        $out = array();
        foreach ($items as $it) {
            if (!isset($it['key'])) {
                continue;
            }
            $out[(string) $it['key']] = isset($it['name']) ? (string) $it['name'] : (string) $it['key'];
        }

        return $out;
    }

    /** @return array<string,string> */
    private function readCache(string $catalog): array
    {
        global $conf;

        $sql = 'SELECT code, label FROM ' . MAIN_DB_PREFIX . "factymx_catalog_cache
                WHERE entity = " . ((int) $conf->entity) . " AND env = '" . $this->db->escape($this->env) . "'
                  AND catalog = '" . $this->db->escape($catalog) . "'
                ORDER BY code";

        $res = $this->db->query($sql);
        if (!$res) {
            return array();
        }

        $out = array();
        while ($row = $this->db->fetch_object($res)) {
            $out[(string) $row->code] = (string) $row->label;
        }
        $this->db->free($res);

        return $out;
    }

    /** Antigüedad en segundos de la copia local, o null si no hay. */
    private function cacheAge(string $catalog): ?int
    {
        global $conf;

        $sql = 'SELECT MIN(fetched_at) AS oldest FROM ' . MAIN_DB_PREFIX . "factymx_catalog_cache
                WHERE entity = " . ((int) $conf->entity) . " AND env = '" . $this->db->escape($this->env) . "'
                  AND catalog = '" . $this->db->escape($catalog) . "'";

        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $this->db->fetch_object($res);
        $this->db->free($res);

        if (!$row || empty($row->oldest)) {
            return null;
        }

        return max(0, dol_now() - (int) $this->db->jdate($row->oldest));
    }

    /**
     * Reemplaza la copia local de un catálogo.
     *
     * Borrar e insertar dentro de una transacción, no actualizar fila por fila:
     * si el SAT retiró una clave, tiene que desaparecer del desplegable. Un
     * upsert la dejaría ahí para siempre.
     */
    private function writeCache(string $catalog, array $items): void
    {
        global $conf;

        if (!$items) {
            return;
        }

        $entity = (int) $conf->entity;
        $env    = $this->db->escape($this->env);
        $cat    = $this->db->escape($catalog);
        $now    = $this->db->idate(dol_now());

        $this->db->begin();

        $sql = 'DELETE FROM ' . MAIN_DB_PREFIX . "factymx_catalog_cache
                WHERE entity = " . $entity . " AND env = '" . $env . "' AND catalog = '" . $cat . "'";
        if (!$this->db->query($sql)) {
            $this->db->rollback();

            return;
        }

        foreach ($items as $code => $label) {
            $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'factymx_catalog_cache
                    (entity, env, catalog, code, label, fetched_at) VALUES ('
                    . $entity . ", '" . $env . "', '" . $cat . "', '"
                    . $this->db->escape((string) $code) . "', '"
                    . $this->db->escape((string) $label) . "', '" . $now . "')";
            if (!$this->db->query($sql)) {
                $this->db->rollback();

                return;
            }
        }

        $this->db->commit();
    }
}
