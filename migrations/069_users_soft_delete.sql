-- Soft delete for system users.
--
-- Why: many tables reference users(user_id) with no ON DELETE action
-- (uploaded_by, deleted_by, created_by, exited_by ...), so a user who ever
-- uploaded a document, deleted a record or created another user could never
-- be removed -- "Failed to delete". Deleting now just stamps the row, like
-- every other record in the Recycle Bin: the person disappears from the user
-- list and can no longer sign in, while their history stays correctly linked.
--
-- Also: usernames must stay unique among LIVE accounts only, so a deleted
-- account no longer reserves its username (restoring one whose name has since
-- been taken is refused by the application with a clear message).
--
-- Deploy the updated PHP (users.php, auth.php, core/guard.php) with this.
-- Idempotent (IF NOT EXISTS / IF EXISTS) and a single transaction.

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS deleted_at timestamp,
    ADD COLUMN IF NOT EXISTS deleted_by bigint REFERENCES users(user_id);

-- Unique username among accounts that have not been deleted.
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_key;
CREATE UNIQUE INDEX IF NOT EXISTS users_username_active_key
    ON users (username) WHERE deleted_at IS NULL;

COMMIT;
