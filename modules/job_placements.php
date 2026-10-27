<?php
// Job Placements: one shared table used by every program module, keyed off beneficiary_service_id.
// Permission is resolved dynamically from the applicant's own service_code.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

// Routes a job_placements action to its handler function.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'listByApplicant': return jpListByApplicant();
        case 'create':           return jpCreate();
        case 'update':            return jpUpdate($id);
        case 'delete':            return jpDelete($id);
        default: error("Unknown job_placements action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

// Trims a string, converting empty/blank to null.
function jpNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
// Validates and returns a YYYY-MM-DD date string, or null.
function jpDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
// Converts a numeric value to int, or null.
function jpIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }

// Valid values for job_placement_employment_type_enum.
function jpEmploymentTypeValid() {
    return ['Full-time', 'Part-time', 'Contractual'];
}
// Returns the employment type if valid, else null.
function jpEmploymentTypeOrNull($v) {
    return in_array($v, jpEmploymentTypeValid(), true) ? $v : null;
}

// Maps each program's service_code to its permission key.
function jpPermissionMap() {
    return [
        'EF' => 'employment',
        'GIP' => 'gip',
        'CDSP' => 'cdsp', 'CDSP-CC' => 'cdsp', 'CDSP-PEC' => 'cdsp', 'CDSP-LEGS' => 'cdsp',
        'SPES' => 'spes',
        'SLP' => 'livelihood', 'DILP' => 'livelihood', 'TUPAD' => 'livelihood', 'CLPEP' => 'livelihood',
        'SKILLS' => 'skills',
        'OFW' => 'ofw',
    ];
}

// Checks the caller's permission for the program a beneficiary_service_id belongs to.
function jpRequirePermission($bsId, $level) {
    requireLogin();

    $s = db()->prepare(
        "SELECT sv.service_code FROM beneficiary_services bs
         JOIN services sv ON sv.service_id = bs.service_id
         WHERE bs.beneficiary_service_id = :id"
    );
    $s->execute([':id' => $bsId]);
    $code = $s->fetchColumn();
    if (!$code) error('Applicant not found.', 404);
    $perm = jpPermissionMap()[$code] ?? null;
    if (!$perm) error('Job placements are not supported for this program.', 422);
    return requirePermission($perm, $level);
}

// Formats a job placement row for the frontend.
function jpFormat($r) {
    return [
        'id'                   => (int) $r['placement_id'],
        'beneficiaryServiceId' => (int) $r['beneficiary_service_id'],
        'jobTitle'             => $r['job_title'],
        'employer'             => $r['employer'],
        'dateHired'            => $r['date_hired'],
        'employmentType'       => $r['employment_type'] ?? '',
        'remarks'              => $r['remarks'] ?? '',
    ];
}

// Validates job placement input and returns [title, employer, dateHired].
function jpValidateInput($d) {
    $title = trim($d['jobTitle'] ?? '');
    if ($title === '') error('Job title is required.', 422);
    $employer = trim($d['employer'] ?? '');
    if ($employer === '') error('Employer is required.', 422);
    $hired = jpDate($d['dateHired'] ?? '');
    if (!$hired) error('Date hired is required.', 422);
    if ($hired > date('Y-m-d')) error('Date hired cannot be in the future.', 422);
    return [$title, $employer, $hired];
}

// ─── Actions ──────────────────────────────────────────────────────────────────

// Returns job placements for an applicant.
function jpListByApplicant() {
    $bsId = jpIntOrNull($_GET['beneficiaryServiceId'] ?? '');
    if (!$bsId) error('beneficiaryServiceId is required.', 422);
    jpRequirePermission($bsId, 'Viewer');

    $s = db()->prepare(
        "SELECT * FROM job_placements
         WHERE beneficiary_service_id = :id AND deleted_at IS NULL
         ORDER BY date_hired DESC, placement_id DESC"
    );
    $s->execute([':id' => $bsId]);
    json(['status' => 'ok', 'data' => array_map('jpFormat', $s->fetchAll())]);
}

// Records a new job placement.
function jpCreate() {
    $d = body();
    $bsId = jpIntOrNull($d['beneficiaryServiceId'] ?? '');
    if (!$bsId) error('beneficiaryServiceId is required.', 422);
    $uid = jpRequirePermission($bsId, 'Editor');
    [$title, $employer, $hired] = jpValidateInput($d);

    $s = db()->prepare(
        "INSERT INTO job_placements(beneficiary_service_id,job_title,employer,date_hired,employment_type,remarks,created_at,updated_at)
         VALUES(:bsid,:title,:employer,:hired,:etype,:remarks,now(),now()) RETURNING placement_id"
    );
    $s->execute([
        ':bsid' => $bsId, ':title' => $title, ':employer' => $employer, ':hired' => $hired,
        ':etype' => jpEmploymentTypeOrNull($d['employmentType'] ?? ''), ':remarks' => jpNullStr($d['remarks'] ?? ''),
    ]);
    $id = (int) $s->fetchColumn();

    logActivity($uid, 'Record Job Placement', 'job-placements', "Recorded placement: {$title} at {$employer}");

    $g = db()->prepare("SELECT * FROM job_placements WHERE placement_id=:id");
    $g->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Job placement recorded.', 'data' => jpFormat($g->fetch())]);
}

// Updates a job placement.
function jpUpdate($id) {
    if (!is_numeric($id)) error('Invalid placement id.', 422);
    $id = (int) $id;
    $d = body();

    $curS = db()->prepare("SELECT beneficiary_service_id FROM job_placements WHERE placement_id=:id AND deleted_at IS NULL");
    $curS->execute([':id' => $id]);
    $bsId = $curS->fetchColumn();
    if (!$bsId) error('Job placement not found.', 404);
    $uid = jpRequirePermission((int) $bsId, 'Editor');
    [$title, $employer, $hired] = jpValidateInput($d);

    db()->prepare(
        "UPDATE job_placements SET job_title=:title, employer=:employer, date_hired=:hired,
         employment_type=:etype, remarks=:remarks, updated_at=now() WHERE placement_id=:id"
    )->execute([
        ':title' => $title, ':employer' => $employer, ':hired' => $hired,
        ':etype' => jpEmploymentTypeOrNull($d['employmentType'] ?? ''), ':remarks' => jpNullStr($d['remarks'] ?? ''), ':id' => $id,
    ]);

    logActivity($uid, 'Update Job Placement', 'job-placements', "Updated placement: {$title} at {$employer}");

    $g = db()->prepare("SELECT * FROM job_placements WHERE placement_id=:id");
    $g->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Job placement updated.', 'data' => jpFormat($g->fetch())]);
}

// Soft-deletes a job placement.
function jpDelete($id) {
    if (!is_numeric($id)) error('Invalid placement id.', 422);
    $id = (int) $id;

    $curS = db()->prepare("SELECT beneficiary_service_id, job_title, employer FROM job_placements WHERE placement_id=:id AND deleted_at IS NULL");
    $curS->execute([':id' => $id]);
    $row = $curS->fetch();
    if (!$row) error('Job placement not found.', 404);
    $uid = jpRequirePermission((int) $row['beneficiary_service_id'], 'Editor');

    db()->prepare("UPDATE job_placements SET deleted_at=now(), deleted_by=:uid WHERE placement_id=:id")
        ->execute([':uid' => $uid, ':id' => $id]);

    logActivity($uid, 'Delete Job Placement', 'job-placements', "Deleted placement: {$row['job_title']} at {$row['employer']}");
    json(['status' => 'ok', 'message' => 'Job placement deleted.']);
}
