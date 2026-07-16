<?php
// DILP: projects (dilp_projects) + profiles (beneficiary spine, no dedicated
// profile table — every remaining field lives on beneficiaries /
// beneficiary_services / the shared classifications & documents tables).
//
// Beneficiary status is NOT stored: it's derived at read time from whether a
// project is assigned and that project's own status (Planned/Ongoing -> Active,
// Completed -> Completed, none or Cancelled -> Inactive). See dilpDeriveStatus().

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listProjects':        requirePermission('livelihood','Viewer'); return dilpListProjects();
        case 'createProject':       requirePermission('livelihood','Editor'); return dilpCreateProject();
        case 'updateProject':       requirePermission('livelihood','Editor'); return dilpUpdateProject($id);
        case 'deleteProject':       requirePermission('livelihood','Editor'); return dilpDeleteProject($id);
        case 'updateProjectStatus': requirePermission('livelihood','Editor'); return dilpUpdateProjectStatus($id);
        case 'listProfiles':        requirePermission('livelihood','Viewer'); return dilpListProfiles();
        case 'getProfile':          requirePermission('livelihood','Viewer'); return dilpGetProfile($id);
        case 'createProfile':       requirePermission('livelihood','Editor'); return dilpCreateProfile();
        case 'updateProfile':       requirePermission('livelihood','Editor'); return dilpUpdateProfile($id);
        case 'deleteProfile':       requirePermission('livelihood','Editor'); return dilpDeleteProfile($id);
        case 'assignProject':       requirePermission('livelihood','Editor'); return dilpAssignProject();
        case 'unassignProject':     requirePermission('livelihood','Editor'); return dilpUnassignProject();
        case 'listDeleted':         requirePermission('livelihood','Viewer'); return dilpListDeleted();
        case 'restoreRecord':       requirePermission('livelihood','Editor'); return dilpRestoreRecord();
        case 'purgeRecord':         requirePermission('livelihood','Editor'); return dilpPurgeRecord();
        default: error("Unknown DILP action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function dilpNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function dilpDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function dilpIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }

function dilpMoneyOrNull($v) {
    if ($v === null) return null;
    $s = trim(str_replace([',', '₱', ' '], '', (string)$v));
    return ($s === '' || !is_numeric($s)) ? null : (float)$s;
}

function dilpServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='DILP' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('DILP service not found.', 500);
    return (int) $sid;
}

// The DILP applicant form's classification dropdown predates
// beneficiary_classification_enum. Vendor/Fisherfolk/Farmer/Displaced Worker/
// Transport Worker are real, distinct livelihood-beneficiary categories worth
// reporting on individually, so they were added to the enum (migration 028)
// rather than collapsed. OFW/PWD/Others map to their existing near-identical
// values instead of duplicating them; anything unrecognized still falls back
// to 'Other'.
function dilpValidClassifications() {
    return ['Student','Fresh Graduate','Employed','Underemployed','Unemployed',
            'Out of School Youth','Person with Disability','Solo Parent',
            'Women','Senior Citizen','Returning OFW','Other','Indigenous People',
            'Vendor','Fisherfolk','Farmer','Displaced Worker','Transport Worker'];
}
function dilpNormalizeClassification($v) {
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') return null;
    $aliases = ['OFW' => 'Returning OFW', 'PWD' => 'Person with Disability', 'Others' => 'Other'];
    if (isset($aliases[$v])) return $aliases[$v];
    return in_array($v, dilpValidClassifications(), true) ? $v : 'Other';
}

// Beneficiary status is derived, never stored: Inactive with no assignment,
// Active while the assigned project is Planned/Ongoing, Completed once it is.
// A Cancelled project (project_status_enum only — TUPAD's activity_status_enum
// has no such state) falls back to Inactive, same as no assignment at all.
function dilpDeriveStatus($projectStatus) {
    if ($projectStatus === 'Completed') return 'Completed';
    if ($projectStatus === 'Planned' || $projectStatus === 'Ongoing') return 'Active';
    return 'Inactive';
}

function dilpUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function dilpFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// ─── Projects ─────────────────────────────────────────────────────────────────

function dilpListProjects() {
    $s = db()->query(
        "SELECT p.*, (SELECT COUNT(*) FROM dilp_project_beneficiaries pb WHERE pb.dilp_project_id = p.dilp_project_id) AS assigned_count
         FROM dilp_projects p
         ORDER BY p.created_at DESC, p.dilp_project_id DESC"
    );
    json(['status' => 'ok', 'data' => array_map('dilpFormatProject', $s->fetchAll())]);
}

function dilpGetProjectById($id) {
    $s = db()->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM dilp_project_beneficiaries pb WHERE pb.dilp_project_id = p.dilp_project_id) AS assigned_count
         FROM dilp_projects p WHERE p.dilp_project_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? dilpFormatProject($r) : null;
}

function dilpFormatProject($r) {
    $bgyId = $r['barangay_id'] ?? null;
    $names = ['barangay' => '', 'cityMunicipality' => '', 'province' => '', 'region' => ''];
    if ($bgyId) {
        $s = db()->prepare(
            "SELECT bgy.barangay_name, c.city_name, p.province_name, r.region_name
             FROM barangays bgy
             LEFT JOIN cities c ON c.city_id = bgy.city_id
             LEFT JOIN provinces p ON p.province_id = c.province_id
             LEFT JOIN regions r ON r.region_id = p.region_id
             WHERE bgy.barangay_id = :id"
        );
        $s->execute([':id' => $bgyId]);
        if ($row = $s->fetch()) {
            $names = [
                'barangay' => $row['barangay_name'] ?? '',
                'cityMunicipality' => $row['city_name'] ?? '',
                'province' => $row['province_name'] ?? '',
                'region' => $row['region_name'] ?? '',
            ];
        }
    }
    return [
        'id'                   => (int) $r['dilp_project_id'],
        'projectIdNumber'      => $r['project_id_number'],
        'projectName'          => $r['project_name'],
        'typeOfProject'        => $r['project_type'],
        'programComponent'     => $r['program_component'],
        'wayOfImplementation'  => $r['implementation_type'],
        'barangayId'           => $bgyId ? (int) $bgyId : null,
        'barangay'             => $names['barangay'],
        'cityMunicipality'     => $names['cityMunicipality'],
        'province'             => $names['province'],
        'region'               => $names['region'],
        'streetPurok'          => $r['street_address'] ?? '',
        'assistanceAmount'     => $r['assistance_amount'] !== null ? (string) $r['assistance_amount'] : '',
        'dateReleased'         => $r['date_released'] ?? '',
        'status'               => $r['status'],
        'assignedCount'        => (int) $r['assigned_count'],
        'documents'            => dilpFetchProjectDocuments((int) $r['dilp_project_id']),
    ];
}

function dilpValidateProjectInput($d, $excludeId = null) {
    $idNum = trim($d['projectIdNumber'] ?? '');
    $name  = trim($d['projectName'] ?? '');
    if ($idNum === '') error('Project ID Number is required.', 422);
    if ($name === '')  error('Project Name is required.', 422);

    $dupS = db()->prepare("SELECT dilp_project_id FROM dilp_projects WHERE project_id_number = :n" . ($excludeId ? " AND dilp_project_id != :ex" : ""));
    $params = [':n' => $idNum];
    if ($excludeId) $params[':ex'] = $excludeId;
    $dupS->execute($params);
    if ($dupS->fetchColumn()) error('A project with this Project ID Number already exists.', 409);

    $type = in_array($d['typeOfProject'] ?? '', ['Individual', 'Group'], true) ? $d['typeOfProject'] : null;
    if (!$type) error('Type of Project is required.', 422);

    $component = in_array($d['programComponent'] ?? '', ['Formation', 'Restoration', 'Enhancement'], true) ? $d['programComponent'] : null;
    if (!$component) error('Program Component is required.', 422);

    $impl = in_array($d['wayOfImplementation'] ?? '', ['ACP', 'Direct Admin'], true) ? $d['wayOfImplementation'] : null;
    if (!$impl) error('Way of Implementation is required.', 422);

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int) $d['barangayId'] > 0) ? (int) $d['barangayId'] : null;

    return [$idNum, $name, $type, $component, $impl, $bgyId];
}

