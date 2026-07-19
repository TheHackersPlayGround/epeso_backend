-- CLPEPForm.tsx (maintenance) already collects Intervention Category, Partner
-- Agency, and Target Beneficiaries, but clpep_interventions has no columns for
-- them -- without these, the already-built form would silently lose this data
-- on every save. Plain varchar/int, matching the form's free-select + "please
-- specify" pattern rather than a rigid enum.
ALTER TABLE clpep_interventions ADD COLUMN intervention_category varchar(100);
ALTER TABLE clpep_interventions ADD COLUMN intervention_category_other varchar(200);
ALTER TABLE clpep_interventions ADD COLUMN partner_agency varchar(100);
ALTER TABLE clpep_interventions ADD COLUMN partner_agency_other varchar(200);
ALTER TABLE clpep_interventions ADD COLUMN target_beneficiaries integer;
