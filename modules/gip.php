<?php
// GIP: profiles (beneficiary spine) + batches

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listBatches':       requirePermission('gip','Viewer'); return gipListBatches();
        case 'createBatch':       requirePermission('gip','Editor'); return gipCreateBatch();
        case 'updateBatch':       requirePermission('gip','Editor'); return gipUpdateBatch($id);
        case 'deleteBatch':       requirePermission('gip','Editor'); return gipDeleteBatch($id);
        case 'updateBatchStatus': requirePermission('gip','Editor'); return gipUpdateBatchStatus($id);
        case 'listProfiles':      requirePermission('gip','Viewer'); return gipListProfiles();
        case 'getProfile':        requirePermission('gip','Viewer'); return gipGetProfile($id);
        case 'createProfile':     requirePermission('gip','Editor'); return gipCreateProfile();
        case 'updateProfile':     requirePermission('gip','Editor'); return gipUpdateProfile($id);
        case 'deleteProfile':     requirePermission('gip','Editor'); return gipDeleteProfile($id);
        case 'assignBatch':       requirePermission('gip','Editor'); return gipAssignBatch();
        case 'unassignBatch':     requirePermission('gip','Editor'); return gipUnassignBatch();
        case 'listDeleted':       requirePermission('gip','Viewer'); return gipListDeleted();
        case 'restoreRecord':     requirePermission('gip','Editor'); return gipRestoreRecord();
        case 'purgeRecord':       requirePermission('gip','Editor'); return gipPurgeRecord();
        default: error("Unknown GIP action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function gipNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function gipDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function gipIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }
function gipYearOrNull($v) {
    if (!is_numeric($v)) return null;
    $y = (int)$v;
    return ($y >= 1900 && $y <= 2100) ? $y : null;
}

function gipServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='GIP' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('GIP service not found.', 500);
    return (int)$sid;
}

// Map frontend education labels to educational_attainment enum.
function gipMapEducation($v) {
    $map = [
        'Elementary Level'            => 'Elementary Undergraduate',
        'Elementary Graduate'         => 'Elementary Graduate',
        'High School Level'           => 'Junior High School Undergraduate',
        'High School Graduate'        => 'Junior High School Graduate',
        'Senior High School Level'    => 'Senior High School Undergraduate',
        'Senior High School Graduate' => 'Senior High School Graduate',
        'Vocational / Technical'      => 'Vocational Graduate',
        'College Level'               => 'College Undergraduate',
        'College Graduate'            => 'College Graduate',
        "Master's Level"              => "Master's Degree",
        "Master's Graduate"           => "Master's Degree",
        'Doctoral'                    => 'Doctorate Degree',
    ];
    return isset($map[$v]) ? $map[$v] : null;
}

// Inverse of gipMapEducation() — the DB stores educational_attainment_enum
// values, but the frontend's dropdown/conditional-field logic (educationFieldFlags
// in GIPProfileForm.tsx) is keyed on the original form labels, so a read-back
// must translate the enum value back or every existing record's Highest
// Educational Attainment silently fails to match any option (and any update
// that doesn't re-touch the dropdown would then also fail to re-map on save,
// nulling out educational_attainment). "Master's Degree" is ambiguous (both
// "Master's Level" and "Master's Graduate" map to it) — disambiguated by
// whether a year_graduated value is on record.
function gipReverseMapEducation($enumValue, $hasYearGraduated) {
    $map = [
        'Elementary Undergraduate'          => 'Elementary Level',
        'Elementary Graduate'               => 'Elementary Graduate',
        'Junior High School Undergraduate'  => 'High School Level',
        'Junior High School Graduate'       => 'High School Graduate',
        'Senior High School Undergraduate'  => 'Senior High School Level',
        'Senior High School Graduate'       => 'Senior High School Graduate',
        'Vocational Graduate'               => 'Vocational / Technical',
        'College Undergraduate'             => 'College Level',
        'College Graduate'                  => 'College Graduate',
        'Doctorate Degree'                  => 'Doctoral',
    ];
    if (isset($map[$enumValue])) return $map[$enumValue];
    if ($enumValue === "Master's Degree") return $hasYearGraduated ? "Master's Graduate" : "Master's Level";
    return '';
}

