<?php
// Input validation, enum-cast, pagination

// Recycle bin retention: a soft-deleted record older than this is
// auto-purged the next time anyone loads the recycle bin (each module's
// {prefix}PurgeExpired(), called from {prefix}ListDeleted() -- see e.g.
// gip.php). Mirrors the "N days left" countdown already shown in
// src/pages/security/ActivityLogsTab.tsx's getDaysRemaining() -- that
// display was previously cosmetic only; this is what makes it real. Keep
// both numbers in sync if this ever changes.
if (!defined('RECYCLE_BIN_RETENTION_DAYS')) {
    define('RECYCLE_BIN_RETENTION_DAYS', 30);
}

// Resolve a report's reporting window from the query string. Explicit
// ?from=&to= wins; otherwise falls back to ?year=&month= (a single calendar
// month), defaulting to the current month. Also returns the prior-period
// window (same span shifted back one year) for year-over-year comparisons.
// Shared by employment.php's monthlyReport and reports.php's summary so both
// support the same Monthly / Annual / Custom Range modes the frontend offers.
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
