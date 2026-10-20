-- Employers: drop "company size" and "years in operation".
--
-- The app and backend no longer read or save them (removed from the employer add, edit and
-- view forms, the list filter and export, the import template and modules/employment.php).
-- The values of the 4 existing employers are lost.
--
-- employer_size_enum was used by employers.company_size only, so it goes too.
--
-- Run this AFTER the updated PHP is in place: the old code still names both columns, and
-- the new code no longer supplies them. Idempotent (IF EXISTS) and a single transaction.

BEGIN;

ALTER TABLE employers DROP COLUMN IF EXISTS company_size;
ALTER TABLE employers DROP COLUMN IF EXISTS years_in_operation;
DROP TYPE IF EXISTS employer_size_enum;

COMMIT;
