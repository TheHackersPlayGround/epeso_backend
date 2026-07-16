-- Redundant with participant_count (a numeric headcount already captures
-- individual-vs-group; a separate Individual/Group flag that doesn't
-- constrain that number was confusing, not informative).
ALTER TABLE tupad_projects DROP COLUMN beneficiary_type;
