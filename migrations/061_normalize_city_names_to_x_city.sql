-- Normalizes city_name from the legal/charter form ("City of X") to the
-- everyday/addressing form ("X City") used in postal addresses and every
-- other PSGC-derived civic reference dataset (e.g. "Danao City", "Cebu
-- City", "General Santos City"), to match how applicant addresses actually
-- read elsewhere in the app (formatAddress() in ReportView.tsx) and how
-- Mabalacat City / Quezon City are already stored.
--
-- Only rows that literally start with "City of " are touched -- this
-- naturally EXCLUDES the two cities whose real name doesn't fit either
-- pattern ("Island Garden City of Samal", "Science City of Muñoz"), so no
-- explicit exclusion list is needed.
--
-- Safe to re-run: after the first run no row still starts with "City of ",
-- so the WHERE clause matches nothing on a second run.

-- Preview first (run this alone, check it looks right, THEN run the UPDATE below):
-- SELECT city_id, city_name,
--        regexp_replace(city_name, '^City of (.+)$', '\1 City') AS would_become
-- FROM cities
-- WHERE city_name LIKE 'City of %'
-- ORDER BY city_name;

UPDATE cities
SET city_name = regexp_replace(city_name, '^City of (.+)$', '\1 City')
WHERE city_name LIKE 'City of %'
RETURNING city_id, city_name;
