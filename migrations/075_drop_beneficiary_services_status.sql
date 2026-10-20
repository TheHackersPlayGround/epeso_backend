-- Step 2 of removing beneficiary_services.status (step 1 was 074, which gave it a default).
--
-- The column was set to 'Active' at enrolment and never changed afterwards (every row is
-- 'Active'); nothing in the application reads or writes it any more. The real status of a
-- person lives in each program's own profile table, or is derived from their assignments.
--
-- beneficiary_service_status_enum was used by this column only, so it goes too.
--
-- Run this AFTER the updated PHP is in place: the new code no longer names the column. Old
-- code that still inserts a status would fail once the column is gone.
-- Idempotent (IF EXISTS) and a single transaction.

BEGIN;

ALTER TABLE beneficiary_services DROP COLUMN IF EXISTS status;
DROP TYPE IF EXISTS beneficiary_service_status_enum;

COMMIT;
