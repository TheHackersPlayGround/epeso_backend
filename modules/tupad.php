<?php
// TUPAD: projects (tupad_projects) + profiles (beneficiary spine, no dedicated
// profile table). No classification or 4Ps fields — the TUPAD applicant form
// never collected them.
//
// Unlike DILP (one project at a time, enforced by a UNIQUE constraint on
// beneficiary_service_id alone), tupad_project_beneficiaries is only unique on
// (project_id, beneficiary_service_id) — a beneficiary can carry a running
// history of TUPAD project assignments. "Current" is simply the most recently
// assigned row; assigning never deletes older rows, so completed assignments
// stay visible as history.
//
// Beneficiary status is derived at read time from the current assignment's
// project status, same rule as DILP: Inactive (none), Active (Planned/Ongoing),
// Completed (Completed).

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listProjects':        requirePermission('livelihood','Viewer'); return tupadListProjects();
        case 'createProject':       requirePermission('livelihood','Editor'); return tupadCreateProject();
        case 'updateProject':       requirePermission('livelihood','Editor'); return tupadUpdateProject($id);
        case 'deleteProject':       requirePermission('livelihood','Editor'); return tupadDeleteProject($id);
        case 'updateProjectStatus': requirePermission('livelihood','Editor'); return tupadUpdateProjectStatus($id);
        case 'listProfiles':        requirePermission('livelihood','Viewer'); return tupadListProfiles();
        case 'getProfile':          requirePermission('livelihood','Viewer'); return tupadGetProfile($id);
        case 'createProfile':       requirePermission('livelihood','Editor'); return tupadCreateProfile();
        case 'updateProfile':       requirePermission('livelihood','Editor'); return tupadUpdateProfile($id);
        case 'deleteProfile':       requirePermission('livelihood','Editor'); return tupadDeleteProfile($id);
        case 'assignProject':       requirePermission('livelihood','Editor'); return tupadAssignProject();
        case 'unassignProject':     requirePermission('livelihood','Editor'); return tupadUnassignProject();
        case 'listDeleted':         requirePermission('livelihood','Viewer'); return tupadListDeleted();
        case 'restoreRecord':       requirePermission('livelihood','Editor'); return tupadRestoreRecord();
        case 'purgeRecord':         requirePermission('livelihood','Editor'); return tupadPurgeRecord();
        default: error("Unknown TUPAD action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function tupadNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function tupadDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function tupadIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }

function tupadMoneyOrNull($v) {
    if ($v === null) return null;
    $s = trim(str_replace([',', '₱', ' '], '', (string)$v));
    return ($s === '' || !is_numeric($s)) ? null : (float)$s;
}

function tupadServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='TUPAD' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('TUPAD service not found.', 500);
    return (int) $sid;
}

// Same derivation rule as DILP: Active while Planned/Ongoing, Completed once
// Completed, Inactive otherwise (no assignment — activity_status_enum has no
// Cancelled state, so there's no extra case to fall back from here).
function tupadDeriveStatus($projectStatus) {
    if ($projectStatus === 'Completed') return 'Completed';
    if ($projectStatus === 'Planned' || $projectStatus === 'Ongoing') return 'Active';
    return 'Inactive';
}

function tupadUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function tupadFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// ─── Projects ─────────────────────────────────────────────────────────────────

function tupadListProjects() {
    $s = db()->query(
        "SELECT p.*, (SELECT COUNT(*) FROM tupad_project_beneficiaries pb WHERE pb.project_id = p.project_id) AS assigned_count
         FROM tupad_projects p
         ORDER BY p.created_at DESC, p.project_id DESC"
    );
    json(['status' => 'ok', 'data' => array_map('tupadFormatProject', $s->fetchAll())]);
}

function tupadGetProjectById($id) {
    $s = db()->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM tupad_project_beneficiaries pb WHERE pb.project_id = p.project_id) AS assigned_count
         FROM tupad_projects p WHERE p.project_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? tupadFormatProject($r) : null;
}

function tupadFormatProject($r) {
    return [
        'id'               => (int) $r['project_id'],
        'title'            => $r['title'],
        'description'      => $r['description'] ?? '',
        'date'             => $r['project_date'],
        'location'         => $r['location'] ?? '',
        'facilitator'      => $r['facilitator'] ?? '',
        'participants'     => $r['participant_count'] !== null ? (string) $r['participant_count'] : '',
        'status'           => $r['status'],
        'assistanceAmount' => $r['assistance_amount'] !== null ? (string) $r['assistance_amount'] : '',
        'dateReleased'     => $r['date_released'] ?? '',
        'assignedCount'    => (int) $r['assigned_count'],
        'documents'        => tupadFetchProjectDocuments((int) $r['project_id']),
    ];
}

