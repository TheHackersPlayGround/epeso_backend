<?php
// System Users tab (users, user_permissions, permissions)

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

// Router entry point. index.php calls this with the parsed action/id/method.
function handle($action, $id, $method)
{
    // User management lives under the "security" module:
    //   viewing the list needs Security = Viewer,
    //   creating/updating/deleting needs Security = Editor.
    // Administrators always pass (handled inside requirePermission).
    switch ($action) {
        case 'list':
            requirePermission('security', 'Viewer');
            return usersList();
        case 'create':
            requirePermission('security', 'Editor');
            return usersCreate();
        case 'update':
            requirePermission('security', 'Editor');
            return usersUpdate($id);
        case 'delete':
            requirePermission('security', 'Editor');
            return usersDelete($id);
        default:
            error("Unknown users action: {$action}", 404);
    }
}

// Is the currently logged-in actor a true Administrator?
// Used to stop non-admins from creating/elevating/deleting Administrator accounts.
function actorIsAdmin()
{
    $id = currentUserId();
    if (!$id) {
        return false;
    }
    $stmt = db()->prepare("SELECT role FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetchColumn() === 'Administrator';
}

// The view-/manage- permission names the current actor personally holds.
// Administrators hold the full set.
function actorPermissionNames()
{
    $id = currentUserId();
    if (!$id) {
        return [];
    }
    $stmt = db()->prepare("SELECT role FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() === 'Administrator') {
        return allPermissionNames();
    }

    $stmt = db()->prepare(
        "SELECT p.permission_name, up.permission_level
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = :id"
    );
    $stmt->execute([':id' => $id]);
    $names = [];
    foreach ($stmt->fetchAll() as $r) {
        $names = array_merge($names, expandModuleNames($r['permission_name'], $r['permission_level']));
    }
    return $names;
}

// Stop a non-Administrator from granting any permission they don't hold themselves.
function guardGrantablePermissions($requested)
{
    if (actorIsAdmin()) {
        return; // Administrators may grant anything.
    }
    $extra = array_values(array_diff($requested, actorPermissionNames()));
    if (!empty($extra)) {
        error('You can only grant permissions that you hold yourself.', 403);
    }
}

// GET /api/users/list  -> all users, each with their permission name list.
function usersList()
{
    $users = db()->query(
        "SELECT user_id, first_name, last_name, username, role, status, last_login
         FROM users ORDER BY user_id"
    )->fetchAll();

    // Pull every user's permissions in one query, then group by user.
    $rows = db()->query(
        "SELECT up.user_id, p.permission_name, up.permission_level
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id"
    )->fetchAll();

    $permsByUser = [];
    foreach ($rows as $r) {
        $names = expandModuleNames($r['permission_name'], $r['permission_level']);
        foreach ($names as $n) {
            $permsByUser[$r['user_id']][] = $n;
        }
    }

    // Administrators always have full access, regardless of stored rows.
    $allPerms = allPermissionNames();

    $out = [];
    foreach ($users as $u) {
        $pub = publicUser($u);
        if ($u['role'] === 'Administrator') {
            $pub['permissions'] = $allPerms;
        } else {
            $pub['permissions'] = isset($permsByUser[$u['user_id']]) ? $permsByUser[$u['user_id']] : [];
        }
        $out[] = $pub;
    }

    json(['status' => 'ok', 'users' => $out]);
}

// POST /api/users/create  { firstName, lastName, username, password, role, status, permissions[] }
function usersCreate()
{
    $d        = body();
    $first    = trim(isset($d['firstName']) ? $d['firstName'] : '');
    $last     = trim(isset($d['lastName']) ? $d['lastName'] : '');
    $username = trim(isset($d['username']) ? $d['username'] : '');
    $password = isset($d['password']) ? $d['password'] : '';
    $role     = isset($d['role']) ? $d['role'] : '';
    $status   = isset($d['status']) ? $d['status'] : '';
    $perms    = (isset($d['permissions']) && is_array($d['permissions'])) ? $d['permissions'] : [];

    if ($first === '' || $last === '' || $username === '' || $password === '') {
        error('First name, last name, username and password are required.', 422);
    }
    if (!in_array($role, validRoles(), true)) {
        error('Role must be Administrator or Staff.', 422);
    }
    if (!in_array($status, validStatuses(), true)) {
        error('Status must be Active or Inactive.', 422);
    }
    if (strlen($password) < 8) {
        error('Password must be at least 8 characters.', 422);
    }
    // Only a true Administrator may create another Administrator (prevents escalation).
    if ($role === 'Administrator' && !actorIsAdmin()) {
        error('Only an Administrator can create an Administrator account.', 403);
    }

    // Username must be unique.
    $check = db()->prepare("SELECT 1 FROM users WHERE username = :u");
    $check->execute([':u' => $username]);
    if ($check->fetch()) {
        error('That username is already taken.', 409);
    }

    // Administrators get the full permission set automatically.
    if ($role === 'Administrator') {
        $perms = allPermissionNames();
    } else {
        // Security is an administrator-only module; never grant it to Staff.
        $perms = array_values(array_filter($perms, function ($p) {
            return $p !== 'view-security' && $p !== 'manage-security';
        }));
    }

    // A non-Administrator may only grant permissions they hold themselves.
    guardGrantablePermissions($perms);

    $hash      = password_hash($password, PASSWORD_DEFAULT);
    $createdBy = currentUserId();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO users
                (first_name, last_name, username, password_hash, role, status, created_by, created_at, updated_at)
             VALUES
                (:first, :last, :username, :hash, :role, :status, :createdBy, now(), now())
             RETURNING user_id"
        );
        $stmt->execute([
            ':first'     => $first,
            ':last'      => $last,
            ':username'  => $username,
            ':hash'      => $hash,
            ':role'      => $role,
            ':status'    => $status,
            ':createdBy' => $createdBy,
        ]);
        $userId = (int) $stmt->fetchColumn();

        syncUserPermissions($userId, $perms);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error('Could not create user.', 500, $e->getMessage());
    }

    json(['status' => 'ok', 'user' => fetchUser($userId)], 201);
}

