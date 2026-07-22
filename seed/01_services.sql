-- Seed services tree (GIP, SPES, CDSP, OFW, EF, CLPEP, SLP, DILP, TUPAD, Skills)
-- One row per program. beneficiary_services.service_id points here, so every
-- program a beneficiary is enrolled in must exist as a row below.
-- Safe to re-run (keyed on the unique service_code).

INSERT INTO services (service_code, service_name, is_active, created_at, updated_at) VALUES
  ('EF',     'Employment Facilitation',                        true, now(), now()),
  ('CDSP',   'Career Development Support Program',              true, now(), now()),
  ('GIP',    'Government Internship Program',                   true, now(), now()),
  ('SPES',   'Special Program for Employment of Students',      true, now(), now()),
  ('SLP',    'Sustainable Livelihood Program',                 true, now(), now()),
  ('DILP',   'DOLE Integrated Livelihood Program',             true, now(), now()),
  ('TUPAD',  'Tulong Panghanapbuhay sa Disadvantaged Workers', true, now(), now()),
  ('CLPEP',  'Child Labor Prevention and Elimination Program',  true, now(), now()),
  ('OFW',    'OFW Assistance',                                 true, now(), now()),
  ('SKILLS', 'Skills Training',                                 true, now(), now())
ON CONFLICT (service_code) DO NOTHING;

-- CDSP's own sub-services (what the profile form's "Service Availed" section
-- offers). Looked up by service_code, not a hardcoded id, since insertion
-- order/sequence state can differ between environments.
INSERT INTO services (service_code, service_name, parent_service_id, is_active, created_at, updated_at) VALUES
  ('CDSP-CC',   'Career Coaching',                          (SELECT service_id FROM services WHERE service_code='CDSP'), true, now(), now()),
  ('CDSP-PEC',  'Pre-Employment Coaching',                   (SELECT service_id FROM services WHERE service_code='CDSP'), true, now(), now()),
  ('CDSP-LEGS', 'Labor Employment for Graduating Students',  (SELECT service_id FROM services WHERE service_code='CDSP'), true, now(), now())
ON CONFLICT (service_code) DO NOTHING;
