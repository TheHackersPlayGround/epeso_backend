-- SPES batches now use a single contact person (Program Coordinator /
-- Person In Charge) instead of separate Coordinator + Supervisor fields.
ALTER TABLE spes_batches DROP COLUMN supervisor;
