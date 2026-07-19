-- CLPEP and SLP profile status should reuse the existing shared
-- profile_status_enum (already used by cdsp_profiles/gip_profiles/
-- spes_profiles) instead of each having their own separate enum type.
-- Also trims the shared enum's unused 'Cancelled' value down to the
-- 3-value derived model (Inactive/Active/Completed) that DILP/TUPAD
-- already compute from assignment status -- no row anywhere currently
-- uses 'Cancelled', so this is safe.
BEGIN;

ALTER TABLE cdsp_profiles ALTER COLUMN status DROP DEFAULT;
ALTER TABLE gip_profiles ALTER COLUMN status DROP DEFAULT;
ALTER TABLE spes_profiles ALTER COLUMN status DROP DEFAULT;
ALTER TABLE clpep_profiles ALTER COLUMN status DROP DEFAULT;
ALTER TABLE slp_profiles ALTER COLUMN status DROP DEFAULT;

CREATE TYPE profile_status_enum_new AS ENUM ('Active', 'Inactive', 'Completed');

ALTER TABLE cdsp_profiles
  ALTER COLUMN status TYPE profile_status_enum_new
  USING status::text::profile_status_enum_new;

ALTER TABLE gip_profiles
  ALTER COLUMN status TYPE profile_status_enum_new
  USING status::text::profile_status_enum_new;

ALTER TABLE spes_profiles
  ALTER COLUMN status TYPE profile_status_enum_new
  USING status::text::profile_status_enum_new;

ALTER TABLE clpep_profiles
  ALTER COLUMN status TYPE profile_status_enum_new
  USING (CASE status::text
           WHEN 'Pending' THEN 'Inactive'
           WHEN 'Closed' THEN 'Inactive'
           ELSE status::text
         END)::profile_status_enum_new;

ALTER TABLE slp_profiles
  ALTER COLUMN status TYPE profile_status_enum_new
  USING (CASE status::text
           WHEN 'Dropped' THEN 'Inactive'
           ELSE status::text
         END)::profile_status_enum_new;

DROP TYPE profile_status_enum;
DROP TYPE clpep_profile_status_enum;
DROP TYPE slp_profile_status_enum;
ALTER TYPE profile_status_enum_new RENAME TO profile_status_enum;

ALTER TABLE cdsp_profiles ALTER COLUMN status SET DEFAULT 'Active'::profile_status_enum;
ALTER TABLE gip_profiles ALTER COLUMN status SET DEFAULT 'Inactive'::profile_status_enum;
ALTER TABLE spes_profiles ALTER COLUMN status SET DEFAULT 'Inactive'::profile_status_enum;
ALTER TABLE clpep_profiles ALTER COLUMN status SET DEFAULT 'Inactive'::profile_status_enum;
ALTER TABLE slp_profiles ALTER COLUMN status SET DEFAULT 'Inactive'::profile_status_enum;

COMMIT;
