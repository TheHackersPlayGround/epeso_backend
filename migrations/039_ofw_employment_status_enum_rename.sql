-- Rename 2 of the 4 ofw_employment_status_enum values to better match the
-- real set of statuses OFW cases are tracked under: "Returning OFW" ->
-- "Self Employed", "Repatriated OFW" -> "Underemployed". Final 4 values:
-- Employed, Unemployed, Self Employed, Underemployed.
-- ofw_profiles has 0 rows at time of writing, so this is a pure rename with
-- no data to migrate (Postgres supports renaming an enum label in place,
-- unlike removing one, which needs the full type-swap dance).
ALTER TYPE ofw_employment_status_enum RENAME VALUE 'Returning OFW' TO 'Self Employed';
ALTER TYPE ofw_employment_status_enum RENAME VALUE 'Repatriated OFW' TO 'Underemployed';
