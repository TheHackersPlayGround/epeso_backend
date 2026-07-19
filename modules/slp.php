<?php
// SLP: projects (slp_projects) + profiles (slp_profiles, dedicated table,
// 1:1 with beneficiary_services via UNIQUE beneficiary_service_id).
//
// Like TUPAD/CLPEP, slp_project_beneficiaries is unique on (project_id,
// beneficiary_service_id) only -- a beneficiary can carry a running history
// of project assignments. "Current" is the most recently assigned row;
// assigning never deletes older rows.
//
// slp_profiles.status is a stored column, but written by this module the
// same way DILP/TUPAD derive it -- never accepted directly from the client:
// Inactive (no assignment), Active (Planned/Ongoing), Completed (Completed).
//
// Sector Classification reuses the shared beneficiary_classifications table
// (multi-row, one per selected sector) instead of a dedicated column, and
// PWD's "specify disability" reuses the shared disabilities table -- both
// already exist and are used the same way by DILP/GIP/SPES/CDSP/employment.
// Educational Attainment reuses beneficiaries.educational_attainment (the
// shared enum column) rather than the old unconstrained
// slp_profiles.educational_attainment_id, which has been dropped.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listProjects':        requirePermission('livelihood','Viewer'); return slpListProjects();
        case 'createProject':       requirePermission('livelihood','Editor'); return slpCreateProject();
        case 'updateProject':       requirePermission('livelihood','Editor'); return slpUpdateProject($id);
        case 'deleteProject':       requirePermission('livelihood','Editor'); return slpDeleteProject($id);
        case 'updateProjectStatus': requirePermission('livelihood','Editor'); return slpUpdateProjectStatus($id);
        case 'listProfiles':        requirePermission('livelihood','Viewer'); return slpListProfiles();
        case 'getProfile':          requirePermission('livelihood','Viewer'); return slpGetProfile($id);
        case 'createProfile':       requirePermission('livelihood','Editor'); return slpCreateProfile();
        case 'updateProfile':       requirePermission('livelihood','Editor'); return slpUpdateProfile($id);
        case 'deleteProfile':       requirePermission('livelihood','Editor'); return slpDeleteProfile($id);
        case 'assignProject':       requirePermission('livelihood','Editor'); return slpAssignProject();
        case 'unassignProject':     requirePermission('livelihood','Editor'); return slpUnassignProject();
        case 'listDeleted':         requirePermission('livelihood','Viewer'); return slpListDeleted();
        case 'restoreRecord':       requirePermission('livelihood','Editor'); return slpRestoreRecord();
        case 'purgeRecord':         requirePermission('livelihood','Editor'); return slpPurgeRecord();
        default: error("Unknown SLP action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function slpNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function slpDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function slpIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }
function slpNumOrNull($v) { return is_numeric($v) ? (float)$v : null; }

function slpMoneyOrNull($v) {
    if ($v === null) return null;
    $s = trim(str_replace([',', '₱', ' '], '', (string)$v));
    return ($s === '' || !is_numeric($s)) ? null : (float)$s;
}

function slpServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='SLP' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('SLP service not found.', 500);
    return (int) $sid;
}

// Same derivation rule as DILP/TUPAD/CLPEP.
function slpDeriveStatus($projectStatus) {
    if ($projectStatus === 'Completed') return 'Completed';
    if ($projectStatus === 'Planned' || $projectStatus === 'Ongoing') return 'Active';
    return 'Inactive';
}

function slpUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function slpFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Sector checkbox label <-> shared beneficiary_classification_enum value.
// 'Not Applicable' has no enum value -- it simply means no row is written.
function slpSectorMap() {
    return [
        'Indigenous People (IP)'             => 'Indigenous People',
        'Senior Citizen'                      => 'Senior Citizen',
        'Solo Parent'                          => 'Solo Parent',
        'Internally Displaced Person (IDP)'   => 'Internally Displaced Person',
        'Overseas Filipino Worker (OFW)'      => 'Returning OFW',
        'Fisherfolks'                          => 'Fisherfolk',
        'Farmers'                              => 'Farmer',
        'Person with Disability (PWD)'        => 'Person with Disability',
        'Others'                               => 'Other',
    ];
}
function slpSectorToClassification($label) {
    return slpSectorMap()[$label] ?? null;
}
function slpClassificationToSector($classification) {
    $reverse = array_flip(slpSectorMap());
    return $reverse[$classification] ?? null;
}

