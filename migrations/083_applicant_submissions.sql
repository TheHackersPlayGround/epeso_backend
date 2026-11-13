-- Applicant self-service kiosk: submissions waiting for staff review + an on/off switch.
--
-- An applicant fills in their own form at the kiosk page (/apply/). What they
-- submit is stored here as a PENDING submission, NOT as a real applicant. Staff
-- review it in the Pending inbox and approve it (which creates the real applicant
-- through the normal Add Applicant code path) or reject it.
--
-- payload      the form exactly as the staff Add Applicant form would send it (JSON),
--              so approving it is just "open the form pre-filled, staff finish, save".
-- program      which program's form it is. Only 'ef' (Employment Facilitation) for now;
--              the column is generic so other programs can use the same inbox later.
-- beneficiary_id / reviewed_by use ON DELETE SET NULL so purging an old applicant or
--              user never fails because of a submission that points at them.
--
-- app_settings is a tiny key/value table; 'kiosk_enabled' is the kiosk on/off switch
-- (administrators change it from the Pending inbox).
--
-- Safe to re-run: every statement is IF NOT EXISTS / ON CONFLICT DO NOTHING.
--
-- Rollback:
--   DROP TABLE applicant_submissions;
--   DROP TYPE submission_status_enum;
--   DROP TABLE app_settings;

BEGIN;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'submission_status_enum') THEN
    CREATE TYPE submission_status_enum AS ENUM ('Pending', 'Approved', 'Rejected');
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key   VARCHAR(50)  PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by    BIGINT       REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('kiosk_enabled', 'true')
ON CONFLICT (setting_key) DO NOTHING;

CREATE TABLE IF NOT EXISTS applicant_submissions (
  submission_id  BIGSERIAL PRIMARY KEY,
  reference_no   VARCHAR(20)  NOT NULL UNIQUE,
  program        VARCHAR(20)  NOT NULL,
  applicant_name VARCHAR(255) NOT NULL,
  birth_date     DATE,
  payload        JSONB        NOT NULL,
  status         submission_status_enum NOT NULL DEFAULT 'Pending',
  submitted_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_ip   VARCHAR(45),
  reviewed_by    BIGINT       REFERENCES users(user_id) ON DELETE SET NULL,
  reviewed_at    TIMESTAMP,
  reject_reason  VARCHAR(500),
  beneficiary_id BIGINT       REFERENCES beneficiaries(beneficiary_id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_applicant_submissions_status
  ON applicant_submissions (status, program, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_applicant_submissions_ip
  ON applicant_submissions (submitted_ip, submitted_at);
CREATE INDEX IF NOT EXISTS idx_applicant_submissions_reviewed_by
  ON applicant_submissions (reviewed_by);
CREATE INDEX IF NOT EXISTS idx_applicant_submissions_beneficiary
  ON applicant_submissions (beneficiary_id);

COMMIT;
