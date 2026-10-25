<?php
// Session check + role gate. Reused by protected modules.

// Returns the logged-in user id, or null.
function currentUserId()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

// Stops the request unless a session is logged in.
function requireSession()
{
    $id = currentUserId();
    if (!$id) {
        error('Not authenticated.', 401);
    }
    return $id;
}

// Stops the request unless the logged-in user's account is active.
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
    // Deleted (soft-deleted -> in the Recycle Bin) takes priority over the
    // status check: a deleted account's status is irrelevant at that point.
    if ($row['deleted_at'] !== null) {
        session_destroy();
        error('Your account has been deleted.', 401, ['code' => 'ACCOUNT_DELETED']);
    }
    if ($row['status'] !== 'Active') {
        session_destroy();
        error('Your account has been deactivated.', 401, ['code' => 'ACCOUNT_DEACTIVATED']);
    }
    return $id;
}

// Stops the request unless the logged-in user is an Administrator.
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

// Stops the request unless the session's cached role is Administrator.
function requireAdminSessionOnly()
{
    $id = requireSession();
    if (($_SESSION['role'] ?? null) !== 'Administrator') {
        error('Administrator access required.', 403);
    }
    return $id;
}


// Stops the request unless the user has the required permission level for a module.
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
