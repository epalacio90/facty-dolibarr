ALTER TABLE llx_factymx_payment ADD UNIQUE INDEX uk_factymx_payment (fk_paiement, entity, env);
ALTER TABLE llx_factymx_payment ADD UNIQUE INDEX uk_factymx_payment_idem (idempotency_key, entity, env);
ALTER TABLE llx_factymx_payment ADD INDEX idx_factymx_payment_status (entity, env, status);
