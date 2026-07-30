<?php
// Shared activity-logging helper. Writes one row to activity_logs per
// meaningful action (login, create/update/delete/status-change of a record
// or batch/activity/project, user management) — not every read/list/view
// call, to keep the log meaningful rather than flooded.
//
// module: the same lowercase slug used everywhere else in the permission
// system (e.g. 'cdsp', 'gip', 'spes', 'security') — one slug per program
// covers both its Records and Maintenance actions; the `action` string
// itself (e.g. "Create Activity" vs "Update Profile") is what distinguishes
// them, not a separate module value.

include_once __DIR__ . '/db.php';

// Logging must never break the action it's describing. If the insert fails
// for any reason (bad connection mid-request, etc.), swallow it silently
// rather than turning a successful save into a 500 error.
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
