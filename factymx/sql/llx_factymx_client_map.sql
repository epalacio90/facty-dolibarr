-- Copyright (C) 2026 Facty — GPLv3, see LICENSE.
--
-- Mapeo tercero de Dolibarr ↔ cliente de Facty, por ambiente.
-- `hash` es una huella de los campos fiscales enviados la última vez: permite
-- detectar que el tercero cambió en Dolibarr y hay que hacer PATCH, sin volver
-- a mandar todo en cada timbrado.
CREATE TABLE IF NOT EXISTS llx_factymx_client_map (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_soc          INTEGER      NOT NULL,
    entity          INTEGER      NOT NULL DEFAULT 1,
    env             VARCHAR(4)   NOT NULL DEFAULT 'test',
    facty_client_id VARCHAR(64)  NOT NULL,
    rfc             VARCHAR(13)  NULL,
    hash            VARCHAR(64)  NULL,
    synced_at       DATETIME     NULL,
    tms             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
