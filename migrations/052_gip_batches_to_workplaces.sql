-- Client requested that GIP Maintenance manage "Workplace/Office" records
-- directly -- the office's own name/details -- instead of an arbitrary
-- "batch" label paired with a separate assigned-office field. batch_name is
-- dropped entirely; assigned_office (renamed workplace_name) becomes the
-- record's real identity. Table/column/constraint/index names are renamed
-- throughout so the DB schema matches the new domain language, not just the UI.

ALTER TABLE gip_batches RENAME TO gip_workplaces;
ALTER TABLE gip_workplaces RENAME COLUMN batch_id TO workplace_id;
ALTER TABLE gip_workplaces RENAME COLUMN assigned_office TO workplace_name;
ALTER TABLE gip_workplaces DROP COLUMN batch_name;

ALTER SEQUENCE gip_batches_batch_id_seq RENAME TO gip_workplaces_workplace_id_seq;

ALTER TABLE gip_workplaces RENAME CONSTRAINT gip_batches_pkey TO gip_workplaces_pkey;
ALTER TABLE gip_workplaces RENAME CONSTRAINT gip_batches_check TO gip_workplaces_check;
ALTER TABLE gip_workplaces RENAME CONSTRAINT gip_batches_monthly_allowance_check TO gip_workplaces_monthly_allowance_check;
ALTER TABLE gip_workplaces RENAME CONSTRAINT gip_batches_slot_count_check TO gip_workplaces_slot_count_check;
ALTER TABLE gip_workplaces RENAME CONSTRAINT gip_batches_deleted_by_fkey TO gip_workplaces_deleted_by_fkey;
ALTER INDEX idx_gip_batches_deleted_by RENAME TO idx_gip_workplaces_deleted_by;

ALTER TABLE attached_documents RENAME COLUMN gip_batch_id TO gip_workplace_id;
ALTER TABLE attached_documents RENAME CONSTRAINT attached_documents_gip_batch_id_fkey TO attached_documents_gip_workplace_id_fkey;
ALTER INDEX idx_attached_documents_gip_batch_id RENAME TO idx_attached_documents_gip_workplace_id;

ALTER TABLE gip_profiles RENAME COLUMN batch_id TO workplace_id;
ALTER TABLE gip_profiles RENAME COLUMN batch_assigned_at TO workplace_assigned_at;
ALTER TABLE gip_profiles RENAME CONSTRAINT fk_gip_profiles_batch TO fk_gip_profiles_workplace;
ALTER INDEX idx_gip_profiles_batch_id RENAME TO idx_gip_profiles_workplace_id;

-- Existing document rows tagged with the old source label
UPDATE attached_documents SET document_source = 'GIP Workplace' WHERE document_source = 'GIP Batch';
