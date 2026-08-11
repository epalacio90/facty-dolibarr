-- Copyright (C) 2026 Facty — GPLv3, see LICENSE.
--
-- Bandeja de salida: timbrados, cancelaciones y consultas pendientes.
--
-- Existe por una razón concreta: un fallo de red NO significa "no se timbró".
-- Significa "no sé". La única respuesta segura es encolar una RECONCILIACIÓN
-- (resolver contra Facty por idempotency_key, y si hace falta por UUID) y
-- converger — jamás reintentar a ciegas, porque cada intento a ciegas puede
-- costar un timbre de verdad.
CREATE TABLE IF NOT EXISTS llx_factymx_job (
    rowid        INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity       INTEGER      NOT NULL DEFAULT 1,
    env          VARCHAR(4)   NOT NULL DEFAULT 'test',

    -- stamp | cancel | reconcile | sat_status | catalog_refresh
    kind         VARCHAR(24)  NOT NULL,
    ref_table    VARCHAR(64)  NULL,
    ref_id       INTEGER      NULL,
    payload_json TEXT         NULL,

    attempts     INTEGER      NOT NULL DEFAULT 0,
    next_run_at  DATETIME     NULL,
    -- pending | running | done | failed
    status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
    last_error   TEXT         NULL,

    tms          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    datec        DATETIME     NULL
) ENGINE=innodb;
