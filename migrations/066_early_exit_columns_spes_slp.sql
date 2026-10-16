-- Records WHEN, WHY and BY WHOM a SPES / SLP participant left early.
--
-- The exit belongs to the assignment, not to the person or the enrollment:
--   * SPES  -> spes_profiles (the batch assignment lives on the profile row;
--              batch_id is KEPT when someone withdraws, so history survives).
--   * SLP   -> slp_project_beneficiaries (one row per person per project; the
--              row is KEPT and marked, instead of being deleted like a mistaken
--              "unassign").
-- exited_at IS NULL means the person did not leave early.
--
-- Requires 065 (the 'Withdrawn' status value) to have been run first, since
-- the application sets profile status to 'Withdrawn' alongside these columns.
-- Idempotent (IF NOT EXISTS) and a single transaction.

BEGIN;

ALTER TABLE spes_profiles
    ADD COLUMN IF NOT EXISTS exited_at   timestamp,
    ADD COLUMN IF NOT EXISTS exit_reason text,
    ADD COLUMN IF NOT EXISTS exited_by   bigint REFERENCES users(user_id);

ALTER TABLE slp_project_beneficiaries
    ADD COLUMN IF NOT EXISTS exited_at   timestamp,
    ADD COLUMN IF NOT EXISTS exit_reason text,
    ADD COLUMN IF NOT EXISTS exited_by   bigint REFERENCES users(user_id);

-- A recorded exit always carries its reason.
ALTER TABLE spes_profiles
    DROP CONSTRAINT IF EXISTS chk_spes_exit_reason,
    ADD CONSTRAINT chk_spes_exit_reason
        CHECK (exited_at IS NULL OR (exit_reason IS NOT NULL AND btrim(exit_reason) <> ''));
ALTER TABLE slp_project_beneficiaries
    DROP CONSTRAINT IF EXISTS chk_slp_exit_reason,
    ADD CONSTRAINT chk_slp_exit_reason
        CHECK (exited_at IS NULL OR (exit_reason IS NOT NULL AND btrim(exit_reason) <> ''));

COMMIT;