// POST /api/users/update/{id}  { firstName, lastName, username, role, status, permissions[], newPassword? }
function usersUpdate($id)
{
    $id = (int) $id;
    if ($id <= 0) {
        error('A valid user id is required.', 422);
    }

    $existing = db()->prepare("SELECT role FROM users WHERE user_id = :id");
    $existing->execute([':id' => $id]);
    $target = $existing->fetch();
    if (!$target) {
        error('User not found.', 404);
    }

    $d        = body();
    $first    = trim(isset($d['firstName']) ? $d['firstName'] : '');
    $last     = trim(isset($d['lastName']) ? $d['lastName'] : '');
    $username = trim(isset($d['username']) ? $d['username'] : '');
    $role     = isset($d['role']) ? $d['role'] : '';
    $status   = isset($d['status']) ? $d['status'] : '';
    $perms    = (isset($d['permissions']) && is_array($d['permissions'])) ? $d['permissions'] : [];
    $newPass  = isset($d['newPassword']) ? $d['newPassword'] : '';

    if ($first === '' || $last === '' || $username === '') {
        error('First name, last name and username are required.', 422);
    }
    if (!in_array($role, validRoles(), true)) {
        error('Role must be Administrator or Staff.', 422);
    }
    if (!in_array($status, validStatuses(), true)) {
        error('Status must be Active or Inactive.', 422);
    }
    // Only a true Administrator may edit an existing Administrator, or promote anyone
    // to Administrator (prevents a Security-Editor from escalating privileges).
    if (!actorIsAdmin() && ($target['role'] === 'Administrator' || $role === 'Administrator')) {
        error('Only an Administrator can modify Administrator accounts.', 403);
    }

    // Username must be unique among OTHER users.
    $check = db()->prepare("SELECT 1 FROM users WHERE username = :u AND user_id <> :id");
    $check->execute([':u' => $username, ':id' => $id]);
    if ($check->fetch()) {
        error('That username is already taken.', 409);
    }

    // Administrators get the full permission set automatically.
    if ($role === 'Administrator') {
        $perms = allPermissionNames();
    } else {
        // Security is an administrator-only module; never grant it to Staff.
        $perms = array_values(array_filter($perms, function ($p) {
            return $p !== 'view-security' && $p !== 'manage-security';
        }));
    }

    // A non-Administrator may only grant permissions they hold themselves.
    guardGrantablePermissions($perms);

    // Don't let the last active Administrator be demoted/deactivated into lockout.
    guardLastAdmin($id, $role, $status);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE users
             SET first_name = :first, last_name = :last, username = :username,
                 role = :role, status = :status, updated_at = now()
             WHERE user_id = :id"
        )->execute([
            ':first'    => $first,
            ':last'     => $last,
            ':username' => $username,
            ':role'     => $role,
            ':status'   => $status,
            ':id'       => $id,
        ]);

        if ($newPass !== '') {
            if (strlen($newPass) < 8) {
                throw new RuntimeException('New password must be at least 8 characters.');
            }
            $pdo->prepare("UPDATE users SET password_hash = :h, updated_at = now() WHERE user_id = :id")
                ->execute([':h' => password_hash($newPass, PASSWORD_DEFAULT), ':id' => $id]);
        }

        syncUserPermissions($id, $perms);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error($e->getMessage(), 422);
    }

    json(['status' => 'ok', 'user' => fetchUser($id)]);
}

