-- Companion to beneficiaries.is_4ps_beneficiary — when they graduated from
-- the 4Ps program, kept on the shared beneficiaries table (not module-
-- specific) since it's the same kind of household/social-welfare fact
-- about the person, not something tied to DILP or any one program.
ALTER TABLE beneficiaries ADD COLUMN year_graduated_4ps smallint;
