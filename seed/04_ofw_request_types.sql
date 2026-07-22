-- Seed the OFW "Type of Request" lookup table. Values copied verbatim from
-- REQUEST_TYPES in AddOFWRequestForm.tsx / OFWProfileModal.tsx (both files
-- keep this list identical) -- exact wording/casing/punctuation matters since
-- these are the literal strings the frontend checkboxes send.
-- Safe to re-run (keyed on the unique request_type_name).

INSERT INTO ofw_request_types (request_type_name) VALUES
  ('employment referral'),
  ('skills training'),
  ('on-line services'),
  ('registration'),
  ('accreditation'),
  ('annual report repatriation'),
  ('OWWA Scholarship – ODSP/EDSP'),
  ('OWWA Benefits'),
  ('OWWA Welfare Case'),
  ('inquiry (pls specify)'),
  ('application letter and resume-making'),
  ('re-integration program for OFWs'),
  ('free clearance for 1st-time jobseekers'),
  ('labor Market Information (LMI)'),
  ('livelihood'),
  ('Job Vacancy for posting'),
  ('other DOLE program (please specify)')
ON CONFLICT (request_type_name) DO NOTHING;
