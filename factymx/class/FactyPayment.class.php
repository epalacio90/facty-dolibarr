<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';

/**
 * \file    class/FactyPayment.class.php
 * \ingroup factymx
 * \brief   Registro del complemento de pago: llx_factymx_payment.
 *
 * Guarda los DOS identificadores porque sirven para cosas distintas: el pago en
 * Facty (`facty_payment_id`) es contra lo que se cancela, y el CFDI de tipo P
 * (`facty_invoice_id`, con su `uuid`) es lo que el SAT conoce y lo que aparece
 * en el acuse. Quedarse sólo con uno obliga a resolver el otro por búsqueda
 * cada vez.
 */
class FactyPayment
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_STAMPED   = 'stamped';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var DoliDB */
    private $db;

    public int $id = 0;
    public int $fk_paiement = 0;
    public int $entity = 1;
    public string $env = 'test';
    public ?string $facty_payment_id = null;
    public ?string $facty_invoice_id = null;
    public ?string $uuid = null;
    public string $status = self::STATUS_PENDING;
    public ?float $monto = null;
    public ?string $moneda = null;
    public ?string $fecha_pago = null;
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

    public static function fetchByPaiement($db, int $paiementId, ?string $env = null): ?self
    {
        global $conf;

        $env = $env ?? FactyConfig::env();

        $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . "factymx_payment
                WHERE fk_paiement = " . $paiementId . " AND entity = " . ((int) $conf->entity) . "
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
     * Reserva la fila antes de llamar a Facty — misma razón que en el timbrado
     * de facturas: un segundo intento se topa con ella y se detiene.
     *
     * @return int >0 rowid; 0 ya existía en curso o timbrado; -1 error
     */
    public function reserve(): int
    {
        global $conf;

        $this->entity = (int) $conf->entity;
        if ($this->env === '') {
            $this->env = FactyConfig::env();
        }

        $existing = self::fetchByPaiement($this->db, $this->fk_paiement, $this->env);
        if ($existing !== null) {
            if ($existing->status === self::STATUS_FAILED) {
                $this->id = $existing->id;
                $existing->status = self::STATUS_PENDING;
                $existing->last_error = null;

                return $existing->update() > 0 ? $existing->id : -1;
            }

            return 0;
        }

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'factymx_payment
                (fk_paiement, entity, env, status, monto, moneda, fecha_pago, idempotency_key, datec)
                VALUES ('
                . ((int) $this->fk_paiement) . ', ' . ((int) $this->entity) . ", '"
                . $this->db->escape($this->env) . "', '"
                . $this->db->escape(self::STATUS_PENDING) . "', "
                . ($this->monto === null ? 'NULL' : (float) $this->monto) . ', '
                . ($this->moneda === null ? 'NULL' : "'" . $this->db->escape($this->moneda) . "'") . ', '
                . ($this->fecha_pago === null ? 'NULL' : "'" . $this->db->escape($this->fecha_pago) . "'") . ", '"
                . $this->db->escape($this->idempotency_key) . "', '"
                . $this->db->idate(dol_now()) . "')";

        if (!$this->db->query($sql)) {
            return -1;
        }

        $this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'factymx_payment');
        $this->status = self::STATUS_PENDING;

        return $this->id;
    }

    public function markStamped(array $response): int
    {
        $this->facty_payment_id = isset($response['paymentId']) ? (string) $response['paymentId'] : null;
        $this->facty_invoice_id = isset($response['paymentInvoiceId']) ? (string) $response['paymentInvoiceId'] : null;
        $this->uuid             = isset($response['uuid']) ? (string) $response['uuid'] : null;
        $this->status           = self::STATUS_STAMPED;
        $this->stamped_at       = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
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
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'factymx_payment SET '
            . 'facty_payment_id = ' . $this->q($this->facty_payment_id) . ', '
            . 'facty_invoice_id = ' . $this->q($this->facty_invoice_id) . ', '
            . 'uuid = ' . $this->q($this->uuid) . ', '
            . "status = '" . $this->db->escape($this->status) . "', "
            . 'monto = ' . ($this->monto === null ? 'NULL' : (float) $this->monto) . ', '
            . 'moneda = ' . $this->q($this->moneda) . ', '
            . 'fecha_pago = ' . $this->q($this->fecha_pago) . ', '
            . 'stamped_at = ' . $this->q($this->stamped_at) . ', '
            . 'cancelled_at = ' . $this->q($this->cancelled_at) . ', '
            . 'cancel_motivo = ' . $this->q($this->cancel_motivo) . ', '
            . 'xml_path = ' . $this->q($this->xml_path) . ', '
            . 'pdf_path = ' . $this->q($this->pdf_path) . ', '
            . 'acuse_path = ' . $this->q($this->acuse_path) . ', '
            . 'last_error = ' . $this->q($this->last_error) . ', '
            . 'facty_request_id = ' . $this->q($this->facty_request_id)
            . ' WHERE rowid = ' . ((int) $this->id);

        return $this->db->query($sql) ? 1 : -1;
    }

    private function q(?string $v): string
    {
        return $v === null || $v === '' ? 'NULL' : "'" . $this->db->escape($v) . "'";
    }

    private function hydrate($row): void
    {
        $this->id               = (int) $row->rowid;
        $this->fk_paiement      = (int) $row->fk_paiement;
        $this->entity           = (int) $row->entity;
        $this->env              = (string) $row->env;
        $this->facty_payment_id = $row->facty_payment_id;
        $this->facty_invoice_id = $row->facty_invoice_id;
        $this->uuid             = $row->uuid;
        $this->status           = (string) $row->status;
        $this->monto            = $row->monto === null ? null : (float) $row->monto;
        $this->moneda           = $row->moneda;
        $this->fecha_pago       = $row->fecha_pago;
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
