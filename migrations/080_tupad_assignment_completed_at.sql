-- TUPAD: stamps each assignment's own completion date, independent of the
-- project's current status (which can change again if the project reopens).

BEGIN;

ALTER TABLE tupad_project_beneficiaries
    ADD COLUMN IF NOT EXISTS completed_at timestamp;

-- One-time backfill so deploying this doesn't blank out existing badges.
-- Already-reopened projects stay unstamped -- that history can't be recovered.
UPDATE tupad_project_beneficiaries tpb
SET completed_at = tpb.assigned_at
FROM tupad_projects tp
WHERE tp.project_id = tpb.project_id
  AND tp.status = 'Completed'
  AND tpb.exited_at IS NULL
  AND tpb.completed_at IS NULL;

COMMIT;
