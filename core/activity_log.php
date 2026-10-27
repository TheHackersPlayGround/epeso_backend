<?php
// Shared activity-logging helper. Writes one row to activity_logs per meaningful action
// (not every read/list/view), using the same lowercase module slug used by permissions.

include_once __DIR__ . '/db.php';

// Logging must never break the action it's describing; swallow insert failures silently.
function logActivity($userId, $action, $module, $details = null, $status = 'Success')
{
    try {
        db()->prepare(
            "INSERT INTO activity_logs (user_id, action, module_name, details, status, ip_address)
             VALUES (:uid, :action, :module, :details, :status, :ip)"
        )->execute([
            ':uid'     => $userId,
            ':action'  => $action,
            ':module'  => $module,
            ':details' => $details,
            ':status'  => $status,
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Intentionally swallowed — see comment above.
    }
}
