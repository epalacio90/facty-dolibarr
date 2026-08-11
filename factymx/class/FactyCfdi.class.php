<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';

/**
 * \file    class/FactyCfdi.class.php
 * \ingroup factymx
 * \brief   El registro del timbrado: llx_factymx_cfdi.
 *
 * Esta fila es el mecanismo central del módulo. Al timbrar guarda a la vez el id
 * de Facty y el UUID del SAT, así que cancelar, relacionar o complementar el
 * CFDI después es una consulta local. Sin ella habría que pedirle a Facty que
 * tradujera un UUID a su id interno en cada operación.
 *
 * Todas las consultas van acotadas por (entity, env). Un id de Facty de pruebas
 * no significa nada en producción — son bases de datos distintas — así que
 * omitir el ambiente devolvería filas que apuntan al lugar equivocado.
 */
class FactyCfdi
{
    const STATUS_PENDING   = 'pending';
    const STATUS_STAMPED   = 'stamped';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /** @var DoliDB */
    private $db;

    public int $id = 0;
    public int $fk_facture = 0;
    public int $entity = 1;
    public string $env = 'test';
    public ?string $facty_invoice_id = null;
    public ?string $uuid = null;
    public string $cfdi_type = 'ingreso';
    public ?string $serie = null;
    public ?int $folio = null;
    public string $status = self::STATUS_PENDING;
    public ?float $total = null;
    public ?string $moneda = null;
    public ?string $stamped_at = null;
    public ?string $cancelled_at = null;
    public ?string $cancel_motivo = null;
    public ?string $xml_path = null;
    public ?string $pdf_path = null;
    public ?string $acuse_path = null;
    public string $idempotency_key = '';
    public ?string $last_error = null;
    public ?string $facty_request_id = null;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Carga el registro de una factura en el ambiente indicado (por omisión, el
     * activo). Devuelve null si esa factura no se ha timbrado ahí.
     */
    public static function fetchByFacture($db, int $factureId, ?string $env = null): ?self
    {
        global $conf;

        $env = $env ?? FactyConfig::env();

        $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . "factymx_cfdi
                WHERE fk_facture = " . ((int) $factureId) . "
                  AND entity = " . ((int) $conf->entity) . "
                  AND env = '" . $db->escape($env) . "'";

        $res = $db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $db->fetch_object($res);
        $db->free($res);
        if (!$row) {
            return null;
        }

        $o = new self($db);
        $o->hydrate($row);

        return $o;
    }

    /** Busca por UUID del SAT — la ruta de reconciliación cuando se perdió el mapeo. */
    public static function fetchByUuid($db, string $uuid, ?string $env = null): ?self
    {
        global $conf;

        $env = $env ?? FactyConfig::env();

        $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . "factymx_cfdi
                WHERE uuid = '" . $db->escape($uuid) . "'
                  AND entity = " . ((int) $conf->entity) . "
                  AND env = '" . $db->escape($env) . "'";

        $res = $db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $db->fetch_object($res);
        $db->free($res);
        if (!$row) {
            return null;
        }

        $o = new self($db);
        $o->hydrate($row);

        return $o;
    }

    /**
     * Reserva la fila ANTES de llamar a Facty.
     *
     * El orden importa: se escribe `pending` primero para que un segundo intento
     * (doble clic, otra pestaña, el cron) se encuentre con la fila y se detenga
     * en lugar de disparar un segundo timbrado. La llave de idempotencia protege
     * del lado de Facty; esto protege del lado de Dolibarr, y las dos juntas son
     * lo que evita gastar dos timbres por la misma factura.
     *
     * @return int >0 rowid; -1 error; 0 ya existía una fila en curso o timbrada
     */
    public function reserve(): int
    {
        global $conf, $user;

        $this->entity = (int) $conf->entity;
        if ($this->env === '') {
            $this->env = FactyConfig::env();
        }

        $existing = self::fetchByFacture($this->db, $this->fk_facture, $this->env);
        if ($existing !== null) {
            if ($existing->status === self::STATUS_FAILED) {
                // Un intento fallido sí se puede reutilizar: no hay CFDI.
                $this->id = $existing->id;
                $existing->status = self::STATUS_PENDING;
                $existing->last_error = null;

                return $existing->update() > 0 ? $existing->id : -1;
            }

            return 0;
        }

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'factymx_cfdi
                (fk_facture, entity, env, cfdi_type, status, idempotency_key, fk_user_creat, datec)
                VALUES ('
                . ((int) $this->fk_facture) . ', '
                . ((int) $this->entity) . ", '"
                . $this->db->escape($this->env) . "', '"
                . $this->db->escape($this->cfdi_type) . "', '"
                . $this->db->escape(self::STATUS_PENDING) . "', '"
                . $this->db->escape($this->idempotency_key) . "', "
                . ((int) ($user->id ?? 0)) . ", '"
                . $this->db->idate(dol_now()) . "')";

        if (!$this->db->query($sql)) {
            return -1;
        }

        $this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'factymx_cfdi');
        $this->status = self::STATUS_PENDING;

        return $this->id;
    }

