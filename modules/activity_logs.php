<?php
// Read-only audit trail of app-wide actions, written via logActivity().
// Never editable or deletable.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'list': requirePermission('security', 'Viewer'); return activityLogsList();
        case 'logExport': requireLogin(); return activityLogsLogExport();
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
            // user_id is ON DELETE SET NULL so a purged account's history survives.
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

// Records one line for a data export (Excel/PDF/Word) that the browser just produced.
// Exports are built client-side, so the browser tells us after the file is made.
function activityLogsLogExport()
{
    $d = body();
    $allowed = ['employment', 'ofw', 'cdsp', 'gip', 'spes', 'livelihood', 'skills', 'documents', 'security', 'report'];
    $module = in_array($d['module'] ?? '', $allowed, true) ? $d['module'] : 'report';
    $name = mb_substr(trim((string) ($d['name'] ?? '')), 0, 200);
    if ($name === '') error('Export name is required.', 422);
    $format = in_array($d['format'] ?? '', ['Excel', 'PDF', 'Word'], true) ? $d['format'] : null;
    $rows = isset($d['rows']) && is_numeric($d['rows']) && (int) $d['rows'] >= 0 ? (int) $d['rows'] : null;

    $details = "Exported {$name}" . ($format || $rows !== null
        ? ' (' . implode(', ', array_filter([$format, $rows !== null ? $rows . ' row' . ($rows === 1 ? '' : 's') : null])) . ')'
        : '');
    logActivity(currentUserId(), 'Export Records', $module, $details);
    json(['status' => 'ok']);
}
