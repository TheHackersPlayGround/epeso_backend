-- Employer portal: employers can ask the PESO to add a new job opening or to change/close one of theirs.
-- Like every employer request, nothing changes in the vacancies until staff accept it (Employers tab -> Employer Updates).
--
-- vacancies.source             where an opening came from: 'Staff' (entered by the PESO, everything that exists today)
--                              or 'Employer' (requested by the employer and accepted by staff). Shown as a tag in the staff list.
-- employer_requests.vacancy_id the opening an edit/close request is about
-- employer_requests.created_vacancy_id  the opening that an accepted "new opening" request created
-- request_type                 now also 'vacancy_new' and 'vacancy_update'
--
-- Run AFTER 085_employer_portal.sql. Safe to run again: every statement is IF NOT EXISTS / re-creates a constraint.
--
-- Rollback (only while no vacancy request exists, or delete those requests first):
--   DELETE FROM employer_requests WHERE request_type IN ('vacancy_new', 'vacancy_update');
--   DROP INDEX IF EXISTS employer_requests_open_vacancy;
--   ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_target_chk;
--   ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_request_type_check;
--   ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_request_type_check CHECK (request_type IN ('referral_status', 'placement_status', 'company_info'));
--   ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_target_chk CHECK (
--        (request_type = 'referral_status'  AND referral_id  IS NOT NULL AND placement_id IS NULL)
--     OR (request_type = 'placement_status' AND placement_id IS NOT NULL AND referral_id  IS NULL)
--     OR (request_type = 'company_info'     AND referral_id  IS NULL     AND placement_id IS NULL));
--   ALTER TABLE employer_requests DROP COLUMN IF EXISTS created_vacancy_id, DROP COLUMN IF EXISTS vacancy_id;
--   ALTER TABLE vacancies DROP CONSTRAINT IF EXISTS chk_vacancy_source;
--   ALTER TABLE vacancies DROP COLUMN IF EXISTS source;

BEGIN;

ALTER TABLE vacancies ADD COLUMN IF NOT EXISTS source VARCHAR(10) NOT NULL DEFAULT 'Staff';
ALTER TABLE vacancies DROP CONSTRAINT IF EXISTS chk_vacancy_source;
ALTER TABLE vacancies ADD CONSTRAINT chk_vacancy_source CHECK (source IN ('Staff', 'Employer'));

ALTER TABLE employer_requests ADD COLUMN IF NOT EXISTS vacancy_id         BIGINT REFERENCES vacancies(vacancy_id) ON DELETE CASCADE;
ALTER TABLE employer_requests ADD COLUMN IF NOT EXISTS created_vacancy_id BIGINT REFERENCES vacancies(vacancy_id) ON DELETE SET NULL;

ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_request_type_check;
ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_request_type_check
  CHECK (request_type IN ('referral_status', 'placement_status', 'company_info', 'vacancy_new', 'vacancy_update'));

-- the request must point at exactly what its type is about
ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_target_chk;
ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_target_chk CHECK (
     (request_type = 'referral_status'  AND referral_id IS NOT NULL AND placement_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'placement_status' AND placement_id IS NOT NULL AND referral_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'company_info'     AND referral_id IS NULL AND placement_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'vacancy_new'      AND referral_id IS NULL AND placement_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'vacancy_update'   AND vacancy_id  IS NOT NULL AND referral_id IS NULL AND placement_id IS NULL)
);

-- one open edit/close request per opening (any number of "new opening" requests may wait at once; the backend caps them)
CREATE UNIQUE INDEX IF NOT EXISTS employer_requests_open_vacancy
  ON employer_requests (vacancy_id) WHERE status = 'Pending' AND vacancy_id IS NOT NULL;

COMMIT;
