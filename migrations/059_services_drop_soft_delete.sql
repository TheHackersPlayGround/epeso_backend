-- Drop deleted_at/deleted_by from services -- they were added in migration
-- 049 to soft-delete CDSP sub-services via cdspCreateService()/cdspDeleteService(),
-- but both of those functions (and the recycle-bin entries for them) were
-- removed from modules/cdsp.php in commit 1cd9dec ("minor fixes"). No code
-- writes to these columns anymore, cdspRecycleMap() no longer lists a
-- 'cdspService' record type, and no other module ever referenced them --
-- they were only ever set for CDSP sub-services in the first place.
--
-- Verified against the live DB before writing this migration: zero rows in
-- services have deleted_at set, so nothing is hidden or orphaned by this drop.
-- Dropping the columns also drops the dependent idx_services_deleted_by index
-- (migration 051) and its FK to users automatically. Safe to re-run.

BEGIN;

ALTER TABLE services DROP COLUMN IF EXISTS deleted_at;
ALTER TABLE services DROP COLUMN IF EXISTS deleted_by;

COMMIT;
