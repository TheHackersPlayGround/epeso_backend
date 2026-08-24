-- Client requested GIP workplaces work like EF's Employer: a permanent
-- directory entry (name, address, supervisor, slots, funding) that gets
-- reused across many deployment rounds, instead of a one-time record with
-- its own period + status. Each applicant now carries their own start date
-- (workplace_assigned_at, already existed) and their own end date
-- (workplace_completed_at, new) instead of sharing the workplace's dates.
-- Workplace status is no longer stored -- it's computed on read from whether
-- any applicant is currently Active there.

ALTER TABLE gip_profiles ADD COLUMN workplace_completed_at TIMESTAMP NULL;

-- Backfill: applicants already marked Completed inherit their (former) shared
-- workplace's completed_at as their own individual completion date, so no
-- historical completion date is lost.
UPDATE gip_profiles gp
SET workplace_completed_at = gw.completed_at
FROM gip_workplaces gw
WHERE gp.workplace_id = gw.workplace_id
  AND gp.status = 'Completed'
  AND gp.workplace_completed_at IS NULL;

ALTER TABLE gip_workplaces DROP CONSTRAINT gip_workplaces_check;
ALTER TABLE gip_workplaces DROP COLUMN start_date;
ALTER TABLE gip_workplaces DROP COLUMN end_date;
ALTER TABLE gip_workplaces DROP COLUMN status;
ALTER TABLE gip_workplaces DROP COLUMN completed_at;
