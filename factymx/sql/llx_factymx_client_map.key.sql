ALTER TABLE llx_factymx_client_map ADD UNIQUE INDEX uk_factymx_client_map (fk_soc, entity, env);
ALTER TABLE llx_factymx_client_map ADD INDEX idx_factymx_client_map_rfc (entity, env, rfc);