// DELETE /api/users/delete/{id}
function usersDelete($id)
{
    $id = (int) $id;
    if ($id <= 0) {
        error('A valid user id is required.', 422);
    }

    $stmt = db()->prepare("SELECT role, status FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    if (!$user) {
        error('User not found.', 404);
    }

    // Only a true Administrator may delete an Administrator account.
    if ($user['role'] === 'Administrator' && !actorIsAdmin()) {
        error('Only an Administrator can delete an Administrator account.', 403);
    }

    // Never delete the last administrator.
    if ($user['role'] === 'Administrator' && countAdmins() <= 1) {
        error('Cannot delete the last administrator account.', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM users WHERE user_id = :id")->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error('Could not delete user.', 500, $e->getMessage());
    }

    json(['status' => 'ok', 'message' => 'User deleted.']);
}

// ── helpers ──────────────────────────────────────────────────────────────────

// Replace a user's permissions, storing ONE row per module with the real level.
// The frontend sends names like 'view-cdsp' / 'manage-cdsp'. We collapse them per
// module: 'manage-X' present => Editor, otherwise 'view-X' => Viewer.
function syncUserPermissions($userId, $permissionNames)
{
    $pdo = db();
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = :id")->execute([':id' => $userId]);

    // Collapse the incoming names into one level per module key.
    $levelByModule = [];
    foreach ($permissionNames as $name) {
        if (strpos($name, 'manage-') === 0) {
            $module = substr($name, 7);
            $levelByModule[$module] = 'Editor';
        } elseif (strpos($name, 'view-') === 0) {
            $module = substr($name, 5);
            if (!isset($levelByModule[$module])) {
                $levelByModule[$module] = 'Viewer';
            }
        }
    }
    if (empty($levelByModule)) {
        return;
    }

    // Map module key -> permission_id.
    $modules = array_keys($levelByModule);
    $placeholders = implode(',', array_fill(0, count($modules), '?'));
    $stmt = $pdo->prepare("SELECT permission_id, permission_name FROM permissions WHERE permission_name IN ($placeholders)");
    $stmt->execute($modules);

    $idByModule = [];
    foreach ($stmt->fetchAll() as $r) {
        $idByModule[$r['permission_name']] = $r['permission_id'];
    }

    // One row per module, carrying the real Viewer/Editor level.
    $ins = $pdo->prepare(
        "INSERT INTO user_permissions (user_id, permission_id, permission_level)
         VALUES (:u, :p, :lvl)"
    );
    foreach ($levelByModule as $module => $level) {
        if (isset($idByModule[$module])) {
            $ins->execute([':u' => $userId, ':p' => $idByModule[$module], ':lvl' => $level]);
        }
    }
}

// Expand one stored module row into the frontend's view-/manage- name list.
function expandModuleNames($module, $level)
{
    $names = ["view-{$module}"];
    if ($level === 'Editor') {
        $names[] = "manage-{$module}";
    }
    return $names;
}

// Fetch a single user (with permissions) in the frontend shape.
function fetchUser($userId)
{
    $stmt = db()->prepare(
        "SELECT user_id, first_name, last_name, username, role, status, last_login
         FROM users WHERE user_id = :id"
    );
    $stmt->execute([':id' => $userId]);
    $u = $stmt->fetch();
    if (!$u) {
        return null;
    }

    $pub = publicUser($u);

    // Administrators always report full access.
    if ($u['role'] === 'Administrator') {
        $pub['permissions'] = allPermissionNames();
        return $pub;
    }

    $perms = db()->prepare(
        "SELECT p.permission_name, up.permission_level
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = :id"
    );
    $perms->execute([':id' => $userId]);

    $names = [];
    foreach ($perms->fetchAll() as $r) {
        $names = array_merge($names, expandModuleNames($r['permission_name'], $r['permission_level']));
    }
    $pub['permissions'] = $names;
    return $pub;
}

// Full view-/manage- name set (used to grant Administrators full access).
// Derived from the 11 module rows: each module yields view-X and manage-X.
function allPermissionNames()
{
    $modules = db()->query("SELECT permission_name FROM permissions ORDER BY permission_id")
        ->fetchAll(PDO::FETCH_COLUMN);
    $names = [];
    foreach ($modules as $m) {
        $names[] = "view-{$m}";
        $names[] = "manage-{$m}";
    }
    return $names;
}

function countAdmins()
{
    return (int) db()->query("SELECT count(*) FROM users WHERE role = 'Administrator' AND status = 'Active'")->fetchColumn();
}

// Block changes that would remove the last active Administrator.
function guardLastAdmin($id, $newRole, $newStatus)
{
    $stmt = db()->prepare("SELECT role, status FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $id]);
    $cur = $stmt->fetch();

    $wasActiveAdmin = $cur && $cur['role'] === 'Administrator' && $cur['status'] === 'Active';
    $stillActiveAdmin = $newRole === 'Administrator' && $newStatus === 'Active';

    if ($wasActiveAdmin && !$stillActiveAdmin && countAdmins() <= 1) {
        error('Cannot demote or deactivate the last administrator.', 409);
    }
}