// Strip parenthetical suffixes so values match the classification enum.
function gipNormalizeClassification($c) {
    $map = [
        'Person with Disability (PWD)' => 'Person with Disability',
        'Indigenous People (IP)'       => 'Indigenous People',
    ];
    return isset($map[$c]) ? $map[$c] : $c;
}

function gipValidClassifications() {
    return ['Student','Fresh Graduate','Employed','Underemployed','Unemployed',
            'Out of School Youth','Person with Disability','Solo Parent',
            'Women','Senior Citizen','Returning OFW','Other','Indigenous People'];
}

// gip_batches.funding_source is a single free-text column; the frontend shows
// a fixed dropdown + an "Others" free-text field. Split/join here so editing a
// batch round-trips correctly without a second DB column.
function gipFundingSourceOptions() {
    return ['DOLE', 'Local Government Unit (LGU)', 'Private / CSR'];
}
function gipSplitFunding($stored) {
    $stored = (string)($stored ?? '');
    if (in_array($stored, gipFundingSourceOptions(), true)) return [$stored, ''];
    return $stored === '' ? ['', ''] : ['Others', $stored];
}
function gipJoinFunding($fundingSource, $fundingSourceOther) {
    if ($fundingSource === 'Others') return trim((string)$fundingSourceOther);
    return trim((string)$fundingSource);
}

function gipUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function gipFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// ─── Batches ──────────────────────────────────────────────────────────────────

function gipListBatches() {
    $s = db()->query(
        "SELECT b.*, (SELECT COUNT(*) FROM gip_profiles gp WHERE gp.batch_id = b.batch_id) AS assigned_count
         FROM gip_batches b
         ORDER BY b.created_at DESC, b.batch_id DESC"
    );
    json(['status' => 'ok', 'data' => array_map('gipFormatBatch', $s->fetchAll())]);
}

