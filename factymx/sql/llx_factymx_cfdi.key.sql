-- Una factura sólo puede tener un CFDI por entity Y POR AMBIENTE. Incluir `env`
-- en la restricción es lo que permite timbrar la misma factura en pruebas y
-- luego en producción sin que la de pruebas bloquee la real.
ALTER TABLE llx_factymx_cfdi ADD UNIQUE INDEX uk_factymx_cfdi_facture (fk_facture, entity, env);

-- Reconciliación: resolver "¿esto sí se timbró?" por llave de idempotencia.
ALTER TABLE llx_factymx_cfdi ADD UNIQUE INDEX uk_factymx_cfdi_idem (idempotency_key, entity, env);

ALTER TABLE llx_factymx_cfdi ADD INDEX idx_factymx_cfdi_uuid (uuid);
ALTER TABLE llx_factymx_cfdi ADD INDEX idx_factymx_cfdi_status (entity, env, status);