function tupadValidateProjectInput($d) {
    $title = trim($d['title'] ?? '');
    if ($title === '') error('Activity title is required.', 422);

    $date = tupadDate($d['date'] ?? '');
    if (!$date) error('Date is required.', 422);

    return [$title, $date];
}

function tupadCreateProject() {
    $uid = requireLogin();
    $d = body();
    [$title, $date] = tupadValidateProjectInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $s = $pdo->prepare(
        "INSERT INTO tupad_projects(title,description,project_date,location,facilitator,participant_count,status,assistance_amount,date_released,created_at,updated_at)
         VALUES(:title,:desc,:date,:loc,:fac,:parts,:status,:amt,:dtrel,now(),now()) RETURNING project_id"
    );
    $s->execute([
        ':title' => $title, ':desc' => tupadNullStr($d['description'] ?? ''), ':date' => $date,
        ':loc' => tupadNullStr($d['location'] ?? ''), ':fac' => tupadNullStr($d['facilitator'] ?? ''),
        ':parts' => tupadIntOrNull($d['participants'] ?? null), ':status' => $status,
        ':amt' => tupadMoneyOrNull($d['assistanceAmount'] ?? null), ':dtrel' => tupadDate($d['dateReleased'] ?? ''),
    ]);
    $id = (int) $s->fetchColumn();

    tupadSyncProjectDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Project created.', 'data' => tupadGetProjectById($id)]);
}

function tupadUpdateProject($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id  = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$title, $date] = tupadValidateProjectInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $chk = $pdo->prepare("SELECT 1 FROM tupad_projects WHERE project_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Project not found.', 404);

    $pdo->prepare(
        "UPDATE tupad_projects SET title=:title,description=:desc,project_date=:date,location=:loc,facilitator=:fac,participant_count=:parts,status=:status,assistance_amount=:amt,date_released=:dtrel,updated_at=now() WHERE project_id=:id"
    )->execute([
        ':title' => $title, ':desc' => tupadNullStr($d['description'] ?? ''), ':date' => $date,
        ':loc' => tupadNullStr($d['location'] ?? ''), ':fac' => tupadNullStr($d['facilitator'] ?? ''),
        ':parts' => tupadIntOrNull($d['participants'] ?? null), ':status' => $status,
        ':amt' => tupadMoneyOrNull($d['assistanceAmount'] ?? null), ':dtrel' => tupadDate($d['dateReleased'] ?? ''),
        ':id' => $id,
    ]);

    tupadSyncProjectDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Project updated.', 'data' => tupadGetProjectById($id)]);
}

function tupadUpdateProjectStatus($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id = (int) $id;
    $d  = body();
    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);

    $chk = db()->prepare("SELECT 1 FROM tupad_projects WHERE project_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Project not found.', 404);

    // No cascade write needed: beneficiary status is derived at read time from
    // this same status column.
    db()->prepare("UPDATE tupad_projects SET status=:s, updated_at=now() WHERE project_id=:id")->execute([':s' => $status, ':id' => $id]);
    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => tupadGetProjectById($id)]);
}

