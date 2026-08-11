ALTER TABLE llx_factymx_job ADD INDEX idx_factymx_job_queue (status, next_run_at);
ALTER TABLE llx_factymx_job ADD INDEX idx_factymx_job_ref (entity, env, ref_table, ref_id);
