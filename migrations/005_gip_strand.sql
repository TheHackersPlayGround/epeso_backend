-- GIP: add a "Strand" field for Senior High School attainments, mirroring
-- CDSP's cdsp_profiles.strand. Course/Degree remains for College/Master's/
-- Doctoral attainments only; Strand and Course/Degree are mutually exclusive
-- in the frontend based on Highest Educational Attainment.

ALTER TABLE gip_profiles ADD COLUMN strand VARCHAR(100);
