-- Re-introduce school name collection at applicant intake, per education level.
--
-- School name was previously dropped from intake (kept only in the Resume
-- Builder). This restores it as a column on the per-level educations table, so
-- Elementary / Secondary / Tertiary / each Graduate Studies row stores its own
-- school. Nullable -- existing rows have no school name. Safe to re-run.

ALTER TABLE educations ADD COLUMN IF NOT EXISTS school_name VARCHAR(255);
