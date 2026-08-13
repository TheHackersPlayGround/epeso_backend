-- Postgres does not auto-create indexes on foreign key columns (only on
-- primary keys), so every join/filter on these FKs was doing a full table
-- scan. Harmless on today's near-empty tables, but this becomes the first
-- thing to bite as data grows (esp. beneficiary detail joins, hit on every
-- profile load). Adding indexes for every FK column identified as missing.

-- Beneficiary detail joins (hit on every beneficiary profile load)
CREATE INDEX IF NOT EXISTS idx_educations_beneficiary_id ON educations(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_eligibilities_beneficiary_id ON eligibilities(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_job_preferences_beneficiary_id ON job_preferences(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_languages_beneficiary_id ON languages(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_licenses_beneficiary_id ON licenses(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_skills_beneficiary_id ON skills(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_trainings_beneficiary_id ON trainings(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_work_experiences_beneficiary_id ON work_experiences(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_work_experiences_company_city_id ON work_experiences(company_city_id);
CREATE INDEX IF NOT EXISTS idx_beneficiary_services_beneficiary_id ON beneficiary_services(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_beneficiary_services_service_id ON beneficiary_services(service_id);

-- Employment facilitation (referral/placement/vacancy joins & reports)
CREATE INDEX IF NOT EXISTS idx_ef_placements_employer_id ON employment_facilitation_placements(employer_id);
CREATE INDEX IF NOT EXISTS idx_ef_placements_vacancy_id ON employment_facilitation_placements(vacancy_id);
CREATE INDEX IF NOT EXISTS idx_ef_placements_referral_id ON employment_facilitation_placements(referral_id);
CREATE INDEX IF NOT EXISTS idx_ef_placements_beneficiary_service_id ON employment_facilitation_placements(beneficiary_service_id);
CREATE INDEX IF NOT EXISTS idx_ef_referrals_vacancy_id ON employment_facilitation_referrals(vacancy_id);
CREATE INDEX IF NOT EXISTS idx_ef_referrals_beneficiary_service_id ON employment_facilitation_referrals(beneficiary_service_id);
CREATE INDEX IF NOT EXISTS idx_vacancies_employer_id ON vacancies(employer_id);
CREATE INDEX IF NOT EXISTS idx_employers_industry_id ON employers(industry_id);
CREATE INDEX IF NOT EXISTS idx_placement_promotions_created_by ON placement_promotions(created_by);

-- Program modules (child rows joined by parent)
CREATE INDEX IF NOT EXISTS idx_attached_documents_beneficiary_id ON attached_documents(beneficiary_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_beneficiary_service_id ON attached_documents(beneficiary_service_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_clpep_intervention_id ON attached_documents(clpep_intervention_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_dilp_project_id ON attached_documents(dilp_project_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_gip_batch_id ON attached_documents(gip_batch_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_slp_project_id ON attached_documents(slp_project_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_spes_batch_id ON attached_documents(spes_batch_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_tupad_project_id ON attached_documents(tupad_project_id);
CREATE INDEX IF NOT EXISTS idx_attached_documents_uploaded_by ON attached_documents(uploaded_by);
CREATE INDEX IF NOT EXISTS idx_ofw_profiles_beneficiary_service_id ON ofw_profiles(beneficiary_service_id);
CREATE INDEX IF NOT EXISTS idx_ofw_profile_agencies_ofw_profile_id ON ofw_profile_agencies(ofw_profile_id);
CREATE INDEX IF NOT EXISTS idx_dilp_project_beneficiaries_dilp_project_id ON dilp_project_beneficiaries(dilp_project_id);
CREATE INDEX IF NOT EXISTS idx_dilp_projects_barangay_id ON dilp_projects(barangay_id);
CREATE INDEX IF NOT EXISTS idx_beneficiaries_barangay_id ON beneficiaries(barangay_id);
CREATE INDEX IF NOT EXISTS idx_cdsp_activities_service_id ON cdsp_activities(service_id);
CREATE INDEX IF NOT EXISTS idx_services_parent_service_id ON services(parent_service_id);
CREATE INDEX IF NOT EXISTS idx_skills_training_activities_batch_id ON skills_training_activities(batch_id);
CREATE INDEX IF NOT EXISTS idx_skills_training_profiles_batch_id ON skills_training_profiles(batch_id);
CREATE INDEX IF NOT EXISTS idx_st_profile_purposes_purpose_id ON skills_training_profile_purposes(purpose_id);
CREATE INDEX IF NOT EXISTS idx_st_profile_purposes_profile_id ON skills_training_profile_purposes(skills_training_profile_id);
CREATE INDEX IF NOT EXISTS idx_st_profile_qualifications_qualification_id ON skills_training_profile_qualifications(qualification_id);
CREATE INDEX IF NOT EXISTS idx_st_profile_qualifications_profile_id ON skills_training_profile_qualifications(skills_training_profile_id);
CREATE INDEX IF NOT EXISTS idx_document_library_folder_id ON document_library(folder_id);
CREATE INDEX IF NOT EXISTS idx_document_library_uploaded_by ON document_library(uploaded_by);
CREATE INDEX IF NOT EXISTS idx_folders_created_by_user_id ON folders(created_by_user_id);
CREATE INDEX IF NOT EXISTS idx_folders_parent_folder_id ON folders(parent_folder_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_user_id ON activity_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_users_created_by ON users(created_by);

-- Audit / soft-delete columns (low query frequency, still worth covering)
CREATE INDEX IF NOT EXISTS idx_beneficiaries_deleted_by ON beneficiaries(deleted_by);
CREATE INDEX IF NOT EXISTS idx_cdsp_activities_deleted_by ON cdsp_activities(deleted_by);
CREATE INDEX IF NOT EXISTS idx_clpep_interventions_deleted_by ON clpep_interventions(deleted_by);
CREATE INDEX IF NOT EXISTS idx_dilp_projects_deleted_by ON dilp_projects(deleted_by);
CREATE INDEX IF NOT EXISTS idx_document_library_deleted_by ON document_library(deleted_by);
CREATE INDEX IF NOT EXISTS idx_folders_deleted_by ON folders(deleted_by);
CREATE INDEX IF NOT EXISTS idx_gip_batches_deleted_by ON gip_batches(deleted_by);
CREATE INDEX IF NOT EXISTS idx_services_deleted_by ON services(deleted_by);
CREATE INDEX IF NOT EXISTS idx_skills_training_activities_deleted_by ON skills_training_activities(deleted_by);
CREATE INDEX IF NOT EXISTS idx_skills_training_batches_deleted_by ON skills_training_batches(deleted_by);
CREATE INDEX IF NOT EXISTS idx_slp_projects_deleted_by ON slp_projects(deleted_by);
CREATE INDEX IF NOT EXISTS idx_spes_batches_deleted_by ON spes_batches(deleted_by);
CREATE INDEX IF NOT EXISTS idx_tupad_projects_deleted_by ON tupad_projects(deleted_by);
