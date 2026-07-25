-- Splits each program's single permission into "records" (applicant/profile
-- access, unchanged) and a new "<program>-maintenance" permission (batch/
-- activity/project management), so an admin can grant either independently.
-- Previously CDSP/SPES/Livelihood's Maintenance screens were gated on the
-- frontend by the generic 'maintenance' permission while the backend actually
-- enforced the program's own permission -- a real mismatch. GIP/Skills
-- Training were already correct (both sides check 'gip'/'skills'). This
-- migration + the paired requirePermission() call-site swaps in the PHP
-- modules make all five programs consistent and independently grantable.
--
-- The old generic 'maintenance' permission (id 9) is intentionally left in
-- place (harmless, avoids FK-cascade cleanup of any existing user_permissions
-- rows referencing it) -- it just stops being referenced by any endpoint.

INSERT INTO permissions (permission_name, description, is_active, created_at) VALUES
  ('cdsp-maintenance',       'CDSP Maintenance (Services & Activities)',           true, now()),
  ('spes-maintenance',       'SPES Maintenance (Batches)',                        true, now()),
  ('gip-maintenance',        'GIP Maintenance (Batches)',                         true, now()),
  ('livelihood-maintenance', 'Livelihood Maintenance (Projects/Interventions)',   true, now()),
  ('skills-maintenance',     'Skills Training Maintenance (Batches & Activities)', true, now())
ON CONFLICT (permission_name) DO NOTHING;

-- Backfill: everyone who currently holds cdsp/spes/gip/livelihood/skills at
-- some level gets the same level auto-granted on the matching new
-- '-maintenance' permission, so no existing account loses batch/activity
-- management access the moment this ships.
INSERT INTO user_permissions (user_id, permission_id, permission_level)
SELECT up.user_id, newp.permission_id, up.permission_level
FROM user_permissions up
JOIN permissions oldp ON oldp.permission_id = up.permission_id
JOIN permissions newp ON newp.permission_name = oldp.permission_name || '-maintenance'
WHERE oldp.permission_name IN ('cdsp', 'spes', 'gip', 'livelihood', 'skills')
ON CONFLICT (user_id, permission_id) DO NOTHING;
