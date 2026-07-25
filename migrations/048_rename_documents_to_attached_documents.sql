-- Renames the `documents` table to `attached_documents`. The name
-- "documents" became ambiguous once migration 044 introduced the separate
-- `document_library` table (the general-purpose, folder-organized Documents
-- menu, unrelated to any specific case/batch/project). This table is the
-- other, distinct concept: files attached directly to one applicant,
-- activity, batch, or project. Renaming it makes that distinction explicit.
--
-- Postgres only renames the table itself with ALTER TABLE ... RENAME TO --
-- every constraint, its backing index, and the auto-increment sequence keep
-- their old `documents_*` names unless renamed explicitly, so each is
-- renamed here too for full consistency (avoiding the exact kind of
-- name/reality mismatch fixed in migrations 043/046).

ALTER TABLE documents RENAME TO attached_documents;

ALTER TABLE attached_documents RENAME CONSTRAINT documents_document_id_not_null TO attached_documents_document_id_not_null;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_document_source_not_null TO attached_documents_document_source_not_null;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_title_not_null TO attached_documents_title_not_null;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_file_name_not_null TO attached_documents_file_name_not_null;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_file_path_not_null TO attached_documents_file_path_not_null;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_uploaded_by_not_null TO attached_documents_uploaded_by_not_null;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_updated_at_not_null TO attached_documents_updated_at_not_null;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_uploaded_at_not_null TO attached_documents_uploaded_at_not_null;

ALTER TABLE attached_documents RENAME CONSTRAINT documents_pkey TO attached_documents_pkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_beneficiary_id_fkey TO attached_documents_beneficiary_id_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_beneficiary_service_id_fkey TO attached_documents_beneficiary_service_id_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_uploaded_by_fkey TO attached_documents_uploaded_by_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_spes_batch_id_fkey TO attached_documents_spes_batch_id_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_dilp_project_id_fkey TO attached_documents_dilp_project_id_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_tupad_project_id_fkey TO attached_documents_tupad_project_id_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_slp_project_id_fkey TO attached_documents_slp_project_id_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_clpep_intervention_id_fkey TO attached_documents_clpep_intervention_id_fkey;
ALTER TABLE attached_documents RENAME CONSTRAINT documents_gip_batch_id_fkey TO attached_documents_gip_batch_id_fkey;

-- Renaming documents_pkey (above) already auto-renames its backing index to
-- match, so no separate ALTER INDEX is needed.
ALTER SEQUENCE documents_document_id_seq RENAME TO attached_documents_document_id_seq;
