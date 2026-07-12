-- Store the ORIGINAL number of openings solicited, separately from the mutable
-- "remaining" counter.
--
-- Background: vacancies.vacancy_count is a decrementing "remaining slots" cache
-- (the hire flow does vacancy_count = vacancy_count - 1 and auto-closes at 0). It
-- is derived data, and it drifts: it is never restored when a placement ends, so
-- it does not reflect true availability, and the report's "Jobs Solicited" reads
-- 0 for a filled vacancy. The clean model stores only the fact (slots_total =
-- original openings) and DERIVES remaining from active placements.
--
-- This migration is additive and safe: it adds slots_total and reconstructs it
-- for existing rows as (current remaining) + (all placements ever made against
-- the vacancy) -- each hire decremented vacancy_count exactly once, so this
-- recovers the original count. vacancy_count is kept (now dormant) and dropped in
-- a later migration once the app no longer reads it. Safe to re-run.

ALTER TABLE vacancies ADD COLUMN IF NOT EXISTS slots_total INTEGER;

-- Reconstruct the original openings for existing rows.
UPDATE vacancies v
SET slots_total = v.vacancy_count
    + COALESCE((SELECT COUNT(*) FROM employment_facilitation_placements p
                WHERE p.vacancy_id = v.vacancy_id), 0)
WHERE slots_total IS NULL;

-- Openings can't be negative.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_vacancy_slots_total') THEN
        ALTER TABLE vacancies ADD CONSTRAINT chk_vacancy_slots_total
            CHECK (slots_total IS NULL OR slots_total >= 0);
    END IF;
END $$;
