-- Turn job_placements.employment_type from freeform text into a real
-- Postgres enum, matching the controlled-vocabulary pattern already used
-- elsewhere in the schema (e.g. ofw_employment_status_enum).
--
-- job_placements has no rows yet (feature just built, not yet in use), so
-- there is no existing data to migrate/backfill.

CREATE TYPE job_placement_employment_type_enum AS ENUM (
    'Full-time', 'Part-time', 'Contractual'
);

ALTER TABLE job_placements
    ALTER COLUMN employment_type TYPE job_placement_employment_type_enum
    USING employment_type::job_placement_employment_type_enum;
