-- GIP: add a free-text "Year Level" field (e.g. "2nd Year", "Grade 10") for
-- applicants whose Highest Educational Attainment is an in-progress level
-- (Elementary/High School/Senior High School/College Level), mirroring
-- CDSP's cdsp_profiles.year_level.

ALTER TABLE gip_profiles ADD COLUMN year_level VARCHAR(50);
