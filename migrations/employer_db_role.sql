-- OPTIONAL but REQUIRED before the employer portal is opened to the internet.
--
-- A database account (role) used ONLY by employer portal requests. If a bug in an employer route were ever abused,
-- this limits the damage: the account can READ the handful of tables employers legitimately see and can WRITE only the
-- employer tables. It cannot read staff accounts, passwords, permissions, activity logs, kiosk submissions or settings,
-- and cannot change any applicant, referral, placement or employer record.
--
-- Run it in pgAdmin on e-peso_db AFTER 085_employer_portal.sql. Change the password below first (use a long random one),
-- then put the same account in config.php:
--
--     'employer_db' => ['host' => 'localhost', 'port' => '5432', 'name' => 'e-peso_db', 'user' => 'employer_app', 'pass' => '<the password>'],
--
-- Safe to run again (it re-applies the same grants). To remove it:
--     DROP OWNED BY employer_app;  DROP ROLE employer_app;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'employer_app') THEN
    CREATE ROLE employer_app LOGIN PASSWORD 'gapo+x/@#$2005Toyang2005.....' NOSUPERUSER NOCREATEDB NOCREATEROLE;
  END IF;
END $$;

-- Nobody but the owner should be able to create objects in the public schema (older databases allow every role to).
-- Your application connects as the owner, so this changes nothing for it.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

REVOKE ALL ON ALL TABLES IN SCHEMA public FROM employer_app;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM employer_app;
GRANT USAGE ON SCHEMA public TO employer_app;

-- Read-only: what employers see (their company, vacancies, referrals, placements) and the applicant form, Sections I-IX.
GRANT SELECT ON
  employers, vacancies, employment_facilitation_referrals, employment_facilitation_placements,
  beneficiaries, beneficiary_services, services, barangays, cities, provinces, regions,
  employment_facilitation_profiles, educations, trainings, applicant_credentials, work_experiences,
  job_preferences, languages, skills, disabilities, attached_documents
TO employer_app;

-- Employer tables: the only things this account may write.
GRANT SELECT ON referral_shared_documents TO employer_app;   -- (run 087_referral_shared_documents.sql first)
GRANT SELECT ON employer_portal_accounts TO employer_app;
GRANT UPDATE (password_hash, must_change_password, last_login, updated_at) ON employer_portal_accounts TO employer_app;
GRANT SELECT, INSERT ON employer_requests TO employer_app;
GRANT UPDATE (status) ON employer_requests TO employer_app;
GRANT SELECT, INSERT, DELETE ON employer_login_attempts TO employer_app;
GRANT INSERT ON employer_access_log TO employer_app;
GRANT USAGE, SELECT ON SEQUENCE employer_requests_request_id_seq, employer_login_attempts_attempt_id_seq, employer_access_log_log_id_seq TO employer_app;
