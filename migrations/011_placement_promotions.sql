-- Promotion history for Employment Facilitation placements.
--
-- A placement can have many promotions over time (e.g. Cook -> Head Cook ->
-- Kitchen Manager) within the SAME job. Each promotion is recorded as its own
-- event row so the full history is preserved -- nothing is overwritten.
--
-- Deliberately does NOT touch placement_status_enum: a promotion is an event,
-- not a lifecycle state, so the placement's status (Active/Resigned/Terminated/
-- Completed) and the "Hired" re-referral gate (status = 'Active') are unaffected.
--
-- Types mirror the live schema: placement_id and users.user_id are BIGINT, and
-- timestamps are timestamptz with CURRENT_TIMESTAMP defaults. Safe to re-run.

CREATE TABLE IF NOT EXISTS placement_promotions (
    promotion_id      BIGSERIAL     PRIMARY KEY,
    placement_id      BIGINT        NOT NULL
                        REFERENCES employment_facilitation_placements(placement_id)
                        ON DELETE CASCADE,
    promotion_date    DATE          NOT NULL,
    new_job_title     TEXT          NOT NULL,
    new_salary_range  TEXT          NULL,
    remarks           TEXT          NULL,
    created_by        BIGINT        NULL REFERENCES users(user_id),
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Serves "promotions for this placement, newest first" lookups.
CREATE INDEX IF NOT EXISTS idx_placement_promotions_placement
    ON placement_promotions (placement_id, promotion_date DESC);
