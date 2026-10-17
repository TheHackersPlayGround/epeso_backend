-- DILP Project ID Number is assigned by DOLE, not by the PESO staff entering the
-- project, so it is often unknown at entry time. Make it optional.
--
-- The UNIQUE constraint stays: PostgreSQL treats NULLs as distinct, so any number
-- of projects can leave it empty while real ID numbers still cannot repeat.
-- The application now stores a blank as NULL (never ''), see modules/dilp.php.
-- Idempotent and a single transaction.

BEGIN;

ALTER TABLE dilp_projects ALTER COLUMN project_id_number DROP NOT NULL;

COMMIT;
