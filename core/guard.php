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
