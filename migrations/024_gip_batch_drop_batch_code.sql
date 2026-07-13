-- GIP batch code was an internal reference field with no confirmed use as a
-- lookup key in practice, and no equivalent exists in SPES. Dropping it (and
-- its UNIQUE constraint, which is dropped automatically with the column).
ALTER TABLE gip_batches DROP COLUMN batch_code;
