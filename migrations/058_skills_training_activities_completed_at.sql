-- Skills Training activities have no completed_at column, which is the same
-- gap already fixed for GIP/SPES batches and for CDSP activities: without it,
-- "when was this actually completed" has to be read from activity_date (the
-- originally-scheduled date) or updated_at (which shifts on every status   
-- change, including a later reopen + re-complete). Neither is a stable
-- completion timestamp. Mirrors cdsp_activities.completed_at exactly.

ALTER TABLE skills_training_activities ADD COLUMN completed_at TIMESTAMP NULL;
