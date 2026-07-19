-- Add a distinct "Internally Displaced Person" classification for SLP's
-- IDP sector option. The closest existing value, 'Displaced Worker', means
-- someone displaced from employment (retrenchment/closure) -- a different
-- concept from disaster/conflict displacement -- so it isn't reused here.
ALTER TYPE beneficiary_classification_enum ADD VALUE IF NOT EXISTS 'Internally Displaced Person';
