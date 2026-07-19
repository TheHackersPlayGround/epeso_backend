-- "Undergraduate" is conventionally a college-level term; applying it to
-- Elementary/Junior High/Senior High/College non-graduates reads oddly.
-- Renamed to "...Level" to match GIP/CDSP's frontend wording more closely
-- (their gipMapEducation()/cdspMapEducation() translation layers are updated
-- in the same change). Also adds 'Post Graduate' for SLP's own dropdown --
-- purely additive, not used by GIP/CDSP's existing maps.
ALTER TYPE educational_attainment_enum RENAME VALUE 'Elementary Undergraduate' TO 'Elementary Level';
ALTER TYPE educational_attainment_enum RENAME VALUE 'Junior High School Undergraduate' TO 'Junior High School Level';
ALTER TYPE educational_attainment_enum RENAME VALUE 'Senior High School Undergraduate' TO 'Senior High School Level';
ALTER TYPE educational_attainment_enum RENAME VALUE 'College Undergraduate' TO 'College Level';
ALTER TYPE educational_attainment_enum ADD VALUE IF NOT EXISTS 'Post Graduate';
