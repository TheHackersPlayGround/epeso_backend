-- An employer's status (Active / Inactive) and the status of its employer-portal login now ALWAYS match: the backend changes
-- both together (Employers -> Edit -> Status, and Portal Account -> Activate / Deactivate). See core/portal_status.php.
--
-- This one-time DATA fix makes the rows that already differ match, so the rule starts from a consistent state.
-- No tables or columns change.
--
-- Rule used for a pair that differs: INACTIVE WINS. Nothing is ever switched ON by this script (a login someone deactivated stays off):
--   * portal account Inactive, employer Active    -> the employer becomes Inactive
--   * employer Inactive,       portal account Active -> the portal account becomes Inactive
-- To bring a company back afterwards: Employers -> Edit -> Status -> Active (this also re-activates its portal login;
-- use "Reset password" in Portal Account if the login had been switched off for a security reason).
--
-- Run it in pgAdmin on e-peso_db AFTER 085_employer_portal.sql (it needs employer_portal_accounts).
-- Safe to run again: a second run finds nothing left to change.
--
-- Undo: there is no automatic undo (it only changes statuses). To reverse one company, set its status by hand in the app.

BEGIN;

UPDATE employers e
   SET status = 'Inactive', updated_at = now()
  FROM employer_portal_accounts a
 WHERE a.employer_id = e.employer_id
   AND e.deleted_at IS NULL
   AND a.status = 'Inactive'
   AND e.status::text <> 'Inactive';

UPDATE employer_portal_accounts a
   SET status = 'Inactive', updated_at = now()
  FROM employers e
 WHERE e.employer_id = a.employer_id
   AND e.status::text = 'Inactive'
   AND a.status <> 'Inactive';

COMMIT;
