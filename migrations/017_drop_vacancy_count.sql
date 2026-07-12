-- Drop the legacy vacancy_count column now that availability is fully derived.
--
-- After migrations 015/016, slots_total is the sole source of truth for openings
-- and "remaining" is computed from active placements. vacancy_count became a
-- dormant mirror of slots_total (no longer decremented, only written to satisfy
-- its NOT NULL role). The app no longer reads it for any logic, so it's removed.
--
-- Runs in one transaction. Making slots_total NOT NULL first guarantees the sole
-- openings column can never be missing. Dropping vacancy_count also removes its
-- obsolete CHECK (vacancies_vacancy_count_check from migration 002). A data-only
-- backup of vacancies was taken before running. Safe to re-run.

BEGIN;

ALTER TABLE vacancies ALTER COLUMN slots_total SET NOT NULL;
ALTER TABLE vacancies DROP COLUMN IF EXISTS vacancy_count;

COMMIT;
