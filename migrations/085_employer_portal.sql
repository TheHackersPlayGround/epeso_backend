-- Employer portal: employers sign in to their own page and see the applicants referred to them, their placements and
-- company details, and send requests the PESO accepts or rejects (Employers tab -> "Employer Updates").
--
-- employer_portal_accounts   one portal login per employer, created/reset/deactivated by an administrator. It is deliberately
--                            NOT the staff `users` table: an employer account can never sign in to the staff system.
-- employer_requests          what an employer asked for (referral status, placement status, company details). One OPEN
--                            (Pending) request at a time per referral / placement / company.
-- employer_login_attempts    failed sign-ins, for the temporary lockout.
-- employer_access_log        every time an employer opens an applicant's details, and password changes.
--
-- Safe to run whether or not an earlier 084_employer_portal.sql was ever run: every statement is IF NOT EXISTS /
-- ADD COLUMN IF NOT EXISTS, and nothing is dropped. (Old leftovers such as employer_sync_log are simply ignored;
-- DROP TABLE IF EXISTS employer_sync_log; if you want them gone.)
--
-- Rollback:
--   DROP TABLE IF EXISTS employer_access_log;
--   DROP TABLE IF EXISTS employer_login_attempts;
--   DROP TABLE IF EXISTS employer_requests;
--   DROP TABLE IF EXISTS employer_portal_accounts;

BEGIN;

CREATE TABLE IF NOT EXISTS employer_portal_accounts (
  account_id           BIGSERIAL    PRIMARY KEY,
  employer_id          BIGINT       NOT NULL UNIQUE REFERENCES employers(employer_id) ON DELETE CASCADE,
  username             VARCHAR(50)  NOT NULL UNIQUE,
  password_hash        TEXT         NOT NULL,
  must_change_password BOOLEAN      NOT NULL DEFAULT TRUE,
  status               VARCHAR(10)  NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
  last_login           TIMESTAMP,
  created_by           BIGINT       REFERENCES users(user_id) ON DELETE SET NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- (an account table made by the earlier 084 lacks this column)
ALTER TABLE employer_portal_accounts ADD COLUMN IF NOT EXISTS last_login TIMESTAMP;

CREATE TABLE IF NOT EXISTS employer_requests (
  request_id    BIGSERIAL    PRIMARY KEY,
  employer_id   BIGINT       NOT NULL REFERENCES employers(employer_id) ON DELETE CASCADE,
  request_type  VARCHAR(20)  NOT NULL CHECK (request_type IN ('referral_status', 'placement_status', 'company_info')),
  referral_id   BIGINT       REFERENCES employment_facilitation_referrals(referral_id) ON DELETE CASCADE,
  placement_id  BIGINT       REFERENCES employment_facilitation_placements(placement_id) ON DELETE CASCADE,
  payload       JSONB        NOT NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Approved', 'Rejected', 'Withdrawn')),
  submitted_by  VARCHAR(100),
  submitted_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   BIGINT       REFERENCES users(user_id) ON DELETE SET NULL,
  reviewed_at   TIMESTAMP,
  review_note   VARCHAR(500),
  -- the request must point at exactly what its type is about
  CONSTRAINT employer_requests_target_chk CHECK (
       (request_type = 'referral_status'  AND referral_id  IS NOT NULL AND placement_id IS NULL)
    OR (request_type = 'placement_status' AND placement_id IS NOT NULL AND referral_id  IS NULL)
    OR (request_type = 'company_info'     AND referral_id  IS NULL     AND placement_id IS NULL)
  )
);
CREATE UNIQUE INDEX IF NOT EXISTS employer_requests_open_referral
  ON employer_requests (referral_id)  WHERE status = 'Pending' AND referral_id  IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS employer_requests_open_placement
  ON employer_requests (placement_id) WHERE status = 'Pending' AND placement_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS employer_requests_open_company
  ON employer_requests (employer_id)  WHERE status = 'Pending' AND request_type = 'company_info';
CREATE INDEX IF NOT EXISTS employer_requests_status_idx   ON employer_requests (status, submitted_at);
CREATE INDEX IF NOT EXISTS employer_requests_employer_idx ON employer_requests (employer_id);

CREATE TABLE IF NOT EXISTS employer_login_attempts (
  attempt_id BIGSERIAL    PRIMARY KEY,
  username   VARCHAR(50),
  ip_address VARCHAR(45),
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS employer_login_attempts_idx ON employer_login_attempts (created_at);

CREATE TABLE IF NOT EXISTS employer_access_log (
  log_id       BIGSERIAL    PRIMARY KEY,
  account_id   BIGINT       REFERENCES employer_portal_accounts(account_id) ON DELETE SET NULL,
  employer_id  BIGINT       REFERENCES employers(employer_id) ON DELETE CASCADE,
  action       VARCHAR(50)  NOT NULL,
  applicant_id BIGINT,
  ip_address   VARCHAR(45),
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
