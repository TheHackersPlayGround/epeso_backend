<?php
// CLPEP: interventions (clpep_interventions) + profiles (clpep_profiles,
// dedicated table, 1:1 with beneficiary_services via UNIQUE beneficiary_service_id).
//
// Like TUPAD/SLP (and unlike DILP/GIP/SPES), clpep_intervention_beneficiaries
// is unique on (intervention_id, beneficiary_service_id) only -- a beneficiary
// can carry a running history of intervention assignments. "Current" is the
// most recently assigned row; assigning never deletes older rows.
//
// clpep_profiles.status is a stored column (unlike DILP/TUPAD, which have no
// profile table to store it on), but it is written by this module the same
// way DILP/TUPAD derive it -- never accepted directly from the client:
// Inactive (no assignment), Active (Planned/Ongoing), Completed (Completed).
//
// CLPEP is a minors-only program: the applicant form collects no Civil
// Status, Contact Number, or Email -- civil_status is hardcoded to 'Single'
// on insert since beneficiaries.civil_status is NOT NULL.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listInterventions':        requirePermission('livelihood','Viewer'); return clpepListInterventions();
        case 'createIntervention':       requirePermission('livelihood','Editor'); return clpepCreateIntervention();
        case 'updateIntervention':       requirePermission('livelihood','Editor'); return clpepUpdateIntervention($id);
        case 'deleteIntervention':       requirePermission('livelihood','Editor'); return clpepDeleteIntervention($id);
        case 'updateInterventionStatus': requirePermission('livelihood','Editor'); return clpepUpdateInterventionStatus($id);
        case 'listProfiles':             requirePermission('livelihood','Viewer'); return clpepListProfiles();
        case 'getProfile':               requirePermission('livelihood','Viewer'); return clpepGetProfile($id);
        case 'createProfile':            requirePermission('livelihood','Editor'); return clpepCreateProfile();
        case 'updateProfile':            requirePermission('livelihood','Editor'); return clpepUpdateProfile($id);
        case 'deleteProfile':            requirePermission('livelihood','Editor'); return clpepDeleteProfile($id);
        case 'assignIntervention':       requirePermission('livelihood','Editor'); return clpepAssignIntervention();
        case 'unassignIntervention':     requirePermission('livelihood','Editor'); return clpepUnassignIntervention();
        case 'listDeleted':              requirePermission('livelihood','Viewer'); return clpepListDeleted();
        case 'restoreRecord':            requirePermission('livelihood','Editor'); return clpepRestoreRecord();
        case 'purgeRecord':              requirePermission('livelihood','Editor'); return clpepPurgeRecord();
        default: error("Unknown CLPEP action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function clpepNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function clpepDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function clpepIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }
function clpepNumOrNull($v) { return is_numeric($v) ? (float)$v : null; }
// Returns the string literals 'true'/'false' rather than PHP booleans --
// PDO (with ATTR_EMULATE_PREPARES off) binds PHP false as an empty string
// for a real Postgres boolean column, which Postgres rejects. Same fix as
// dilp.php's is4PsBeneficiary binding.
function clpepBoolOrNull($v) {
    if ($v === true || $v === 'true' || $v === 1 || $v === '1') return 'true';
    if ($v === false || $v === 'false' || $v === 0 || $v === '0') return 'false';
    return null;
}

function clpepServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='CLPEP' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('CLPEP service not found.', 500);
    return (int) $sid;
}

// Same derivation rule as DILP/TUPAD: Active while Planned/Ongoing, Completed
// once Completed, Inactive otherwise (no assignment, or the intervention was
// Cancelled -- clpep_intervention_status_enum has a Cancelled state DILP's
// project_status_enum shares, so that falls back to Inactive too).
function clpepDeriveStatus($interventionStatus) {
    if ($interventionStatus === 'Completed') return 'Completed';
    if ($interventionStatus === 'Planned' || $interventionStatus === 'Ongoing') return 'Active';
    return 'Inactive';
}

function clpepUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function clpepFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// ─── Interventions ─────────────────────────────────────────────────────────────

function clpepListInterventions() {
    $s = db()->query(
        "SELECT i.*, (SELECT COUNT(*) FROM clpep_intervention_beneficiaries ib WHERE ib.intervention_id = i.intervention_id) AS assigned_count
         FROM clpep_interventions i
         ORDER BY i.created_at DESC, i.intervention_id DESC"
    );
    json(['status' => 'ok', 'data' => array_map('clpepFormatIntervention', $s->fetchAll())]);
}

