-- SLPProfileForm.tsx already collects a "please specify the referring party"
-- free-text field when Eligibility Type is Referral, but slp_profiles has no
-- column for it -- without this, the already-built form would silently lose
-- that value on every save.
ALTER TABLE slp_profiles ADD COLUMN referring_party varchar(200);
