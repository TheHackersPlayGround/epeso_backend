-- Seed permissions rows
-- One row per dashboard module. The Viewer/Editor level is stored per user in
-- user_permissions.permission_level (NOT here). The frontend sends view-/manage-
-- names, which the backend collapses to one module row + level. Safe to re-run.

INSERT INTO permissions (permission_name, description, is_active, created_at) VALUES
  ('employment',  'Employment Facilitation', true, now()),
  ('cdsp',        'CDSP',                    true, now()),
  ('gip',         'GIP',                     true, now()),
  ('spes',        'SPES',                    true, now()),
  ('livelihood',  'Livelihood',              true, now()),
  ('skills',      'Skills Training',         true, now()),
  ('ofw',         'OFW',                     true, now()),
  ('documents',   'Documents',               true, now()),
  ('maintenance', 'Maintenance',             true, now()),
  ('security',    'Security',                true, now()),
  ('report',      'Report',                  true, now())
ON CONFLICT (permission_name) DO NOTHING;
