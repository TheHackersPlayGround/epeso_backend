-- Drop beneficiaries.status (and its enum type).
--
-- Why: the column was never read and never changed after insert -- every row
-- is 'Active', no module updates it, no view/index/trigger depends on it.
-- "Deleted" is already modelled by beneficiaries.deleted_at (recycle bin), and
-- a beneficiary's status *within a program* lives in the program's own tables
-- or is derived from the assignment (see cdsp/gip/spes/slp/clpep/dilp/tupad).
-- Keeping a third, unused "status" on the person only invited reading the
-- wrong one.
--
-- DEPLOY ORDER: the PHP modules no longer name this column in their INSERTs
-- (cdsp, clpep, dilp, employment, gip, ofw, skills_training, slp, spes,
-- tupad). Deploy that code BEFORE running this, or old code will fail with
-- "column status of relation beneficiaries does not exist" when adding a person.
--
-- SAFE BY CONSTRUCTION:
--   * single transaction (all-or-nothing);
--   * a guard aborts the whole thing if ANY row holds a value other than
--     'Active', i.e. it refuses to throw away information;
--   * DROP TYPE has no CASCADE, so it aborts too if some other column still
--     uses the enum.
-- Take a database backup before running (Security -> Data Management, or
-- pg_dump). Safe to re-run: IF EXISTS makes a second run a no-op.

BEGIN;

DO $$
DECLARE
    non_active integer;
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'beneficiaries' AND column_name = 'status'
    ) THEN
        SELECT count(*) INTO non_active FROM beneficiaries WHERE status::text <> 'Active';
        IF non_active > 0 THEN
            RAISE EXCEPTION
                'Refusing to drop beneficiaries.status: % row(s) hold a value other than Active.', non_active;
        END IF;
    END IF;
END $$;

ALTER TABLE beneficiaries DROP COLUMN IF EXISTS status;

DROP TYPE IF EXISTS beneficiary_status_enum;

COMMIT;
