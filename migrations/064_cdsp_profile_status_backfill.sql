-- Backfill cdsp_profiles.status so the stored value matches the status CDSP
-- actually shows.
--
-- Until now this column was set to 'Active' when a profile was created and
-- never touched again, so it was wrong for anyone whose activity had finished.
-- cdsp.php now keeps it in step with the derived rule on every change
-- (cdspSyncProfileStatus / cdspSyncActivityParticipantsStatus); this one-off
-- statement brings the existing rows in line:
--   Active   = the person has at least one assigned activity that is not Completed
--   Inactive = no assigned activity, or all of them are Completed
--
-- Deploy the updated cdsp.php first (or right after): rows backfilled here
-- stay correct only because the code keeps them correct from then on.
-- Single statement, idempotent, only touches rows whose value would change.

BEGIN;

UPDATE cdsp_profiles cp
SET status = s.new_status,
    updated_at = now()
FROM (
    SELECT p.beneficiary_service_id,
           CASE WHEN EXISTS (
               SELECT 1
               FROM cdsp_activity_participants cap
               JOIN cdsp_activities ca ON ca.activity_id = cap.activity_id
               WHERE cap.beneficiary_service_id = p.beneficiary_service_id
                 AND ca.status <> 'Completed'
           ) THEN 'Active'::profile_status_enum ELSE 'Inactive'::profile_status_enum END AS new_status
    FROM cdsp_profiles p
) s
WHERE cp.beneficiary_service_id = s.beneficiary_service_id
  AND cp.status IS DISTINCT FROM s.new_status;

COMMIT;
