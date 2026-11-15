<?php
// Staff side of the employer portal: the portal login each employer uses (administrators only).
//
// An employer account is NOT a staff user: it lives in its own table, belongs to exactly one employer, and can
// never sign in to the staff system. The admin sets the username; the system makes a one-time temporary password,
// shows it once, and the employer must change it at their first portal login.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'get':           requireAdmin(); return eaGet($id);
        case 'create':        requireAdmin(); return eaCreate();
        case 'resetPassword': requireAdmin(); return eaResetPassword($id);
        case 'setStatus':     requireAdmin(); return eaSetStatus($id);
        case 'activity':      requireAdmin(); return eaActivity();
        default:
            error("Unknown employer_accounts action: {$action}", 404);
    }
}

// GET /api/employer_accounts/activity  -- what employers did in their portal (Security -> Employer Activity; administrators only).
// The newest 5,000 lines: sign-ins and sign-outs, password changes, each applicant opened, each shared document viewed or downloaded.
function eaActivity()
{
    // document_id was added by migration 087; reading it through to_jsonb keeps this working on a database without it.
    $rows = db()->query(
        "SELECT l.log_id, l.created_at, l.action, l.ip_address, l.applicant_id,
                e.company_name, a.username,
                NULLIF(TRIM(CONCAT(b.last_name, ', ', b.first_name)), ',') AS applicant_name,
                d.file_name AS document_name, d.document_type
         FROM employer_access_log l
         LEFT JOIN employers e                ON e.employer_id = l.employer_id
         LEFT JOIN employer_portal_accounts a ON a.account_id = l.account_id
         LEFT JOIN beneficiaries b            ON b.beneficiary_id = l.applicant_id
         LEFT JOIN attached_documents d       ON d.document_id = CAST(to_jsonb(l)->>'document_id' AS bigint)
         WHERE l.action <> 'view_document'   -- on-screen document views are no longer logged (they count as viewing the applicant); older lines are left out
         ORDER BY l.created_at DESC, l.log_id DESC
         LIMIT 5000"
    )->fetchAll(PDO::FETCH_ASSOC);

    json(['status' => 'ok', 'data' => array_map(fn ($r) => [
        'id'            => (int) $r['log_id'],
        'timestamp'     => $r['created_at'],
        'company'       => $r['company_name'] ?? '(deleted employer)',
        'account'       => $r['username'] ?? '(deleted account)',
        'action'        => $r['action'],
        'applicantName' => $r['applicant_name'],
        'documentName'  => $r['document_name'],
        'documentType'  => $r['document_type'],
        'ipAddress'     => $r['ip_address'],
    ], $rows)]);
}

// A readable one-time password (no look-alike characters such as 0/O or 1/l).
function eaTempPassword()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

// The employer must exist and not be in the recycle bin.
function eaEmployerOrFail($id)
{
    if (!is_numeric($id)) error('Invalid employer id.', 422);
    $s = db()->prepare("SELECT employer_id, company_name FROM employers WHERE employer_id = :id AND deleted_at IS NULL");
    $s->execute([':id' => (int) $id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) error('Employer not found.', 404);
    return $row;
}

function eaRow($r)
{
    if (!$r) return null;
    return [
        'accountId'          => (int) $r['account_id'],
        'employerId'         => (int) $r['employer_id'],
        'username'           => $r['username'],
        'status'             => $r['status'],
        'mustChangePassword' => (bool) $r['must_change_password'],
        'createdAt'          => $r['created_at'],
        'updatedAt'          => $r['updated_at'],
    ];
}

function eaFetch($employerId)
{
    $s = db()->prepare(
        "SELECT account_id, employer_id, username, must_change_password, status, created_at, updated_at
         FROM employer_portal_accounts WHERE employer_id = :id"
    );
    $s->execute([':id' => (int) $employerId]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

// GET /api/employer_accounts/get/{employerId} -> the employer's portal account, or null if it has none yet.
function eaGet($employerId)
{
    eaEmployerOrFail($employerId);
    json(['status' => 'ok', 'data' => eaRow(eaFetch($employerId))]);
}

// POST /api/employer_accounts/create  { employerId, username } -> { account, temporaryPassword }
function eaCreate()
{
    $uid = requireAdmin();
    $d = body();
    $emp = eaEmployerOrFail($d['employerId'] ?? null);
    $username = strtolower(trim((string) ($d['username'] ?? '')));

    if (!preg_match('/^[a-z0-9][a-z0-9._-]{3,49}$/', $username)) {
        error('Username must be 4-50 characters: letters, numbers, dot, dash or underscore.', 422);
    }
    if (eaFetch($emp['employer_id'])) error('This employer already has a portal account.', 409);
    $taken = db()->prepare("SELECT 1 FROM employer_portal_accounts WHERE username = :u");
    $taken->execute([':u' => $username]);
    if ($taken->fetchColumn()) error('That username is already taken.', 409);

    $temp = eaTempPassword();
    try {
        db()->prepare(
            "INSERT INTO employer_portal_accounts (employer_id, username, password_hash, must_change_password, created_by)
             VALUES (:eid, :u, :h, TRUE, :by)"
        )->execute([':eid' => $emp['employer_id'], ':u' => $username, ':h' => password_hash($temp, PASSWORD_DEFAULT), ':by' => $uid]);
    } catch (Throwable $e) {
        error('Failed to create the portal account. Please try again.', 500);
    }

    logActivity($uid, 'Create Employer Portal Account', 'employment', "Created portal account \"{$username}\" for {$emp['company_name']}");
    json(['status' => 'ok', 'message' => 'Portal account created.', 'data' => [
        'account' => eaRow(eaFetch($emp['employer_id'])), 'temporaryPassword' => $temp,
    ]], 201);
}

// POST /api/employer_accounts/resetPassword/{employerId} -> { temporaryPassword }
function eaResetPassword($employerId)
{
    $uid = requireAdmin();
    $emp = eaEmployerOrFail($employerId);
    if (!eaFetch($emp['employer_id'])) error('This employer has no portal account yet.', 404);

    $temp = eaTempPassword();
    db()->prepare(
        "UPDATE employer_portal_accounts
         SET password_hash = :h, must_change_password = TRUE, updated_at = now()
         WHERE employer_id = :eid"
    )->execute([':h' => password_hash($temp, PASSWORD_DEFAULT), ':eid' => $emp['employer_id']]);

    logActivity($uid, 'Reset Employer Portal Password', 'employment', "Reset the portal password for {$emp['company_name']}");
    json(['status' => 'ok', 'message' => 'Password reset.', 'data' => ['temporaryPassword' => $temp]]);
}

// POST /api/employer_accounts/setStatus/{employerId}  { status: 'Active' | 'Inactive' }
function eaSetStatus($employerId)
{
    $uid = requireAdmin();
    $emp = eaEmployerOrFail($employerId);
    $status = (string) (body()['status'] ?? '');
    if (!in_array($status, ['Active', 'Inactive'], true)) error('Invalid status.', 422);
    if (!eaFetch($emp['employer_id'])) error('This employer has no portal account yet.', 404);

    db()->prepare("UPDATE employer_portal_accounts SET status = :s, updated_at = now() WHERE employer_id = :eid")
        ->execute([':s' => $status, ':eid' => $emp['employer_id']]);

    logActivity($uid, $status === 'Active' ? 'Activate Employer Portal Account' : 'Deactivate Employer Portal Account', 'employment',
        "Set the portal account of {$emp['company_name']} to {$status}");
    json(['status' => 'ok', 'message' => "Portal account is now {$status}.", 'data' => eaRow(eaFetch($emp['employer_id']))]);
}
