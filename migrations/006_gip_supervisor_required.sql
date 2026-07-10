-- GIP: swap which of coordinator/supervisor is required. The batch form now
-- only collects "Supervisor / Immediate Head" as the required contact person;
-- "Program Coordinator / Person In Charge" was removed from the UI.
-- Safe to run: gip_batches has no rows yet (module is newly integrated).

ALTER TABLE gip_batches ALTER COLUMN coordinator DROP NOT NULL;
ALTER TABLE gip_batches ALTER COLUMN supervisor SET NOT NULL;
