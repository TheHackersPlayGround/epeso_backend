<?php
// Staff side of the applicant kiosk: the "Pending" inbox. Submissions arrive from the public
// kiosk (modules/kiosk.php) as PENDING rows; staff look at them here and either approve (the
// normal Add Applicant save, which passes `submissionId` so the row is marked Approved in the
// same transaction -- see employmentCreateApplicant) or reject them.
//
// Who sees what: each program keeps its own permission. Someone only sees submissions for
// programs they can at least view, and only Editors (or administrators) can reject.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/duplicates.php';
include_once __DIR__ . '/../core/activity_log.php';
include_once __DIR__ . '/../core/submission_cleanup.php';
include_once __DIR__ . '/../core/kiosk_programs.php';

// Programs the inbox holds, with the permission module that guards each (defined in core/kiosk_programs.php).
function submissionPrograms()
{
    $out = [];
    foreach (kioskProgramRegistry() as $id => $cfg) {
        $out[$id] = ['permission' => $cfg['permission'], 'label' => $cfg['short']];
    }
    return $out;
}

// Router entry point. index.php calls this with the parsed action/id/method.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'list':           return submissionsList();
        case 'counts':         return submissionsCounts();
        case 'get':            return submissionsGet($id);
        case 'reject':         return submissionsReject($id);
        case 'delete':         return submissionsDelete($id);
        case 'kioskSettings':  return submissionsKioskSettings();
        case 'setKiosk':       return submissionsSetKiosk();
        case 'setKioskPrograms': return submissionsSetKioskPrograms();
        default:
            error("Unknown submissions action: {$action}", 404);
    }
}

