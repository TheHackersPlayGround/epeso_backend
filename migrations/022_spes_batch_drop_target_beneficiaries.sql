-- SPES batches now track a single capacity figure (Available Slots),
-- merged into the Batch Information section. Target Beneficiaries was a
-- redundant secondary count, never enforced by any logic.
ALTER TABLE spes_batches DROP COLUMN target_beneficiaries;
