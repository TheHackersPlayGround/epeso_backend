<?php
// Input validation, enum-cast, pagination

// Recycle bin retention: soft-deleted records older than this are auto-purged on next
// load. Keep in sync with ActivityLogsTab.tsx's getDaysRemaining() countdown.
if (!defined('RECYCLE_BIN_RETENTION_DAYS')) {
    define('RECYCLE_BIN_RETENTION_DAYS', 30);
}

// Strict whole-number parse: only plain digits accepted; "1.5"/"1e3"/"-2"/"" return null.
function wholeNumberOrNull($v)
{
    $s = trim((string) $v);
    return ctype_digit($s) ? (int) $s : null;
}

// Resolves a report window from the query string (?from=&to= or ?year=&month=, default
// current month); also returns the prior-year window for year-over-year comparisons.
function resolveReportWindow()
{
    $from = isset($_GET['from']) ? trim($_GET['from']) : '';
    $to   = isset($_GET['to'])   ? trim($_GET['to'])   : '';
    if ($from === '' || $to === '') {
        $y = isset($_GET['year'])  && is_numeric($_GET['year'])  ? (int) $_GET['year']  : (int) date('Y');
        $m = isset($_GET['month']) && is_numeric($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
        if ($m < 1 || $m > 12) error('Invalid month.', 422);
        $from = sprintf('%04d-%02d-01', $y, $m);
        $to   = date('Y-m-t', strtotime($from)); // last day of that month
    }
    $fromTs = strtotime($from);
    $toTs   = strtotime($to);
    if ($fromTs === false || $toTs === false) error('Invalid date range.', 422);
    $from = date('Y-m-d', $fromTs);
    $to   = date('Y-m-d', $toTs);
    if ($from > $to) error('The From date must be on or before the To date.', 422);

    return [
        'from'      => $from,
        'to'        => $to,
        'pfrom'     => date('Y-m-d', strtotime($from . ' -1 year')),
        'pto'       => date('Y-m-d', strtotime($to   . ' -1 year')),
        'curYear'   => (int) date('Y', $fromTs),
        'prevYear'  => (int) date('Y', $fromTs) - 1,
    ];
}

// Shape a DB users row into the form the frontend expects (camelCase, no password).
// Note: the DB has no email column; the frontend derives it from username.
function publicUser($u)
{
    return [
        'id'        => (int) $u['user_id'],
        'firstName' => $u['first_name'],
        'lastName'  => $u['last_name'],
        'username'  => $u['username'],
        'email'     => $u['username'] . '@peso.gov.ph',
        'role'      => $u['role'],
        'status'    => $u['status'],
        'lastLogin' => isset($u['last_login']) ? $u['last_login'] : null,
    ];
}

// Allowed enum values (exact casing the DB requires).
function validRoles()
{
    return ['Administrator', 'Staff'];
}

function validStatuses()
{
    return ['Active', 'Inactive'];
}
