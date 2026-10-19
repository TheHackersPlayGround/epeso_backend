-- OFW remarks: dropped. The official OFW request form has no remarks field, nothing in the
-- app wrote to it in practice (0 of the existing profiles had a remark), and the app and
-- backend no longer read or save it.
--
-- Run this AFTER deploying the updated PHP (modules/ofw.php) -- the old code still named
-- the column. Idempotent (IF EXISTS) and a single transaction.

BEGIN;

ALTER TABLE ofw_profiles DROP COLUMN IF EXISTS remarks;

COMMIT;
