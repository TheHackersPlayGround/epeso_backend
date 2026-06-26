-- Seed permissions rows
-- permission_name values match the frontend permission IDs in
-- SystemUsersTab.tsx (permissionGroups). Safe to re-run.

INSERT INTO permissions (permission_name, description, is_active, created_at) VALUES
  ('view-applicants',     'View Applicants',             true, now()),
  ('add-applicant',       'Add Applicant',               true, now()),
  ('edit-applicant',      'Edit Applicant',              true, now()),
  ('delete-applicant',    'Delete Applicant',            true, now()),
  ('view-employers',      'View Employers',              true, now()),
  ('add-employer',        'Add Employer',                true, now()),
  ('edit-employer',       'Edit Employer',               true, now()),
  ('delete-employer',     'Delete Employer',             true, now()),
  ('view-programs',       'View Programs',               true, now()),
  ('add-program',         'Add Program/Event',           true, now()),
  ('edit-program',        'Edit Program/Event',          true, now()),
  ('delete-program',      'Delete Program/Event',        true, now()),
  ('access-maintenance',  'Access Maintenance Page',     true, now()),
  ('add-records',         'Add Records',                 true, now()),
  ('edit-records',        'Edit Records',                true, now()),
  ('delete-records',      'Delete Records',              true, now()),
  ('view-activity-log',   'View Activity Log',           true, now()),
  ('export-activity-log', 'Export Activity Log',         true, now()),
  ('view-reports',        'View Reports',                true, now()),
  ('generate-reports',    'Generate Reports',            true, now()),
  ('export-reports',      'Export Reports',              true, now()),
  ('view-users',          'View Users',                  true, now()),
  ('add-user',            'Add User',                    true, now()),
  ('edit-user',           'Edit User',                   true, now()),
  ('delete-user',         'Delete User',                 true, now()),
  ('assign-roles',        'Assign Roles & Permissions',  true, now()),
  ('access-settings',     'Access System Settings',      true, now()),
  ('modify-config',       'Modify System Configuration', true, now())
ON CONFLICT (permission_name) DO NOTHING;
