-- Seed the Skills Training lookup table (qualifications + purposes) that the applicant form's
-- checkboxes reference. These were empty (0 rows) before backend integration -- nothing could
-- be selected until these exist.
-- Includes an explicit "Other" row in each type so the free-text "please specify" entries have
-- an option_id to attach to (skills_training_profile_options.other_specification is per-row, so
-- multiple custom entries can all point at the same "Other" option row).
-- Safe to re-run (keyed on the unique (option_type, option_name) pair).

INSERT INTO skills_training_options (option_type, option_name) VALUES
  ('Qualification', 'EIM/ELECTRICAL'),
  ('Qualification', 'COOKERY'),
  ('Qualification', 'FORKLIFT'),
  ('Qualification', 'BREAD AND PASTRY'),
  ('Qualification', 'Other'),
  ('Purpose', 'For employment'),
  ('Purpose', 'For local or overseas work'),
  ('Purpose', 'To start a livelihood / business'),
  ('Purpose', 'To enhance existing skills'),
  ('Purpose', 'Other')
ON CONFLICT (option_type, option_name) DO NOTHING;
