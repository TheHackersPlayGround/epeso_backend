-- Drop the unconstrained, FK-less educational_attainment_id in favor of the
-- shared beneficiaries.educational_attainment enum (already exists, unused
-- by any module) -- avoids building a redundant lookup table for a value
-- that already has a clean, shared home on the beneficiary spine.
ALTER TABLE slp_profiles DROP COLUMN IF EXISTS educational_attainment_id;
