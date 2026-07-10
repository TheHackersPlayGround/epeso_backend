-- SPES: spes_batches has no completed_at column, which would force the same
-- bug GIP had (and fixed) where "Date Completed" was read from program_end_date
-- -- a mutable, originally-planned date that can differ from when the batch
-- was actually marked Completed (e.g. absences extending the real period).
-- Adding this now, before any SPES batch data exists, avoids reproducing a
-- known-fixed bug from day one.

ALTER TABLE spes_batches ADD COLUMN completed_at TIMESTAMP NULL;
