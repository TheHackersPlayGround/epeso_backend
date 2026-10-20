-- Employers: keep the industry as a plain text column and drop the industries table.
--
-- industries only held a name. Nothing in the application managed it (no add / rename /
-- deactivate screen; is_active was never changed), the industry list in the forms is fixed in
-- the code, and "Other" already saved whatever the user typed. So the name now lives directly
-- on the employer, the same way business_type already does.
--
-- Existing employers keep their industry: each name is copied across before the old
-- column and table are dropped.
--
-- Run this at the same time as the updated PHP (modules/employment.php): the old code reads
-- employers.industry_id and the industries table, the new code reads employers.industry.
-- Run it ONCE -- it is not safe to repeat (the old column and table are gone afterwards).
-- It is a single transaction, so if anything fails, nothing is changed.

BEGIN;

ALTER TABLE employers ADD COLUMN industry varchar(100);

UPDATE employers e
SET industry = i.industry_name
FROM industries i
WHERE i.industry_id = e.industry_id;

ALTER TABLE employers DROP COLUMN industry_id;   -- also drops its foreign key to industries
DROP TABLE industries;

COMMIT;
