-- Step 1 of removing beneficiary_services.status.
--
-- That column was set to 'Active' when a person was enrolled and never changed afterwards
-- (all rows are 'Active'); nothing in the application reads it any more. The real status of
-- a person lives in each program's own profile table (or is derived from assignments).
--
-- The enrolment code (modules/*.php) no longer supplies a status. The column is NOT NULL
-- with no default, so give it one: inserts from the NEW code get 'Active' from the default,
-- and inserts from the OLD code (which still names the column) keep working too.
--
-- RUN THIS BEFORE the updated PHP goes live. The new code relies on the default, so until
-- this has run, enrolling anyone into a program fails ("null value in column status").
--
-- Step 2 (dropping the column and beneficiary_service_status_enum) comes later, once every
-- copy of the app and database is on this version. Idempotent and a single transaction.

BEGIN;

ALTER TABLE beneficiary_services ALTER COLUMN status SET DEFAULT 'Active';

COMMIT;