function clpepGetInterventionById($id) {
    $s = db()->prepare(
        "SELECT i.*, (SELECT COUNT(*) FROM clpep_intervention_beneficiaries ib WHERE ib.intervention_id = i.intervention_id) AS assigned_count
         FROM clpep_interventions i WHERE i.intervention_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? clpepFormatIntervention($r) : null;
}

function clpepFormatIntervention($r) {
    return [
        'id'                       => (int) $r['intervention_id'],
        'interventionName'         => $r['intervention_name'],
        'description'              => $r['description'] ?? '',
        'interventionCategory'     => $r['intervention_category'] ?? '',
        'interventionCategoryOther'=> $r['intervention_category_other'] ?? '',
        'targetBeneficiaries'      => $r['target_beneficiaries'] !== null ? (string) $r['target_beneficiaries'] : '',
        'date'                     => $r['intervention_date'],
        'implementingOfficer'      => $r['facilitator'] ?? '',
        'partnerAgency'            => $r['partner_agency'] ?? '',
        'partnerAgencyOther'       => $r['partner_agency_other'] ?? '',
        'location'                 => $r['venue'] ?? '',
        'status'                   => $r['status'],
        'assignedCount'            => (int) $r['assigned_count'],
        'documents'                => clpepFetchInterventionDocuments((int) $r['intervention_id']),
    ];
}

function clpepValidateInterventionInput($d) {
    $name = trim($d['interventionName'] ?? '');
    if ($name === '') error('Intervention Name is required.', 422);

    $date = clpepDate($d['date'] ?? '');
    if (!$date) error('Date is required.', 422);

    $officer = trim($d['implementingOfficer'] ?? '');
    if ($officer === '') error('Implementing Officer is required.', 422);

    $category = clpepNullStr($d['interventionCategory'] ?? '');
    if (!$category) error('Intervention Category is required.', 422);

    return [$name, $date, $officer, $category];
}

function clpepCreateIntervention() {
    $uid = requireLogin();
    $d = body();
    [$name, $date, $officer, $category] = clpepValidateInterventionInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $s = $pdo->prepare(
        "INSERT INTO clpep_interventions(intervention_name,description,intervention_category,intervention_category_other,target_beneficiaries,intervention_date,facilitator,partner_agency,partner_agency_other,venue,status,created_at,updated_at)
         VALUES(:name,:desc,:cat,:catOther,:target,:date,:officer,:agency,:agencyOther,:venue,:status,now(),now()) RETURNING intervention_id"
    );
    $s->execute([
        ':name' => $name, ':desc' => clpepNullStr($d['description'] ?? ''), ':cat' => $category,
        ':catOther' => $category === 'Other' ? clpepNullStr($d['interventionCategoryOther'] ?? '') : null,
        ':target' => clpepIntOrNull($d['targetBeneficiaries'] ?? null), ':date' => $date, ':officer' => $officer,
        ':agency' => clpepNullStr($d['partnerAgency'] ?? ''),
        ':agencyOther' => ($d['partnerAgency'] ?? '') === 'Other' ? clpepNullStr($d['partnerAgencyOther'] ?? '') : null,
        ':venue' => clpepNullStr($d['location'] ?? ''), ':status' => $status,
    ]);
    $id = (int) $s->fetchColumn();

    clpepSyncInterventionDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Intervention created.', 'data' => clpepGetInterventionById($id)]);
}

function clpepUpdateIntervention($id) {
    if (!is_numeric($id)) error('Invalid intervention id.', 422);
    $id  = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$name, $date, $officer, $category] = clpepValidateInterventionInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $chk = $pdo->prepare("SELECT 1 FROM clpep_interventions WHERE intervention_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Intervention not found.', 404);

    $pdo->prepare(
        "UPDATE clpep_interventions SET intervention_name=:name,description=:desc,intervention_category=:cat,intervention_category_other=:catOther,target_beneficiaries=:target,intervention_date=:date,facilitator=:officer,partner_agency=:agency,partner_agency_other=:agencyOther,venue=:venue,status=:status,updated_at=now() WHERE intervention_id=:id"
    )->execute([
        ':name' => $name, ':desc' => clpepNullStr($d['description'] ?? ''), ':cat' => $category,
        ':catOther' => $category === 'Other' ? clpepNullStr($d['interventionCategoryOther'] ?? '') : null,
        ':target' => clpepIntOrNull($d['targetBeneficiaries'] ?? null), ':date' => $date, ':officer' => $officer,
        ':agency' => clpepNullStr($d['partnerAgency'] ?? ''),
        ':agencyOther' => ($d['partnerAgency'] ?? '') === 'Other' ? clpepNullStr($d['partnerAgencyOther'] ?? '') : null,
        ':venue' => clpepNullStr($d['location'] ?? ''), ':status' => $status, ':id' => $id,
    ]);

    clpepSyncInterventionDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Intervention updated.', 'data' => clpepGetInterventionById($id)]);
}

function clpepUpdateInterventionStatus($id) {
    if (!is_numeric($id)) error('Invalid intervention id.', 422);
    $id = (int) $id;
    $d  = body();
    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);

    $chk = db()->prepare("SELECT 1 FROM clpep_interventions WHERE intervention_id=:id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Intervention not found.', 404);

    db()->prepare("UPDATE clpep_interventions SET status=:s, updated_at=now() WHERE intervention_id=:id")->execute([':s' => $status, ':id' => $id]);

    // Cascade: refresh derived status for every beneficiary currently linked
    // to this intervention (clpep_profiles.status is stored, unlike DILP/TUPAD
    // which compute it purely at read time).
    clpepRefreshDerivedStatusForIntervention($id, $status);

    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => clpepGetInterventionById($id)]);
}

