-- Dedicated FK columns for DILP/TUPAD project documents, mirroring the
-- existing batch_id (GIP) / spes_batch_id (SPES) pattern rather than reusing
-- a shared generic column.
ALTER TABLE documents ADD COLUMN dilp_project_id bigint REFERENCES dilp_projects(dilp_project_id) ON DELETE CASCADE;
ALTER TABLE documents ADD COLUMN tupad_project_id bigint REFERENCES tupad_projects(project_id) ON DELETE CASCADE;