// 'Editor' / 'Viewer' / null for this user on a permission module (administrators: Editor).
function subUserLevel($uid, $module)
{
    $stmt = db()->prepare("SELECT role FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $uid]);
    if ($stmt->fetchColumn() === 'Administrator') return 'Editor';

    $stmt = db()->prepare(
        "SELECT up.permission_level
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = :id AND p.permission_name = :module"
    );
    $stmt->execute([':id' => $uid, ':module' => $module]);
    $level = $stmt->fetchColumn();
    return $level === false ? null : $level;
}

// Program ids this user may at least view (or, with $needEditor, may act on).
function subAllowedPrograms($uid, $needEditor = false)
{
    $out = [];
    foreach (submissionPrograms() as $id => $p) {
        $level = subUserLevel($uid, $p['permission']);
        if ($level === 'Editor' || (!$needEditor && $level === 'Viewer')) $out[] = $id;
    }
    return $out;
}

// '{ef,gip}' -- a Postgres array literal for ANY(:x::text[]).
function subArrayLiteral($items)
{
    return '{' . implode(',', array_map(function ($s) { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"'; }, $items)) . '}';
}

// Shape one DB row for the frontend.
function subRowOut($r, $canEdit)
{
    $programs = submissionPrograms();
    return [
        'id'            => (int) $r['submission_id'],
        'referenceNo'   => $r['reference_no'],
        'program'       => $r['program'],
        'programLabel'  => $programs[$r['program']]['label'] ?? $r['program'],
        'applicantName' => $r['applicant_name'],
        'birthdate'     => $r['birth_date'],
        'status'        => $r['status'],
        'submittedAt'   => $r['submitted_at'],
        'reviewedAt'    => $r['reviewed_at'],
        'reviewedBy'    => isset($r['reviewer']) && trim($r['reviewer']) !== '' ? trim($r['reviewer']) : null,
        'rejectReason'  => $r['reject_reason'],
        // Rejected only: whole days left before it is deleted automatically (see core/submission_cleanup.php).
        'purgeInDays'   => isset($r['purge_in_days']) ? (int) $r['purge_in_days'] : null,
        'beneficiaryId' => $r['beneficiary_id'] !== null ? (int) $r['beneficiary_id'] : null,
        // Approved only: is the saved record still there? 'active', 'recycle' (in the Recycle Bin) or 'deleted'.
        'recordState'   => $r['record_state'] ?? null,
        'canEdit'       => $canEdit,
    ];
}

// The table is created by migration 083; say so clearly if it hasn't been run yet.
function subRequireTables()
{
    try {
        db()->query("SELECT 1 FROM applicant_submissions LIMIT 1");
    } catch (Throwable $e) {
        error('The kiosk tables are missing. Run migration 083_applicant_submissions.sql first.', 500);
    }
}

// GET /api/submissions/list?status=Pending|Approved|Rejected|all&program=ef
function submissionsList()
{
    $uid = requireLogin();
    $allowed = subAllowedPrograms($uid);
    if (!$allowed) error('You do not have permission to perform this action.', 403);
    subRequireTables();
    submissionsPurgeExpiredRejected(); // drop rejected ones past the 30-day retention before listing

    $editable = subAllowedPrograms($uid, true);
    $status   = isset($_GET['status']) ? (string) $_GET['status'] : 'Pending';
    $program  = isset($_GET['program']) ? (string) $_GET['program'] : '';
    if ($program !== '' && in_array($program, $allowed, true)) $allowed = [$program];

    $sql = "SELECT s.submission_id, s.reference_no, s.program, s.applicant_name, s.birth_date,
                   s.status::text AS status, s.submitted_at, s.reviewed_at, s.reject_reason, s.beneficiary_id,
                   CASE WHEN s.status = 'Rejected' AND s.reviewed_at IS NOT NULL
                        THEN GREATEST(0, ceil(" . (int) SUBMISSION_REJECTED_RETENTION_DAYS . " - extract(epoch FROM (now() - s.reviewed_at)) / 86400))::int
                   END AS purge_in_days,
                   CASE WHEN s.status = 'Pending' THEN s.payload::text END AS payload_json,
                   CASE WHEN s.status = 'Approved' THEN
                       CASE WHEN s.beneficiary_id IS NULL THEN 'deleted' WHEN b.deleted_at IS NOT NULL THEN 'recycle' ELSE 'active' END
                   END AS record_state,
                   trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')) AS reviewer
            FROM applicant_submissions s
            LEFT JOIN users u ON u.user_id = s.reviewed_by
            LEFT JOIN beneficiaries b ON b.beneficiary_id = s.beneficiary_id
            WHERE s.program = ANY(:programs::text[])";
    $params = [':programs' => subArrayLiteral($allowed)];
    if (in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
        $sql .= " AND s.status = :status::submission_status_enum";
        $params[':status'] = $status;
    }
    // Waiting ones first (oldest first, so nobody is forgotten), then everything else newest first.
    $sql .= " ORDER BY CASE WHEN s.status = 'Pending' THEN 0 ELSE 1 END,
                       CASE WHEN s.status = 'Pending' THEN s.submitted_at END ASC,
                       s.submitted_at DESC
              LIMIT 500";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $row = subRowOut($r, in_array($r['program'], $editable, true));
        // Pending only: is this person already in the program? (same name + birthdate)
        $row['duplicates'] = [];
        $cfg = kioskProgramRegistry()[$r['program']] ?? null;
        if ($r['status'] === 'Pending' && $cfg !== null && $r['payload_json'] !== null) {
            $form = json_decode($r['payload_json'], true) ?: [];
            $row['duplicates'] = dupFindExisting(dupServiceIds($cfg['dup']), (string) ($form[$cfg['first']] ?? ''), (string) ($form[$cfg['last']] ?? ''), $r['birth_date']);
        }
        $out[] = $row;
    }
    json(['status' => 'ok', 'data' => $out]);
}

// GET /api/submissions/counts -> { total, byProgram }  (pending only; drives the navbar badge)
function submissionsCounts()
{
    $uid = requireLogin();
    $allowed = subAllowedPrograms($uid);
    $byProgram = [];
    foreach ($allowed as $p) $byProgram[$p] = 0;
    if (!$allowed) json(['status' => 'ok', 'data' => ['total' => 0, 'byProgram' => (object) [], 'ready' => true]]);

    try {
        $stmt = db()->prepare(
            "SELECT program, count(*) AS n FROM applicant_submissions
             WHERE status = 'Pending' AND program = ANY(:programs::text[]) GROUP BY program"
        );
        $stmt->execute([':programs' => subArrayLiteral($allowed)]);
    } catch (Throwable $e) {
        // Migration not run yet: show no badge instead of erroring on every poll.
        json(['status' => 'ok', 'data' => ['total' => 0, 'byProgram' => (object) [], 'ready' => false]]);
    }
    $total = 0;
    foreach ($stmt->fetchAll() as $r) {
        $byProgram[$r['program']] = (int) $r['n'];
        $total += (int) $r['n'];
    }
    // A cheap "has anything changed?" signature for the Pending page, which polls this every few seconds and
    // reloads the full list only when it differs: row count + newest id + latest submit / review time.
    $sig = db()->prepare(
        "SELECT count(*) AS n, coalesce(max(submission_id), 0) AS latest_id,
                max(greatest(submitted_at, coalesce(reviewed_at, submitted_at))) AS updated_at
         FROM applicant_submissions WHERE program = ANY(:programs::text[])"
    );
    $sig->execute([':programs' => subArrayLiteral($allowed)]);
    $sg = $sig->fetch();
    json(['status' => 'ok', 'data' => [
        'total' => $total, 'byProgram' => (object) $byProgram, 'ready' => true,
        'signature' => ((int) $sg['n']) . '|' . ((int) $sg['latest_id']) . '|' . ($sg['updated_at'] ?? ''),
    ]]);
}

// GET /api/submissions/get/{id} -> the row plus its full form data (to open it pre-filled).
function submissionsGet($id)
{
    $uid = requireLogin();
    $id = (int) $id;
    subRequireTables();
    $stmt = db()->prepare(
        "SELECT s.submission_id, s.reference_no, s.program, s.applicant_name, s.birth_date,
                s.status::text AS status, s.submitted_at, s.reviewed_at, s.reject_reason, s.beneficiary_id,
                s.payload,
                CASE WHEN s.status = 'Approved' THEN
                       CASE WHEN s.beneficiary_id IS NULL THEN 'deleted' WHEN b.deleted_at IS NOT NULL THEN 'recycle' ELSE 'active' END
                   END AS record_state,
                trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')) AS reviewer
         FROM applicant_submissions s
         LEFT JOIN users u ON u.user_id = s.reviewed_by
         LEFT JOIN beneficiaries b ON b.beneficiary_id = s.beneficiary_id
         WHERE s.submission_id = :id"
    );
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    if (!$r) error('Submission not found.', 404);

    $programs = submissionPrograms();
    $level = isset($programs[$r['program']]) ? subUserLevel($uid, $programs[$r['program']]['permission']) : null;
    if ($level === null) error('You do not have permission to perform this action.', 403);

    $out = subRowOut($r, $level === 'Editor');
    $out['payload'] = json_decode($r['payload'], true);
    json(['status' => 'ok', 'data' => $out]);
}

// POST /api/submissions/reject/{id}  { reason }
function submissionsReject($id)
{
    $uid = requireLogin();
    $id = (int) $id;
    subRequireTables();
    $d = body();
    $reason = mb_substr(trim((string) ($d['reason'] ?? '')), 0, 500);
    if ($reason === '') error('Please enter the reason for rejecting this submission.', 422);

    $stmt = db()->prepare("SELECT program FROM applicant_submissions WHERE submission_id = :id");
    $stmt->execute([':id' => $id]);
    $program = $stmt->fetchColumn();
    if ($program === false) error('Submission not found.', 404);
    $programs = submissionPrograms();
    if (!isset($programs[$program]) || subUserLevel($uid, $programs[$program]['permission']) !== 'Editor') {
        error('You do not have permission to perform this action.', 403);
    }

    // Only a still-pending one can be rejected; the WHERE makes this safe if two staff act at once.
    $upd = db()->prepare(
        "UPDATE applicant_submissions
         SET status = 'Rejected', reviewed_by = :uid, reviewed_at = now(), reject_reason = :reason
         WHERE submission_id = :id AND status = 'Pending'
         RETURNING reference_no"
    );
    $upd->execute([':uid' => $uid, ':reason' => $reason, ':id' => $id]);
    $ref = $upd->fetchColumn();
    if ($ref === false) error('This submission was already approved or rejected.', 409);

    logActivity($uid, 'Reject Submission', 'employment', "Rejected kiosk submission {$ref}");
    json(['status' => 'ok', 'message' => 'Submission rejected.']);
}

// POST /api/submissions/delete/{id}
// Permanently removes a REJECTED submission (it holds a person's details for no reason once
// staff have turned it down). Pending ones must be rejected first and approved ones are kept
// as the record of where the applicant came from. Hard delete: no recycle bin. The activity
// log gets the reference number only, never the applicant's name or details.
function submissionsDelete($id)
{
    $uid = requireLogin();
    $id = (int) $id;
    subRequireTables();

    $stmt = db()->prepare(
        "SELECT s.program, s.status::text AS status,
                CASE WHEN s.beneficiary_id IS NULL THEN 'deleted' WHEN b.deleted_at IS NOT NULL THEN 'recycle' ELSE 'active' END AS record_state
         FROM applicant_submissions s
         LEFT JOIN beneficiaries b ON b.beneficiary_id = s.beneficiary_id
         WHERE s.submission_id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) error('Submission not found.', 404);

    $programs = submissionPrograms();
    if (!isset($programs[$row['program']]) || subUserLevel($uid, $programs[$row['program']]['permission']) !== 'Editor') {
        error('You do not have permission to perform this action.', 403);
    }
    // Rejected ones can go any time. An Approved one can go only once its record has been deleted (the entry
    // is then just history); while the record exists, the entry is the proof of where it came from.
    $approvedGone = $row['status'] === 'Approved' && $row['record_state'] !== 'active';
    if ($row['status'] !== 'Rejected' && !$approvedGone) {
        error('Only rejected submissions, or approved ones whose record was deleted, can be deleted.', 409);
    }

    // The same rule is repeated in the DELETE so a concurrent change (e.g. the record being restored) can't slip through.
    $del = db()->prepare(
        "DELETE FROM applicant_submissions
         WHERE submission_id = :id
           AND (status = 'Rejected'
                OR (status = 'Approved' AND (beneficiary_id IS NULL
                    OR EXISTS (SELECT 1 FROM beneficiaries b WHERE b.beneficiary_id = applicant_submissions.beneficiary_id AND b.deleted_at IS NOT NULL))))
         RETURNING reference_no"
    );
    $del->execute([':id' => $id]);
    $ref = $del->fetchColumn();
    if ($ref === false) error('This submission can no longer be deleted.', 409);

    logActivity($uid, 'Delete Submission', 'employment', $approvedGone
        ? "Deleted approved kiosk submission {$ref} (its record was already deleted)"
        : "Deleted rejected kiosk submission {$ref}");
    json(['status' => 'ok', 'message' => 'Submission deleted.']);
}

// GET /api/submissions/kioskSettings -> { enabled }
function submissionsKioskSettings()
{
    $uid = requireLogin();
    if (!subAllowedPrograms($uid)) error('You do not have permission to perform this action.', 403);
    try {
        $v = db()->query("SELECT setting_value FROM app_settings WHERE setting_key = 'kiosk_enabled'")->fetchColumn();
    } catch (Throwable $e) {
        error('The kiosk tables are missing. Run migration 083_applicant_submissions.sql first.', 500);
    }
    // The master switch, plus which programs are on (each one can be closed on its own, e.g. outside the SPES season).
    $on = kioskEnabledPrograms();
    $programs = [];
    foreach (kioskProgramRegistry() as $id => $cfg) {
        $programs[] = ['id' => $id, 'label' => $cfg['short'], 'enabled' => in_array($id, $on, true)];
    }
    json(['status' => 'ok', 'data' => ['enabled' => $v === 'true', 'programs' => $programs]]);
}

// POST /api/submissions/setKioskPrograms  { programs: ['ef', 'cdsp', ...] }   (administrators only)
// The programs listed are on; every other program is off.
function submissionsSetKioskPrograms()
{
    $uid = requireAdmin();
    $d = body();
    if (!isset($d['programs']) || !is_array($d['programs'])) error('Missing "programs".', 422);
    $known = array_keys(kioskProgramRegistry());
    $picked = [];
    foreach ($d['programs'] as $p) {
        if (!is_string($p) || !in_array($p, $known, true)) error('Unknown program.', 422);
        $picked[$p] = true;
    }
    $list = array_values(array_intersect($known, array_keys($picked))); // registry order, no duplicates
    subRequireTables();
    db()->prepare(
        "INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES ('kiosk_programs', :v, :uid)
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now(), updated_by = EXCLUDED.updated_by"
    )->execute([':v' => implode(',', $list), ':uid' => $uid]);
    logActivity($uid, 'Kiosk Programs', 'employment', 'Kiosk services set to: ' . ($list ? implode(', ', $list) : 'none'));
    $on = kioskEnabledPrograms();
    $programs = [];
    foreach (kioskProgramRegistry() as $id => $cfg) {
        $programs[] = ['id' => $id, 'label' => $cfg['short'], 'enabled' => in_array($id, $on, true)];
    }
    json(['status' => 'ok', 'data' => ['programs' => $programs]]);
}

// POST /api/submissions/setKiosk  { enabled: bool }   (administrators only)
function submissionsSetKiosk()
{
    $uid = requireAdmin();
    $d = body();
    if (!array_key_exists('enabled', $d)) error('Missing "enabled".', 422);
    $enabled = ($d['enabled'] === true || $d['enabled'] === 'true' || $d['enabled'] === 1) ? 'true' : 'false';
    subRequireTables();
    db()->prepare(
        "INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES ('kiosk_enabled', :v, :uid)
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now(), updated_by = EXCLUDED.updated_by"
    )->execute([':v' => $enabled, ':uid' => $uid]);
    logActivity($uid, $enabled === 'true' ? 'Open Kiosk' : 'Close Kiosk', 'employment', 'Applicant self-service kiosk turned ' . ($enabled === 'true' ? 'on' : 'off'));
    json(['status' => 'ok', 'data' => ['enabled' => $enabled === 'true']]);
}
