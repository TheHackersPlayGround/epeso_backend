-- Seed the Skills Training lookup tables (qualifications + purposes) that the
-- applicant form's checkboxes reference. These were empty (0 rows) before
-- backend integration -- nothing could be selected until these exist.
-- Includes an explicit "Other" row in each so the free-text "please specify"
-- entries have a qualification_id/purpose_id to attach to (the junction
-- tables' other_qualification/other_purpose columns are per-row, so multiple
-- custom entries can all point at the same "Other" lookup row).
-- Safe to re-run (keyed on the unique name columns).

INSERT INTO skills_training_qualifications (qualification_name) VALUES
  ('EIM/ELECTRICAL'),
  ('COOKERY'),
  ('FORKLIFT'),
  ('BREAD AND PASTRY'),
  ('Other')
ON CONFLICT (qualification_name) DO NOTHING;

INSERT INTO skills_training_purposes (purpose_name) VALUES
  ('For employment'),
  ('For local or overseas work'),
  ('To start a livelihood / business'),
  ('To enhance existing skills'),
  ('Other')
ON CONFLICT (purpose_name) DO NOTHING;
