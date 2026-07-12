-- CDSP duplicated beneficiary_services.date_applied into its own
-- cdsp_profiles.date_received column, and only the CDSP-local copy was kept
-- in sync on edits — the two could drift apart, and the extra column caused
-- a schema-mismatch error on environments where it wasn't present. CDSP now
-- reads/writes beneficiary_services.date_applied only, matching GIP/SPES.
ALTER TABLE cdsp_profiles DROP COLUMN date_received;
