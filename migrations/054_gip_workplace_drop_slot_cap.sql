-- Client requested no capacity limit on GIP workplaces -- an admin can assign
-- as many applicants to a workplace/office as needed. slot_count and its
-- check constraint are dropped entirely.

ALTER TABLE gip_workplaces DROP CONSTRAINT gip_workplaces_slot_count_check;
ALTER TABLE gip_workplaces DROP COLUMN slot_count;
