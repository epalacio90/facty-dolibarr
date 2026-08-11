-- Copyright (C) 2026 Facty — GPLv3, see LICENSE.
--
-- El registro del timbrado: una fila por factura de Dolibarr timbrada en Facty.
--
-- Esta tabla es el truco entero del módulo. Guarda a la vez el id de Facty y el
-- UUID del SAT en el momento de timbrar, así que cancelar, relacionar o
-- complementar un CFDI después es una consulta local — nunca hay que adivinar
-- ni pedirle a Facty que traduzca un UUID a su id interno.
--
-- `env` es funcional, no burocrático: producción y pruebas son bases de datos
-- distintas en Facty, así que un facty_invoice_id de un ambiente no significa
-- nada en el otro. Sin esta columna el módulo le mandaría a producción ids que
-- sólo existen en pruebas la primera vez que alguien cambia el switch.

CREATE TABLE IF NOT EXISTS llx_factymx_cfdi (
    rowid             INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_facture        INTEGER      NOT NULL,
    entity            INTEGER      NOT NULL DEFAULT 1,
    env               VARCHAR(4)   NOT NULL DEFAULT 'test',

    facty_invoice_id  VARCHAR(64)  NULL,
    uuid              VARCHAR(36)  NULL,
    cfdi_type         VARCHAR(16)  NOT NULL DEFAULT 'ingreso',
    serie             VARCHAR(25)  NULL,
    folio             INTEGER      NULL,

    -- pending | stamped | failed | cancelled
    status            VARCHAR(16)  NOT NULL DEFAULT 'pending',
    total             DOUBLE(24,8) NULL,
    moneda            VARCHAR(3)   NULL,

    stamped_at        DATETIME     NULL,
    cancelled_at      DATETIME     NULL,
    cancel_motivo     VARCHAR(2)   NULL,

    xml_path          VARCHAR(255) NULL,
    pdf_path          VARCHAR(255) NULL,
    acuse_path        VARCHAR(255) NULL,

    -- Determinista: dolibarr:{env}:{entity}:facture:{rowid}. Un doble clic, un
    -- reintento tras timeout y una repetición del cron producen la misma llave,
    -- así que Facty devuelve el MISMO CFDI y cobra UN solo timbre.
    idempotency_key   VARCHAR(128) NOT NULL,

    -- Último error, para que la pantalla de diagnóstico pueda mostrarlo junto
    -- con el request id de Facty en lugar de mandar al usuario a los logs.
    last_error        TEXT         NULL,
    facty_request_id  VARCHAR(64)  NULL,

    fk_user_creat     INTEGER      NULL,
    tms               TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    datec             DATETIME     NULL
) ENGINE=innodb;