function tupadDeleteProject($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id = (int) $id;

    $cntS = db()->prepare("SELECT COUNT(*) FROM tupad_project_beneficiaries WHERE project_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int) $cntS->fetchColumn();
    if ($cnt > 0) {
        error("Cannot delete: {$cnt} " . ($cnt === 1 ? 'beneficiary' : 'beneficiaries') . " assigned to this project. Unassign them first.", 409);
    }

    $docs = db()->prepare("SELECT file_path FROM documents WHERE tupad_project_id=:id");
    $docs->execute([':id' => $id]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    db()->prepare("DELETE FROM tupad_projects WHERE project_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Project deleted.']);
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function tupadListProfiles() {
    $s = db()->prepare(
        "SELECT DISTINCT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => tupadServiceId()]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $bid) {
        $p = tupadBuildProfile((int) $bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function tupadGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = tupadBuildProfile((int) $id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

function tupadBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.date_applied, bs.received_by, bs.remarks
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND bs.service_id = :sid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':sid' => tupadServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int) $b['beneficiary_service_id'];

    // Current assignment = most recently assigned row; older rows (if any)
    // survive as history rather than being deleted on reassignment.
    $histS = db()->prepare(
        "SELECT tpb.project_id, tpb.assigned_at, tp.title, tp.status
         FROM tupad_project_beneficiaries tpb
         JOIN tupad_projects tp ON tp.project_id = tpb.project_id
         WHERE tpb.beneficiary_service_id = :bsid
         ORDER BY tpb.assigned_at DESC, tpb.project_beneficiary_id DESC"
    );
    $histS->execute([':bsid' => $bsId]);
    $history = $histS->fetchAll();
    $current = $history[0] ?? null;

    $assignmentHistory = array_map(function ($h) {
        return [
            'projectId'     => (int) $h['project_id'],
            'projectName'   => $h['title'],
            'assignedDate'  => $h['assigned_at'],
            'completedDate' => $h['status'] === 'Completed' ? $h['assigned_at'] : null,
        ];
    }, $history);

    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (new DateTime($b['birth_date']))->diff(new DateTime('today'))->y;
    }

    return [
        'id'                    => $bid,
        'beneficiaryServiceId'  => $bsId,
        'lastName'              => $b['last_name'],
        'firstName'             => $b['first_name'],
        'middleName'            => $b['middle_name'] ?? '',
        'nameExtension'         => $b['suffix'] ?? '',
        'sex'                   => $b['sex'] ?? '',
        'birthdate'             => $b['birth_date'] ?? '',
        'age'                   => $age,
        'civilStatus'           => $b['civil_status'] ?? '',
        'contactNumber'         => $b['contact_no'] ?? '',
        'streetPurok'           => $b['street_address'] ?? '',
        'barangay'              => $b['barangay_name'] ?? '',
        'barangayId'            => (int) ($b['barangay_id'] ?? 0),
        'cityMunicipality'      => $b['city_name'] ?? '',
        'province'              => $b['province_name'] ?? '',
        'region'                => $b['region_name'] ?? '',
        'assignedProjectId'     => $current ? (int) $current['project_id'] : null,
        'assignedProjectName'   => $current['title'] ?? '',
        'assignedProjectStatus' => $current['status'] ?? '',
        'assignmentHistory'     => $assignmentHistory,
        'attachedDocuments'     => tupadFetchSavedDocuments($bid),
        'dateApplied'           => $b['date_applied'] ?? '',
        'remarks'               => $b['remarks'] ?? '',
        'receivedBy'            => $b['received_by'] ?? '',
        'status'                => tupadDeriveStatus($current['status'] ?? null),
    ];
}

// Required fields mirror beneficiaries' real NOT NULL columns (sex, birth_date,
// civil_status, barangay_id) so a bad request 422s cleanly instead of failing
// as a raw DB constraint violation.
function tupadValidateProfileInput($d) {
    if (tupadNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (tupadNullStr($d['lastName'] ?? '') === null)  error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male', 'Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = tupadDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    $validCivil = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;
    if (!$civil) error('Civil status is required.', 422);

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int) $d['barangayId'] > 0)
             ? (int) $d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    return [$sex, $birth, $civil, $bgyId];
}

function tupadCreateProfile() {
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = tupadValidateProfileInput($d);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare(
            "INSERT INTO beneficiaries(first_name,middle_name,last_name,suffix,sex,birth_date,civil_status,street_address,barangay_id,contact_no,status)
             VALUES(:fn,:mn,:ln,:sfx,:sex,:bdate,:civil,:street,:bgy,:contact,'Active') RETURNING beneficiary_id"
        );
        $s->execute([
            ':fn' => trim($d['firstName']), ':mn' => tupadNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => tupadNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => tupadNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId,
            ':contact' => tupadNullStr($d['contactNumber'] ?? ''),
        ]);
        $bid = (int) $s->fetchColumn();

        $s2 = $pdo->prepare(
            "INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by,remarks) VALUES(:bid,:sid,'Active',:date,:rby,:rmk) RETURNING beneficiary_service_id"
        );
        $s2->execute([
            ':bid' => $bid, ':sid' => tupadServiceId(), ':date' => tupadDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
            ':rby' => tupadNullStr($d['receivedBy'] ?? ''), ':rmk' => tupadNullStr($d['remarks'] ?? ''),
        ]);
        $bsId = (int) $s2->fetchColumn();

        tupadSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => tupadBuildProfile($bid)]);
}

function tupadUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = tupadValidateProfileInput($d);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => tupadServiceId()]);
    $bsId = $chk->fetchColumn();
    if (!$bsId) error('TUPAD profile not found.', 404);
    $bsId = (int) $bsId;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,suffix=:sfx,sex=:sex,birth_date=:bdate,civil_status=:civil,street_address=:street,barangay_id=:bgy,contact_no=:contact,updated_at=now() WHERE beneficiary_id=:bid"
        )->execute([
            ':fn' => trim($d['firstName']), ':mn' => tupadNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => tupadNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => tupadNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId,
            ':contact' => tupadNullStr($d['contactNumber'] ?? ''), ':bid' => $bid,
        ]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby,date_applied=:date,remarks=:rmk WHERE beneficiary_service_id=:bsid")
            ->execute([
                ':rby' => tupadNullStr($d['receivedBy'] ?? ''), ':date' => tupadDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
                ':rmk' => tupadNullStr($d['remarks'] ?? ''), ':bsid' => $bsId,
            ]);

        tupadSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => tupadBuildProfile($bid)]);
}

function tupadDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();

    // Cannot delete a beneficiary whose most recent assignment is to a
    // non-completed project — unassign first, same lock spirit as DILP/SPES.
    $chk = db()->prepare(
        "SELECT tp.status
         FROM beneficiary_services bs
         JOIN tupad_project_beneficiaries tpb ON tpb.beneficiary_service_id = bs.beneficiary_service_id
         JOIN tupad_projects tp ON tp.project_id = tpb.project_id
         WHERE bs.beneficiary_id = :bid AND bs.service_id = :sid
         ORDER BY tpb.assigned_at DESC, tpb.project_beneficiary_id DESC LIMIT 1"
    );
    $chk->execute([':bid' => $bid, ':sid' => tupadServiceId()]);
    $projStatus = $chk->fetchColumn();
    if ($projStatus !== false && in_array($projStatus, ['Planned', 'Ongoing'], true)) {
        error('This beneficiary cannot be deleted because they are currently assigned to an active project. Unassign them first.', 409);
    }

    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Beneficiary moved to recycle bin.']);
}

// ─── Assign / Unassign project ────────────────────────────────────────────────
// tupad_project_beneficiaries is unique on (project_id, beneficiary_service_id)
// only — assigning always inserts (or refreshes an existing identical link),
// never deletes an older assignment, so completed projects remain in history.

