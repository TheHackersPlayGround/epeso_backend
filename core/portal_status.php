<?php
// An employer's status (Active / Inactive) and the status of its employer-portal login ALWAYS match:
//   - setting the employer to Inactive / Active also deactivates / activates its portal account, and
//   - deactivating / activating the portal account also sets the employer to Inactive / Active.
// Both functions below return true when they actually changed something. They are meant to run inside the same database transaction as
// the change that triggered them, so the two can never end up half-changed. (Migration 088 made the rows that already differed match.)

// True once migration 085 (the employer portal tables) has been run on this database.
function portalAccountsExist($pdo)
{
    return (bool) $pdo->query("SELECT to_regclass('public.employer_portal_accounts') IS NOT NULL")->fetchColumn();
}

// The employer's status changed -> give its portal account (if it has one) the same status.
function syncPortalAccountToEmployer($pdo, $employerId, $status)
{
    if (!in_array($status, ['Active', 'Inactive'], true) || !portalAccountsExist($pdo)) return false;
    $s = $pdo->prepare("UPDATE employer_portal_accounts SET status = :s, updated_at = now() WHERE employer_id = :e AND status <> :s");
    $s->execute([':s' => $status, ':e' => (int) $employerId]);
    return $s->rowCount() > 0;
}

// The portal account's status changed -> give its employer the same status.
function syncEmployerToPortalAccount($pdo, $employerId, $status)
{
    if (!in_array($status, ['Active', 'Inactive'], true)) return false;
    // employers.status is an enum, so the new value and the comparison use separate parameters (each one gets its own type).
    $s = $pdo->prepare("UPDATE employers SET status = :s, updated_at = now() WHERE employer_id = :e AND deleted_at IS NULL AND status::text <> :cur");
    $s->execute([':s' => $status, ':cur' => $status, ':e' => (int) $employerId]);
    return $s->rowCount() > 0;
}
