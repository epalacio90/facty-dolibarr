-- Copyright (C) 2026 Facty — GPLv3, see LICENSE.
--
-- Bitácora de llamadas a la API, para la pantalla de diagnóstico.
--
-- Guarda el request id que devuelve Facty para que un ticket de soporte llegue
-- con una referencia que se pueda buscar del otro lado. NUNCA guarda la API key
-- ni el cuerpo de la petición: el receptor (RFC, nombre, CP) es dato personal.
CREATE TABLE IF NOT EXISTS llx_factymx_log (
    rowid            INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity           INTEGER      NOT NULL DEFAULT 1,
    env              VARCHAR(4)   NOT NULL DEFAULT 'test',
    fk_user          INTEGER      NULL,
    action           VARCHAR(64)  NOT NULL,
    method           VARCHAR(8)   NULL,
    path             VARCHAR(255) NULL,
    http_status      INTEGER      NULL,
    facty_code       VARCHAR(64)  NULL,
    facty_request_id VARCHAR(64)  NULL,
    duration_ms      INTEGER      NULL,
    message          TEXT         NULL,
    tms              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
