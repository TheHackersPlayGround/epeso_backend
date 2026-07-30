<?php
// Activity Logs: read-only audit trail of meaningful actions app-wide.
// Rows are written via core/activity_log.php's logActivity() helper from
// every module's login/create/update/delete/status-change/user-management
// call sites -- this module only lists them (matching every other list
// endpoint in this app, search/filter/sort/pagination all happen
// client-side against the full list, not as query-string params here).
// Never editable or individually deletable -- an audit trail that could be
// tampered with piecemeal wouldn't be trustworthy.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'list': requirePermission('security', 'Viewer'); return activityLogsList();
        default: error("Unknown Activity Logs action: {$action}", 404);
    }
}

function activityLogsList()
{
    $s = db()->query(
        "SELECT al.activity_log_id, al.created_at, u.username, u.role, al.action, al.module_name, al.details, al.status
         FROM activity_logs al
         LEFT JOIN users u ON u.user_id = al.user_id
         ORDER BY al.created_at DESC"
    );

    $out = array_map(function ($r) {
        return [
            'id'        => (int) $r['activity_log_id'],
            'timestamp' => $r['created_at'],
            // activity_logs.user_id is ON DELETE SET NULL (not CASCADE)
            // specifically so a purged account's history survives -- this
            // is the one place that matters.
            'user'      => $r['username'] ?? '(deleted user)',
            'role'      => $r['role'] ?? '',
            'action'    => $r['action'],
            'module'    => $r['module_name'],
            'details'   => $r['details'] ?? '',
            'status'    => $r['status'],
        ];
    }, $s->fetchAll());

    json(['status' => 'ok', 'data' => $out]);
}
