-- Adds 'Withdrawn' to profile_status_enum: a participant who was assigned to a
-- batch/project that had already started and then left before it finished
-- (SPES batch / SLP project). Distinct from:
--   Inactive  -> not currently assigned (new, unassigned)
--   Completed -> finished the batch/project
--   Withdrawn -> started, then left early
--
-- RUN THIS FILE ON ITS OWN, before 066, and NOT inside BEGIN/COMMIT with
-- anything that uses the value: PostgreSQL does not let a freshly added enum
-- value be used in the same transaction that added it. (In pgAdmin just run
-- this one statement, then run 066 as a separate query.)
--
-- The enum is shared by cdsp_profiles, gip_profiles, spes_profiles,
-- slp_profiles and clpep_profiles, so all of them gain the allowed value;
-- only SPES and SLP code ever writes it. Safe to re-run (IF NOT EXISTS).

ALTER TYPE profile_status_enum ADD VALUE IF NOT EXISTS 'Withdrawn';