function gipGetBatchById($id) {
    $s = db()->prepare(
        "SELECT b.*, (SELECT COUNT(*) FROM gip_profiles gp WHERE gp.batch_id = b.batch_id) AS assigned_count
         FROM gip_batches b WHERE b.batch_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? gipFormatBatch($r) : null;
}

function gipFormatBatch($r) {
    [$funding, $fundingOther] = gipSplitFunding($r['funding_source'] ?? '');
    return [
        'id'                 => (int) $r['batch_id'],
        'batchName'          => $r['batch_name'],
        'batchCode'          => $r['batch_code'],
        'description'        => $r['description'] ?? '',
        'assignedOffice'     => $r['assigned_office'],
        'deploymentLocation' => $r['deployment_location'] ?? '',
        'coordinator'        => $r['coordinator'],
        'supervisor'         => $r['supervisor'] ?? '',
        'slots'              => (string) $r['slot_count'],
        'assignedCount'      => (int) $r['assigned_count'],
        'fundingSource'      => $funding,
        'fundingSourceOther' => $fundingOther,
        'startDate'          => $r['start_date'],
        'endDate'            => $r['end_date'],
        'allowance'          => $r['monthly_allowance'] !== null ? (string) $r['monthly_allowance'] : '',
        'status'             => $r['status'],
        'documents'          => gipFetchBatchDocuments((int) $r['batch_id']),
    ];
}

function gipValidateBatchInput($d) {
    $name = trim($d['batchName'] ?? '');
    $code = trim($d['batchCode'] ?? '');
    if ($name === '') error('Batch name is required.', 422);
    if ($code === '') error('Batch code is required.', 422);
    $office = trim($d['assignedOffice'] ?? '');
    $loc    = trim($d['deploymentLocation'] ?? '');
    $sup    = trim($d['supervisor'] ?? '');
    if ($office === '') error('Assigned office is required.', 422);
    if ($loc === '')    error('Deployment location is required.', 422);
    if ($sup === '')    error('Supervisor is required.', 422);
    $slots = gipIntOrNull($d['slots'] ?? '');
    if ($slots === null || $slots < 0) error('A valid number of slots is required.', 422);
    $start = gipDate($d['startDate'] ?? '');
    $end   = gipDate($d['endDate'] ?? '');
    if (!$start) error('Start date is required.', 422);
    if (!$end)   error('End date is required.', 422);
    if ($end < $start) error('End date cannot be before start date.', 422);
    $funding = gipJoinFunding($d['fundingSource'] ?? '', $d['fundingSourceOther'] ?? '');
    if ($funding === '') error('Funding source is required.', 422);
    return [$name, $code, $office, $loc, $sup, $slots, $start, $end, $funding];
}

// Applies the side effects of a batch status transition: stamps/clears
// gip_batches.completed_at and cascades gip_profiles.status for its interns.
// Shared by gipCreateBatch/gipUpdateBatch (full-form save) and
// gipUpdateBatchStatus (quick status wizard) so both paths stay in sync —
// completed_at reflects the moment the batch was *actually* marked done, not
// its (mutable, originally-planned) end_date, since the real internship
// period can run past end_date (e.g. absences extending it).
function gipCascadeBatchStatus($pdo, $id, $prevStatus, $newStatus) {
    if ($newStatus === 'Completed' && $prevStatus !== 'Completed') {
        $pdo->prepare("UPDATE gip_batches SET completed_at=now() WHERE batch_id=:id")->execute([':id' => $id]);
        $pdo->prepare("UPDATE gip_profiles SET status='Completed', updated_at=now() WHERE batch_id=:id AND status='Active'")->execute([':id' => $id]);
    } elseif ($prevStatus === 'Completed' && $newStatus !== 'Completed') {
        // Reopening: clear the stale completion timestamp and reactivate its
        // interns, backfilling batch_assigned_at if it was never set.
        $pdo->prepare("UPDATE gip_batches SET completed_at=NULL WHERE batch_id=:id")->execute([':id' => $id]);
        $pdo->prepare("UPDATE gip_profiles SET status='Active', batch_assigned_at=COALESCE(batch_assigned_at, now()), updated_at=now() WHERE batch_id=:id AND status='Completed'")->execute([':id' => $id]);
    }
}

function gipCreateBatch() {
    $uid = requireLogin();
    $d = body();
    [$name, $code, $office, $loc, $sup, $slots, $start, $end, $funding] = gipValidateBatchInput($d);

    $chk = db()->prepare("SELECT 1 FROM gip_batches WHERE batch_code=:c");
    $chk->execute([':c' => $code]);
    if ($chk->fetchColumn()) error('Batch code "' . $code . '" is already in use.', 409);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';
    $allowance = is_numeric($d['allowance'] ?? null) ? (float) $d['allowance'] : null;

    $pdo = db();
    $s = $pdo->prepare(
        "INSERT INTO gip_batches(batch_name,batch_code,description,assigned_office,deployment_location,coordinator,supervisor,slot_count,funding_source,start_date,end_date,monthly_allowance,status,created_at,updated_at)
         VALUES(:name,:code,:desc,:office,:loc,:coord,:sup,:slots,:funding,:start,:end,:allow,:status,now(),now()) RETURNING batch_id"
    );
    $s->execute([':name'=>$name,':code'=>$code,':desc'=>gipNullStr($d['description']??''),':office'=>$office,':loc'=>$loc,':coord'=>gipNullStr($d['coordinator']??''),':sup'=>$sup,':slots'=>$slots,':funding'=>$funding,':start'=>$start,':end'=>$end,':allow'=>$allowance,':status'=>$status]);
    $id = (int) $s->fetchColumn();

    // A batch can only ever be created as Planned via the UI, but guard the
    // (API-only) edge case of creating one already Completed.
    gipCascadeBatchStatus($pdo, $id, null, $status);

    gipSyncBatchDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Batch created.', 'data' => gipGetBatchById($id)]);
}

function gipUpdateBatch($id) {
    if (!is_numeric($id)) error('Invalid batch id.', 422);
    $id  = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$name, $code, $office, $loc, $sup, $slots, $start, $end, $funding] = gipValidateBatchInput($d);

    $chk = db()->prepare("SELECT 1 FROM gip_batches WHERE batch_code=:c AND batch_id != :id");
    $chk->execute([':c' => $code, ':id' => $id]);
    if ($chk->fetchColumn()) error('Batch code "' . $code . '" is already in use.', 409);

    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';
    $allowance = is_numeric($d['allowance'] ?? null) ? (float) $d['allowance'] : null;

    $pdo = db();
    $curS = $pdo->prepare("SELECT status FROM gip_batches WHERE batch_id=:id");
    $curS->execute([':id' => $id]);
    $prev = $curS->fetchColumn();
    if ($prev === false) error('Batch not found.', 404);

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE gip_batches SET batch_name=:name,batch_code=:code,description=:desc,assigned_office=:office,deployment_location=:loc,coordinator=:coord,supervisor=:sup,slot_count=:slots,funding_source=:funding,start_date=:start,end_date=:end,monthly_allowance=:allow,status=:status,updated_at=now() WHERE batch_id=:id"
        )->execute([':name'=>$name,':code'=>$code,':desc'=>gipNullStr($d['description']??''),':office'=>$office,':loc'=>$loc,':coord'=>gipNullStr($d['coordinator']??''),':sup'=>$sup,':slots'=>$slots,':funding'=>$funding,':start'=>$start,':end'=>$end,':allow'=>$allowance,':status'=>$status,':id'=>$id]);

        gipCascadeBatchStatus($pdo, $id, $prev, $status);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update batch: ' . $e->getMessage(), 500);
    }

    gipSyncBatchDocuments($pdo, $id, $uid, $d['documents'] ?? []);
    json(['status' => 'ok', 'message' => 'Batch updated.', 'data' => gipGetBatchById($id)]);
}

