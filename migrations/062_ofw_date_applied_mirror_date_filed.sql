-- OFW now has a single date: the Date Filed from the paper request form. The
-- OFW form no longer has a separate "Date Applied" field, and the backend
-- (modules/ofw.php) always writes beneficiary_services.date_applied = the
-- record's date_filed so the shared spine column that every program's reports
-- (including the General PESO Report) read stays consistent.
--
-- This brings OFW records saved BEFORE that change in line (e.g. a record filed
-- 2026-07-28 whose Date Applied was entered as 2026-07-15). Safe to re-run:
-- rows that already match are skipped.

-- Preview first (run this alone, check it looks right, THEN run the UPDATE below):
-- SELECT op.reference_no, op.date_filed, bs.date_applied
-- FROM ofw_profiles op
-- JOIN beneficiary_services bs ON bs.beneficiary_service_id = op.beneficiary_service_id
-- WHERE bs.date_applied IS DISTINCT FROM op.date_filed;

UPDATE beneficiary_services bs
SET date_applied = op.date_filed
FROM ofw_profiles op
WHERE op.beneficiary_service_id = bs.beneficiary_service_id
  AND bs.date_applied IS DISTINCT FROM op.date_filed
RETURNING op.reference_no, bs.date_applied;
