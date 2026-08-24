-- Client wants GIP's "in progress" applicant status to read "Ongoing" instead
-- of "Active" -- more consistent with "Completed" than the old batch-era
-- wording. profile_status_enum is shared by cdsp_profiles, clpep_profiles,
-- gip_profiles, slp_profiles, spes_profiles, so this only ADDS a value
-- (never removes/renames 'Active') -- the other four modules are unaffected
-- since they never write 'Ongoing'. Only GIP switches to writing it.
--
-- Note: ADD VALUE must commit before it's used, so this file must be run as
-- separate statements (not wrapped in one explicit transaction) -- psql's
-- default autocommit-per-statement mode via `-f` handles this correctly.

ALTER TYPE profile_status_enum ADD VALUE 'Ongoing';

-- Backfill: GIP's one existing applicant already marked Active moves to
-- Ongoing so there's no leftover inconsistency. Scoped to gip_profiles only --
-- cdsp_profiles/clpep_profiles/slp_profiles/spes_profiles keep 'Active'.
UPDATE gip_profiles SET status = 'Ongoing' WHERE status = 'Active';
