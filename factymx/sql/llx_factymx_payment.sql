-- Copyright (C) 2026 Facty — GPLv3, see LICENSE.
--
-- Complemento de pago (REP): una fila por pago de Dolibarr complementado.
-- `facty_payment_id` es el pago en Facty; `facty_invoice_id` es el CFDI de tipo
-- P que lo documenta. Se guardan los dos porque cancelar un REP se hace contra
-- el pago, pero el UUID que el SAT conoce es el del comprobante.
CREATE TABLE IF NOT EXISTS llx_factymx_payment (
    rowid            INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_paiement      INTEGER      NOT NULL,
    entity           INTEGER      NOT NULL DEFAULT 1,
    env              VARCHAR(4)   NOT NULL DEFAULT 'test',

    facty_payment_id VARCHAR(64)  NULL,
    facty_invoice_id VARCHAR(64)  NULL,
    uuid             VARCHAR(36)  NULL,

    status           VARCHAR(16)  NOT NULL DEFAULT 'pending',
    monto            DOUBLE(24,8) NULL,
    moneda           VARCHAR(3)   NULL,
    fecha_pago       DATETIME     NULL,

    stamped_at       DATETIME     NULL,
    cancelled_at     DATETIME     NULL,
    cancel_motivo    VARCHAR(2)   NULL,

    xml_path         VARCHAR(255) NULL,
    pdf_path         VARCHAR(255) NULL,
    acuse_path       VARCHAR(255) NULL,

    idempotency_key  VARCHAR(128) NOT NULL,
    last_error       TEXT         NULL,
    facty_request_id VARCHAR(64)  NULL,

    fk_user_creat    INTEGER      NULL,
    tms              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    datec            DATETIME     NULL
) ENGINE=innodb;
