-- Split the general-purpose "Documents menu" (folder-organized, PESO-staff
-- filing, unrelated to any specific applicant/batch/project) out of the
-- case-linked `documents` table into its own `document_library` table.
--
-- `documents` stays reserved for records tied to exactly one case-context
-- owner (beneficiary_service_id, gip_batch_id, spes_batch_id, dilp_project_id,
-- tupad_project_id, slp_project_id, clpep_intervention_id). It never actually
-- used folder_id in practice (0 rows had it set) -- the Documents menu was
-- always a conceptually separate feature, just sharing a table by default.
-- modules/documents.php and the frontend DocumentsContext were still an
-- unwired stub/mock at the time of this migration, so there is no existing
-- document_library data to backfill.

CREATE TABLE document_library (
    document_id  BIGSERIAL PRIMARY KEY,
    folder_id    BIGINT REFERENCES folders(folder_id),
    title        VARCHAR(255) NOT NULL,
    file_name    VARCHAR(255) NOT NULL,
    file_path    TEXT NOT NULL,
    file_size    BIGINT,
    mime_type    VARCHAR(100),
    uploaded_by  BIGINT NOT NULL REFERENCES users(user_id),
    uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Safe to drop outright: verified 0 of 6 existing documents rows had
-- folder_id set (SELECT count(folder_id) FROM documents = 0) before this
-- migration was written.
ALTER TABLE documents DROP COLUMN folder_id;
