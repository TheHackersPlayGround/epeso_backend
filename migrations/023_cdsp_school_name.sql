-- CDSP's Educational Background lacked a School / University field (the
-- frontend type already had a schoolName property but the backend never
-- persisted it — always returned ''). Adding it, matching GIP's
-- gip_profiles.school_name column.
ALTER TABLE cdsp_profiles ADD COLUMN school_name character varying(255);
