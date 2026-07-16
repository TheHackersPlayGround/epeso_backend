-- Add DILP-specific livelihood beneficiary categories to the shared
-- beneficiary_classification_enum. OFW/PWD/Others already have near-identical
-- existing values (Returning OFW / Person with Disability / Other) and are
-- mapped to those in code rather than duplicated here.
ALTER TYPE beneficiary_classification_enum ADD VALUE IF NOT EXISTS 'Vendor';
ALTER TYPE beneficiary_classification_enum ADD VALUE IF NOT EXISTS 'Fisherfolk';
ALTER TYPE beneficiary_classification_enum ADD VALUE IF NOT EXISTS 'Farmer';
ALTER TYPE beneficiary_classification_enum ADD VALUE IF NOT EXISTS 'Displaced Worker';
ALTER TYPE beneficiary_classification_enum ADD VALUE IF NOT EXISTS 'Transport Worker';
