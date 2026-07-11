-- Normalize salary ranges to First Normal Form.
--
-- A salary range is two facts (a lower and an upper bound), so the single
-- free-text column (vacancies.salary_range / placement_promotions.new_salary_range)
-- packs two values into one field -- a 1NF violation that can't be queried,
-- sorted, or validated. This adds atomic, typed min/max columns.
--
-- NON-DESTRUCTIVE / reversible: the original text columns are KEPT and the app
-- keeps them populated in parallel during the transition, so existing display
-- and export code is unaffected. A later migration drops them once the app reads
-- exclusively from min/max. Safe to re-run (IF NOT EXISTS + guarded constraints).

-- 1. Atomic, typed bound columns (nullable during transition).
ALTER TABLE vacancies
    ADD COLUMN IF NOT EXISTS salary_min NUMERIC(12,2),
    ADD COLUMN IF NOT EXISTS salary_max NUMERIC(12,2);

ALTER TABLE placement_promotions
    ADD COLUMN IF NOT EXISTS new_salary_min NUMERIC(12,2),
    ADD COLUMN IF NOT EXISTS new_salary_max NUMERIC(12,2);

-- 2. Backfill ONLY well-formed "<number>-<number>" values. The cleaner strips
--    everything except digits, dot, and dash (so ₱, commas, spaces are ignored);
--    a value that isn't exactly num-num (single value, "Negotiable", en-dash,
--    blank) is left NULL so no existing row is corrupted -- review those by hand.
UPDATE vacancies
SET salary_min = split_part(regexp_replace(salary_range, '[^0-9.-]', '', 'g'), '-', 1)::NUMERIC,
    salary_max = split_part(regexp_replace(salary_range, '[^0-9.-]', '', 'g'), '-', 2)::NUMERIC
WHERE regexp_replace(salary_range, '[^0-9.-]', '', 'g') ~ '^[0-9.]+-[0-9.]+$'
  AND salary_min IS NULL;

UPDATE placement_promotions
SET new_salary_min = split_part(regexp_replace(new_salary_range, '[^0-9.-]', '', 'g'), '-', 1)::NUMERIC,
    new_salary_max = split_part(regexp_replace(new_salary_range, '[^0-9.-]', '', 'g'), '-', 2)::NUMERIC
WHERE regexp_replace(new_salary_range, '[^0-9.-]', '', 'g') ~ '^[0-9.]+-[0-9.]+$'
  AND new_salary_min IS NULL;

-- 3. Integrity rules the string form could never enforce. Guarded so re-runs are safe.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_vacancy_salary') THEN
        ALTER TABLE vacancies ADD CONSTRAINT chk_vacancy_salary
            CHECK (salary_min IS NULL OR salary_max IS NULL OR salary_min <= salary_max);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_promotion_salary') THEN
        ALTER TABLE placement_promotions ADD CONSTRAINT chk_promotion_salary
            CHECK (new_salary_min IS NULL OR new_salary_max IS NULL OR new_salary_min <= new_salary_max);
    END IF;
END $$;

-- Transition note: the old text columns are intentionally retained. Once the app
-- reads exclusively from the min/max columns, a future migration can run:
--   ALTER TABLE vacancies DROP COLUMN salary_range;
--   ALTER TABLE placement_promotions DROP COLUMN new_salary_range;
