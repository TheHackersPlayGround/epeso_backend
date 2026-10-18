-- Merges skills_training_batches into skills_training_activities: a training
-- now carries its own batch name directly instead of pointing at a separate
-- batch record. The Add/Edit Training screen's "Training Batch" field is a
-- plain typed field, not a picker, so a separate batch entity to pick from
-- no longer serves a purpose -- see the paired PHP/frontend changes in this
-- same commit.
--
-- Also drops skills_training_profiles.batch_id, a denormalized column that
-- was kept in sync on every assign/unassign but never actually read anywhere
-- in the app -- confirmed by grepping the full backend and frontend before
-- writing this migration.
--
-- Tested end to end on a disposable clone of the live database before being
-- written here: the backfill step below was verified to reproduce every
-- training's batch name exactly, with no mismatches, before the old table
-- and columns were dropped on that clone.

BEGIN;

-- 1. Add the new column, nullable for now so it can be backfilled first.
ALTER TABLE skills_training_activities ADD COLUMN batch_name character varying(255);

-- 2. Backfill every existing training's batch_name from its real, current
--    link -- an exact copy via the ID relationship, not retyped text, so
--    this step cannot introduce a mismatch.
UPDATE skills_training_activities a
SET batch_name = b.batch_name
FROM skills_training_batches b
WHERE b.batch_id = a.batch_id;

-- 3. Safety check: every row must now have a batch_name. Aborts the whole
--    transaction (ROLLBACK) if anything was missed, rather than silently
--    leaving a training with no batch name.
DO $$
DECLARE missing_count integer;
BEGIN
  SELECT count(*) INTO missing_count FROM skills_training_activities WHERE batch_name IS NULL;
  IF missing_count > 0 THEN
    RAISE EXCEPTION 'Backfill incomplete: % training row(s) have no batch_name', missing_count;
  END IF;
END $$;

-- 4. Now safe to require it going forward.
ALTER TABLE skills_training_activities ALTER COLUMN batch_name SET NOT NULL;

-- 5. Drop the old link to the batches table.
ALTER TABLE skills_training_activities DROP CONSTRAINT skills_training_activities_batch_id_fkey;
ALTER TABLE skills_training_activities DROP COLUMN batch_id;

-- 6. Drop the dead, write-only column on profiles (and its link).
ALTER TABLE skills_training_profiles DROP CONSTRAINT skills_training_profiles_batch_id_fkey;
ALTER TABLE skills_training_profiles DROP COLUMN batch_id;

-- 7. The batches table itself is no longer referenced by anything -- drop it.
DROP TABLE skills_training_batches;

COMMIT;
