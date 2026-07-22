-- The "employment referral" Type of Request sub-panel (Desired Position, Type
-- of Skill, Agency) had no backing columns at all. Desired Position / Type of
-- Skill are single values per profile -> plain columns on ofw_profiles.
-- Agency is a repeatable free-text list (the frontend's "+ Add Agency" adds
-- another blank text input, not a pick from a fixed list), so it gets its own
-- child table rather than a lookup+junction pair like ofw_request_types.
ALTER TABLE ofw_profiles
  ADD COLUMN desired_position character varying(255),
  ADD COLUMN type_of_skill character varying(255);

CREATE TABLE ofw_profile_agencies (
  ofw_profile_agency_id bigserial PRIMARY KEY,
  ofw_profile_id bigint NOT NULL REFERENCES ofw_profiles(ofw_profile_id) ON DELETE CASCADE,
  agency_name character varying(255) NOT NULL
);