function clpepRefreshDerivedStatusForIntervention($interventionId, $interventionStatus) {
    $derived = clpepDeriveStatus($interventionStatus);
    db()->prepare(
        "UPDATE clpep_profiles SET status=:st, updated_at=now()
         WHERE beneficiary_service_id IN (
           SELECT beneficiary_service_id FROM clpep_intervention_beneficiaries WHERE intervention_id=:iid
         )"
    )->execute([':st' => $derived, ':iid' => $interventionId]);
}

function clpepDeleteIntervention($id) {
    if (!is_numeric($id)) error('Invalid intervention id.', 422);
    $id = (int) $id;

    $cntS = db()->prepare("SELECT COUNT(*) FROM clpep_intervention_beneficiaries WHERE intervention_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int) $cntS->fetchColumn();
    if ($cnt > 0) {
        error("Cannot delete: {$cnt} " . ($cnt === 1 ? 'beneficiary' : 'beneficiaries') . " assigned to this intervention. Unassign them first.", 409);
    }

    $docs = db()->prepare("SELECT file_path FROM documents WHERE clpep_intervention_id=:id");
    $docs->execute([':id' => $id]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    db()->prepare("DELETE FROM clpep_interventions WHERE intervention_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Intervention deleted.']);
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function clpepListProfiles() {
    $s = db()->prepare(
        "SELECT DISTINCT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => clpepServiceId()]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $bid) {
        $p = clpepBuildProfile((int) $bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function clpepGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = clpepBuildProfile((int) $id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

function clpepBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.date_applied, bs.received_by, bs.remarks,
                cp.clpep_profile_id, cp.child_labor_status, cp.school_status, cp.nature_of_work,
                cp.currently_working, cp.hours_worked_per_week, cp.school_name, cp.grade_year_level,
                cp.guardian_name, cp.guardian_relationship, cp.guardian_contact_no, cp.status AS profile_status
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         LEFT JOIN clpep_profiles cp ON cp.beneficiary_service_id = bs.beneficiary_service_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND bs.service_id = :sid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':sid' => clpepServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int) $b['beneficiary_service_id'];

    // Current assignment = most recently assigned row; older rows survive as
    // history rather than being deleted on reassignment (same rule as TUPAD).
    $histS = db()->prepare(
        "SELECT ib.intervention_id, ib.assigned_at, i.intervention_name, i.status
         FROM clpep_intervention_beneficiaries ib
         JOIN clpep_interventions i ON i.intervention_id = ib.intervention_id
         WHERE ib.beneficiary_service_id = :bsid
         ORDER BY ib.assigned_at DESC, ib.intervention_beneficiary_id DESC"
    );
    $histS->execute([':bsid' => $bsId]);
    $history = $histS->fetchAll();
    $current = $history[0] ?? null;

    $assignmentHistory = array_map(function ($h) {
        return [
            'interventionId'   => (int) $h['intervention_id'],
            'interventionName' => $h['intervention_name'],
            'assignedDate'     => $h['assigned_at'],
            'completedDate'    => $h['status'] === 'Completed' ? $h['assigned_at'] : null,
        ];
    }, $history);

    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (new DateTime($b['birth_date']))->diff(new DateTime('today'))->y;
    }

    return [
        'id'                     => $bid,
        'beneficiaryServiceId'   => $bsId,
        'lastName'               => $b['last_name'],
        'firstName'              => $b['first_name'],
        'middleName'             => $b['middle_name'] ?? '',
        'nameExtension'          => $b['suffix'] ?? '',
        'sex'                    => $b['sex'] ?? '',
        'birthdate'              => $b['birth_date'] ?? '',
        'age'                    => $age,
        'streetPurok'            => $b['street_address'] ?? '',
        'barangay'               => $b['barangay_name'] ?? '',
        'barangayId'             => (int) ($b['barangay_id'] ?? 0),
        'cityMunicipality'       => $b['city_name'] ?? '',
        'province'               => $b['province_name'] ?? '',
        'region'                 => $b['region_name'] ?? '',
        'childLaborStatus'       => $b['child_labor_status'] ?? '',
        'schoolStatus'           => $b['school_status'] ?? '',
        'natureOfWork'           => $b['nature_of_work'] ?? '',
        'currentlyWorking'       => $b['currently_working'] !== null ? (bool) $b['currently_working'] : null,
        'hoursWorkedPerWeek'     => $b['hours_worked_per_week'] !== null ? (string) $b['hours_worked_per_week'] : '',
        'schoolName'             => $b['school_name'] ?? '',
        'gradeYearLevel'         => $b['grade_year_level'] ?? '',
        'guardianName'           => $b['guardian_name'] ?? '',
        'guardianRelationship'   => $b['guardian_relationship'] ?? '',
        'guardianContactNumber'  => $b['guardian_contact_no'] ?? '',
        'assignedInterventionId'      => $current ? (int) $current['intervention_id'] : null,
        'assignedInterventionName'    => $current['intervention_name'] ?? '',
        'assignedInterventionStatus'  => $current['status'] ?? '',
        'assignmentHistory'      => $assignmentHistory,
        'attachedDocuments'      => clpepFetchSavedDocuments($bid),
        'dateApplied'            => $b['date_applied'] ?? '',
        'remarks'                => $b['remarks'] ?? '',
        'receivedBy'             => $b['received_by'] ?? '',
        'status'                 => $b['profile_status'] ?? 'Inactive',
    ];
}

// Required fields mirror beneficiaries' real NOT NULL columns (sex, birth_date,
// barangay_id -- civil_status is hardcoded, not user input, since CLPEP is
// minors-only and the form doesn't collect it). child_labor_status is also
// NOT NULL on clpep_profiles but has no required marker in the UI, so an
// empty value defaults to 'Not Yet Assessed' rather than blocking the save.
function clpepValidateProfileInput($d) {
    if (clpepNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (clpepNullStr($d['lastName'] ?? '') === null)  error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male', 'Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = clpepDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int) $d['barangayId'] > 0)
             ? (int) $d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    $validChildLabor = ['Child Laborer', 'At Risk of Child Labor', 'Former Child Laborer', 'Not Yet Assessed'];
    $childLabor = in_array($d['childLaborStatus'] ?? '', $validChildLabor, true) ? $d['childLaborStatus'] : 'Not Yet Assessed';

    $validSchool = ['Currently Enrolled', 'Out of School', 'ALS Learner', 'ALS Graduate', 'Graduated'];
    $school = in_array($d['schoolStatus'] ?? '', $validSchool, true) ? $d['schoolStatus'] : null;

    return [$sex, $birth, $bgyId, $childLabor, $school];
}

function clpepCreateProfile() {
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $bgyId, $childLabor, $school] = clpepValidateProfileInput($d);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare(
            "INSERT INTO beneficiaries(first_name,middle_name,last_name,suffix,sex,birth_date,civil_status,street_address,barangay_id,status)
             VALUES(:fn,:mn,:ln,:sfx,:sex,:bdate,'Single',:street,:bgy,'Active') RETURNING beneficiary_id"
        );
        $s->execute([
            ':fn' => trim($d['firstName']), ':mn' => clpepNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => clpepNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth,
            ':street' => clpepNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId,
        ]);
        $bid = (int) $s->fetchColumn();

        $s2 = $pdo->prepare(
            "INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by,remarks) VALUES(:bid,:sid,'Active',:date,:rby,:rmk) RETURNING beneficiary_service_id"
        );
        $s2->execute([
            ':bid' => $bid, ':sid' => clpepServiceId(), ':date' => clpepDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
            ':rby' => clpepNullStr($d['receivedBy'] ?? ''), ':rmk' => clpepNullStr($d['remarks'] ?? ''),
        ]);
        $bsId = (int) $s2->fetchColumn();

        $pdo->prepare(
            "INSERT INTO clpep_profiles(beneficiary_service_id,child_labor_status,school_status,nature_of_work,currently_working,hours_worked_per_week,school_name,grade_year_level,guardian_name,guardian_relationship,guardian_contact_no,status,date_applied,created_at,updated_at)
             VALUES(:bsid,:cls,:school,:nature,:working,:hours,:sname,:grade,:gname,:grel,:gcontact,'Inactive',:date,now(),now())"
        )->execute([
            ':bsid' => $bsId, ':cls' => $childLabor, ':school' => $school,
            ':nature' => clpepNullStr($d['natureOfWork'] ?? ''), ':working' => clpepBoolOrNull($d['currentlyWorking'] ?? null),
            ':hours' => clpepNumOrNull($d['hoursWorkedPerWeek'] ?? null), ':sname' => clpepNullStr($d['schoolName'] ?? ''),
            ':grade' => clpepNullStr($d['gradeYearLevel'] ?? ''), ':gname' => clpepNullStr($d['guardianName'] ?? ''),
            ':grel' => clpepNullStr($d['guardianRelationship'] ?? ''), ':gcontact' => clpepNullStr($d['guardianContactNumber'] ?? ''),
            ':date' => clpepDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
        ]);

        clpepSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => clpepBuildProfile($bid)]);
}

function clpepUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $bgyId, $childLabor, $school] = clpepValidateProfileInput($d);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => clpepServiceId()]);
    $bsId = $chk->fetchColumn();
    if (!$bsId) error('CLPEP profile not found.', 404);
    $bsId = (int) $bsId;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,suffix=:sfx,sex=:sex,birth_date=:bdate,street_address=:street,barangay_id=:bgy,updated_at=now() WHERE beneficiary_id=:bid"
        )->execute([
            ':fn' => trim($d['firstName']), ':mn' => clpepNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => clpepNullStr($d['nameExtension'] ?? ''), ':sex' => $sex, ':bdate' => $birth,
            ':street' => clpepNullStr($d['streetPurok'] ?? ''), ':bgy' => $bgyId, ':bid' => $bid,
        ]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby,date_applied=:date,remarks=:rmk WHERE beneficiary_service_id=:bsid")
            ->execute([
                ':rby' => clpepNullStr($d['receivedBy'] ?? ''), ':date' => clpepDate($d['dateApplied'] ?? '') ?? date('Y-m-d'),
                ':rmk' => clpepNullStr($d['remarks'] ?? ''), ':bsid' => $bsId,
            ]);

        $pdo->prepare(
            "UPDATE clpep_profiles SET child_labor_status=:cls,school_status=:school,nature_of_work=:nature,currently_working=:working,hours_worked_per_week=:hours,school_name=:sname,grade_year_level=:grade,guardian_name=:gname,guardian_relationship=:grel,guardian_contact_no=:gcontact,date_applied=:date,updated_at=now() WHERE beneficiary_service_id=:bsid"
        )->execute([
            ':cls' => $childLabor, ':school' => $school,
            ':nature' => clpepNullStr($d['natureOfWork'] ?? ''), ':working' => clpepBoolOrNull($d['currentlyWorking'] ?? null),
            ':hours' => clpepNumOrNull($d['hoursWorkedPerWeek'] ?? null), ':sname' => clpepNullStr($d['schoolName'] ?? ''),
            ':grade' => clpepNullStr($d['gradeYearLevel'] ?? ''), ':gname' => clpepNullStr($d['guardianName'] ?? ''),
            ':grel' => clpepNullStr($d['guardianRelationship'] ?? ''), ':gcontact' => clpepNullStr($d['guardianContactNumber'] ?? ''),
            ':date' => clpepDate($d['dateApplied'] ?? '') ?? date('Y-m-d'), ':bsid' => $bsId,
        ]);

        clpepSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => clpepBuildProfile($bid)]);
}

function clpepDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();

    // Cannot delete a beneficiary whose most recent assignment is to a
    // non-completed intervention -- unassign first, same lock spirit as TUPAD.
    $chk = db()->prepare(
        "SELECT i.status
         FROM beneficiary_services bs
         JOIN clpep_intervention_beneficiaries ib ON ib.beneficiary_service_id = bs.beneficiary_service_id
         JOIN clpep_interventions i ON i.intervention_id = ib.intervention_id
         WHERE bs.beneficiary_id = :bid AND bs.service_id = :sid
         ORDER BY ib.assigned_at DESC, ib.intervention_beneficiary_id DESC LIMIT 1"
    );
    $chk->execute([':bid' => $bid, ':sid' => clpepServiceId()]);
    $status = $chk->fetchColumn();
    if ($status !== false && in_array($status, ['Planned', 'Ongoing'], true)) {
        error('This beneficiary cannot be deleted because they are currently assigned to an active intervention. Unassign them first (only possible while the intervention is still Planned), or wait until it is marked Completed.', 409);
    }

    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Beneficiary moved to recycle bin.']);
}

// ─── Assign / Unassign intervention ────────────────────────────────────────────
// clpep_intervention_beneficiaries is unique on (intervention_id,
// beneficiary_service_id) only -- assigning always inserts (or refreshes an
// existing identical link), never deletes an older assignment, so completed
// interventions remain in history. clpep_profiles.status is written here to
// reflect the new assignment (derived, never accepted from the client).