function dilpCreateProject() {
    $uid = requireLogin();
    $d = body();
    [$idNum, $name, $type, $component, $impl, $bgyId] = dilpValidateProjectInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    // A project can only ever be created as Planned via the UI (add-mode locks
    // the radio group), but guard the API-only edge case here too.
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $s = $pdo->prepare(
        "INSERT INTO dilp_projects(project_id_number,project_name,project_type,program_component,implementation_type,barangay_id,street_address,assistance_amount,date_released,status,created_at,updated_at)
         VALUES(:idnum,:name,:type,:comp,:impl,:bgy,:street,:amt,:dtrel,:status,now(),now()) RETURNING dilp_project_id"
    );
    $s->execute([
        ':idnum' => $idNum, ':name' => $name, ':type' => $type, ':comp' => $component, ':impl' => $impl,
        ':bgy' => $bgyId, ':street' => dilpNullStr($d['streetPurok'] ?? ''),
        ':amt' => dilpMoneyOrNull($d['assistanceAmount'] ?? null), ':dtrel' => dilpDate($d['dateReleased'] ?? ''),
        ':status' => $status,
    ]);
    $id = (int) $s->fetchColumn();

    dilpSyncProjectDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Project created.', 'data' => dilpGetProjectById($id)]);
}

function dilpUpdateProject($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id  = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$idNum, $name, $type, $component, $impl, $bgyId] = dilpValidateProjectInput($d, $id);

    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $chk = $pdo->prepare("SELECT 1 FROM dilp_projects WHERE dilp_project_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Project not found.', 404);

    $pdo->prepare(
        "UPDATE dilp_projects SET project_id_number=:idnum,project_name=:name,project_type=:type,program_component=:comp,implementation_type=:impl,barangay_id=:bgy,street_address=:street,assistance_amount=:amt,date_released=:dtrel,status=:status,updated_at=now() WHERE dilp_project_id=:id"
    )->execute([
        ':idnum' => $idNum, ':name' => $name, ':type' => $type, ':comp' => $component, ':impl' => $impl,
        ':bgy' => $bgyId, ':street' => dilpNullStr($d['streetPurok'] ?? ''),
        ':amt' => dilpMoneyOrNull($d['assistanceAmount'] ?? null), ':dtrel' => dilpDate($d['dateReleased'] ?? ''),
        ':status' => $status, ':id' => $id,
    ]);

    dilpSyncProjectDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Project updated.', 'data' => dilpGetProjectById($id)]);
}

function dilpUpdateProjectStatus($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id = (int) $id;
    $d  = body();
    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);

    $chk = db()->prepare("SELECT 1 FROM dilp_projects WHERE dilp_project_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Project not found.', 404);

    // No cascade write needed here: beneficiary status is derived at read time
    // from this same status column, so simply changing it here is sufficient.
    db()->prepare("UPDATE dilp_projects SET status=:s, updated_at=now() WHERE dilp_project_id=:id")->execute([':s' => $status, ':id' => $id]);
    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => dilpGetProjectById($id)]);
}

