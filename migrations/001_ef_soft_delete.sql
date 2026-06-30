-- Soft delete for the Employment Facilitation module.
--
-- Adds a nullable deleted_at (when it was deleted) and deleted_by (which user)
-- to every EF-deletable table. NULL deleted_at = active/visible; a timestamp
-- means the row is in the recycle bin (hidden from all lists, still restorable).
--
-- Scope: Employment Facilitation only — applicants (beneficiaries), employers,
-- and referrals. Safe to re-run (IF NOT EXISTS).

ALTER TABLE beneficiaries
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE employers
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE employment_facilitation_referrals
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL REFERENCES users(user_id);

-- Partial indexes keep the "active rows" lists fast (they only index live rows).
CREATE INDEX IF NOT EXISTS idx_beneficiaries_active
    ON beneficiaries (beneficiary_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_employers_active
    ON employers (employer_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_ef_referrals_active
    ON employment_facilitation_referrals (referral_id) WHERE deleted_at IS NULL;
