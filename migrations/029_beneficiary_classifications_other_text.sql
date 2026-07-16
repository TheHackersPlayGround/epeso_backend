-- Free-text "please specify" companion for classification = 'Other'.
-- Nullable on the shared beneficiary_classifications table since it's a
-- generic classification detail, not specific to any one module.
ALTER TABLE beneficiary_classifications ADD COLUMN classification_other varchar(255);
