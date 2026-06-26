<?php
// Login, logout, me, change-password, security-answers

include_once __DIR__ . '/../core/helpers.php';

// Router entry point. index.php calls this with the parsed action.
function handle($action, $id, $method)
{
    // A session carries "who is logged in" across requests via a cookie.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    switch ($action) {
        case 'login':
            return authLogin();
        case 'logout':
            return authLogout();
        case 'me':
            return authMe();
        case 'change-password':
            return authChangePassword();
        default:
            error("Unknown auth action: {$action}", 404);
    }
}

// POST /api/auth/login  { username, password }
function authLogin()
{
    $data     = body();
    $username = isset($data['username']) ? trim($data['username']) : '';
    $password = isset($data['password']) ? $data['password'] : '';

    if ($username === '' || $password === '') {
        error('Username and password are required.', 422);
    }

    $stmt = db()->prepare(
        "SELECT user_id, first_name, last_name, username, password_hash, role, status, last_login
         FROM users WHERE username = :u"
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    // Same vague message whether the user is missing or the password is wrong.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        error('Invalid username or password.', 401);
    }

    if ($user['status'] !== 'Active') {
        error('This account is inactive. Please contact an administrator.', 403);
    }

    // Record the login time.
    db()->prepare("UPDATE users SET last_login = now() WHERE user_id = :id")
        ->execute([':id' => $user['user_id']]);

    // Remember who is logged in.
    $_SESSION['user_id'] = (int) $user['user_id'];

    json(['status' => 'ok', 'user' => publicUser($user)]);
}

// GET /api/auth/me  -> the currently logged-in user (used on page refresh)
function authMe()
{
    if (empty($_SESSION['user_id'])) {
        error('Not authenticated.', 401);
    }

    $stmt = db()->prepare(
        "SELECT user_id, first_name, last_name, username, role, status, last_login
         FROM users WHERE user_id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        // Session points at a user that no longer exists.
        session_destroy();
        error('Not authenticated.', 401);
    }

    json(['status' => 'ok', 'user' => publicUser($user)]);
}

// POST /api/auth/logout
function authLogout()
{
    $_SESSION = [];
    session_destroy();
    json(['status' => 'ok', 'message' => 'Logged out.']);
}

// POST /api/auth/change-password  { currentPassword, newPassword }
function authChangePassword()
{
    if (empty($_SESSION['user_id'])) {
        error('Not authenticated.', 401);
    }

    $data    = body();
    $current = isset($data['currentPassword']) ? $data['currentPassword'] : '';
    $new     = isset($data['newPassword']) ? $data['newPassword'] : '';

    if ($current === '' || $new === '') {
        error('Current and new password are required.', 422);
    }
    if (strlen($new) < 8) {
        error('New password must be at least 8 characters.', 422);
    }

    $stmt = db()->prepare("SELECT password_hash FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        error('Current password is incorrect.', 403);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    db()->prepare("UPDATE users SET password_hash = :h, updated_at = now() WHERE user_id = :id")
        ->execute([':h' => $hash, ':id' => $_SESSION['user_id']]);

    json(['status' => 'ok', 'message' => 'Password updated.']);
}