// ─── Projects ─────────────────────────────────────────────────────────────────

function slpListProjects() {
    $s = db()->query(
        "SELECT p.*, (SELECT COUNT(*) FROM slp_project_beneficiaries pb WHERE pb.project_id = p.project_id) AS assigned_count
         FROM slp_projects p
         ORDER BY p.created_at DESC, p.project_id DESC"
    );
    json(['status' => 'ok', 'data' => array_map('slpFormatProject', $s->fetchAll())]);
}

function slpGetProjectById($id) {
    $s = db()->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM slp_project_beneficiaries pb WHERE pb.project_id = p.project_id) AS assigned_count
         FROM slp_projects p WHERE p.project_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? slpFormatProject($r) : null;
}

function slpFormatProject($r) {
    return [
        'id'               => (int) $r['project_id'],
        'projectName'      => $r['project_name'],
        'description'      => $r['description'] ?? '',
        'slpTrack'         => $r['slp_track'],
        'dateStarted'      => $r['date_started'],
        'location'         => $r['location'] ?? '',
        'facilitator'      => $r['facilitator'] ?? '',
        'status'           => $r['status'],
        'assistanceAmount' => $r['assistance_amount'] !== null ? (string) $r['assistance_amount'] : '',
        'dateReleased'     => $r['date_released'] ?? '',
        'assignedCount'    => (int) $r['assigned_count'],
        'documents'        => slpFetchProjectDocuments((int) $r['project_id']),
    ];
}

function slpValidateProjectInput($d) {
    $name = trim($d['projectName'] ?? '');
    if ($name === '') error('Project Name is required.', 422);

    $date = slpDate($d['dateStarted'] ?? '');
    if (!$date) error('Date Started is required.', 422);

    $facilitator = trim($d['facilitator'] ?? '');
    if ($facilitator === '') error('Facilitator is required.', 422);

    $validTrack = ['Enterprise - Individual', 'Enterprise - Association', 'Employment'];
    $track = in_array($d['slpTrack'] ?? '', $validTrack, true) ? $d['slpTrack'] : null;
    if (!$track) error('SLP Track is required.', 422);

    return [$name, $date, $facilitator, $track];
}

function slpCreateProject() {
    $uid = requireLogin();
    $d = body();
    [$name, $date, $facilitator, $track] = slpValidateProjectInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    // project_category is NOT NULL but SLPForm.tsx doesn't collect it (the
    // maintenance form never surfaced this field) -- default to 'Others'
    // rather than reintroducing a UI field nobody asked for.
    $pdo = db();
    $s = $pdo->prepare(
        "INSERT INTO slp_projects(project_name,description,slp_track,project_category,date_started,location,facilitator,assistance_amount,date_released,status,created_at,updated_at)
         VALUES(:name,:desc,:track,'Others',:date,:loc,:fac,:amt,:dtrel,:status,now(),now()) RETURNING project_id"
    );
    $s->execute([
        ':name' => $name, ':desc' => slpNullStr($d['description'] ?? ''), ':track' => $track, ':date' => $date,
        ':loc' => slpNullStr($d['location'] ?? ''), ':fac' => $facilitator,
        ':amt' => slpMoneyOrNull($d['assistanceAmount'] ?? null), ':dtrel' => slpDate($d['dateReleased'] ?? ''),
        ':status' => $status,
    ]);
    $id = (int) $s->fetchColumn();

    slpSyncProjectDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Project created.', 'data' => slpGetProjectById($id)]);
}

function slpUpdateProject($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id  = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$name, $date, $facilitator, $track] = slpValidateProjectInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $chk = $pdo->prepare("SELECT 1 FROM slp_projects WHERE project_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Project not found.', 404);

    $pdo->prepare(
        "UPDATE slp_projects SET project_name=:name,description=:desc,slp_track=:track,date_started=:date,location=:loc,facilitator=:fac,assistance_amount=:amt,date_released=:dtrel,status=:status,updated_at=now() WHERE project_id=:id"
    )->execute([
        ':name' => $name, ':desc' => slpNullStr($d['description'] ?? ''), ':track' => $track, ':date' => $date,
        ':loc' => slpNullStr($d['location'] ?? ''), ':fac' => $facilitator,
        ':amt' => slpMoneyOrNull($d['assistanceAmount'] ?? null), ':dtrel' => slpDate($d['dateReleased'] ?? ''),
        ':status' => $status, ':id' => $id,
    ]);

    slpSyncProjectDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Project updated.', 'data' => slpGetProjectById($id)]);
}

