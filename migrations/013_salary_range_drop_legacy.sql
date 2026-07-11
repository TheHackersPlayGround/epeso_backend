-- Drop the legacy free-text salary columns now that vacancies/promotions read
-- from the atomic salary_min/salary_max bounds (migrations 011, 012).
--
-- SAFE BY CONSTRUCTION:
--   * runs in a single transaction (all-or-nothing);
--   * first rescues single-value legacy salaries the range-backfill left NULL
--     (a lone "₱50000" -> min = max = 50000);
--   * then a guard RAISES (aborting the whole transaction, so nothing is
--     dropped) if ANY row still has legacy text with no numeric bounds --
--     i.e. it refuses to lose data.
-- A data-only backup of both tables was taken before running this.

BEGIN;

-- 1. Rescue: lone numeric values (no range dash) become a fixed salary min=max.
UPDATE placement_promotions
SET new_salary_min = regexp_replace(new_salary_range, '[^0-9.]', '', 'g')::NUMERIC,
    new_salary_max = regexp_replace(new_salary_range, '[^0-9.]', '', 'g')::NUMERIC
WHERE new_salary_min IS NULL AND new_salary_max IS NULL
  AND regexp_replace(new_salary_range, '[^0-9.]', '', 'g') ~ '^[0-9.]+$'
  AND regexp_replace(new_salary_range, '[^0-9.]', '', 'g') <> '';

UPDATE vacancies
SET salary_min = regexp_replace(salary_range, '[^0-9.]', '', 'g')::NUMERIC,
    salary_max = regexp_replace(salary_range, '[^0-9.]', '', 'g')::NUMERIC
WHERE salary_min IS NULL AND salary_max IS NULL
  AND regexp_replace(salary_range, '[^0-9.]', '', 'g') ~ '^[0-9.]+$'
  AND regexp_replace(salary_range, '[^0-9.]', '', 'g') <> '';

-- 2. Guard: abort if any row would lose salary info (non-empty text, no bounds).
DO $$
DECLARE n integer;
BEGIN
    SELECT count(*) INTO n FROM (
        SELECT 1 FROM vacancies
         WHERE coalesce(trim(salary_range), '') <> '' AND salary_min IS NULL AND salary_max IS NULL
        UNION ALL
        SELECT 1 FROM placement_promotions
         WHERE coalesce(trim(new_salary_range), '') <> '' AND new_salary_min IS NULL AND new_salary_max IS NULL
    ) x;
    IF n > 0 THEN
        RAISE EXCEPTION 'Aborting drop: % row(s) still have salary text with no numeric bounds. Resolve them first.', n;
    END IF;
END $$;

-- 3. Safe to remove the legacy columns.
ALTER TABLE vacancies             DROP COLUMN salary_range;
ALTER TABLE placement_promotions  DROP COLUMN new_salary_range;

COMMIT;
