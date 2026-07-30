-- Extends soft-delete (deleted_at/deleted_by) to every "Maintenance"-level
-- entity (sub-services, batches, activities, projects, interventions) so
-- they get the same 30-day recycle-bin safety net already given to
-- applicant/beneficiary records, instead of being hard-deleted the instant
-- their in-use guard passes.
--
-- services.deleted_at only ever gets set on CDSP sub-services (rows with a
-- non-null parent_service_id) via cdspDeleteService() -- every other module's
-- own top-level service row (service_code='GIP'/'SPES'/etc.) has no delete
-- action anywhere in the app and will never be soft-deleted.

ALTER TABLE services                     ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE services                     ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE cdsp_activities              ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE cdsp_activities              ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE gip_batches                  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE gip_batches                  ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE spes_batches                 ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE spes_batches                 ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE dilp_projects                ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE dilp_projects                ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE tupad_projects                ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE tupad_projects                ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE slp_projects                 ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE slp_projects                 ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE clpep_interventions          ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE clpep_interventions          ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE skills_training_batches      ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE skills_training_batches      ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE skills_training_activities   ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE skills_training_activities   ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);
