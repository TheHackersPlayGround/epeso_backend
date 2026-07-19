<?php
// SPES: profiles (beneficiary spine) + batches

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listBatches':       requirePermission('spes','Viewer'); return spesListBatches();
        case 'createBatch':       requirePermission('spes','Editor'); return spesCreateBatch();
        case 'updateBatch':       requirePermission('spes','Editor'); return spesUpdateBatch($id);
        case 'deleteBatch':       requirePermission('spes','Editor'); return spesDeleteBatch($id);
        case 'updateBatchStatus': requirePermission('spes','Editor'); return spesUpdateBatchStatus($id);
        case 'listProfiles':      requirePermission('spes','Viewer'); return spesListProfiles();
        case 'getProfile':        requirePermission('spes','Viewer'); return spesGetProfile($id);
        case 'createProfile':     requirePermission('spes','Editor'); return spesCreateProfile();
        case 'updateProfile':     requirePermission('spes','Editor'); return spesUpdateProfile($id);
        case 'deleteProfile':     requirePermission('spes','Editor'); return spesDeleteProfile($id);
        case 'assignBatch':       requirePermission('spes','Editor'); return spesAssignBatch();
        case 'unassignBatch':     requirePermission('spes','Editor'); return spesUnassignBatch();
        case 'listDeleted':       requirePermission('spes','Viewer'); return spesListDeleted();
        case 'restoreRecord':     requirePermission('spes','Editor'); return spesRestoreRecord();
        case 'purgeRecord':       requirePermission('spes','Editor'); return spesPurgeRecord();
        default: error("Unknown SPES action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function spesNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function spesDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function spesIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }

// Frontend sends money formatted with thousands separators (e.g. "80,000");
// annual_family_income is a plain numeric(12,2) column.
function spesMoneyOrNull($v) {
    if ($v === null) return null;
    $s = trim(str_replace([',', '₱', ' '], '', (string)$v));
    return ($s === '' || !is_numeric($s)) ? null : (float)$s;
}

function spesServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='SPES' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('SPES service not found.', 500);
    return (int)$sid;
}

function spesValidClassifications() {
    return ['Student','Fresh Graduate','Employed','Underemployed','Unemployed',
            'Out of School Youth','Person with Disability','Solo Parent',
            'Women','Senior Citizen','Returning OFW','Other','Indigenous People'];
}

// spes_batches.funding_source is a single free-text column; the frontend
// shows a fixed dropdown ending in an "Other" sentinel + a free-text field.
// Split/join here so editing a batch round-trips correctly without a second
// DB column.
function spesFundingSourceOptions() {
    return ['DOLE', 'LGU', 'DOLE + LGU', 'Partner Agency'];
}
function spesSplitFunding($stored) {
    $stored = (string)($stored ?? '');
    if (in_array($stored, spesFundingSourceOptions(), true)) return [$stored, ''];
    return $stored === '' ? ['', ''] : ['Other', $stored];
}
function spesJoinFunding($fundingSource, $fundingSourceOther) {
    if ($fundingSource === 'Other') return trim((string)$fundingSourceOther);
    return trim((string)$fundingSource);
}

function spesUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function spesFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// ─── Batches ──────────────────────────────────────────────────────────────────

function spesListBatches() {
    $s = db()->query(
        "SELECT b.*, (SELECT COUNT(*) FROM spes_profiles sp WHERE sp.batch_id = b.batch_id) AS assigned_count
         FROM spes_batches b
         ORDER BY b.created_at DESC, b.batch_id DESC"
    );
    json(['status' => 'ok', 'data' => array_map('spesFormatBatch', $s->fetchAll())]);
}

