-- Employment Facilitation: "Received By" now holds the receiving staff member's NAME.
--
-- Until now the Add Applicant form never asked for it, so the application saved the logged-in
-- user's ID number there (for example '1' or '4'). The new "For PESO Office Only" section lets
-- staff type the name, and a blank defaults to the logged-in user's name. This converts the
-- existing ID numbers on Employment Facilitation applications into those users' full names so
-- the View screen and exports read the same for old and new records.
--
-- Only values that are purely digits AND match an existing user are changed; anything already a
-- name (or a number with no matching user) is left exactly as it is.
--
-- Run this at the same time as the updated PHP (modules/employment.php). Safe to run more than
-- once: after the first run nothing matches any more. A single transaction.

BEGIN;

UPDATE beneficiary_services bs
SET received_by = trim(u.first_name || ' ' || u.last_name)
FROM users u, services s
WHERE s.service_id = bs.service_id
  AND s.service_name = 'Employment Facilitation'
  AND bs.received_by ~ '^[0-9]+$'
  AND u.user_id = bs.received_by::bigint;

COMMIT;
