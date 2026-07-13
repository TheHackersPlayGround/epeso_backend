-- Simplify SPES batch status to match GIP (Planned/Ongoing/Completed),
-- dropping the Open/Closed distinction and the separate "Application
-- Period" date fields. Neither was ever used to gate any logic — the
-- assign-while-open guard already runs purely off batch status, and the
-- application dates were confirmed dead (stored/displayed only, never
-- read by any backend rule).
BEGIN;

ALTER TABLE spes_batches ALTER COLUMN status DROP DEFAULT;

CREATE TYPE spes_batch_status_enum_new AS ENUM ('Planned', 'Ongoing', 'Completed');

ALTER TABLE spes_batches
  ALTER COLUMN status TYPE spes_batch_status_enum_new
  USING (CASE status::text
           WHEN 'Open' THEN 'Planned'
           WHEN 'Closed' THEN 'Planned'
           ELSE status::text
         END)::spes_batch_status_enum_new;

DROP TYPE spes_batch_status_enum;
ALTER TYPE spes_batch_status_enum_new RENAME TO spes_batch_status_enum;

ALTER TABLE spes_batches ALTER COLUMN status SET DEFAULT 'Planned'::spes_batch_status_enum;

ALTER TABLE spes_batches
  DROP COLUMN application_start_date,
  DROP COLUMN application_end_date;

COMMIT;
