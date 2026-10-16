-- Records WHEN, WHY and BY WHOM a TUPAD beneficiary left a project early
-- (same shape as migration 066 for SPES / SLP).
--
-- The exit belongs to the assignment: tupad_project_beneficiaries keeps the
-- row and marks it, instead of deleting it like a mistaken "unassign".
-- exited_at IS NULL means the person did not leave early.
--
-- TUPAD has no stored profile status (it is derived from the assignment), so
-- unlike SPES/SLP this does NOT need migration 065 / the 'Withdrawn' enum
-- value: the derived status simply reads 'Withdrawn' when exited_at is set.
--
-- Idempotent (IF NOT EXISTS) and a single transaction.

BEGIN;

ALTER TABLE tupad_project_beneficiaries
    ADD COLUMN IF NOT EXISTS exited_at   timestamp,
    ADD COLUMN IF NOT EXISTS exit_reason text,
    ADD COLUMN IF NOT EXISTS exited_by   bigint REFERENCES users(user_id);

-- A recorded exit always carries its reason.
ALTER TABLE tupad_project_beneficiaries
    DROP CONSTRAINT IF EXISTS chk_tupad_exit_reason,
    ADD CONSTRAINT chk_tupad_exit_reason
        CHECK (exited_at IS NULL OR (exit_reason IS NOT NULL AND btrim(exit_reason) <> ''));

COMMIT;
