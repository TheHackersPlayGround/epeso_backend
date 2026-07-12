-- Reset legacy auto-closed vacancies so the derived availability model governs.
--
-- Before migration 015, vacancies.status was set to 'Closed' automatically when
-- the vacancy filled up (vacancy_count hit 0). Under the new model, status means
-- ONLY the officer's manual open/close intent -- fullness is derived (slots_total
-- minus active placements). So those stored 'Closed' values are stale artifacts
-- of the old auto-close, not real "an officer closed this" decisions.
--
-- This establishes a clean baseline: every vacancy starts manually Open, and the
-- effective status is computed (Closed only when manually closed OR full). A
-- vacancy that is genuinely full still shows Closed via the derived rule, and now
-- correctly REOPENS when a placement ends. Assumes no deliberate manual closures
-- exist yet (true for this dataset -- all closures were automatic). Safe to re-run.

UPDATE vacancies SET status = 'Open', updated_at = now()
WHERE status = 'Closed';
