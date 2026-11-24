-- Employer portal: an employer can ask the PESO to record a promotion for one of their active placements.
-- Like every employer request, nothing changes until staff accept it (Employers tab -> Employer Updates); accepting adds a normal
-- promotion record (the same placement_promotions row staff create by hand, with the staff member who accepted it as its author).
--
-- request_type   now also 'placement_promotion' (it points at a placement, like 'placement_status'; the existing "one open request
--                per placement" rule therefore also covers it: a placement cannot have a status request and a promotion request waiting at once)
-- employer_app   may now READ placement_promotions, so the employer's Placements page can show the current job title after a promotion
--
-- Run AFTER 085, 086 and 087. Safe to run again.
--
-- Rollback (delete those requests first):
--   DELETE FROM employer_requests WHERE request_type = 'placement_promotion';
--   ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_target_chk;
--   ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_request_type_check;
--   ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_request_type_check
--     CHECK (request_type IN ('referral_status', 'placement_status', 'company_info', 'vacancy_new', 'vacancy_update'));
--   ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_target_chk CHECK (
--        (request_type = 'referral_status'  AND referral_id IS NOT NULL AND placement_id IS NULL AND vacancy_id IS NULL)
--     OR (request_type = 'placement_status' AND placement_id IS NOT NULL AND referral_id IS NULL AND vacancy_id IS NULL)
--     OR (request_type = 'company_info'     AND referral_id IS NULL AND placement_id IS NULL AND vacancy_id IS NULL)
--     OR (request_type = 'vacancy_new'      AND referral_id IS NULL AND placement_id IS NULL AND vacancy_id IS NULL)
--     OR (request_type = 'vacancy_update'   AND vacancy_id  IS NOT NULL AND referral_id IS NULL AND placement_id IS NULL));

BEGIN;

ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_request_type_check;
ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_request_type_check
  CHECK (request_type IN ('referral_status', 'placement_status', 'company_info', 'vacancy_new', 'vacancy_update', 'placement_promotion'));

-- the request must point at exactly what its type is about
ALTER TABLE employer_requests DROP CONSTRAINT IF EXISTS employer_requests_target_chk;
ALTER TABLE employer_requests ADD CONSTRAINT employer_requests_target_chk CHECK (
     (request_type = 'referral_status'    AND referral_id IS NOT NULL AND placement_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'placement_status'   AND placement_id IS NOT NULL AND referral_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'placement_promotion' AND placement_id IS NOT NULL AND referral_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'company_info'       AND referral_id IS NULL AND placement_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'vacancy_new'        AND referral_id IS NULL AND placement_id IS NULL AND vacancy_id IS NULL)
  OR (request_type = 'vacancy_update'     AND vacancy_id  IS NOT NULL AND referral_id IS NULL AND placement_id IS NULL)
);

-- the limited portal login (created by employer_db_role.sql) may read the promotions of the placements it shows
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'employer_app') THEN
    GRANT SELECT ON placement_promotions TO employer_app;
  END IF;
END $$;

COMMIT;
