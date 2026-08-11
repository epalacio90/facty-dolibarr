-- Copyright (C) 2026 Facty — GPLv3, see LICENSE.
--
-- Mapeo producto de Dolibarr ↔ producto de Facty, por ambiente.
-- `code` es el identificador que se le manda a Facty (Product.code allá) para
-- que el alta sea idempotente: reintentar una sincronización interrumpida
-- reconcilia en vez de duplicar el catálogo.
CREATE TABLE IF NOT EXISTS llx_factymx_product_map (
    rowid            INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_product       INTEGER      NOT NULL,
    entity           INTEGER      NOT NULL DEFAULT 1,
    env              VARCHAR(4)   NOT NULL DEFAULT 'test',
    facty_product_id VARCHAR(64)  NOT NULL,
    code             VARCHAR(64)  NULL,
    hash             VARCHAR(64)  NULL,
    synced_at        DATETIME     NULL,
    tms              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
