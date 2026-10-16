-- Records WHEN, WHY and BY WHOM a GIP intern left their workplace/office
-- early (same shape as 066 for SPES/SLP and 067 for TUPAD).
--
-- The assignment lives on gip_profiles itself (workplace_id,
-- workplace_assigned_at, workplace_completed_at), so the exit columns go on the
-- same row; workplace_id is KEPT on withdrawal so the record of where they
-- were survives. exited_at IS NULL means the person did not leave early.
--
-- Requires migration 065 (the 'Withdrawn' value of profile_status_enum), which
-- the application sets as the profile status alongside these columns.
-- Idempotent (IF NOT EXISTS) and a single transaction.

BEGIN;

ALTER TABLE gip_profiles
    ADD COLUMN IF NOT EXISTS exited_at   timestamp,
    ADD COLUMN IF NOT EXISTS exit_reason text,
    ADD COLUMN IF NOT EXISTS exited_by   bigint REFERENCES users(user_id);

-- A recorded exit always carries its reason.
ALTER TABLE gip_profiles
    DROP CONSTRAINT IF EXISTS chk_gip_exit_reason,
    ADD CONSTRAINT chk_gip_exit_reason
        CHECK (exited_at IS NULL OR (exit_reason IS NOT NULL AND btrim(exit_reason) <> ''));

COMMIT;