function spesGetBatchById($id) {
    $s = db()->prepare(
        "SELECT b.*, (SELECT COUNT(*) FROM spes_profiles sp WHERE sp.batch_id = b.batch_id) AS assigned_count
         FROM spes_batches b WHERE b.batch_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? spesFormatBatch($r) : null;
}

function spesFormatBatch($r) {
    [$funding, $fundingOther] = spesSplitFunding($r['funding_source'] ?? '');
    return [
        'id'                   => (int) $r['batch_id'],
        'batchName'            => $r['batch_name'],
        'description'          => $r['description'] ?? '',
        'programStartDate'     => $r['program_start_date'],
        'programEndDate'       => $r['program_end_date'],
        'availableSlots'       => (string) $r['available_slots'],
        'assignedCount'        => (int) $r['assigned_count'],
        'employer'             => $r['employer_agency'],
        'deploymentLocation'   => $r['deployment_location'] ?? '',
        'coordinator'          => $r['coordinator'],
        'fundingSource'        => $funding,
        'fundingSourceOther'   => $fundingOther,
        'status'               => $r['status'],
        'documents'            => spesFetchBatchDocuments((int) $r['batch_id']),
    ];
}

function spesValidateBatchInput($d) {
    $name = trim($d['batchName'] ?? '');
    if ($name === '') error('Batch name is required.', 422);

    $progStart = spesDate($d['programStartDate'] ?? '');
    $progEnd   = spesDate($d['programEndDate'] ?? '');
    if (!$progStart) error('Program Start Date is required.', 422);
    if (!$progEnd)   error('Program End Date is required.', 422);
    if ($progEnd < $progStart) error('Program End Date cannot be before Program Start Date.', 422);

    $slots = spesIntOrNull($d['availableSlots'] ?? '');
    if ($slots === null || $slots < 0) error('A valid number of available slots is required.', 422);

    $employer = trim($d['employer'] ?? '');
    $loc      = trim($d['deploymentLocation'] ?? '');
    $coord    = trim($d['coordinator'] ?? '');
    if ($employer === '') error('Participating Employer / Agency is required.', 422);
    if ($loc === '')      error('Deployment Location is required.', 422);
    if ($coord === '')    error('Program Coordinator is required.', 422);

    $funding = spesJoinFunding($d['fundingSource'] ?? '', $d['fundingSourceOther'] ?? '');
    if ($funding === '') error('Funding source is required.', 422);

    return [$name, $progStart, $progEnd, $slots, $employer, $loc, $coord, $funding];
}

// Applies the side effects of a batch status transition: stamps/clears
// spes_batches.completed_at and cascades spes_profiles.status for its
// beneficiaries. Shared by spesCreateBatch/spesUpdateBatch (full-form save)
// and spesUpdateBatchStatus (quick status wizard) so both paths stay in sync
// — completed_at reflects the moment the batch was *actually* marked done,
// not its (mutable, originally-planned) program_end_date.
function spesCascadeBatchStatus($pdo, $id, $prevStatus, $newStatus) {
    if ($newStatus === 'Completed' && $prevStatus !== 'Completed') {
        $pdo->prepare("UPDATE spes_batches SET completed_at=now() WHERE batch_id=:id")->execute([':id' => $id]);
        $pdo->prepare("UPDATE spes_profiles SET status='Completed', updated_at=now() WHERE batch_id=:id AND status='Active'")->execute([':id' => $id]);
    } elseif ($prevStatus === 'Completed' && $newStatus !== 'Completed') {
        // Reopening: clear the stale completion timestamp and reactivate its
        // beneficiaries, backfilling batch_assigned_at if it was never set.
        $pdo->prepare("UPDATE spes_batches SET completed_at=NULL WHERE batch_id=:id")->execute([':id' => $id]);
        $pdo->prepare("UPDATE spes_profiles SET status='Active', batch_assigned_at=COALESCE(batch_assigned_at, now()), updated_at=now() WHERE batch_id=:id AND status='Completed'")->execute([':id' => $id]);
    }
}

function spesCreateBatch() {
    $uid = requireLogin();
    $d = body();
    [$name, $progStart, $progEnd, $slots, $employer, $loc, $coord, $funding] = spesValidateBatchInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $s = $pdo->prepare(
        "INSERT INTO spes_batches(batch_name,description,program_start_date,program_end_date,available_slots,employer_agency,deployment_location,coordinator,funding_source,status,created_at,updated_at)
         VALUES(:name,:desc,:pstart,:pend,:slots,:employer,:loc,:coord,:funding,:status,now(),now()) RETURNING batch_id"
    );
    $s->execute([
        ':name' => $name, ':desc' => spesNullStr($d['description'] ?? ''),
        ':pstart' => $progStart, ':pend' => $progEnd,
        ':slots' => $slots, ':employer' => $employer, ':loc' => $loc,
        ':coord' => $coord, ':funding' => $funding, ':status' => $status,
    ]);
    $id = (int) $s->fetchColumn();

    // A batch can only ever be created as Planned via the UI, but guard the
    // (API-only) edge case of creating one already Completed.
    spesCascadeBatchStatus($pdo, $id, null, $status);

    spesSyncBatchDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Batch created.', 'data' => spesGetBatchById($id)]);
}

function spesUpdateBatch($id) {
    if (!is_numeric($id)) error('Invalid batch id.', 422);
    $id  = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$name, $progStart, $progEnd, $slots, $employer, $loc, $coord, $funding] = spesValidateBatchInput($d);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $pdo = db();
    $curS = $pdo->prepare("SELECT status FROM spes_batches WHERE batch_id=:id");
    $curS->execute([':id' => $id]);
    $prev = $curS->fetchColumn();
    if ($prev === false) error('Batch not found.', 404);

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE spes_batches SET batch_name=:name,description=:desc,program_start_date=:pstart,program_end_date=:pend,available_slots=:slots,employer_agency=:employer,deployment_location=:loc,coordinator=:coord,funding_source=:funding,status=:status,updated_at=now() WHERE batch_id=:id"
        )->execute([
            ':name' => $name, ':desc' => spesNullStr($d['description'] ?? ''),
            ':pstart' => $progStart, ':pend' => $progEnd,
            ':slots' => $slots, ':employer' => $employer, ':loc' => $loc,
            ':coord' => $coord, ':funding' => $funding, ':status' => $status, ':id' => $id,
        ]);

        spesCascadeBatchStatus($pdo, $id, $prev, $status);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update batch: ' . $e->getMessage(), 500);
    }

    spesSyncBatchDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Batch updated.', 'data' => spesGetBatchById($id)]);
}

