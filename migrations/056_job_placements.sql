-- Client requested a "job placement" feature: after a beneficiary completes
-- a program (starting with Skills Training), record the job title, employer,
-- and date hired. Built as ONE shared table (not a per-module copy) since
-- this same feature is planned for GIP, CDSP, and others later -- keyed off
-- beneficiary_service_id, the same spine column every module's own profile
-- table already hangs off of. Mirrors the existing cross-module pattern used
-- by attached_documents (one shared table for every program) rather than
-- duplicating columns into gip_profiles/cdsp_profiles/skills_training_profiles/etc.
--
-- No UNIQUE constraint on beneficiary_service_id -- a person may have more
-- than one placement recorded over time (e.g. changes jobs later), matching
-- how employment_facilitation_placements already allows multiple rows per
-- beneficiary_service_id rather than restricting to one-ever.

CREATE TABLE job_placements (
    placement_id           BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    beneficiary_service_id BIGINT NOT NULL REFERENCES beneficiary_services(beneficiary_service_id),
    job_title               VARCHAR(255) NOT NULL,
    employer                VARCHAR(255) NOT NULL,
    date_hired               DATE NOT NULL,
    employment_type          VARCHAR(100),
    remarks                  TEXT,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at               TIMESTAMP NULL,
    deleted_by               INTEGER NULL REFERENCES users(user_id)
);

CREATE INDEX idx_job_placements_beneficiary_service_id ON job_placements(beneficiary_service_id);
CREATE INDEX idx_job_placements_deleted_by ON job_placements(deleted_by);