function gipUpdateBatchStatus($id) {
    if (!is_numeric($id)) error('Invalid batch id.', 422);
    $id = (int) $id;
    $d  = body();
    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);

    $curS = db()->prepare("SELECT status FROM gip_batches WHERE batch_id=:id");
    $curS->execute([':id' => $id]);
    $prev = $curS->fetchColumn();
    if ($prev === false) error('Batch not found.', 404);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE gip_batches SET status=:s, updated_at=now() WHERE batch_id=:id")->execute([':s' => $status, ':id' => $id]);
        gipCascadeBatchStatus($pdo, $id, $prev, $status);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update batch status: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => gipGetBatchById($id)]);
}

function gipDeleteBatch($id) {
    if (!is_numeric($id)) error('Invalid batch id.', 422);
    $id = (int) $id;

    // gip_profiles.batch_id is the only record that an internship happened
    // (no participants junction table like CDSP) — block deletion outright
    // rather than nulling profiles out to allow it.
    $cntS = db()->prepare("SELECT COUNT(*) FROM gip_profiles WHERE batch_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int) $cntS->fetchColumn();
    if ($cnt > 0) {
        error("Cannot delete: {$cnt} applicant" . ($cnt === 1 ? '' : 's') . " linked to this batch (current or past interns).", 409);
    }

    $docs = db()->prepare("SELECT file_path FROM documents WHERE batch_id=:id");
    $docs->execute([':id' => $id]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    // documents.batch_id is ON DELETE CASCADE, so deleting the batch row also
    // removes its document rows once the files above are unlinked from disk.
    db()->prepare("DELETE FROM gip_batches WHERE batch_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Batch deleted.']);
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function gipListProfiles() {
    $s = db()->prepare(
        "SELECT DISTINCT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => gipServiceId()]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $bid) {
        $p = gipBuildProfile((int) $bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function gipGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = gipBuildProfile((int) $id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

function gipBuildProfile($bid) {
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
    $s->execute([':bid' => $bid, ':sid' => gipServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int) $b['beneficiary_service_id'];

    $gpS = db()->prepare("SELECT * FROM gip_profiles WHERE beneficiary_service_id=:id LIMIT 1");
    $gpS->execute([':id' => $bsId]);
    $gp = $gpS->fetch() ?: [];

    $clsS = db()->prepare("SELECT classification FROM beneficiary_classifications WHERE beneficiary_id=:id");
    $clsS->execute([':id' => $bid]);
    $classifications = $clsS->fetchAll(PDO::FETCH_COLUMN);

    // gip_profiles.batch_id is a single direct FK (no assignment-history table),
    // so we can only ever surface a 0-or-1-entry "history" from the live link.
    $assignmentHistory = [];
    $batchId = isset($gp['batch_id']) ? $gp['batch_id'] : null;
    if ($batchId) {
        $batchS = db()->prepare("SELECT batch_id, batch_name, status, completed_at FROM gip_batches WHERE batch_id=:id");
        $batchS->execute([':id' => $batchId]);
        $batch = $batchS->fetch();
        if ($batch) {
            $assignmentHistory[] = [
                'batchId'       => (int) $batch['batch_id'],
                'batchName'     => $batch['batch_name'],
                'assignedDate'  => $gp['batch_assigned_at'] ?? '',
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
        'gipProfileId'            => isset($gp['gip_profile_id']) ? (int) $gp['gip_profile_id'] : null,
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
        'classificationOther'     => '',
        'highestEducation'        => gipReverseMapEducation($b['educational_attainment'] ?? '', !empty($gp['year_graduated'])),
        'schoolName'              => $gp['school_name'] ?? '',
        'course'                  => $gp['course_degree'] ?? '',
        'strand'                  => $gp['strand'] ?? '',
        'yearLevel'               => $gp['year_level'] ?? '',
        'yearGraduated'           => isset($gp['year_graduated']) && $gp['year_graduated'] !== null ? (string) $gp['year_graduated'] : '',
        'assignedBatchId'         => $batchId ? (int) $batchId : null,
        'assignmentHistory'       => $assignmentHistory,
        'attachedDocuments'       => gipFetchSavedDocuments($bid),
        'dateApplicationReceived' => $b['date_applied'] ?? '',
        'receivedBy'              => $b['received_by'] ?? '',
        'status'                  => $gp['status'] ?? 'Inactive',
        'remarks'                 => $gp['remarks'] ?? '',
    ];
}

// Required fields mirror beneficiaries' real NOT NULL columns (sex, birth_date,
// civil_status, barangay_id) so a bad request 422s cleanly instead of failing
// as a raw DB constraint violation.
function gipValidateProfileInput($d) {
    if (gipNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (gipNullStr($d['lastName'] ?? '') === null)  error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male', 'Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = gipDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    // civil_status_enum has no "Annulled" value (the frontend offers it as an
    // option) — anything outside the DB's real enum falls back to null rather
    // than failing the whole save.
    $validCivil = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int) $d['barangayId'] > 0)
             ? (int) $d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    return [$sex, $birth, $civil, $bgyId];
}

function gipCreateProfile() {
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = gipValidateProfileInput($d);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare("INSERT INTO beneficiaries(first_name,middle_name,last_name,sex,birth_date,civil_status,street_address,barangay_id,contact_no,email,status,educational_attainment) VALUES(:fn,:mn,:ln,:sex,:bdate,:civil,:street,:bgy,:contact,:email,'Active',:educ) RETURNING beneficiary_id");
        $s->execute([':fn'=>trim($d['firstName']),':mn'=>gipNullStr($d['middleName']??''),':ln'=>trim($d['lastName']),':sex'=>$sex,':bdate'=>$birth,':civil'=>$civil,':street'=>gipNullStr($d['streetPurok']??''),':bgy'=>$bgyId,':contact'=>gipNullStr($d['contactNumber']??''),':email'=>gipNullStr($d['email']??''),':educ'=>gipMapEducation($d['highestEducation']??'')]);
        $bid = (int) $s->fetchColumn();

        $s2 = $pdo->prepare("INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by) VALUES(:bid,:sid,'Active',:date,:rby) RETURNING beneficiary_service_id");
        $s2->execute([':bid'=>$bid,':sid'=>gipServiceId(),':date'=>gipDate($d['dateApplicationReceived']??'')??date('Y-m-d'),':rby'=>gipNullStr($d['receivedBy']??'')]);
        $bsId = (int) $s2->fetchColumn();

        $validCls = gipValidClassifications();
        $rawCls   = is_array($d['classification'] ?? null) ? $d['classification'] : [];
        $ins = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification) VALUES(:bid,:cls) ON CONFLICT DO NOTHING");
        foreach ($rawCls as $c) {
            $norm = gipNormalizeClassification($c);
            if (in_array($norm, $validCls, true)) $ins->execute([':bid'=>$bid,':cls'=>$norm]);
        }

        $pdo->prepare("INSERT INTO gip_profiles(beneficiary_service_id,school_name,course_degree,strand,year_level,year_graduated,remarks,status) VALUES(:bsid,:school,:course,:strand,:ylvl,:yr,:rmk,'Inactive')")
            ->execute([':bsid'=>$bsId,':school'=>gipNullStr($d['schoolName']??''),':course'=>gipNullStr($d['course']??''),':strand'=>gipNullStr($d['strand']??''),':ylvl'=>gipNullStr($d['yearLevel']??''),':yr'=>gipYearOrNull($d['yearGraduated']??''),':rmk'=>gipNullStr($d['remarks']??'')]);

        gipSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => gipBuildProfile($bid)]);
}

function gipUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId] = gipValidateProfileInput($d);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => gipServiceId()]);
    $bsId = $chk->fetchColumn();
    if (!$bsId) error('GIP profile not found.', 404);
    $bsId = (int) $bsId;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,sex=:sex,birth_date=:bdate,civil_status=:civil,street_address=:street,barangay_id=:bgy,contact_no=:contact,email=:email,educational_attainment=:educ,updated_at=now() WHERE beneficiary_id=:bid")
            ->execute([':fn'=>trim($d['firstName']),':mn'=>gipNullStr($d['middleName']??''),':ln'=>trim($d['lastName']),':sex'=>$sex,':bdate'=>$birth,':civil'=>$civil,':street'=>gipNullStr($d['streetPurok']??''),':bgy'=>$bgyId,':contact'=>gipNullStr($d['contactNumber']??''),':email'=>gipNullStr($d['email']??''),':educ'=>gipMapEducation($d['highestEducation']??''),':bid'=>$bid]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby WHERE beneficiary_service_id=:bsid")
            ->execute([':rby'=>gipNullStr($d['receivedBy']??''),':bsid'=>$bsId]);

        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id=:bid")->execute([':bid'=>$bid]);
        $validCls = gipValidClassifications();
        $rawCls   = is_array($d['classification'] ?? null) ? $d['classification'] : [];
        $ins = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification) VALUES(:bid,:cls)");
        foreach ($rawCls as $c) {
            $norm = gipNormalizeClassification($c);
            if (in_array($norm, $validCls, true)) $ins->execute([':bid'=>$bid,':cls'=>$norm]);
        }

        $ex = db()->prepare("SELECT 1 FROM gip_profiles WHERE beneficiary_service_id=:id");
        $ex->execute([':id' => $bsId]);
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE gip_profiles SET school_name=:school,course_degree=:course,strand=:strand,year_level=:ylvl,year_graduated=:yr,remarks=:rmk,updated_at=now() WHERE beneficiary_service_id=:bsid")
                ->execute([':school'=>gipNullStr($d['schoolName']??''),':course'=>gipNullStr($d['course']??''),':strand'=>gipNullStr($d['strand']??''),':ylvl'=>gipNullStr($d['yearLevel']??''),':yr'=>gipYearOrNull($d['yearGraduated']??''),':rmk'=>gipNullStr($d['remarks']??''),':bsid'=>$bsId]);
        } else {
            $pdo->prepare("INSERT INTO gip_profiles(beneficiary_service_id,school_name,course_degree,strand,year_level,year_graduated,remarks,status) VALUES(:bsid,:school,:course,:strand,:ylvl,:yr,:rmk,'Inactive')")
                ->execute([':bsid'=>$bsId,':school'=>gipNullStr($d['schoolName']??''),':course'=>gipNullStr($d['course']??''),':strand'=>gipNullStr($d['strand']??''),':ylvl'=>gipNullStr($d['yearLevel']??''),':yr'=>gipYearOrNull($d['yearGraduated']??''),':rmk'=>gipNullStr($d['remarks']??'')]);
        }

        gipSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => gipBuildProfile($bid)]);
}

function gipDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int) $id;
    $uid = requireLogin();

    // Cannot delete an applicant currently interning in an active batch —
    // same lock spirit as EF's referral/placement guard. Unassign first.
    $chk = db()->prepare(
        "SELECT gp.batch_id, gp.status
         FROM gip_profiles gp
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = gp.beneficiary_service_id
         WHERE bs.beneficiary_id = :bid AND bs.service_id = :sid"
    );
    $chk->execute([':bid' => $bid, ':sid' => gipServiceId()]);
    $row = $chk->fetch();
    if ($row && $row['batch_id'] && $row['status'] === 'Active') {
        error('This applicant cannot be deleted because they are currently assigned to a batch. Unassign them first.', 409);
    }

    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Applicant moved to recycle bin.']);
}

// ─── Assign / Unassign batch ──────────────────────────────────────────────────

function gipAssignBatch() {
    requireLogin();
    $d = body();
    $bid     = gipIntOrNull($d['applicantId'] ?? '');
    $batchId = gipIntOrNull($d['batchId'] ?? '');
    if (!$bid || !$batchId) error('applicantId and batchId are required.', 422);

    $bsS = db()->prepare(
        "SELECT bs.beneficiary_service_id FROM beneficiary_services bs
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $bsS->execute([':bid' => $bid, ':sid' => gipServiceId()]);
    $bsId = $bsS->fetchColumn();
    if (!$bsId) error('GIP profile not found.', 404);

    $gpS = db()->prepare("SELECT gip_profile_id FROM gip_profiles WHERE beneficiary_service_id=:id");
    $gpS->execute([':id' => (int) $bsId]);
    $gpId = $gpS->fetchColumn();
    if (!$gpId) error('GIP profile not found.', 404);
    $gpId = (int) $gpId;

    $batchS = db()->prepare("SELECT status, slot_count FROM gip_batches WHERE batch_id=:id");
    $batchS->execute([':id' => $batchId]);
    $batch = $batchS->fetch();
    if (!$batch) error('Batch not found.', 404);
    if ($batch['status'] !== 'Planned') error('Only Planned batches can be assigned.', 409);

    $cntS = db()->prepare("SELECT COUNT(*) FROM gip_profiles WHERE batch_id=:id AND gip_profile_id != :gid");
    $cntS->execute([':id' => $batchId, ':gid' => $gpId]);
    $current = (int) $cntS->fetchColumn();
    if ($current >= (int) $batch['slot_count']) {
        error("This batch is already at full capacity ({$current}/{$batch['slot_count']}).", 409);
    }

    db()->prepare("UPDATE gip_profiles SET batch_id=:b, status='Active', batch_assigned_at=now(), updated_at=now() WHERE gip_profile_id=:gid")
        ->execute([':b' => $batchId, ':gid' => $gpId]);
    json(['status' => 'ok', 'message' => 'Applicant assigned to batch.']);
}

function gipUnassignBatch() {
    requireLogin();
    $d = body();
    $bid = gipIntOrNull($d['applicantId'] ?? '');
    if (!$bid) error('applicantId is required.', 422);

    $row = db()->prepare(
        "SELECT gp.gip_profile_id, gb.status AS batch_status
         FROM gip_profiles gp
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = gp.beneficiary_service_id
         LEFT JOIN gip_batches gb ON gb.batch_id = gp.batch_id
         WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid
         ORDER BY gp.gip_profile_id DESC LIMIT 1"
    );
    $row->execute([':bid' => $bid, ':sid' => gipServiceId()]);
    $r = $row->fetch();
    if (!$r || !$r['gip_profile_id']) error('GIP profile not found.', 404);

    // Preserve the completion record: once the batch is Completed, unassigning
    // would erase the only place this internship's history lives.
    if ($r['batch_status'] === 'Completed') {
        error('This batch is already completed — unassigning would erase its completion record.', 409);
    }

    db()->prepare("UPDATE gip_profiles SET batch_id=NULL, status='Inactive', batch_assigned_at=NULL, updated_at=now() WHERE gip_profile_id=:gid")
        ->execute([':gid' => (int) $r['gip_profile_id']]);
    json(['status' => 'ok', 'message' => 'Applicant unassigned from batch.']);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────
//   - savedDocuments entries WITH a base64 dataUrl  → new uploads (saved to disk + inserted)
//   - entries WITHOUT a dataUrl (existing files)     → kept by their document_id
//   - existing docs no longer in the list             → deleted (file unlinked)

function gipSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    $docs = is_array($d['savedDocuments'] ?? null) ? $d['savedDocuments'] : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='GIP'");
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
         VALUES(:bid,:bsid,'GIP',:dtype,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'gip_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $dtype  = gipNullStr($doc['documentType'] ?? '');
        $custom = gipNullStr($doc['customName'] ?? '');
        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':dtype' => $dtype,
            ':title' => $custom ?? ($dtype ?? $origName),
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function gipFetchSavedDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='GIP' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    return array_map(function ($r) {
        return [
            'id'           => (string) $r['document_id'],
            'documentType' => $r['document_type'] ?? '',
            'customName'   => $r['title'] ?? '',
            'fileName'     => $r['file_name'],
            'fileSize'     => gipFormatBytes($r['file_size'] ?? 0),
            'url'          => gipUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ─── Documents (batch) ────────────────────────────────────────────────────────
// Batch files aren't tied to a beneficiary, so they key off documents.batch_id
// instead (nullable FK, ON DELETE CASCADE) — same shared table, same pattern.

function gipSyncBatchDocuments($pdo, $batchId, $uid, $docsPayload) {
    $docs = is_array($docsPayload) ? $docsPayload : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE batch_id=:bid");
    $sel->execute([':bid' => $batchId]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(batch_id,document_source,document_type,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:bid,'GIP Batch',NULL,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'gip_batch_' . $batchId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $ins->execute([
            ':bid' => $batchId, ':title' => $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function gipFetchBatchDocuments($batchId) {
    $s = db()->prepare(
        "SELECT document_id, title, file_name, file_path, file_size
         FROM documents WHERE batch_id=:bid ORDER BY document_id"
    );
    $s->execute([':bid' => $batchId]);
    return array_map(function ($r) {
        return [
            'id'       => (string) $r['document_id'],
            'fileName' => $r['file_name'],
            'fileSize' => gipFormatBytes($r['file_size'] ?? 0),
            'url'      => gipUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN  (soft-deleted GIP applicants) — mirrors employment.php's pattern
// so these records surface in the same Security > Activity Logs > Recycle Bin
// screen alongside EF's applicants/employers/referrals.
// ═══════════════════════════════════════════════════════════════════════════════

// recordType -> [table, primary-key column]. Only one type for GIP today.
function gipRecycleMap() {
    return [
        'gipApplicant' => ['beneficiaries', 'beneficiary_id'],
    ];
}

// Read + validate { recordType, id } from the request body. Returns [type, id].
function gipRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(gipRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

// GET /api/gip/listDeleted
function gipListDeleted() {
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
    $s->execute([':sid' => gipServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType'  => 'gipApplicant',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'GIP Applicants',
            'description' => 'Government Internship Program applicant record',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

// POST /api/gip/restoreRecord  { recordType, id }  — undo a soft delete.
function gipRestoreRecord() {
    [$type, $id] = gipRecycleTarget();
    [$table, $pk] = gipRecycleMap()[$type];

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    json(['status' => 'ok', 'message' => 'Record restored.']);
}

// POST /api/gip/purgeRecord  { recordType, id }  — permanent delete.
// Only acts on records already in the recycle bin (deleted_at IS NOT NULL).
function gipPurgeRecord() {
    [$type, $id] = gipRecycleTarget();
    [$table, $pk] = gipRecycleMap()[$type];

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    gipHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

// Permanently remove a GIP applicant and its GIP-specific data (uploaded
// files, gip_profiles row, classifications, service enrollment). Used by the
// recycle bin's permanent-delete action; not reachable except for an
// already soft-deleted row. Mirrors employmentHardDeleteApplicant.
function gipHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
        $bsStmt->execute([':id' => $bid, ':sid' => gipServiceId()]);
        $bsId = $bsStmt->fetchColumn();
        if ($bsId !== false) {
            $pdo->prepare("DELETE FROM gip_profiles WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'GIP'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'GIP'")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
