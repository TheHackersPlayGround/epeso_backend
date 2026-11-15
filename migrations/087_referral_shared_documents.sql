-- Employer portal: which of an applicant's uploaded documents (Application Letter, Resume, Biodata ...) the PESO chose to send
-- to the employer for ONE referral. Nothing is shared automatically: an employer can open a document only if staff selected it
-- for a referral of that employer, and only while the referral is active or hired (not after "Not Hired", or if it is deleted).
--
-- referral_shared_documents   one row per (referral, document) that staff shared. Staff choose them when they refer an applicant
--                             and can change them later from the Referrals tab. Deleting the referral or the document removes the row.
-- employer_access_log.document_id   which document an employer opened or downloaded (views are logged, like applicant details)
--
-- Run AFTER 085_employer_portal.sql. Safe to run again: every statement is IF NOT EXISTS.
--
-- Rollback:
--   DROP TABLE IF EXISTS referral_shared_documents;
--   ALTER TABLE employer_access_log DROP COLUMN IF EXISTS document_id;

BEGIN;

CREATE TABLE IF NOT EXISTS referral_shared_documents (
  referral_id BIGINT    NOT NULL REFERENCES employment_facilitation_referrals(referral_id) ON DELETE CASCADE,
  document_id BIGINT    NOT NULL REFERENCES attached_documents(document_id) ON DELETE CASCADE,
  shared_by   BIGINT    REFERENCES users(user_id) ON DELETE SET NULL,
  shared_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (referral_id, document_id)
);
CREATE INDEX IF NOT EXISTS referral_shared_documents_document_idx ON referral_shared_documents (document_id);

ALTER TABLE employer_access_log ADD COLUMN IF NOT EXISTS document_id BIGINT;

COMMIT;
