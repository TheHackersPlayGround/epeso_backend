-- spes_profiles.date_received is dead schema — the backend has always read
-- and written Date Applied via beneficiary_services.date_applied only
-- (spes.php never references date_received). Dropping it to avoid the same
-- confusion that caused the CDSP date_received bug (see migration 018).
ALTER TABLE spes_profiles DROP COLUMN date_received;
