-- GIP: track when a batch was actually marked Completed, separate from its
-- (mutable, originally-planned) end_date. Mirrors cdsp_activities.completed_at.
-- The assignment-history "Date Completed" shown to users must reflect the real
-- status-change moment, not end_date, since the real internship period can run
-- past the planned end_date (e.g. absences extending it).

ALTER TABLE gip_batches ADD COLUMN completed_at TIMESTAMP NULL;
