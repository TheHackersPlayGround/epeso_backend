-- documents.batch_id was ambiguous: two different batch concepts exist
-- (gip_batches and spes_batches), but only this column was left unprefixed
-- while its sibling module-context FKs (spes_batch_id, dilp_project_id,
-- tupad_project_id, slp_project_id, clpep_intervention_id) are all named
-- after their module. Renamed for consistency and clarity; it only ever
-- referenced gip_batches, never spes_batches.
ALTER TABLE documents RENAME COLUMN batch_id TO gip_batch_id;
ALTER TABLE documents RENAME CONSTRAINT documents_batch_id_fkey TO documents_gip_batch_id_fkey;