function clpepAssignIntervention() {
    requireLogin();
    $d = body();
    $bid           = clpepIntOrNull($d['applicantId'] ?? '');
    $interventionId = clpepIntOrNull($d['interventionId'] ?? '');
    if (!$bid || !$interventionId) error('applicantId and interventionId are required.', 422);

    $bsS = db()->prepare(
        "SELECT bs.beneficiary_service_id FROM beneficiary_services bs
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $bsS->execute([':bid' => $bid, ':sid' => clpepServiceId()]);
    $bsId = $bsS->fetchColumn();
    if (!$bsId) error('CLPEP profile not found.', 404);
    $bsId = (int) $bsId;

    $ivS = db()->prepare("SELECT status, target_beneficiaries FROM clpep_interventions WHERE intervention_id=:id");
    $ivS->execute([':id' => $interventionId]);
    $iv = $ivS->fetch();
    if (!$iv) error('Intervention not found.', 404);
    if ($iv['status'] !== 'Planned') error('Only Planned interventions can be assigned.', 409);

    if ($iv['target_beneficiaries'] !== null) {
        $cntS = db()->prepare(
            "SELECT COUNT(*) FROM clpep_intervention_beneficiaries
             WHERE intervention_id=:id AND beneficiary_service_id != :bsid"
        );
        $cntS->execute([':id' => $interventionId, ':bsid' => $bsId]);
        $current = (int) $cntS->fetchColumn();
        if ($current >= (int) $iv['target_beneficiaries']) {
            error("This intervention is already at full capacity ({$current}/{$iv['target_beneficiaries']}).", 409);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO clpep_intervention_beneficiaries(intervention_id,beneficiary_service_id,assigned_at)
             VALUES(:iid,:bsid,now())
             ON CONFLICT (intervention_id, beneficiary_service_id) DO UPDATE SET assigned_at=now()"
        )->execute([':iid' => $interventionId, ':bsid' => $bsId]);

        $pdo->prepare("UPDATE clpep_profiles SET status=:st, updated_at=now() WHERE beneficiary_service_id=:bsid")
            ->execute([':st' => clpepDeriveStatus($iv['status']), ':bsid' => $bsId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to assign intervention: ' . $e->getMessage(), 500);
    }

    json(['status' => 'ok', 'message' => 'Beneficiary assigned to intervention.']);
}

function clpepUnassignIntervention() {
    requireLogin();
    $d = body();
    $bid = clpepIntOrNull($d['applicantId'] ?? '');
    if (!$bid) error('applicantId is required.', 422);

    $row = db()->prepare(
        "SELECT ib.intervention_beneficiary_id, ib.beneficiary_service_id, i.status AS intervention_status
         FROM beneficiary_services bs
         JOIN clpep_intervention_beneficiaries ib ON ib.beneficiary_service_id = bs.beneficiary_service_id
         JOIN clpep_interventions i ON i.intervention_id = ib.intervention_id
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid
         ORDER BY ib.assigned_at DESC, ib.intervention_beneficiary_id DESC LIMIT 1"
    );
    $row->execute([':bid' => $bid, ':sid' => clpepServiceId()]);
    $r = $row->fetch();
    if (!$r) error('This beneficiary is not currently assigned to an intervention.', 404);

    // Only the current (most recent) assignment can be undone, and only while
    // its intervention is still Planned -- once it has moved on, this row is
    // the sole record that the assignment happened at all.
    if ($r['intervention_status'] !== 'Planned') {
        error('This intervention is no longer Planned -- unassigning would erase the only record of this assignment.', 409);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM clpep_intervention_beneficiaries WHERE intervention_beneficiary_id=:id")
            ->execute([':id' => (int) $r['intervention_beneficiary_id']]);

        $pdo->prepare("UPDATE clpep_profiles SET status='Inactive', updated_at=now() WHERE beneficiary_service_id=:bsid")
            ->execute([':bsid' => (int) $r['beneficiary_service_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to unassign intervention: ' . $e->getMessage(), 500);
    }

    json(['status' => 'ok', 'message' => 'Beneficiary unassigned from intervention.']);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────

function clpepSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    $docs = is_array($d['attachedDocuments'] ?? null) ? $d['attachedDocuments'] : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='CLPEP'");
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
         VALUES(:bid,:bsid,'CLPEP',:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'clpep_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function clpepFetchSavedDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='CLPEP' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'fileName' => $r['file_name'],
            'fileSize' => clpepFormatBytes($r['file_size'] ?? 0),
            'url'      => clpepUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ─── Documents (intervention) ─────────────────────────────────────────────────

function clpepSyncInterventionDocuments($pdo, $interventionId, $uid, $docsPayload) {
    $docs = is_array($docsPayload) ? $docsPayload : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE clpep_intervention_id=:iid");
    $sel->execute([':iid' => $interventionId]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(clpep_intervention_id,document_source,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:iid,'CLPEP Intervention',:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['name'] ?? $doc['fileName'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'clpep_intervention_' . $interventionId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':iid' => $interventionId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function clpepFetchInterventionDocuments($interventionId) {
    $s = db()->prepare(
        "SELECT document_id, file_name, file_path, file_size, mime_type
         FROM documents WHERE clpep_intervention_id=:iid ORDER BY document_id"
    );
    $s->execute([':iid' => $interventionId]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'name'     => $r['file_name'],
            'fileType' => $r['mime_type'] ?? '',
            'url'      => clpepUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN  (soft-deleted CLPEP beneficiaries) — mirrors tupad.php's pattern.
// ═══════════════════════════════════════════════════════════════════════════════

function clpepRecycleMap() {
    return [
        'clpepApplicant' => ['beneficiaries', 'beneficiary_id'],
    ];
}

function clpepRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(clpepRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

function clpepListDeleted() {
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
    $s->execute([':sid' => clpepServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType'  => 'clpepApplicant',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'CLPEP Beneficiaries',
            'description' => 'Child Labor Prevention and Elimination Program beneficiary record',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

function clpepRestoreRecord() {
    [$type, $id] = clpepRecycleTarget();
    [$table, $pk] = clpepRecycleMap()[$type];

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    json(['status' => 'ok', 'message' => 'Record restored.']);
}

function clpepPurgeRecord() {
    [$type, $id] = clpepRecycleTarget();
    [$table, $pk] = clpepRecycleMap()[$type];

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    clpepHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

function clpepHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
        $bsStmt->execute([':id' => $bid, ':sid' => clpepServiceId()]);
        $bsId = $bsStmt->fetchColumn();
        if ($bsId !== false) {
            $pdo->prepare("DELETE FROM clpep_intervention_beneficiaries WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
            $pdo->prepare("DELETE FROM clpep_profiles WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'CLPEP'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'CLPEP'")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
