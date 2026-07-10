-- GIP: track when a profile was assigned to its batch, and let the shared
-- documents table hold batch-level files (contracts/MOAs) alongside the
-- existing beneficiary-level attachments.
--
-- gip_profiles.batch_id is a single direct FK (no participants junction table
-- like CDSP's), so batch_assigned_at is the only place "date assigned" can
-- live without guessing from updated_at (which also changes on unrelated edits).

ALTER TABLE gip_profiles ADD COLUMN batch_assigned_at timestamp NULL;
ALTER TABLE documents ADD COLUMN batch_id BIGINT REFERENCES gip_batches(batch_id) ON DELETE CASCADE;