function dilpDeleteProject($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id = (int) $id;

    $cntS = db()->prepare("SELECT COUNT(*) FROM dilp_project_beneficiaries WHERE dilp_project_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int) $cntS->fetchColumn();
    if ($cnt > 0) {
        error("Cannot delete: {$cnt} " . ($cnt === 1 ? 'beneficiary' : 'beneficiaries') . " assigned to this project. Unassign them first.", 409);
    }

    $docs = db()->prepare("SELECT file_path FROM documents WHERE dilp_project_id=:id");
    $docs->execute([':id' => $id]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    db()->prepare("DELETE FROM dilp_projects WHERE dilp_project_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Project deleted.']);
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function dilpListProfiles() {
    $s = db()->prepare(
        "SELECT DISTINCT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => dilpServiceId()]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $bid) {
        $p = dilpBuildProfile((int) $bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function dilpGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = dilpBuildProfile((int) $id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

function dilpBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.date_applied, bs.received_by, bs.remarks,
                dpb.dilp_project_id AS assigned_project_id,
                dproj.project_name AS assigned_project_name,
                dproj.status AS assigned_project_status
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         LEFT JOIN dilp_project_beneficiaries dpb ON dpb.beneficiary_service_id = bs.beneficiary_service_id
         LEFT JOIN dilp_projects dproj ON dproj.dilp_project_id = dpb.dilp_project_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND bs.service_id = :sid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':sid' => dilpServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int) $b['beneficiary_service_id'];

    $clsS = db()->prepare("SELECT classification, classification_other FROM beneficiary_classifications WHERE beneficiary_id=:id LIMIT 1");
    $clsS->execute([':id' => $bid]);
    $cls = $clsS->fetch();
    $classification = $cls['classification'] ?? null;
    $classificationOther = $cls['classification_other'] ?? null;

    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (new DateTime($b['birth_date']))->diff(new DateTime('today'))->y;
    }

    return [
        'id'                   => $bid,
        'beneficiaryServiceId' => $bsId,
        'lastName'             => $b['last_name'],
        'firstName'            => $b['first_name'],
        'middleName'           => $b['middle_name'] ?? '',
        'nameExtension'        => $b['suffix'] ?? '',
        'sex'                  => $b['sex'] ?? '',
        'birthdate'            => $b['birth_date'] ?? '',
        'age'                  => $age,
        'civilStatus'          => $b['civil_status'] ?? '',
        'contactNumber'        => $b['contact_no'] ?? '',
        'email'                => $b['email'] ?? '',
        'beneficiaryClassification' => $classification ?: '',
        'beneficiaryClassificationOther' => $classificationOther ?? '',
        'streetPurok'          => $b['street_address'] ?? '',
        'barangay'             => $b['barangay_name'] ?? '',
        'barangayId'           => (int) ($b['barangay_id'] ?? 0),
        'cityMunicipality'     => $b['city_name'] ?? '',
        'province'             => $b['province_name'] ?? '',
        'region'               => $b['region_name'] ?? '',
        'is4PsBeneficiary'     => (bool) ($b['is_4ps_beneficiary'] ?? false),
        'yearGraduated4Ps'     => $b['year_graduated_4ps'] !== null ? (string) $b['year_graduated_4ps'] : '',
        'assignedProjectId'    => $b['assigned_project_id'] ? (int) $b['assigned_project_id'] : null,
        'assignedProjectName'  => $b['assigned_project_name'] ?? '',
        'assignedProjectStatus'=> $b['assigned_project_status'] ?? '',
        'attachedDocuments'    => dilpFetchSavedDocuments($bid),
        'dateApplied'          => $b['date_applied'] ?? '',
        'remarks'              => $b['remarks'] ?? '',
        'receivedBy'           => $b['received_by'] ?? '',
        'status'               => dilpDeriveStatus($b['assigned_project_status'] ?? null),
    ];
}

// Required fields mirror beneficiaries' real NOT NULL columns (sex, birth_date,
// civil_status, barangay_id) so a bad request 422s cleanly instead of failing
// as a raw DB constraint violation.
function dilpValidateProfileInput($d) {
    if (dilpNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (dilpNullStr($d['lastName'] ?? '') === null)  error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male', 'Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = dilpDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    $validCivil = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;
    if (!$civil) error('Civil status is required.', 422);

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int) $d['barangayId'] > 0)
             ? (int) $d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    return [$sex, $birth, $civil, $bgyId];
}

function dilpCreateProfile() {
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = dilpValidateProfileInput($d);
    $is4Ps = !empty($d['is4PsBeneficiary']);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare(
            "INSERT INTO beneficiaries(first_name,middle_name,last_name,suffix,sex,birth_date,civil_status,street_address,barangay_id,contact_no,email,is_4ps_beneficiary,year_graduated_4ps,status)
             VALUES(:fn,:mn,:ln,:sfx,:sex,:bdate,:civil,:street,:bgy,:contact,:email,:is4ps,:ygrad,'Active') RETURNING beneficiary_id"
        );
        $s->execute([
            ':fn' => trim($d['firstName']), ':mn' => dilpNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => dilpNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => dilpNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId,
            ':contact' => dilpNullStr($d['contactNumber'] ?? ''), ':email' => dilpNullStr($d['email'] ?? ''),
            ':is4ps' => $is4Ps ? 'true' : 'false', ':ygrad' => $is4Ps ? dilpIntOrNull($d['yearGraduated4Ps'] ?? null) : null,
        ]);
        $bid = (int) $s->fetchColumn();

        $s2 = $pdo->prepare(
            "INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by,remarks) VALUES(:bid,:sid,'Active',:date,:rby,:rmk) RETURNING beneficiary_service_id"
        );
        $s2->execute([
            ':bid' => $bid, ':sid' => dilpServiceId(), ':date' => dilpDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
            ':rby' => dilpNullStr($d['receivedBy'] ?? ''), ':rmk' => dilpNullStr($d['remarks'] ?? ''),
        ]);
        $bsId = (int) $s2->fetchColumn();

        $cls = dilpNormalizeClassification($d['beneficiaryClassification'] ?? '');
        if ($cls !== null) {
            $clsOther = $cls === 'Other' ? dilpNullStr($d['beneficiaryClassificationOther'] ?? '') : null;
            $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification,classification_other) VALUES(:bid,:cls,:clsOther) ON CONFLICT DO NOTHING")
                ->execute([':bid' => $bid, ':cls' => $cls, ':clsOther' => $clsOther]);
        }

        dilpSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => dilpBuildProfile($bid)]);
}

function dilpUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = dilpValidateProfileInput($d);
    $is4Ps = !empty($d['is4PsBeneficiary']);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => dilpServiceId()]);
    $bsId = $chk->fetchColumn();
    if (!$bsId) error('DILP profile not found.', 404);
    $bsId = (int) $bsId;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,suffix=:sfx,sex=:sex,birth_date=:bdate,civil_status=:civil,street_address=:street,barangay_id=:bgy,contact_no=:contact,email=:email,is_4ps_beneficiary=:is4ps,year_graduated_4ps=:ygrad,updated_at=now() WHERE beneficiary_id=:bid"
        )->execute([
            ':fn' => trim($d['firstName']), ':mn' => dilpNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => dilpNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => dilpNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId,
            ':contact' => dilpNullStr($d['contactNumber'] ?? ''), ':email' => dilpNullStr($d['email'] ?? ''),
            ':is4ps' => $is4Ps ? 'true' : 'false', ':ygrad' => $is4Ps ? dilpIntOrNull($d['yearGraduated4Ps'] ?? null) : null, ':bid' => $bid,
        ]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby,date_applied=:date,remarks=:rmk WHERE beneficiary_service_id=:bsid")
            ->execute([
                ':rby' => dilpNullStr($d['receivedBy'] ?? ''), ':date' => dilpDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
                ':rmk' => dilpNullStr($d['remarks'] ?? ''), ':bsid' => $bsId,
            ]);

        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id=:bid")->execute([':bid' => $bid]);
        $cls = dilpNormalizeClassification($d['beneficiaryClassification'] ?? '');
        if ($cls !== null) {
            $clsOther = $cls === 'Other' ? dilpNullStr($d['beneficiaryClassificationOther'] ?? '') : null;
            $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification,classification_other) VALUES(:bid,:cls,:clsOther)")
                ->execute([':bid' => $bid, ':cls' => $cls, ':clsOther' => $clsOther]);
        }

        dilpSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => dilpBuildProfile($bid)]);
}

function dilpDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();

    // Cannot delete a beneficiary currently assigned to a non-completed
    // project — unassign first, same lock spirit as SPES/GIP's batch guard.
    $chk = db()->prepare(
        "SELECT dproj.status
         FROM beneficiary_services bs
         JOIN dilp_project_beneficiaries dpb ON dpb.beneficiary_service_id = bs.beneficiary_service_id
         JOIN dilp_projects dproj ON dproj.dilp_project_id = dpb.dilp_project_id
         WHERE bs.beneficiary_id = :bid AND bs.service_id = :sid"
    );
    $chk->execute([':bid' => $bid, ':sid' => dilpServiceId()]);
    $projStatus = $chk->fetchColumn();
    if ($projStatus !== false && in_array($projStatus, ['Planned', 'Ongoing'], true)) {
        error('This beneficiary cannot be deleted because they are currently assigned to an active project. Unassign them first.', 409);
    }

    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Beneficiary moved to recycle bin.']);
}

// ─── Assign / Unassign project ────────────────────────────────────────────────
// dilp_project_beneficiaries has a UNIQUE constraint on beneficiary_service_id
// alone — a DILP beneficiary can only ever be linked to one project at a time
// (unlike TUPAD, which allows a running history). Reassigning upserts the row.

function dilpAssignProject() {
    requireLogin();
    $d = body();
    $bid       = dilpIntOrNull($d['applicantId'] ?? '');
    $projectId = dilpIntOrNull($d['projectId'] ?? '');
    if (!$bid || !$projectId) error('applicantId and projectId are required.', 422);

    $bsS = db()->prepare(
        "SELECT bs.beneficiary_service_id FROM beneficiary_services bs
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $bsS->execute([':bid' => $bid, ':sid' => dilpServiceId()]);
    $bsId = $bsS->fetchColumn();
    if (!$bsId) error('DILP profile not found.', 404);
    $bsId = (int) $bsId;

    // Same rule as unassign: once the beneficiary's current project has moved
    // past Planned, there's no history table to fall back on (unlike TUPAD) —
    // this junction row is the only record that assignment ever happened, so
    // reassigning would erase it.
    $curS = db()->prepare(
        "SELECT dproj.status FROM dilp_project_beneficiaries dpb
         JOIN dilp_projects dproj ON dproj.dilp_project_id = dpb.dilp_project_id
         WHERE dpb.beneficiary_service_id = :bsid"
    );
    $curS->execute([':bsid' => $bsId]);
    $curProjectStatus = $curS->fetchColumn();
    if ($curProjectStatus !== false && $curProjectStatus !== 'Planned') {
        error('This beneficiary is already assigned to a project that is no longer Planned — reassigning would erase the only record of that assignment.', 409);
    }

    $projS = db()->prepare("SELECT status FROM dilp_projects WHERE dilp_project_id=:id");
    $projS->execute([':id' => $projectId]);
    $status = $projS->fetchColumn();
    if ($status === false) error('Project not found.', 404);
    if ($status !== 'Planned') error('Only Planned projects can be assigned.', 409);

    db()->prepare(
        "INSERT INTO dilp_project_beneficiaries(dilp_project_id,beneficiary_service_id,assigned_at)
         VALUES(:pid,:bsid,now())
         ON CONFLICT (beneficiary_service_id) DO UPDATE SET dilp_project_id=EXCLUDED.dilp_project_id, assigned_at=now()"
    )->execute([':pid' => $projectId, ':bsid' => (int) $bsId]);

    json(['status' => 'ok', 'message' => 'Beneficiary assigned to project.']);
}

function dilpUnassignProject() {
    requireLogin();
    $d = body();
    $bid = dilpIntOrNull($d['applicantId'] ?? '');
    if (!$bid) error('applicantId is required.', 422);

    $row = db()->prepare(
        "SELECT dpb.dilp_project_id, dproj.status AS project_status
         FROM beneficiary_services bs
         JOIN dilp_project_beneficiaries dpb ON dpb.beneficiary_service_id = bs.beneficiary_service_id
         LEFT JOIN dilp_projects dproj ON dproj.dilp_project_id = dpb.dilp_project_id
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid"
    );
    $row->execute([':bid' => $bid, ':sid' => dilpServiceId()]);
    $r = $row->fetch();
    if (!$r) error('This beneficiary is not currently assigned to a project.', 404);

    // dilp_project_beneficiaries is the only place an assignment is recorded
    // (no separate history table) — once the project has moved past Planned,
    // unassigning would silently erase the only record it ever happened.
    if ($r['project_status'] !== 'Planned') {
        error('This project is no longer Planned — unassigning would erase the only record of this assignment.', 409);
    }

    db()->prepare(
        "DELETE FROM dilp_project_beneficiaries WHERE beneficiary_service_id = (SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id=:bid AND service_id=:sid)"
    )->execute([':bid' => $bid, ':sid' => dilpServiceId()]);

    json(['status' => 'ok', 'message' => 'Beneficiary unassigned from project.']);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────

function dilpSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    $docs = is_array($d['attachedDocuments'] ?? null) ? $d['attachedDocuments'] : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='DILP'");
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
         VALUES(:bid,:bsid,'DILP',:dtype,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'dilp_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $dtype  = dilpNullStr($doc['documentType'] ?? '');
        $custom = dilpNullStr($doc['customName'] ?? $doc['name'] ?? '');
        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':dtype' => $dtype,
            ':title' => $custom ?? ($dtype ?? $origName),
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function dilpFetchSavedDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='DILP' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    return array_map(function ($r) {
        return [
            'id'           => (string) $r['document_id'],
            'documentType' => $r['document_type'] ?? '',
            'customName'   => $r['title'] ?? '',
            'fileName'     => $r['file_name'],
            'fileSize'     => dilpFormatBytes($r['file_size'] ?? 0),
            'url'          => dilpUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ─── Documents (project) ──────────────────────────────────────────────────────

function dilpSyncProjectDocuments($pdo, $projectId, $uid, $docsPayload) {
    $docs = is_array($docsPayload) ? $docsPayload : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE dilp_project_id=:pid");
    $sel->execute([':pid' => $projectId]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(dilp_project_id,document_source,document_type,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:pid,'DILP Project',NULL,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'dilp_project_' . $projectId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':pid' => $projectId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function dilpFetchProjectDocuments($projectId) {
    $s = db()->prepare(
        "SELECT document_id, file_name, file_path, file_size
         FROM documents WHERE dilp_project_id=:pid ORDER BY document_id"
    );
    $s->execute([':pid' => $projectId]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'fileName' => $r['file_name'],
            'fileSize' => dilpFormatBytes($r['file_size'] ?? 0),
            'url'      => dilpUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN  (soft-deleted DILP beneficiaries) — mirrors spes.php's pattern.
// ═══════════════════════════════════════════════════════════════════════════════

function dilpRecycleMap() {
    return [
        'dilpApplicant' => ['beneficiaries', 'beneficiary_id'],
    ];
}

function dilpRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(dilpRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

function dilpListDeleted() {
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
    $s->execute([':sid' => dilpServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType'  => 'dilpApplicant',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'DILP Beneficiaries',
            'description' => 'DOLE Integrated Livelihood Program beneficiary record',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

function dilpRestoreRecord() {
    [$type, $id] = dilpRecycleTarget();
    [$table, $pk] = dilpRecycleMap()[$type];

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    json(['status' => 'ok', 'message' => 'Record restored.']);
}

function dilpPurgeRecord() {
    [$type, $id] = dilpRecycleTarget();
    [$table, $pk] = dilpRecycleMap()[$type];

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    dilpHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

function dilpHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
        $bsStmt->execute([':id' => $bid, ':sid' => dilpServiceId()]);
        $bsId = $bsStmt->fetchColumn();
        if ($bsId !== false) {
            $pdo->prepare("DELETE FROM dilp_project_beneficiaries WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'DILP'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'DILP'")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