function spesUpdateBatchStatus($id) {
    if (!is_numeric($id)) error('Invalid batch id.', 422);
    $id = (int) $id;
    $d  = body();
    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);

    $curS = db()->prepare("SELECT status FROM spes_batches WHERE batch_id=:id");
    $curS->execute([':id' => $id]);
    $prev = $curS->fetchColumn();
    if ($prev === false) error('Batch not found.', 404);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE spes_batches SET status=:s, updated_at=now() WHERE batch_id=:id")->execute([':s' => $status, ':id' => $id]);
        spesCascadeBatchStatus($pdo, $id, $prev, $status);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update batch status: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => spesGetBatchById($id)]);
}

function spesDeleteBatch($id) {
    if (!is_numeric($id)) error('Invalid batch id.', 422);
    $id = (int) $id;

    // spes_profiles.batch_id is the only record that a deployment happened
    // (no participants junction table) — block deletion outright rather than
    // nulling profiles out to allow it.
    $cntS = db()->prepare("SELECT COUNT(*) FROM spes_profiles WHERE batch_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int) $cntS->fetchColumn();
    if ($cnt > 0) {
        error("Cannot delete: {$cnt} applicant" . ($cnt === 1 ? '' : 's') . " linked to this batch (current or past assignees).", 409);
    }

    $docs = db()->prepare("SELECT file_path FROM documents WHERE spes_batch_id=:id");
    $docs->execute([':id' => $id]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    // documents.spes_batch_id is ON DELETE CASCADE, so deleting the batch row
    // also removes its document rows once the files above are unlinked.
    db()->prepare("DELETE FROM spes_batches WHERE batch_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Batch deleted.']);
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function spesListProfiles() {
    $s = db()->prepare(
        "SELECT DISTINCT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => spesServiceId()]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $bid) {
        $p = spesBuildProfile((int) $bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function spesGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = spesBuildProfile((int) $id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

function spesBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.date_applied, bs.status AS bs_status, bs.received_by
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND bs.service_id = :sid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':sid' => spesServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int) $b['beneficiary_service_id'];

    $spS = db()->prepare("SELECT * FROM spes_profiles WHERE beneficiary_service_id=:id LIMIT 1");
    $spS->execute([':id' => $bsId]);
    $sp = $spS->fetch() ?: [];

    $clsS = db()->prepare("SELECT classification, classification_other FROM beneficiary_classifications WHERE beneficiary_id=:id");
    $clsS->execute([':id' => $bid]);
    $clsRows = $clsS->fetchAll();
    $classifications = array_column($clsRows, 'classification');
    $classificationOther = '';
    foreach ($clsRows as $row) {
        if ($row['classification'] === 'Other') { $classificationOther = $row['classification_other'] ?? ''; break; }
    }

    // spes_profiles.batch_id is a single direct FK (no assignment-history
    // table), so we can only ever surface a 0-or-1-entry "history" from the
    // live link — same constraint as GIP.
    $assignmentHistory = [];
    $batchId = isset($sp['batch_id']) ? $sp['batch_id'] : null;
    if ($batchId) {
        $batchS = db()->prepare("SELECT batch_id, batch_name, status, completed_at FROM spes_batches WHERE batch_id=:id");
        $batchS->execute([':id' => $batchId]);
        $batch = $batchS->fetch();
        if ($batch) {
            $assignmentHistory[] = [
                'batchId'       => (int) $batch['batch_id'],
                'batchName'     => $batch['batch_name'],
                'assignedDate'  => $sp['batch_assigned_at'] ?? '',
                'completedDate' => ($batch['status'] === 'Completed') ? ($batch['completed_at'] ?? '') : null,
            ];
        }
    }

    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (new DateTime($b['birth_date']))->diff(new DateTime('today'))->y;
    }

    return [
        'id'                      => $bid,
        'spesProfileId'           => isset($sp['spes_profile_id']) ? (int) $sp['spes_profile_id'] : null,
        'beneficiaryServiceId'    => $bsId,
        'lastName'                => $b['last_name'],
        'firstName'               => $b['first_name'],
        'middleName'              => $b['middle_name'] ?? '',
        'sex'                     => $b['sex'] ?? '',
        'birthdate'               => $b['birth_date'] ?? '',
        'age'                     => $age,
        'civilStatus'             => $b['civil_status'] ?? '',
        'contactNumber'           => $b['contact_no'] ?? '',
        'email'                   => $b['email'] ?? '',
        'streetPurok'             => $b['street_address'] ?? '',
        'barangay'                => $b['barangay_name'] ?? '',
        'barangayId'              => (int) ($b['barangay_id'] ?? 0),
        'cityMunicipality'        => $b['city_name'] ?? '',
        'province'                => $b['province_name'] ?? '',
        'region'                  => $b['region_name'] ?? '',
        'classification'          => array_values($classifications),
        'classificationOther'     => $classificationOther,
        'schoolName'              => $sp['school_name'] ?? '',
        'schoolType'              => $sp['school_type'] ?? '',
        'gradeYearLevel'          => $sp['grade_year_level'] ?? '',
        'course'                  => $sp['course'] ?? '',
        'annualFamilyIncome'      => $sp['annual_family_income'] !== null ? (string) $sp['annual_family_income'] : '',
        'numberOfDependents'      => isset($sp['dependent_count']) ? (int) $sp['dependent_count'] : 0,
        'assignedBatchId'         => $batchId ? (int) $batchId : null,
        'assignmentHistory'       => $assignmentHistory,
        'attachedDocuments'       => spesFetchSavedDocuments($bid),
        'dateApplicationReceived' => $b['date_applied'] ?? '',
        'receivedBy'              => $b['received_by'] ?? '',
        'status'                  => $sp['status'] ?? 'Inactive',
        'remarks'                 => $sp['remarks'] ?? '',
    ];
}

// Required fields mirror beneficiaries' real NOT NULL columns (sex, birth_date,
// civil_status, barangay_id) so a bad request 422s cleanly instead of failing
// as a raw DB constraint violation.
function spesValidateProfileInput($d) {
    if (spesNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (spesNullStr($d['lastName'] ?? '') === null)  error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male', 'Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = spesDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    $validCivil = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int) $d['barangayId'] > 0)
             ? (int) $d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    return [$sex, $birth, $civil, $bgyId];
}

function spesCreateProfile() {
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = spesValidateProfileInput($d);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare("INSERT INTO beneficiaries(first_name,middle_name,last_name,sex,birth_date,civil_status,street_address,barangay_id,contact_no,email,status) VALUES(:fn,:mn,:ln,:sex,:bdate,:civil,:street,:bgy,:contact,:email,'Active') RETURNING beneficiary_id");
        $s->execute([':fn'=>trim($d['firstName']),':mn'=>spesNullStr($d['middleName']??''),':ln'=>trim($d['lastName']),':sex'=>$sex,':bdate'=>$birth,':civil'=>$civil,':street'=>spesNullStr($d['streetPurok']??''),':bgy'=>$bgyId,':contact'=>spesNullStr($d['contactNumber']??''),':email'=>spesNullStr($d['email']??'')]);
        $bid = (int) $s->fetchColumn();

        $s2 = $pdo->prepare("INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by) VALUES(:bid,:sid,'Active',:date,:rby) RETURNING beneficiary_service_id");
        $s2->execute([':bid'=>$bid,':sid'=>spesServiceId(),':date'=>spesDate($d['dateApplicationReceived']??'')??date('Y-m-d'),':rby'=>spesNullStr($d['receivedBy']??'')]);
        $bsId = (int) $s2->fetchColumn();

        $pdo->prepare("INSERT INTO spes_profiles(beneficiary_service_id,school_name,school_type,grade_year_level,course,annual_family_income,dependent_count,remarks,status) VALUES(:bsid,:school,:stype,:glvl,:course,:income,:deps,:rmk,'Inactive')")
            ->execute([
                ':bsid'=>$bsId, ':school'=>spesNullStr($d['schoolName']??''), ':stype'=>spesNullStr($d['schoolType']??''),
                ':glvl'=>spesNullStr($d['gradeYearLevel']??''), ':course'=>spesNullStr($d['course']??''),
                ':income'=>spesMoneyOrNull($d['annualFamilyIncome']??null), ':deps'=>spesIntOrNull($d['numberOfDependents']??0) ?? 0,
                ':rmk'=>spesNullStr($d['remarks']??''),
            ]);

        $validCls = spesValidClassifications();
        $rawCls   = is_array($d['classification'] ?? null) ? $d['classification'] : [];
        $ins = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification,classification_other) VALUES(:bid,:cls,:clsOther) ON CONFLICT DO NOTHING");
        foreach ($rawCls as $c) {
            if (in_array($c, $validCls, true)) {
                $clsOther = $c === 'Other' ? spesNullStr($d['classificationOther'] ?? '') : null;
                $ins->execute([':bid'=>$bid,':cls'=>$c,':clsOther'=>$clsOther]);
            }
        }

        spesSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => spesBuildProfile($bid)]);
}

function spesUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = spesValidateProfileInput($d);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => spesServiceId()]);
    $bsId = $chk->fetchColumn();
    if (!$bsId) error('SPES profile not found.', 404);
    $bsId = (int) $bsId;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,sex=:sex,birth_date=:bdate,civil_status=:civil,street_address=:street,barangay_id=:bgy,contact_no=:contact,email=:email,updated_at=now() WHERE beneficiary_id=:bid")
            ->execute([':fn'=>trim($d['firstName']),':mn'=>spesNullStr($d['middleName']??''),':ln'=>trim($d['lastName']),':sex'=>$sex,':bdate'=>$birth,':civil'=>$civil,':street'=>spesNullStr($d['streetPurok']??''),':bgy'=>$bgyId,':contact'=>spesNullStr($d['contactNumber']??''),':email'=>spesNullStr($d['email']??''),':bid'=>$bid]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby,date_applied=:date WHERE beneficiary_service_id=:bsid")
            ->execute([':rby'=>spesNullStr($d['receivedBy']??''),':date'=>spesDate($d['dateApplicationReceived']??'')??date('Y-m-d'),':bsid'=>$bsId]);

        $ex = db()->prepare("SELECT 1 FROM spes_profiles WHERE beneficiary_service_id=:id");
        $ex->execute([':id' => $bsId]);
        $params = [
            ':school'=>spesNullStr($d['schoolName']??''), ':stype'=>spesNullStr($d['schoolType']??''),
            ':glvl'=>spesNullStr($d['gradeYearLevel']??''), ':course'=>spesNullStr($d['course']??''),
            ':income'=>spesMoneyOrNull($d['annualFamilyIncome']??null), ':deps'=>spesIntOrNull($d['numberOfDependents']??0) ?? 0,
            ':rmk'=>spesNullStr($d['remarks']??''), ':bsid'=>$bsId,
        ];
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE spes_profiles SET school_name=:school,school_type=:stype,grade_year_level=:glvl,course=:course,annual_family_income=:income,dependent_count=:deps,remarks=:rmk,updated_at=now() WHERE beneficiary_service_id=:bsid")
                ->execute($params);
        } else {
            $pdo->prepare("INSERT INTO spes_profiles(beneficiary_service_id,school_name,school_type,grade_year_level,course,annual_family_income,dependent_count,remarks,status) VALUES(:bsid,:school,:stype,:glvl,:course,:income,:deps,:rmk,'Inactive')")
                ->execute($params);
        }

        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id=:bid")->execute([':bid'=>$bid]);
        $validCls = spesValidClassifications();
        $rawCls   = is_array($d['classification'] ?? null) ? $d['classification'] : [];
        $ins = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification,classification_other) VALUES(:bid,:cls,:clsOther)");
        foreach ($rawCls as $c) {
            if (in_array($c, $validCls, true)) {
                $clsOther = $c === 'Other' ? spesNullStr($d['classificationOther'] ?? '') : null;
                $ins->execute([':bid'=>$bid,':cls'=>$c,':clsOther'=>$clsOther]);
            }
        }

        spesSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => spesBuildProfile($bid)]);
}

function spesDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();

    // Cannot delete an applicant currently deployed in an active batch — same
    // lock spirit as GIP/EF's assignment guard. Unassign first.
    $chk = db()->prepare(
        "SELECT sp.batch_id, sp.status
         FROM spes_profiles sp
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = sp.beneficiary_service_id
         WHERE bs.beneficiary_id = :bid AND bs.service_id = :sid"
    );
    $chk->execute([':bid' => $bid, ':sid' => spesServiceId()]);
    $row = $chk->fetch();
    if ($row && $row['batch_id'] && $row['status'] === 'Active') {
        error('This applicant cannot be deleted because they are currently assigned to a batch. Unassign them first (only possible while the batch is still Planned), or wait until it is marked Completed.', 409);
    }

    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Applicant moved to recycle bin.']);
}

// ─── Assign / Unassign batch ──────────────────────────────────────────────────

function spesAssignBatch() {
    requireLogin();
    $d = body();
    $bid     = spesIntOrNull($d['applicantId'] ?? '');
    $batchId = spesIntOrNull($d['batchId'] ?? '');
    if (!$bid || !$batchId) error('applicantId and batchId are required.', 422);

    $bsS = db()->prepare(
        "SELECT bs.beneficiary_service_id FROM beneficiary_services bs
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $bsS->execute([':bid' => $bid, ':sid' => spesServiceId()]);
    $bsId = $bsS->fetchColumn();
    if (!$bsId) error('SPES profile not found.', 404);

    $spS = db()->prepare("SELECT spes_profile_id FROM spes_profiles WHERE beneficiary_service_id=:id");
    $spS->execute([':id' => (int) $bsId]);
    $spId = $spS->fetchColumn();
    if (!$spId) error('SPES profile not found.', 404);
    $spId = (int) $spId;

    // Same rule as unassign: once the applicant's current batch has moved past
    // Planned, there's no history table to fall back on — batch_id is the only
    // record that assignment ever happened, so reassigning would erase it.
    $curS = db()->prepare(
        "SELECT spb.status FROM spes_profiles sp
         LEFT JOIN spes_batches spb ON spb.batch_id = sp.batch_id
         WHERE sp.spes_profile_id = :spid AND sp.batch_id IS NOT NULL"
    );
    $curS->execute([':spid' => $spId]);
    $curBatchStatus = $curS->fetchColumn();
    if ($curBatchStatus !== false && $curBatchStatus !== 'Planned') {
        error('This applicant is already assigned to a batch that is no longer Planned — reassigning would erase the only record of that assignment.', 409);
    }

    $batchS = db()->prepare("SELECT status, available_slots FROM spes_batches WHERE batch_id=:id");
    $batchS->execute([':id' => $batchId]);
    $batch = $batchS->fetch();
    if (!$batch) error('Batch not found.', 404);
    if ($batch['status'] !== 'Planned') error('Only Planned batches can be assigned.', 409);

    $cntS = db()->prepare("SELECT COUNT(*) FROM spes_profiles WHERE batch_id=:id AND spes_profile_id != :spid");
    $cntS->execute([':id' => $batchId, ':spid' => $spId]);
    $current = (int) $cntS->fetchColumn();
    if ($current >= (int) $batch['available_slots']) {
        error("This batch is already at full capacity ({$current}/{$batch['available_slots']}).", 409);
    }

    db()->prepare("UPDATE spes_profiles SET batch_id=:b, status='Active', batch_assigned_at=now(), updated_at=now() WHERE spes_profile_id=:spid")
        ->execute([':b' => $batchId, ':spid' => $spId]);
    json(['status' => 'ok', 'message' => 'Applicant assigned to batch.']);
}

function spesUnassignBatch() {
    requireLogin();
    $d = body();
    $bid = spesIntOrNull($d['applicantId'] ?? '');
    if (!$bid) error('applicantId is required.', 422);

    $row = db()->prepare(
        "SELECT sp.spes_profile_id, spb.status AS batch_status
         FROM spes_profiles sp
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = sp.beneficiary_service_id
         LEFT JOIN spes_batches spb ON spb.batch_id = sp.batch_id
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid
         ORDER BY sp.spes_profile_id DESC LIMIT 1"
    );
    $row->execute([':bid' => $bid, ':sid' => spesServiceId()]);
    $r = $row->fetch();
    if (!$r || !$r['spes_profile_id']) error('SPES profile not found.', 404);

    // spes_profiles.batch_id is the only place an assignment is recorded (no
    // separate history table) — once the batch has moved past Planned
    // (Ongoing or Completed), unassigning would silently erase the only
    // record that this applicant was ever part of it.
    if ($r['batch_status'] !== 'Planned') {
        error('This batch is no longer Planned — unassigning would erase the only record of this assignment.', 409);
    }

    db()->prepare("UPDATE spes_profiles SET batch_id=NULL, status='Inactive', batch_assigned_at=NULL, updated_at=now() WHERE spes_profile_id=:spid")
        ->execute([':spid' => (int) $r['spes_profile_id']]);
    json(['status' => 'ok', 'message' => 'Applicant unassigned from batch.']);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────
//   - savedDocuments entries WITH a base64 dataUrl  → new uploads (saved to disk + inserted)
//   - entries WITHOUT a dataUrl (existing files)     → kept by their document_id
//   - existing docs no longer in the list             → deleted (file unlinked)

function spesSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    // The frontend's SPESApplicant type calls this field "attachedDocuments"
    // (not "savedDocuments" — that name only applies to EF's ApplicantFormData).
    $docs = is_array($d['attachedDocuments'] ?? null) ? $d['attachedDocuments'] : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='SPES'");
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
         VALUES(:bid,:bsid,'SPES',:dtype,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'spes_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $dtype  = spesNullStr($doc['documentType'] ?? '');
        $custom = spesNullStr($doc['customName'] ?? $doc['name'] ?? '');
        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':dtype' => $dtype,
            ':title' => $custom ?? ($dtype ?? $origName),
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function spesFetchSavedDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='SPES' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    return array_map(function ($r) {
        return [
            'id'           => (string) $r['document_id'],
            'documentType' => $r['document_type'] ?? '',
            'customName'   => $r['title'] ?? '',
            'fileName'     => $r['file_name'],
            'fileSize'     => spesFormatBytes($r['file_size'] ?? 0),
            'url'          => spesUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ─── Documents (batch) ────────────────────────────────────────────────────────
// Batch files aren't tied to a beneficiary, so they key off
// documents.spes_batch_id instead (nullable FK, ON DELETE CASCADE) — same
// shared table, same pattern GIP uses via documents.batch_id.

function spesSyncBatchDocuments($pdo, $batchId, $uid, $docsPayload) {
    $docs = is_array($docsPayload) ? $docsPayload : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE spes_batch_id=:bid");
    $sel->execute([':bid' => $batchId]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(spes_batch_id,document_source,document_type,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:bid,'SPES Batch',NULL,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'spes_batch_' . $batchId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':bid' => $batchId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function spesFetchBatchDocuments($batchId) {
    $s = db()->prepare(
        "SELECT document_id, file_name, file_path, file_size
         FROM documents WHERE spes_batch_id=:bid ORDER BY document_id"
    );
    $s->execute([':bid' => $batchId]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'fileName' => $r['file_name'],
            'fileSize' => spesFormatBytes($r['file_size'] ?? 0),
            'url'      => spesUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN  (soft-deleted SPES applicants) — mirrors gip.php's pattern so
// these records surface in the same Security > Activity Logs > Recycle Bin
// screen alongside EF/GIP/CDSP applicants.
// ═══════════════════════════════════════════════════════════════════════════════

// recordType -> [table, primary-key column]. Only one type for SPES today.
function spesRecycleMap() {
    return [
        'spesApplicant' => ['beneficiaries', 'beneficiary_id'],
    ];
}

// Read + validate { recordType, id } from the request body. Returns [type, id].
function spesRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(spesRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

// GET /api/spes/listDeleted
function spesListDeleted() {
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
    $s->execute([':sid' => spesServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType'  => 'spesApplicant',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'SPES Applicants',
            'description' => 'Special Program for Employment of Students applicant record',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

// POST /api/spes/restoreRecord  { recordType, id }  — undo a soft delete.
function spesRestoreRecord() {
    [$type, $id] = spesRecycleTarget();
    [$table, $pk] = spesRecycleMap()[$type];

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    json(['status' => 'ok', 'message' => 'Record restored.']);
}

// POST /api/spes/purgeRecord  { recordType, id }  — permanent delete.
// Only acts on records already in the recycle bin (deleted_at IS NOT NULL).
function spesPurgeRecord() {
    [$type, $id] = spesRecycleTarget();
    [$table, $pk] = spesRecycleMap()[$type];

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    spesHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

// Permanently remove a SPES applicant and its SPES-specific data (uploaded
// files, spes_profiles row, service enrollment). Used by the recycle bin's
// permanent-delete action; not reachable except for an already soft-deleted
// row. Mirrors gipHardDeleteApplicant.
function spesHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
        $bsStmt->execute([':id' => $bid, ':sid' => spesServiceId()]);
        $bsId = $bsStmt->fetchColumn();
        if ($bsId !== false) {
            $pdo->prepare("DELETE FROM spes_profiles WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'SPES'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'SPES'")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
