-- SPES: let the shared documents table hold batch-level files (MOAs,
-- endorsement letters, funding approvals) alongside existing
-- beneficiary-level attachments, same pattern as GIP's documents.batch_id.
--
-- A separate column (not a reuse of documents.batch_id) is required since
-- that column's FK already points at gip_batches specifically -- a shared
-- polymorphic column would let a SPES batch_id collide with an unrelated
-- GIP batch_id of the same number.

ALTER TABLE documents ADD COLUMN spes_batch_id BIGINT REFERENCES spes_batches(batch_id) ON DELETE CASCADE;
