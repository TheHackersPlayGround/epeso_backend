-- Fix: allow a vacancy's remaining-slot count to reach 0.
--
-- vacancy_count tracks REMAINING openings. When the last applicant is hired the
-- app decrements it to 0 and sets status = 'Closed'. The original CHECK required
-- vacancy_count > 0, so hiring the final applicant violated the constraint and
-- the whole hire transaction rolled back. Allow 0 (a full / closed vacancy).

ALTER TABLE vacancies DROP CONSTRAINT IF EXISTS vacancies_vacancy_count_check;
ALTER TABLE vacancies ADD CONSTRAINT vacancies_vacancy_count_check CHECK (vacancy_count >= 0);
