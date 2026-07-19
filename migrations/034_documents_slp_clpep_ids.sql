-- Dedicated FK columns for SLP/CLPEP project documents, mirroring the
-- dilp_project_id/tupad_project_id pattern (migration 027).
ALTER TABLE documents ADD COLUMN slp_project_id bigint REFERENCES slp_projects(project_id) ON DELETE CASCADE;
ALTER TABLE documents ADD COLUMN clpep_intervention_id bigint REFERENCES clpep_interventions(intervention_id) ON DELETE CASCADE;
