<?php
// Session check + role gate. Reused by protected modules.

// Ensure the PHP session is started, then return the logged-in user id (or null).
function currentUserId()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

// Session-only check: is there a logged-in session? No database access, so it
// is safe for the few read-only endpoints that must keep working while the
// database is mid-restore (see requireAdminSessionOnly()).
function requireSession()
{
    $id = currentUserId();
    if (!$id) {
        error('Not authenticated.', 401);
    }
    return $id;
}

// Stop the request unless someone is logged in AND their account is still
// Active. An administrator setting a user to Inactive (or deleting them) must
// take effect on the very next request, not only at their next login -- the
// session they already hold would otherwise stay valid. The detail code lets
// the app show "your account has been deactivated" instead of a generic error.
// Returns the user id.
function requireLogin()
{
    $id = requireSession();
    $stmt = db()->prepare("SELECT status, to_jsonb(users)->>'deleted_at' AS deleted_at FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        // The account no longer exists.
        session_destroy();
        error('Not authenticated.', 401);
    }
    // Deactivated, or deleted (soft-deleted -> in the Recycle Bin).
    if ($row['status'] !== 'Active' || $row['deleted_at'] !== null) {
        session_destroy();
        error('Your account has been deactivated.', 401, ['code' => 'ACCOUNT_DEACTIVATED']);
    }
    return $id;
}

// Stop the request unless the logged-in user is an Administrator. Returns the user id.
function requireAdmin()
{
    $id = requireLogin();
    $stmt = db()->prepare("SELECT role FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $id]);
    $role = $stmt->fetchColumn();
    if ($role !== 'Administrator') {
        error('Administrator access required.', 403);
    }
    return $id;
}

// Same admin gate, but reads the role cached in the session at login instead
// of querying the users table. Use this ONLY for read-only polling endpoints
// that must keep working while the database itself is mid-operation and
// temporarily unqueryable -- e.g. backup restoreProgress, which is polled
// throughout a restore that drops and reloads the very schema the users
// table lives in. A normal requireAdmin() there would hang or error out for
// the whole restore, since its DB query targets a table being torn down.
// Trade-off: a demoted admin keeps this narrow, non-sensitive access (step
// number + phase label, nothing else) until they next log in. Every other
// admin-gated action still uses the live, DB-backed requireAdmin() above.
function requireAdminSessionOnly()
{
    $id = requireSession();
    if (($_SESSION['role'] ?? null) !== 'Administrator') {
        error('Administrator access required.', 403);
    }
    return $id;
}


// Module-level permission gate. Mirrors the frontend canManage()/canView() rules.
//
//   $module  one of the 11 dashboard keys: 'employment','cdsp','gip','spes',
//            'livelihood','skills','ofw','documents','maintenance','security','report'
//   $level   'Viewer'  -> user may read the module (Viewer OR Editor row)
//            'Editor'  -> user may add/edit/delete (Editor row required)
//
// Administrators always pass. Returns the user id so callers can use it.
//
// Usage inside a module:
//   requirePermission('cdsp', 'Viewer');   // for GET/list/read endpoints
//   requirePermission('cdsp', 'Editor');   // for create/update/delete endpoints
function requirePermission($module, $level = 'Viewer')
{
    $id = requireLogin();

    // Administrators have full access to every module.
    $stmt = db()->prepare("SELECT role FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() === 'Administrator') {
        return $id;
    }

    // Look up the user's stored level for this module (one row per module).
    $stmt = db()->prepare(
        "SELECT up.permission_level
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = :id AND p.permission_name = :module"
    );
    $stmt->execute([':id' => $id, ':module' => $module]);
    $stored = $stmt->fetchColumn(); // 'Viewer' | 'Editor' | false (no access)

    // Editor satisfies both Viewer and Editor requests; Viewer satisfies only Viewer.
    $ok = ($level === 'Editor')
        ? ($stored === 'Editor')
        : ($stored === 'Viewer' || $stored === 'Editor');

    if (!$ok) {
        error('You do not have permission to perform this action.', 403);
    }
    return $id;
}
