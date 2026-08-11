-- Copyright (C) 2026 Facty — GPLv3, see LICENSE.
--
-- Caché de catálogos SAT leídos de Facty (§2.4 del plan).
--
-- El módulo NO trae los catálogos empaquetados. Se leen de Facty y se guardan
-- aquí con un TTL, así que una actualización del SAT llega sin publicar una
-- versión nueva del módulo, y la instalación no arrastra decenas de MB de datos
-- que envejecen solos.
--
-- Si Facty no responde, el selector debe degradarse a "catálogo no disponible,
-- reintenta" con lo último en caché — nunca a un select vacío que parezca que
-- el catálogo no tiene valores.
CREATE TABLE IF NOT EXISTS llx_factymx_catalog_cache (
    rowid      INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity     INTEGER      NOT NULL DEFAULT 1,
    env        VARCHAR(4)   NOT NULL DEFAULT 'test',
    catalog    VARCHAR(64)  NOT NULL,
    code       VARCHAR(64)  NOT NULL,
    label      VARCHAR(255) NOT NULL,
    extra_json TEXT         NULL,
    fetched_at DATETIME     NOT NULL
) ENGINE=innodb;
