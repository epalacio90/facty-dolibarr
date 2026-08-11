ALTER TABLE llx_factymx_catalog_cache ADD UNIQUE INDEX uk_factymx_catalog (entity, env, catalog, code);
ALTER TABLE llx_factymx_catalog_cache ADD INDEX idx_factymx_catalog_fetched (entity, env, catalog, fetched_at);
