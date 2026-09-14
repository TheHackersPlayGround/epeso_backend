-- Fix: employers and employment_facilitation_referrals were missing the
-- deleted_by column that 001_ef_soft_delete.sql was supposed to add to every
-- EF-deletable table (only beneficiaries actually got it). Without it,
-- efDeleteEmployer() and efDeleteReferral() always fail with a Postgres
-- "column deleted_by does not exist" error -- soft-deleting an employer or a
-- referral has been completely broken. Safe to re-run (IF NOT EXISTS).

ALTER TABLE employers
    ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE employment_facilitation_referrals
    ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);
