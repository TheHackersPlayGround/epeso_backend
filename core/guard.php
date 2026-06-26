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

// Stop the request unless someone is logged in. Returns the user id.
function requireLogin()
{
    $id = currentUserId();
    if (!$id) {
        error('Not authenticated.', 401);
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
