-- SPES: two gaps found while wiring the real backend against the existing
-- SPESProfileForm.tsx / GIPMaintenanceForm-style assignment history:
--
-- 1. spes_profiles has no column for the Course/Program field that the
--    frontend already shows for College/Technical-Vocational students
--    (schema only has school_name/school_type/grade_year_level).
-- 2. spes_profiles has no "when was this applicant assigned to their batch"
--    timestamp, needed for Assignment History's "Date Assigned" the same way
--    GIP added gip_profiles.batch_assigned_at (migration 003) for the same
--    reason -- spes_profiles.updated_at changes on unrelated edits too, so it
--    can't stand in for it.

ALTER TABLE spes_profiles ADD COLUMN course VARCHAR(150) NULL;
ALTER TABLE spes_profiles ADD COLUMN batch_assigned_at TIMESTAMP NULL;