function slpUpdateProjectStatus($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id = (int) $id;
    $d  = body();
    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);

    $chk = db()->prepare("SELECT 1 FROM slp_projects WHERE project_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Project not found.', 404);

    db()->prepare("UPDATE slp_projects SET status=:s, updated_at=now() WHERE project_id=:id")->execute([':s' => $status, ':id' => $id]);

    // Cascade: refresh derived status for every beneficiary currently linked
    // to this project (slp_profiles.status is stored, unlike DILP/TUPAD which
    // compute it purely at read time).
    slpRefreshDerivedStatusForProject($id, $status);

    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => slpGetProjectById($id)]);
}

function slpRefreshDerivedStatusForProject($projectId, $projectStatus) {
    $derived = slpDeriveStatus($projectStatus);
    db()->prepare(
        "UPDATE slp_profiles SET status=:st, updated_at=now()
         WHERE beneficiary_service_id IN (
           SELECT beneficiary_service_id FROM slp_project_beneficiaries WHERE project_id=:pid
         )"
    )->execute([':st' => $derived, ':pid' => $projectId]);
}

function slpDeleteProject($id) {
    if (!is_numeric($id)) error('Invalid project id.', 422);
    $id = (int) $id;

    $cntS = db()->prepare("SELECT COUNT(*) FROM slp_project_beneficiaries WHERE project_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int) $cntS->fetchColumn();
    if ($cnt > 0) {
        error("Cannot delete: {$cnt} " . ($cnt === 1 ? 'beneficiary' : 'beneficiaries') . " assigned to this project. Unassign them first.", 409);
    }

    $docs = db()->prepare("SELECT file_path FROM documents WHERE slp_project_id=:id");
    $docs->execute([':id' => $id]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    db()->prepare("DELETE FROM slp_projects WHERE project_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Project deleted.']);
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function slpListProfiles() {
    $s = db()->prepare(
        "SELECT DISTINCT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => slpServiceId()]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $bid) {
        $p = slpBuildProfile((int) $bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function slpGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = slpBuildProfile((int) $id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

function slpBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.date_applied AS bs_date_applied, bs.received_by, bs.remarks AS bs_remarks,
                sp.slp_profile_id, sp.slp_participant_id_no, sp.participant_type, sp.eligibility_type, sp.referring_party,
                sp.source_of_income, sp.household_monthly_income, sp.vulnerability_score, sp.vulnerability_severity,
                sp.assessment_result, sp.slp_track, sp.status AS profile_status, sp.date_applied AS profile_date_applied,
                sp.remarks AS profile_remarks, sp.indigenous_group
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         LEFT JOIN slp_profiles sp ON sp.beneficiary_service_id = bs.beneficiary_service_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND bs.service_id = :sid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':sid' => slpServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int) $b['beneficiary_service_id'];

    // Current assignment = most recently assigned row; older rows survive as
    // history rather than being deleted on reassignment (same rule as TUPAD).
    $histS = db()->prepare(
        "SELECT pb.project_id, pb.assigned_at, pr.project_name, pr.status
         FROM slp_project_beneficiaries pb
         JOIN slp_projects pr ON pr.project_id = pb.project_id
         WHERE pb.beneficiary_service_id = :bsid
         ORDER BY pb.assigned_at DESC, pb.project_beneficiary_id DESC"
    );
    $histS->execute([':bsid' => $bsId]);
    $history = $histS->fetchAll();
    $current = $history[0] ?? null;

    $assignmentHistory = array_map(function ($h) {
        return [
            'projectId'     => (int) $h['project_id'],
            'projectName'   => $h['project_name'],
            'assignedDate'  => $h['assigned_at'],
            'completedDate' => $h['status'] === 'Completed' ? $h['assigned_at'] : null,
        ];
    }, $history);

    // Sector tags come from the shared beneficiary_classifications table.
    $clsS = db()->prepare("SELECT classification, classification_other FROM beneficiary_classifications WHERE beneficiary_id=:bid");
    $clsS->execute([':bid' => $bid]);
    $sector = [];
    $sectorOthersSpecify = '';
    foreach ($clsS->fetchAll() as $row) {
        $label = slpClassificationToSector($row['classification']);
        if ($label !== null) {
            $sector[] = $label;
            if ($row['classification'] === 'Other') $sectorOthersSpecify = $row['classification_other'] ?? '';
        }
    }

    // PWD "specify disability" comes from the shared disabilities table --
    // treated as a single free-text value, matching the form's one field.
    $disS = db()->prepare("SELECT disability_name FROM disabilities WHERE beneficiary_id=:bid ORDER BY disability_id LIMIT 1");
    $disS->execute([':bid' => $bid]);
    $sectorDisabilitySpecify = $disS->fetchColumn();
    if ($sectorDisabilitySpecify === false) $sectorDisabilitySpecify = '';

    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (new DateTime($b['birth_date']))->diff(new DateTime('today'))->y;
    }

    return [
        'id'                          => $bid,
        'beneficiaryServiceId'        => $bsId,
        'lastName'                    => $b['last_name'],
        'firstName'                   => $b['first_name'],
        'middleName'                  => $b['middle_name'] ?? '',
        'nameExtension'               => $b['suffix'] ?? '',
        'sex'                         => $b['sex'] ?? '',
        'birthdate'                   => $b['birth_date'] ?? '',
        'age'                         => $age,
        'civilStatus'                 => $b['civil_status'] ?? '',
        'contactNumber'               => $b['contact_no'] ?? '',
        'email'                       => $b['email'] ?? '',
        'streetPurok'                 => $b['street_address'] ?? '',
        'barangay'                    => $b['barangay_name'] ?? '',
        'barangayId'                  => (int) ($b['barangay_id'] ?? 0),
        'cityMunicipality'            => $b['city_name'] ?? '',
        'province'                    => $b['province_name'] ?? '',
        'region'                      => $b['region_name'] ?? '',
        'is4PsBeneficiary'            => (bool) ($b['is_4ps_beneficiary'] ?? false),
        'slpParticipantIdNumber'      => $b['slp_participant_id_no'] ?? '',
        'eligibilityType'             => $b['eligibility_type'] ?? '',
        'referringParty'              => $b['referring_party'] ?? '',
        'sector'                      => $sector,
        'sectorOthersSpecify'         => $sectorOthersSpecify,
        'sectorIpGroupSpecify'        => $b['indigenous_group'] ?? '',
        'sectorDisabilitySpecify'     => $sectorDisabilitySpecify,
        'educationalAttainment'       => $b['educational_attainment'] ?? '',
        'sourceOfIncome'              => $b['source_of_income'] ?? '',
        'totalHouseholdMonthlyIncome' => $b['household_monthly_income'] !== null ? (string) $b['household_monthly_income'] : '',
        'householdVulnerabilityScore' => $b['vulnerability_score'] !== null ? (string) $b['vulnerability_score'] : '',
        'vulnerabilitySeverity'       => $b['vulnerability_severity'] ?? '',
        'assessmentResult'            => $b['assessment_result'] ?? '',
        'slpTrack'                    => $b['slp_track'] ?? '',
        'remarks'                     => $b['profile_remarks'] ?? '',
        'assignedSlpProjectId'        => $current ? (int) $current['project_id'] : null,
        'projectName'                 => $current['project_name'] ?? '',
        'assignedProjectStatus'       => $current['status'] ?? '',
        'assignmentHistory'           => $assignmentHistory,
        'attachedDocuments'           => slpFetchSavedDocuments($bid),
        'dateApplied'                 => $b['profile_date_applied'] ?? $b['bs_date_applied'] ?? '',
        'receivedBy'                  => $b['received_by'] ?? '',
        'status'                      => $b['profile_status'] ?? 'Inactive',
    ];
}

// Required fields mirror beneficiaries' real NOT NULL columns (sex,
// birth_date, civil_status, barangay_id). participant_type/eligibility_type/
// slp_track are NOT NULL on slp_profiles but have no required marker in the
// UI, so an empty value falls back to a sensible default rather than
// blocking the save.
function slpValidateProfileInput($d) {
    if (slpNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (slpNullStr($d['lastName'] ?? '') === null)  error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male', 'Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = slpDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    // Civil status enum is Single/Married/Widowed/Separated/Divorced -- the
    // form's dropdown currently shows "Annulled" instead of "Divorced",
    // which does not exist as a value; treated as invalid/blank here so it
    // 422s cleanly rather than failing as a raw DB constraint violation.
    $validCivil = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;
    if (!$civil) error('Civil status is required.', 422);

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int) $d['barangayId'] > 0)
             ? (int) $d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    $validEligibility = ['Regular', 'Disaster-Affected', 'Area-Based Convergence', 'Walk-In', 'Referral'];
    $eligibility = in_array($d['eligibilityType'] ?? '', $validEligibility, true) ? $d['eligibilityType'] : 'Regular';

    $validTrack = ['Enterprise - Individual', 'Enterprise - Association', 'Employment'];
    $track = in_array($d['slpTrack'] ?? '', $validTrack, true) ? $d['slpTrack'] : 'Enterprise - Individual';

    $validSeverity = ['Low', 'Medium', 'High'];
    $severity = in_array($d['vulnerabilitySeverity'] ?? '', $validSeverity, true) ? $d['vulnerabilitySeverity'] : null;

    $validAssessment = ['Qualified', 'Not Qualified'];
    $assessment = in_array($d['assessmentResult'] ?? '', $validAssessment, true) ? $d['assessmentResult'] : null;

    $validEdu = ['No Formal Education', 'Elementary Level', 'Elementary Graduate',
                 'Junior High School Level', 'Junior High School Graduate',
                 'Senior High School Level', 'Senior High School Graduate',
                 'Vocational Graduate', 'College Level', 'College Graduate',
                 "Master's Degree", 'Doctorate Degree', 'Post Graduate'];
    $edu = in_array($d['educationalAttainment'] ?? '', $validEdu, true) ? $d['educationalAttainment'] : null;

    return [$sex, $birth, $civil, $bgyId, $eligibility, $track, $severity, $assessment, $edu];
}

function slpSyncClassifications($pdo, $bid, $d) {
    $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id=:bid")->execute([':bid' => $bid]);

    $sectors = is_array($d['sector'] ?? null) ? $d['sector'] : [];
    $ins = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification,classification_other) VALUES(:bid,:cls,:other) ON CONFLICT DO NOTHING");
    foreach ($sectors as $label) {
        $cls = slpSectorToClassification($label);
        if ($cls === null) continue; // 'Not Applicable' or unrecognized
        $other = $cls === 'Other' ? slpNullStr($d['sectorOthersSpecify'] ?? '') : null;
        $ins->execute([':bid' => $bid, ':cls' => $cls, ':other' => $other]);
    }

    $pdo->prepare("DELETE FROM disabilities WHERE beneficiary_id=:bid")->execute([':bid' => $bid]);
    $disabilitySpecify = slpNullStr($d['sectorDisabilitySpecify'] ?? '');
    if ($disabilitySpecify !== null && in_array('Person with Disability (PWD)', $sectors, true)) {
        $pdo->prepare("INSERT INTO disabilities(beneficiary_id,disability_name,created_at,updated_at) VALUES(:bid,:name,now(),now())")
            ->execute([':bid' => $bid, ':name' => $disabilitySpecify]);
    }
}

function slpCreateProfile() {
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId, $eligibility, $track, $severity, $assessment, $edu] = slpValidateProfileInput($d);
    $is4Ps = !empty($d['is4PsBeneficiary']);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare(
            "INSERT INTO beneficiaries(first_name,middle_name,last_name,suffix,sex,birth_date,civil_status,street_address,barangay_id,contact_no,email,is_4ps_beneficiary,educational_attainment,status)
             VALUES(:fn,:mn,:ln,:sfx,:sex,:bdate,:civil,:street,:bgy,:contact,:email,:is4ps,:edu,'Active') RETURNING beneficiary_id"
        );
        $s->execute([
            ':fn' => trim($d['firstName']), ':mn' => slpNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => slpNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => slpNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId,
            ':contact' => slpNullStr($d['contactNumber'] ?? ''), ':email' => slpNullStr($d['email'] ?? ''),
            ':is4ps' => $is4Ps ? 'true' : 'false', ':edu' => $edu,
        ]);
        $bid = (int) $s->fetchColumn();

        $s2 = $pdo->prepare(
            "INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by,remarks) VALUES(:bid,:sid,'Active',:date,:rby,:rmk) RETURNING beneficiary_service_id"
        );
        $s2->execute([
            ':bid' => $bid, ':sid' => slpServiceId(), ':date' => slpDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
            ':rby' => slpNullStr($d['receivedBy'] ?? ''), ':rmk' => null,
        ]);
        $bsId = (int) $s2->fetchColumn();

        $pdo->prepare(
            "INSERT INTO slp_profiles(beneficiary_service_id,slp_participant_id_no,participant_type,eligibility_type,referring_party,source_of_income,household_monthly_income,vulnerability_score,vulnerability_severity,assessment_result,slp_track,indigenous_group,status,date_applied,remarks,created_at,updated_at)
             VALUES(:bsid,:pidno,:ptype,:elig,:refparty,:income,:hhincome,:vscore,:vsev,:assess,:track,:ipgroup,'Inactive',:date,:remarks,now(),now())"
        )->execute([
            ':bsid' => $bsId, ':pidno' => slpNullStr($d['slpParticipantIdNumber'] ?? ''),
            ':ptype' => $is4Ps ? '4Ps' : 'Non-4Ps', ':elig' => $eligibility,
            ':refparty' => $eligibility === 'Referral' ? slpNullStr($d['referringParty'] ?? '') : null,
            ':income' => slpNullStr($d['sourceOfIncome'] ?? ''), ':hhincome' => slpMoneyOrNull($d['totalHouseholdMonthlyIncome'] ?? null),
            ':vscore' => slpNumOrNull($d['householdVulnerabilityScore'] ?? null), ':vsev' => $severity, ':assess' => $assessment,
            ':track' => $track, ':ipgroup' => in_array('Indigenous People (IP)', is_array($d['sector'] ?? null) ? $d['sector'] : [], true) ? slpNullStr($d['sectorIpGroupSpecify'] ?? '') : null,
            ':date' => slpDate($d['dateApplied'] ?? '') ?? date('Y-m-d'), ':remarks' => slpNullStr($d['remarks'] ?? ''),
        ]);

        slpSyncClassifications($pdo, $bid, $d);
        slpSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => slpBuildProfile($bid)]);
}

function slpUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId, $eligibility, $track, $severity, $assessment, $edu] = slpValidateProfileInput($d);
    $is4Ps = !empty($d['is4PsBeneficiary']);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => slpServiceId()]);
    $bsId = $chk->fetchColumn();
    if (!$bsId) error('SLP profile not found.', 404);
    $bsId = (int) $bsId;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,suffix=:sfx,sex=:sex,birth_date=:bdate,civil_status=:civil,street_address=:street,barangay_id=:bgy,contact_no=:contact,email=:email,is_4ps_beneficiary=:is4ps,educational_attainment=:edu,updated_at=now() WHERE beneficiary_id=:bid"
        )->execute([
            ':fn' => trim($d['firstName']), ':mn' => slpNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => slpNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => slpNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId,
            ':contact' => slpNullStr($d['contactNumber'] ?? ''), ':email' => slpNullStr($d['email'] ?? ''),
            ':is4ps' => $is4Ps ? 'true' : 'false', ':edu' => $edu, ':bid' => $bid,
        ]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby,date_applied=:date WHERE beneficiary_service_id=:bsid")
            ->execute([
                ':rby' => slpNullStr($d['receivedBy'] ?? ''), ':date' => slpDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
                ':bsid' => $bsId,
            ]);

        $pdo->prepare(
            "UPDATE slp_profiles SET slp_participant_id_no=:pidno,participant_type=:ptype,eligibility_type=:elig,referring_party=:refparty,source_of_income=:income,household_monthly_income=:hhincome,vulnerability_score=:vscore,vulnerability_severity=:vsev,assessment_result=:assess,slp_track=:track,indigenous_group=:ipgroup,date_applied=:date,remarks=:remarks,updated_at=now() WHERE beneficiary_service_id=:bsid"
        )->execute([
            ':pidno' => slpNullStr($d['slpParticipantIdNumber'] ?? ''), ':ptype' => $is4Ps ? '4Ps' : 'Non-4Ps', ':elig' => $eligibility,
            ':refparty' => $eligibility === 'Referral' ? slpNullStr($d['referringParty'] ?? '') : null,
            ':income' => slpNullStr($d['sourceOfIncome'] ?? ''), ':hhincome' => slpMoneyOrNull($d['totalHouseholdMonthlyIncome'] ?? null),
            ':vscore' => slpNumOrNull($d['householdVulnerabilityScore'] ?? null), ':vsev' => $severity, ':assess' => $assessment,
            ':track' => $track, ':ipgroup' => in_array('Indigenous People (IP)', is_array($d['sector'] ?? null) ? $d['sector'] : [], true) ? slpNullStr($d['sectorIpGroupSpecify'] ?? '') : null,
            ':date' => slpDate($d['dateApplied'] ?? '') ?? date('Y-m-d'), ':remarks' => slpNullStr($d['remarks'] ?? ''), ':bsid' => $bsId,
        ]);

        slpSyncClassifications($pdo, $bid, $d);
        slpSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => slpBuildProfile($bid)]);
}

function slpDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();

    // Cannot delete a beneficiary whose most recent assignment is to a
    // non-completed project -- unassign first, same lock spirit as TUPAD.
    $chk = db()->prepare(
        "SELECT pr.status
         FROM beneficiary_services bs
         JOIN slp_project_beneficiaries pb ON pb.beneficiary_service_id = bs.beneficiary_service_id
         JOIN slp_projects pr ON pr.project_id = pb.project_id
         WHERE bs.beneficiary_id = :bid AND bs.service_id = :sid
         ORDER BY pb.assigned_at DESC, pb.project_beneficiary_id DESC LIMIT 1"
    );
    $chk->execute([':bid' => $bid, ':sid' => slpServiceId()]);
    $status = $chk->fetchColumn();
    if ($status !== false && in_array($status, ['Planned', 'Ongoing'], true)) {
        error('This beneficiary cannot be deleted because they are currently assigned to an active project. Unassign them first (only possible while the project is still Planned), or wait until it is marked Completed.', 409);
    }

    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Beneficiary moved to recycle bin.']);
}

// ─── Assign / Unassign project ────────────────────────────────────────────────

function slpAssignProject() {
    requireLogin();
    $d = body();
    $bid       = slpIntOrNull($d['applicantId'] ?? '');
    $projectId = slpIntOrNull($d['projectId'] ?? '');
    if (!$bid || !$projectId) error('applicantId and projectId are required.', 422);

    $bsS = db()->prepare(
        "SELECT bs.beneficiary_service_id, sp.slp_track
         FROM beneficiary_services bs
         JOIN slp_profiles sp ON sp.beneficiary_service_id = bs.beneficiary_service_id
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $bsS->execute([':bid' => $bid, ':sid' => slpServiceId()]);
    $bsRow = $bsS->fetch();
    if (!$bsRow) error('SLP profile not found.', 404);
    $bsId = (int) $bsRow['beneficiary_service_id'];

    $projS = db()->prepare("SELECT status, slp_track FROM slp_projects WHERE project_id=:id");
    $projS->execute([':id' => $projectId]);
    $proj = $projS->fetch();
    if (!$proj) error('Project not found.', 404);
    $status = $proj['status'];
    if ($status !== 'Planned') error('Only Planned projects can be assigned.', 409);
    // A project's SLP Track (Individual/Association/Employment) represents a
    // distinct assistance mechanism from the applicant's own track -- an
    // Association-track project can't be assigned to an Individual-track
    // applicant and vice versa.
    if ($proj['slp_track'] !== $bsRow['slp_track']) {
        error("This applicant's SLP Track ({$bsRow['slp_track']}) does not match this project's SLP Track ({$proj['slp_track']}).", 409);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO slp_project_beneficiaries(project_id,beneficiary_service_id,assigned_at)
             VALUES(:pid,:bsid,now())
             ON CONFLICT (project_id, beneficiary_service_id) DO UPDATE SET assigned_at=now()"
        )->execute([':pid' => $projectId, ':bsid' => $bsId]);

        $pdo->prepare("UPDATE slp_profiles SET status=:st, updated_at=now() WHERE beneficiary_service_id=:bsid")
            ->execute([':st' => slpDeriveStatus($status), ':bsid' => $bsId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to assign project: ' . $e->getMessage(), 500);
    }

    json(['status' => 'ok', 'message' => 'Beneficiary assigned to project.']);
}

function slpUnassignProject() {
    requireLogin();
    $d = body();
    $bid = slpIntOrNull($d['applicantId'] ?? '');
    if (!$bid) error('applicantId is required.', 422);

    $row = db()->prepare(
        "SELECT pb.project_beneficiary_id, pb.beneficiary_service_id, pr.status AS project_status
         FROM beneficiary_services bs
         JOIN slp_project_beneficiaries pb ON pb.beneficiary_service_id = bs.beneficiary_service_id
         JOIN slp_projects pr ON pr.project_id = pb.project_id
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid
         ORDER BY pb.assigned_at DESC, pb.project_beneficiary_id DESC LIMIT 1"
    );
    $row->execute([':bid' => $bid, ':sid' => slpServiceId()]);
    $r = $row->fetch();
    if (!$r) error('This beneficiary is not currently assigned to a project.', 404);

    if ($r['project_status'] !== 'Planned') {
        error('This project is no longer Planned -- unassigning would erase the only record of this assignment.', 409);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM slp_project_beneficiaries WHERE project_beneficiary_id=:id")
            ->execute([':id' => (int) $r['project_beneficiary_id']]);

        $pdo->prepare("UPDATE slp_profiles SET status='Inactive', updated_at=now() WHERE beneficiary_service_id=:bsid")
            ->execute([':bsid' => (int) $r['beneficiary_service_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to unassign project: ' . $e->getMessage(), 500);
    }

    json(['status' => 'ok', 'message' => 'Beneficiary unassigned from project.']);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────

function slpSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    $docs = is_array($d['attachedDocuments'] ?? null) ? $d['attachedDocuments'] : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='SLP'");
    $sel->execute([':bid' => $bid]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(beneficiary_id,beneficiary_service_id,document_source,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:bid,:bsid,'SLP',:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'slp_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function slpFetchSavedDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='SLP' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'fileName' => $r['file_name'],
            'fileSize' => slpFormatBytes($r['file_size'] ?? 0),
            'url'      => slpUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ─── Documents (project) ──────────────────────────────────────────────────────

function slpSyncProjectDocuments($pdo, $projectId, $uid, $docsPayload) {
    $docs = is_array($docsPayload) ? $docsPayload : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE slp_project_id=:pid");
    $sel->execute([':pid' => $projectId]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(slp_project_id,document_source,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:pid,'SLP Project',:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['name'] ?? $doc['fileName'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'slp_project_' . $projectId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':pid' => $projectId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function slpFetchProjectDocuments($projectId) {
    $s = db()->prepare(
        "SELECT document_id, file_name, file_path, file_size, mime_type
         FROM documents WHERE slp_project_id=:pid ORDER BY document_id"
    );
    $s->execute([':pid' => $projectId]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'name'     => $r['file_name'],
            'fileType' => $r['mime_type'] ?? '',
            'url'      => slpUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN  (soft-deleted SLP beneficiaries) — mirrors tupad.php's pattern.
// ═══════════════════════════════════════════════════════════════════════════════

function slpRecycleMap() {
    return [
        'slpApplicant' => ['beneficiaries', 'beneficiary_id'],
    ];
}

function slpRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(slpRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

function slpListDeleted() {
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
    $s->execute([':sid' => slpServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType'  => 'slpApplicant',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'SLP Beneficiaries',
            'description' => 'Sustainable Livelihood Program beneficiary record',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

function slpRestoreRecord() {
    [$type, $id] = slpRecycleTarget();
    [$table, $pk] = slpRecycleMap()[$type];

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    json(['status' => 'ok', 'message' => 'Record restored.']);
}

function slpPurgeRecord() {
    [$type, $id] = slpRecycleTarget();
    [$table, $pk] = slpRecycleMap()[$type];

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    slpHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

function slpHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
        $bsStmt->execute([':id' => $bid, ':sid' => slpServiceId()]);
        $bsId = $bsStmt->fetchColumn();
        if ($bsId !== false) {
            $pdo->prepare("DELETE FROM slp_project_beneficiaries WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
            $pdo->prepare("DELETE FROM slp_profiles WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'SLP'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'SLP'")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM disabilities WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
