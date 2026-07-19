-- Skills Training's applicant form has an Accepted/Waitlisted status dropdown
-- with no column to store it in. Reusing the existing shared application_status_enum
-- (already used by spes_profiles.application_status for the same concept) instead
-- of the unused skills_training_application_status_enum duplicate that already
-- sits in the DB unreferenced.
ALTER TABLE skills_training_profiles
  ADD COLUMN application_status application_status_enum NOT NULL DEFAULT 'Waitlisted';
