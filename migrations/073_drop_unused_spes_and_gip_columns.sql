-- Drop three columns that nothing in the application uses.
--
--   spes_profiles.application_date   -- never read or written by any code. Postgres filled it
--                                       with the current date; the real date is
--                                       beneficiary_services.date_applied.
--   spes_profiles.application_status -- never read or written by the SPES code; always
--                                       'Pending'. (application_status_enum itself stays: Skills
--                                       Training's skills_training_profiles.application_status
--                                       uses it.)
--   gip_workplaces.coordinator       -- the backend saved it, but no GIP screen has a field for
--                                       it (0 of 4 rows ever had a value). SPES batches keep
--                                       their own coordinator column.
--
-- Checked first: no constraint, index or view depends on any of these columns.
--
-- Run this AFTER deploying the updated PHP (modules/gip.php no longer names
-- gip_workplaces.coordinator). Idempotent (IF EXISTS) and a single transaction.

BEGIN;

ALTER TABLE spes_profiles  DROP COLUMN IF EXISTS application_date;
ALTER TABLE spes_profiles  DROP COLUMN IF EXISTS application_status;
ALTER TABLE gip_workplaces DROP COLUMN IF EXISTS coordinator;

COMMIT;
