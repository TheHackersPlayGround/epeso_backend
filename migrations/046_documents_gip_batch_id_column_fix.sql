-- Completes migration 043 (documents_batch_id_rename.sql), which only
-- partially applied on this database: the foreign key constraint had already
-- been renamed to documents_gip_batch_id_fkey, but the underlying column was
-- still named `batch_id` instead of `gip_batch_id`. Every gip.php query that
-- looked up a batch's attached documents (e.g. "WHERE gip_batch_id = :id")
-- was therefore hitting a column that didn't exist, crashing GIP's batch
-- list/create/update/delete endpoints outright (the frontend then silently
-- showed an empty batch list instead of surfacing the error).
--
-- Wrapped in a existence check so this is safe to run regardless of whether
-- 043 already fully applied on a given environment.
DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'documents' AND column_name = 'batch_id'
  ) THEN
    ALTER TABLE documents RENAME COLUMN batch_id TO gip_batch_id;
  END IF;
END $$;
