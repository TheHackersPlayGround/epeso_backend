-- Drop 27 enum types that nothing uses.
--
-- Checked against the live e-peso_db: none of these types is used by any table
-- column, view, function, domain or composite type, and pg_depend shows no other
-- object depending on them (constraints, defaults, ...). They are leftovers from
-- earlier designs that were replaced by shared enums (e.g. profile_status_enum,
-- application_status_enum, project_status_enum) or by lookup tables. The backend
-- and frontend code do not reference them either.
--
-- Note: migrations 031 and 038 already dropped / abandoned some of these
-- (clpep_profile_status_enum, slp_profile_status_enum,
-- skills_training_application_status_enum) yet they are still in the database,
-- most likely recreated by restoring an older backup.
--
-- Plain DROP TYPE (no CASCADE) on purpose: if any type turns out to still have a
-- dependent object, the statement errors and the whole migration rolls back,
-- leaving the database unchanged. Safe to re-run: IF EXISTS skips types that are
-- already gone.
--
-- Rollback: see the commented CREATE TYPE block at the bottom.

BEGIN;

DROP TYPE IF EXISTS assessment_result_enum;
DROP TYPE IF EXISTS attendance_status_enum;
DROP TYPE IF EXISTS beneficiary_type_enum;
DROP TYPE IF EXISTS cdsp_application_status_enum;
DROP TYPE IF EXISTS cdsp_educational_attainment_enum;
DROP TYPE IF EXISTS cdsp_service_availed_enum;
DROP TYPE IF EXISTS cdsp_status_enum;
DROP TYPE IF EXISTS child_labor_status_enum;
DROP TYPE IF EXISTS clpep_profile_status_enum;
DROP TYPE IF EXISTS clpep_relationship_enum;
DROP TYPE IF EXISTS completion_status_enum;
DROP TYPE IF EXISTS dilp_beneficiary_type_enum;
DROP TYPE IF EXISTS eligibility_type_enum;
DROP TYPE IF EXISTS gip_status_enum;
DROP TYPE IF EXISTS intervention_status_enum;
DROP TYPE IF EXISTS participant_type_enum;
DROP TYPE IF EXISTS referral_required_enum;
DROP TYPE IF EXISTS school_status_enum;
DROP TYPE IF EXISTS skills_training_application_status_enum;
DROP TYPE IF EXISTS slp_profile_status_enum;
DROP TYPE IF EXISTS slp_sector_classification_enum;
DROP TYPE IF EXISTS spes_status_enum;
DROP TYPE IF EXISTS training_status_enum;
DROP TYPE IF EXISTS tupad_beneficiary_type_enum;
DROP TYPE IF EXISTS tupad_status_enum;
DROP TYPE IF EXISTS type_of_employment_enum;
DROP TYPE IF EXISTS vulnerability_severity_enum;

COMMIT;

-- ---------------------------------------------------------------------------
-- ROLLBACK (only if you ever need the types back; nothing in the app uses them):
--
-- CREATE TYPE assessment_result_enum AS ENUM ('Qualified', 'Not Qualified');
-- CREATE TYPE attendance_status_enum AS ENUM ('Enrolled', 'Present', 'Absent', 'Dropped');
-- CREATE TYPE beneficiary_type_enum AS ENUM ('Individual', 'Group');
-- CREATE TYPE cdsp_application_status_enum AS ENUM ('Active', 'Completed', 'Dropped');
-- CREATE TYPE cdsp_educational_attainment_enum AS ENUM ('No Formal Education', 'Elementary Level', 'Elementary Graduate', 'High School Level', 'High School Graduate', 'Vocational', 'College Level', 'College Graduate', 'Post Graduate');
-- CREATE TYPE cdsp_service_availed_enum AS ENUM ('Career Coaching', 'Pre-Employment Coaching', 'Labor Employment for Graduating Students');
-- CREATE TYPE cdsp_status_enum AS ENUM ('Active', 'Completed', 'Reffered', 'Dropped');
-- CREATE TYPE child_labor_status_enum AS ENUM ('At Risk', 'Child Laborer', 'Rescued', 'Rehabilitated');
-- CREATE TYPE clpep_profile_status_enum AS ENUM ('Active', 'Pending', 'Completed', 'Closed');
-- CREATE TYPE clpep_relationship_enum AS ENUM ('Father', 'Mother', 'Guardian', 'Grandparent', 'Relative', 'Others');
-- CREATE TYPE completion_status_enum AS ENUM ('Ongoing', 'Completed', 'Incomplete', 'Failed');
-- CREATE TYPE dilp_beneficiary_type_enum AS ENUM ('Individual', 'Group');
-- CREATE TYPE eligibility_type_enum AS ENUM ('Regular', 'Disaster-affected', 'Area-Based Convergence', 'Walk-in', 'Referral');
-- CREATE TYPE gip_status_enum AS ENUM ('Active', 'Completed', 'Withdrawn', 'On Hold');
-- CREATE TYPE intervention_status_enum AS ENUM ('Planned', 'Active', 'Completed', 'Archived');
-- CREATE TYPE participant_type_enum AS ENUM ('4Ps', 'Non-4Ps');
-- CREATE TYPE referral_required_enum AS ENUM ('Yes', 'No');
-- CREATE TYPE school_status_enum AS ENUM ('In School', 'Out of School', 'Dropped Out', 'Graduated');
-- CREATE TYPE skills_training_application_status_enum AS ENUM ('Pending', 'Accepted', 'Waitlisted', 'Rejected');
-- CREATE TYPE slp_profile_status_enum AS ENUM ('Active', 'Completed', 'Dropped');
-- CREATE TYPE slp_sector_classification_enum AS ENUM ('Indigenous People', 'Internally Displaced Person', 'Overseas Filipino Worker', 'Person with Disability', 'Senior Citizen', 'Solo Parent', 'Women', 'Youth', 'Returning OFW', 'Other');
-- CREATE TYPE spes_status_enum AS ENUM ('Active', 'Completed', 'Dropped', 'Suspended');
-- CREATE TYPE training_status_enum AS ENUM ('Planned', 'Ongoing', 'Completed');
-- CREATE TYPE tupad_beneficiary_type_enum AS ENUM ('Individual', 'Group');
-- CREATE TYPE tupad_status_enum AS ENUM ('Pending', 'Assigned', 'Completed', 'Cancelled');
-- CREATE TYPE type_of_employment_enum AS ENUM ('Full-Time', 'Part-Time', 'Contractual', 'Temporary', 'Self-Employed');
-- CREATE TYPE vulnerability_severity_enum AS ENUM ('Low', 'Medium', 'High');