    /** Persiste el resultado de un timbrado exitoso. */
    public function markStamped(array $factyResponse): int
    {
        $this->facty_invoice_id = isset($factyResponse['id']) ? (string) $factyResponse['id'] : null;
        $this->uuid             = isset($factyResponse['uuid']) ? (string) $factyResponse['uuid'] : null;
        $this->serie            = isset($factyResponse['serie']) ? (string) $factyResponse['serie'] : null;
        $this->folio            = isset($factyResponse['folio']) ? (int) $factyResponse['folio'] : null;
        $this->total            = isset($factyResponse['total']) ? (float) $factyResponse['total'] : null;
        $this->moneda           = isset($factyResponse['moneda']) ? (string) $factyResponse['moneda'] : null;
        $this->status           = self::STATUS_STAMPED;
        $this->stamped_at       = isset($factyResponse['stampedAt'])
            ? dol_print_date(dol_stringtotime((string) $factyResponse['stampedAt']), '%Y-%m-%d %H:%M:%S')
            : dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
        $this->last_error       = null;

        return $this->update();
    }

    public function markFailed(string $error, ?string $requestId = null): int
    {
        $this->status           = self::STATUS_FAILED;
        $this->last_error       = $error;
        $this->facty_request_id = $requestId;

        return $this->update();
    }

    public function update(): int
    {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'factymx_cfdi SET '
            . "facty_invoice_id = " . $this->q($this->facty_invoice_id) . ', '
            . "uuid = " . $this->q($this->uuid) . ', '
            . "serie = " . $this->q($this->serie) . ', '
            . 'folio = ' . ($this->folio === null ? 'NULL' : (int) $this->folio) . ', '
            . "status = '" . $this->db->escape($this->status) . "', "
            . 'total = ' . ($this->total === null ? 'NULL' : (float) $this->total) . ', '
            . "moneda = " . $this->q($this->moneda) . ', '
            . "stamped_at = " . $this->q($this->stamped_at) . ', '
            . "cancelled_at = " . $this->q($this->cancelled_at) . ', '
            . "cancel_motivo = " . $this->q($this->cancel_motivo) . ', '
            . "xml_path = " . $this->q($this->xml_path) . ', '
            . "pdf_path = " . $this->q($this->pdf_path) . ', '
            . "acuse_path = " . $this->q($this->acuse_path) . ', '
            . "last_error = " . $this->q($this->last_error) . ', '
            . "facty_request_id = " . $this->q($this->facty_request_id)
            . ' WHERE rowid = ' . ((int) $this->id);

        return $this->db->query($sql) ? 1 : -1;
    }

    /** Escapa o devuelve NULL. Nada se concatena crudo. */
    private function q(?string $v): string
    {
        return $v === null || $v === '' ? 'NULL' : "'" . $this->db->escape($v) . "'";
    }

    private function hydrate($row): void
    {
        $this->id               = (int) $row->rowid;
        $this->fk_facture       = (int) $row->fk_facture;
        $this->entity           = (int) $row->entity;
        $this->env              = (string) $row->env;
        $this->facty_invoice_id = $row->facty_invoice_id;
        $this->uuid             = $row->uuid;
        $this->cfdi_type        = (string) $row->cfdi_type;
        $this->serie            = $row->serie;
        $this->folio            = $row->folio === null ? null : (int) $row->folio;
        $this->status           = (string) $row->status;
        $this->total            = $row->total === null ? null : (float) $row->total;
        $this->moneda           = $row->moneda;
        $this->stamped_at       = $row->stamped_at;
        $this->cancelled_at     = $row->cancelled_at;
        $this->cancel_motivo    = $row->cancel_motivo;
        $this->xml_path         = $row->xml_path;
        $this->pdf_path         = $row->pdf_path;
        $this->acuse_path       = $row->acuse_path;
        $this->idempotency_key  = (string) $row->idempotency_key;
        $this->last_error       = $row->last_error;
        $this->facty_request_id = $row->facty_request_id;
    }
}
