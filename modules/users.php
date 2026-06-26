<?php
// System Users tab (users, user_permissions, permissions)

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

// Router entry point. index.php calls this with the parsed action/id/method.
function handle($action, $id, $method)
{
    // Every user-management action requires an authenticated Administrator.
    requireAdmin();

    switch ($action) {
        case 'list':
            return usersList();
        case 'create':
            return usersCreate();
        case 'update':
            return usersUpdate($id);
        case 'delete':
            return usersDelete($id);
        default:
            error("Unknown users action: {$action}", 404);
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
        "SELECT up.user_id, p.permission_name
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id"
    )->fetchAll();

    $permsByUser = [];
    foreach ($rows as $r) {
        $permsByUser[$r['user_id']][] = $r['permission_name'];
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

    // Username must be unique.
    $check = db()->prepare("SELECT 1 FROM users WHERE username = :u");
    $check->execute([':u' => $username]);
    if ($check->fetch()) {
        error('That username is already taken.', 409);
    }

    // Administrators get the full permission set automatically.
    if ($role === 'Administrator') {
        $perms = allPermissionNames();
    }

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

    $existing = db()->prepare("SELECT user_id FROM users WHERE user_id = :id");
    $existing->execute([':id' => $id]);
    if (!$existing->fetch()) {
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

    // Username must be unique among OTHER users.
    $check = db()->prepare("SELECT 1 FROM users WHERE username = :u AND user_id <> :id");
    $check->execute([':u' => $username, ':id' => $id]);
    if ($check->fetch()) {
        error('That username is already taken.', 409);
    }

    // Administrators get the full permission set automatically.
    if ($role === 'Administrator') {
        $perms = allPermissionNames();
    }

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

// Replace a user's permissions with the given list of permission names.
function syncUserPermissions($userId, $permissionNames)
{
    $pdo = db();
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = :id")->execute([':id' => $userId]);

    $permissionNames = array_values(array_unique($permissionNames));
    if (empty($permissionNames)) {
        return;
    }

    // Map permission names -> ids.
    $placeholders = implode(',', array_fill(0, count($permissionNames), '?'));
    $stmt = $pdo->prepare("SELECT permission_id, permission_name FROM permissions WHERE permission_name IN ($placeholders)");
    $stmt->execute($permissionNames);

    $idByName = [];
    foreach ($stmt->fetchAll() as $r) {
        $idByName[$r['permission_name']] = $r['permission_id'];
    }

    // Presence of a permission = granted. Level is stored as 'Editor'.
    $ins = $pdo->prepare(
        "INSERT INTO user_permissions (user_id, permission_id, permission_level)
         VALUES (:u, :p, 'Editor')"
    );
    foreach ($permissionNames as $name) {
        if (isset($idByName[$name])) {
            $ins->execute([':u' => $userId, ':p' => $idByName[$name]]);
        }
    }
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
        "SELECT p.permission_name
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = :id"
    );
    $perms->execute([':id' => $userId]);

    $pub['permissions'] = $perms->fetchAll(PDO::FETCH_COLUMN);
    return $pub;
}

// Every permission name in the system (used to grant Administrators full access).
function allPermissionNames()
{
    return db()->query("SELECT permission_name FROM permissions ORDER BY permission_id")
        ->fetchAll(PDO::FETCH_COLUMN);
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
