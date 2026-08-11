ALTER TABLE llx_factymx_product_map ADD UNIQUE INDEX uk_factymx_product_map (fk_product, entity, env);
