-- OFW's request-review status (Pending/Ongoing/Approved/Completed/Rejected)
-- had no backing column at all, and no existing enum matches this value set
-- (beneficiary_service_status_enum is the closest but is a different concept
-- -- enrollment active/inactive -- with different values: Pending/Approved/
-- Active/Completed/Cancelled, no Ongoing, no Rejected). New enum needed.
CREATE TYPE ofw_status_enum AS ENUM ('Pending', 'Ongoing', 'Approved', 'Completed', 'Rejected');

ALTER TABLE ofw_profiles
  ADD COLUMN status ofw_status_enum NOT NULL DEFAULT 'Pending',
  ADD COLUMN remarks text;