function tupadAssignProject() {
    requireLogin();
    $d = body();
    $bid       = tupadIntOrNull($d['applicantId'] ?? '');
    $projectId = tupadIntOrNull($d['projectId'] ?? '');
    if (!$bid || !$projectId) error('applicantId and projectId are required.', 422);

    $bsS = db()->prepare(
        "SELECT bs.beneficiary_service_id FROM beneficiary_services bs
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $bsS->execute([':bid' => $bid, ':sid' => tupadServiceId()]);
    $bsId = $bsS->fetchColumn();
    if (!$bsId) error('TUPAD profile not found.', 404);

    $projS = db()->prepare("SELECT status, participant_count FROM tupad_projects WHERE project_id=:id");
    $projS->execute([':id' => $projectId]);
    $proj = $projS->fetch();
    if (!$proj) error('Project not found.', 404);
    if ($proj['status'] !== 'Planned') error('Only Planned projects can be assigned.', 409);

    // Number of Participants is an optional target headcount (unlike GIP/SPES's
    // required slot_count) — only enforce a cap when one was actually set.
    if ($proj['participant_count'] !== null) {
        $cntS = db()->prepare(
            "SELECT COUNT(*) FROM tupad_project_beneficiaries
             WHERE project_id=:id AND beneficiary_service_id != :bsid"
        );
        $cntS->execute([':id' => $projectId, ':bsid' => (int) $bsId]);
        $current = (int) $cntS->fetchColumn();
        if ($current >= (int) $proj['participant_count']) {
            error("This project is already at full capacity ({$current}/{$proj['participant_count']}).", 409);
        }
    }

    db()->prepare(
        "INSERT INTO tupad_project_beneficiaries(project_id,beneficiary_service_id,assigned_at)
         VALUES(:pid,:bsid,now())
         ON CONFLICT (project_id, beneficiary_service_id) DO UPDATE SET assigned_at=now()"
    )->execute([':pid' => $projectId, ':bsid' => (int) $bsId]);

    json(['status' => 'ok', 'message' => 'Beneficiary assigned to project.']);
}

function tupadUnassignProject() {
    requireLogin();
    $d = body();
    $bid = tupadIntOrNull($d['applicantId'] ?? '');
    if (!$bid) error('applicantId is required.', 422);

    $row = db()->prepare(
        "SELECT tpb.project_beneficiary_id, tp.status AS project_status
         FROM beneficiary_services bs
         JOIN tupad_project_beneficiaries tpb ON tpb.beneficiary_service_id = bs.beneficiary_service_id
         JOIN tupad_projects tp ON tp.project_id = tpb.project_id
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid
         ORDER BY tpb.assigned_at DESC, tpb.project_beneficiary_id DESC LIMIT 1"
    );
    $row->execute([':bid' => $bid, ':sid' => tupadServiceId()]);
    $r = $row->fetch();
    if (!$r) error('This beneficiary is not currently assigned to a project.', 404);

    // Only the current (most recent) assignment can be undone, and only while
    // its project is still Planned — once it has moved on, this row is the
    // sole record that the assignment happened at all.
    if ($r['project_status'] !== 'Planned') {
        error('This project is no longer Planned — unassigning would erase the only record of this assignment.', 409);
    }

    db()->prepare("DELETE FROM tupad_project_beneficiaries WHERE project_beneficiary_id=:id")
        ->execute([':id' => (int) $r['project_beneficiary_id']]);

    json(['status' => 'ok', 'message' => 'Beneficiary unassigned from project.']);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────

function tupadSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    $docs = is_array($d['attachedDocuments'] ?? null) ? $d['attachedDocuments'] : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='TUPAD'");
    $sel->execute([':bid' => $bid]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(beneficiary_id,beneficiary_service_id,document_source,document_type,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:bid,:bsid,'TUPAD',:dtype,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'tupad_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $dtype  = tupadNullStr($doc['documentType'] ?? '');
        $custom = tupadNullStr($doc['customName'] ?? $doc['name'] ?? '');
        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':dtype' => $dtype,
            ':title' => $custom ?? ($dtype ?? $origName),
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function tupadFetchSavedDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='TUPAD' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    return array_map(function ($r) {
        return [
            'id'           => (string) $r['document_id'],
            'documentType' => $r['document_type'] ?? '',
            'customName'   => $r['title'] ?? '',
            'fileName'     => $r['file_name'],
            'fileSize'     => tupadFormatBytes($r['file_size'] ?? 0),
            'url'          => tupadUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ─── Documents (project) ──────────────────────────────────────────────────────

function tupadSyncProjectDocuments($pdo, $projectId, $uid, $docsPayload) {
    $docs = is_array($docsPayload) ? $docsPayload : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE tupad_project_id=:pid");
    $sel->execute([':pid' => $projectId]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(tupad_project_id,document_source,document_type,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:pid,'TUPAD Project',NULL,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'tupad_project_' . $projectId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':pid' => $projectId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function tupadFetchProjectDocuments($projectId) {
    $s = db()->prepare(
        "SELECT document_id, file_name, file_path, file_size
         FROM documents WHERE tupad_project_id=:pid ORDER BY document_id"
    );
    $s->execute([':pid' => $projectId]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'fileName' => $r['file_name'],
            'fileSize' => tupadFormatBytes($r['file_size'] ?? 0),
            'url'      => tupadUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN  (soft-deleted TUPAD beneficiaries) — mirrors spes.php's pattern.
// ═══════════════════════════════════════════════════════════════════════════════

function tupadRecycleMap() {
    return [
        'tupadApplicant' => ['beneficiaries', 'beneficiary_id'],
    ];
}

function tupadRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(tupadRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

function tupadListDeleted() {
    $s = db()->prepare(
        "SELECT b.beneficiary_id AS id,
                CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL THEN ' ' || LEFT(b.middle_name, 1) || '.' ELSE '' END) AS name,
                b.deleted_at, u.username AS deleted_by
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id AND bs.service_id = :sid
         LEFT JOIN users u ON u.user_id = b.deleted_by
         WHERE b.deleted_at IS NOT NULL"
    );
    $s->execute([':sid' => tupadServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType'  => 'tupadApplicant',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'TUPAD Beneficiaries',
            'description' => 'Tulong Panghanapbuhay sa Disadvantaged Workers beneficiary record',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

function tupadRestoreRecord() {
    [$type, $id] = tupadRecycleTarget();
    [$table, $pk] = tupadRecycleMap()[$type];

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    json(['status' => 'ok', 'message' => 'Record restored.']);
}

function tupadPurgeRecord() {
    [$type, $id] = tupadRecycleTarget();
    [$table, $pk] = tupadRecycleMap()[$type];

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    tupadHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

function tupadHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
        $bsStmt->execute([':id' => $bid, ':sid' => tupadServiceId()]);
        $bsId = $bsStmt->fetchColumn();
        if ($bsId !== false) {
            $pdo->prepare("DELETE FROM tupad_project_beneficiaries WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'TUPAD'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'TUPAD'")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
