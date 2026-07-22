-- Correct the OFW case-processing workflow order. This is not an official
-- documented DOLE/PESO process, just an internally-designed workflow -- the
-- original order (Pending -> Ongoing -> Approved -> Completed) had a case
-- being actively worked before it was ever approved to proceed, which reads
-- backwards. Reordered to Pending -> Approved -> Ongoing -> Completed
-- (Rejected branches off separately). Postgres has no direct "reorder enum
-- values" command, so this drops and recreates the type -- safe since
-- ofw_profiles has 0 rows at time of writing.
ALTER TABLE ofw_profiles ALTER COLUMN status DROP DEFAULT;

CREATE TYPE ofw_status_enum_new AS ENUM ('Pending', 'Approved', 'Ongoing', 'Completed', 'Rejected');

ALTER TABLE ofw_profiles
  ALTER COLUMN status TYPE ofw_status_enum_new
  USING status::text::ofw_status_enum_new;

DROP TYPE ofw_status_enum;
ALTER TYPE ofw_status_enum_new RENAME TO ofw_status_enum;

ALTER TABLE ofw_profiles ALTER COLUMN status SET DEFAULT 'Pending'::ofw_status_enum;
