<?php
// Skills Training: batches (skills_training_batches) -> trainings/activities
// (skills_training_activities) -> participants (skills_training_activity_participants),
// plus profiles (beneficiary spine + skills_training_profiles + classification/
// qualification/purpose junctions). Mirrors cdsp.php's batch->activity->participant
// shape (the closest existing precedent) rather than GIP/SPES's flat single-batch model.
//
// Design notes (confirmed against the live schema before writing this):
//  - skills_training_profiles has no derived "status" column for batch/activity
//    assignment (unlike CDSP/GIP/SPES) -- assignment state is read live from
//    skills_training_activity_participants each time, same spirit as DILP/TUPAD's
//    fully-computed model.
//  - skills_training_profiles.application_status (Accepted/Waitlisted) is a
//    real, manually-set stored column (migration 038), reusing the shared
//    application_status_enum already used by spes_profiles -- not derived.
//  - Attendance (skills_training_activity_participants.attended) is intentionally
//    NOT exposed here yet (deferred per product decision) -- rows are inserted
//    with attended=NULL and never toggled by any endpoint below.
//  - skills_training_profiles.batch_id is kept in sync with whichever activity's
//    batch the applicant is currently assigned to (denormalized convenience column
//    already on the table), cleared back to NULL on unassign.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listBatches':              requirePermission('skills','Viewer'); return stListBatches();
        case 'createBatch':              requirePermission('skills','Editor'); return stCreateBatch();
        case 'deleteBatch':              requirePermission('skills','Editor'); return stDeleteBatch($id);

        case 'listActivities':           requirePermission('skills','Viewer'); return stListActivities();
        case 'createActivity':           requirePermission('skills','Editor'); return stCreateActivity();
        case 'updateActivity':           requirePermission('skills','Editor'); return stUpdateActivity($id);
        case 'updateActivityStatus':     requirePermission('skills','Editor'); return stUpdateActivityStatus($id);
        case 'deleteActivity':           requirePermission('skills','Editor'); return stDeleteActivity($id);

        case 'listActivityParticipants': requirePermission('skills','Viewer'); return stListActivityParticipants($id);
        case 'addParticipant':           requirePermission('skills','Editor'); return stAddParticipant();
        case 'removeParticipant':        requirePermission('skills','Editor'); return stRemoveParticipant();
        case 'updateAttendance':         requirePermission('skills','Editor'); return stUpdateAttendance();

        case 'listQualifications':       requirePermission('skills','Viewer'); return stListQualifications();
        case 'listPurposes':             requirePermission('skills','Viewer'); return stListPurposes();

        case 'listProfiles':             requirePermission('skills','Viewer'); return stListProfiles();
        case 'getProfile':               requirePermission('skills','Viewer'); return stGetProfile($id);
        case 'createProfile':            requirePermission('skills','Editor'); return stCreateProfile();
        case 'updateProfile':            requirePermission('skills','Editor'); return stUpdateProfile($id);
        case 'deleteProfile':            requirePermission('skills','Editor'); return stDeleteProfile($id);

        case 'listDeleted':              requirePermission('skills','Viewer'); return stListDeleted();
        case 'restoreRecord':            requirePermission('skills','Editor'); return stRestoreRecord();
        case 'purgeRecord':              requirePermission('skills','Editor'); return stPurgeRecord();

        default: error("Unknown Skills Training action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function stNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function stDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function stIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }

function stServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='SKILLS' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('Skills Training service not found.', 500);
    return (int)$sid;
}

function stUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function stFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

function stValidClassifications() {
    return ['Student','Out of School Youth','Person with Disability','Employed','Unemployed','Women'];
}

// Frontend shows "PWD" as the checkbox label; the shared enum's real value is
// "Person with Disability" (same mapping DILP/CDSP already use).
function stNormalizeClassification($c) {
    return $c === 'PWD' ? 'Person with Disability' : $c;
}
function stDenormalizeClassification($c) {
    return $c === 'Person with Disability' ? 'PWD' : $c;
}

// ─── Batches ──────────────────────────────────────────────────────────────────

function stListBatches() {
    $s = db()->query(
        "SELECT b.batch_id, b.batch_name, b.description, b.is_active,
                (SELECT COUNT(*) FROM skills_training_activities a WHERE a.batch_id = b.batch_id) AS training_count
         FROM skills_training_batches b
         ORDER BY b.batch_name"
    );
    $out = array_map(function($r) {
        return [
            'id'            => (int)$r['batch_id'],
            'batchName'     => $r['batch_name'],
            'description'   => $r['description'] ?? '',
            'isActive'      => (bool)$r['is_active'],
            'trainingCount' => (int)$r['training_count'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $out]);
}

function stCreateBatch() {
    $d = body();
    $name = trim($d['batchName'] ?? '');
    if ($name === '') error('Batch name is required.', 422);
    $chk = db()->prepare("SELECT 1 FROM skills_training_batches WHERE batch_name=:n");
    $chk->execute([':n' => $name]);
    if ($chk->fetchColumn()) error('This batch already exists.', 409);
    $s = db()->prepare("INSERT INTO skills_training_batches(batch_name,description,is_active,created_at,updated_at) VALUES(:n,:d,true,now(),now()) RETURNING batch_id");
    $s->execute([':n' => $name, ':d' => stNullStr($d['description'] ?? '')]);
    json(['status' => 'ok', 'message' => 'Batch added.', 'data' => ['id' => (int)$s->fetchColumn(), 'batchName' => $name]]);
}

function stDeleteBatch($id) {
    if (!is_numeric($id)) error('Invalid batch id.', 422);
    $id = (int)$id;
    $cntS = db()->prepare("SELECT COUNT(*) FROM skills_training_activities WHERE batch_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int)$cntS->fetchColumn();
    if ($cnt > 0) error("Cannot delete: $cnt training" . ($cnt === 1 ? '' : 's') . ' still under this batch.', 409);
    db()->prepare("DELETE FROM skills_training_batches WHERE batch_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Batch deleted.']);
}

// ─── Trainings / Activities ───────────────────────────────────────────────────

function stFormatActivity($r) {
    return [
        'id'            => (int)$r['activity_id'],
        'batchId'       => (int)$r['batch_id'],
        'service'       => $r['batch_name'],
        'program'       => 'Skills Training',
        'title'         => $r['activity_title'],
        'description'   => $r['description'] ?? '',
        'date'          => $r['activity_date'] ?? '',
        'location'      => $r['venue'] ?? '',
        'facilitator'   => $r['facilitator'] ?? '',
        'participants'  => isset($r['participant_count']) ? (int)$r['participant_count'] : null,
        'assignedCount' => isset($r['assigned_count']) ? (int)$r['assigned_count'] : 0,
        'status'        => $r['status'],
    ];
}

function stListActivities() {
    $s = db()->query(
        "SELECT a.activity_id, a.batch_id, b.batch_name, a.activity_title, a.description,
                a.activity_date, a.venue, a.facilitator, a.participant_count, a.status,
                (SELECT COUNT(*) FROM skills_training_activity_participants p WHERE p.activity_id = a.activity_id) AS assigned_count
         FROM skills_training_activities a
         JOIN skills_training_batches b ON b.batch_id = a.batch_id
         ORDER BY a.activity_date DESC, a.activity_id DESC"
    );
    json(['status' => 'ok', 'data' => array_map('stFormatActivity', $s->fetchAll())]);
}

function stGetActivityById($id) {
    $s = db()->prepare(
        "SELECT a.activity_id, a.batch_id, b.batch_name, a.activity_title, a.description,
                a.activity_date, a.venue, a.facilitator, a.participant_count, a.status,
                (SELECT COUNT(*) FROM skills_training_activity_participants p WHERE p.activity_id = a.activity_id) AS assigned_count
         FROM skills_training_activities a
         JOIN skills_training_batches b ON b.batch_id = a.batch_id
         WHERE a.activity_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? stFormatActivity($r) : null;
}

function stCreateActivity() {
    $d = body();
    if (stNullStr($d['title'] ?? '') === null) error('Training title is required.', 422);
    $batchId = stIntOrNull($d['batchId'] ?? '');
    if (!$batchId) error('Training batch is required.', 422);
    $chk = db()->prepare("SELECT 1 FROM skills_training_batches WHERE batch_id=:id");
    $chk->execute([':id' => $batchId]);
    if (!$chk->fetchColumn()) error('Selected batch not found.', 422);

    // New trainings always start Planned -- matches the maintenance form's Add
    // mode, which disables Ongoing/Completed until the record actually exists.
    $s = db()->prepare(
        "INSERT INTO skills_training_activities(batch_id,activity_title,description,activity_date,venue,facilitator,participant_count,status,created_at,updated_at)
         VALUES(:bid,:t,:desc,:date,:venue,:fac,:pc,'Planned',now(),now()) RETURNING activity_id"
    );
    $s->execute([
        ':bid' => $batchId, ':t' => trim($d['title']), ':desc' => stNullStr($d['description'] ?? ''),
        ':date' => stDate($d['date'] ?? '') ?? date('Y-m-d'), ':venue' => stNullStr($d['location'] ?? ''),
        ':fac' => stNullStr($d['facilitator'] ?? ''), ':pc' => stIntOrNull($d['participants'] ?? '') ?? 0,
    ]);
    json(['status' => 'ok', 'message' => 'Training created.', 'data' => stGetActivityById((int)$s->fetchColumn())]);
}

function stUpdateActivity($id) {
    if (!is_numeric($id)) error('Invalid training id.', 422);
    $id = (int)$id;
    $d = body();
    if (stNullStr($d['title'] ?? '') === null) error('Training title is required.', 422);
    $batchId = stIntOrNull($d['batchId'] ?? '');
    if (!$batchId) error('Training batch is required.', 422);
    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    db()->prepare(
        "UPDATE skills_training_activities
         SET batch_id=:bid, activity_title=:t, description=:desc, activity_date=:date,
             venue=:venue, facilitator=:fac, participant_count=:pc, status=:st, updated_at=now()
         WHERE activity_id=:id"
    )->execute([
        ':bid' => $batchId, ':t' => trim($d['title']), ':desc' => stNullStr($d['description'] ?? ''),
        ':date' => stDate($d['date'] ?? '') ?? date('Y-m-d'), ':venue' => stNullStr($d['location'] ?? ''),
        ':fac' => stNullStr($d['facilitator'] ?? ''), ':pc' => stIntOrNull($d['participants'] ?? '') ?? 0,
        ':st' => $status, ':id' => $id,
    ]);
    json(['status' => 'ok', 'message' => 'Training updated.', 'data' => stGetActivityById($id)]);
}

function stUpdateActivityStatus($id) {
    if (!is_numeric($id)) error('Invalid training id.', 422);
    $d = body();
    $valid  = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);
    db()->prepare("UPDATE skills_training_activities SET status=:s,updated_at=now() WHERE activity_id=:id")
        ->execute([':s' => $status, ':id' => (int)$id]);
    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => stGetActivityById((int)$id)]);
}

function stDeleteActivity($id) {
    if (!is_numeric($id)) error('Invalid training id.', 422);
    $id = (int)$id;
    $cntS = db()->prepare("SELECT COUNT(*) FROM skills_training_activity_participants WHERE activity_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int)$cntS->fetchColumn();
    if ($cnt > 0) error("Cannot delete: $cnt applicant" . ($cnt === 1 ? ' is' : 's are') . ' still assigned to this training. Unassign them first.', 409);
    db()->prepare("DELETE FROM skills_training_activities WHERE activity_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Training deleted.']);
}

// ─── Participants (assign / unassign) ────────────────────────────────────────

function stListActivityParticipants($activityId) {
    if (!is_numeric($activityId)) error('Invalid training id.', 422);
    $s = db()->prepare(
        "SELECT b.beneficiary_id, b.first_name, b.last_name, b.middle_name, b.contact_no, b.sex,
                bs.beneficiary_service_id, sp.application_status, p.attended
         FROM skills_training_activity_participants p
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         JOIN skills_training_profiles sp ON sp.beneficiary_service_id = bs.beneficiary_service_id
         WHERE p.activity_id = :aid
         ORDER BY b.last_name, b.first_name"
    );
    $s->execute([':aid' => (int)$activityId]);
    $out = array_map(function($r) {
        return [
            'id' => (int)$r['beneficiary_id'], 'beneficiaryServiceId' => (int)$r['beneficiary_service_id'],
            'lastName' => $r['last_name'], 'firstName' => $r['first_name'], 'middleName' => $r['middle_name'] ?? '',
            'sex' => $r['sex'] ?? '', 'contactNumber' => $r['contact_no'] ?? '', 'status' => $r['application_status'],
            'attended' => $r['attended'] === null ? null : (bool)$r['attended'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $out]);
}

function stUpdateAttendance() {
    $d      = body();
    $actId  = stIntOrNull($d['activityId'] ?? '');
    $bsId   = stIntOrNull($d['beneficiaryServiceId'] ?? '');
    if (!$actId || !$bsId) error('activityId and beneficiaryServiceId required.', 422);
    if (!isset($d['attended']) || !is_bool($d['attended'])) error('attended must be true or false.', 422);
    db()->prepare("UPDATE skills_training_activity_participants SET attended=:att WHERE activity_id=:a AND beneficiary_service_id=:b")
        ->execute([':att' => $d['attended'] ? 'true' : 'false', ':a' => $actId, ':b' => $bsId]);
    json(['status' => 'ok', 'message' => 'Attendance updated.']);
}

function stAddParticipant() {
    $d = body();
    $actId = stIntOrNull($d['activityId'] ?? '');
    $bsId  = stIntOrNull($d['beneficiaryServiceId'] ?? '');
    if (!$actId || !$bsId) error('activityId and beneficiaryServiceId are required.', 422);

    $actS = db()->prepare("SELECT status, batch_id, participant_count FROM skills_training_activities WHERE activity_id=:a");
    $actS->execute([':a' => $actId]);
    $act = $actS->fetch();
    if (!$act) error('Training not found.', 404);
    if ($act['status'] !== 'Planned') {
        error('Only trainings that are still Planned can be assigned.', 409);
    }
    if ($act['participant_count'] !== null && (int)$act['participant_count'] > 0) {
        $cntS = db()->prepare("SELECT COUNT(*) FROM skills_training_activity_participants WHERE activity_id=:a AND beneficiary_service_id != :b");
        $cntS->execute([':a' => $actId, ':b' => $bsId]);
        $current = (int)$cntS->fetchColumn();
        if ($current >= (int)$act['participant_count']) {
            error("This training is already at full capacity ({$current}/{$act['participant_count']}).", 409);
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // A profile can only be actively assigned to one training at a time --
        // clear any prior assignment first (re-assign replaces, doesn't stack).
        $pdo->prepare("DELETE FROM skills_training_activity_participants WHERE beneficiary_service_id=:b")->execute([':b' => $bsId]);
        $pdo->prepare("INSERT INTO skills_training_activity_participants(activity_id,beneficiary_service_id,attended) VALUES(:a,:b,NULL)")->execute([':a' => $actId, ':b' => $bsId]);
        $pdo->prepare("UPDATE skills_training_profiles SET batch_id=:bid, updated_at=now() WHERE beneficiary_service_id=:b")->execute([':bid' => $act['batch_id'], ':b' => $bsId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error('Failed to assign training: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Training assigned.']);
}

function stRemoveParticipant() {
    $d = body();
    $actId = stIntOrNull($d['activityId'] ?? '');
    $bsId  = stIntOrNull($d['beneficiaryServiceId'] ?? '');
    if (!$actId || !$bsId) error('activityId and beneficiaryServiceId are required.', 422);

    $statS = db()->prepare("SELECT status FROM skills_training_activities WHERE activity_id=:a");
    $statS->execute([':a' => $actId]);
    $status = $statS->fetchColumn();
    if ($status !== 'Planned') {
        error('This training is no longer Planned -- unassigning would erase the only record of this assignment. Wait until it is marked Completed, or reopen it first.', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM skills_training_activity_participants WHERE activity_id=:a AND beneficiary_service_id=:b")->execute([':a' => $actId, ':b' => $bsId]);
        $pdo->prepare("UPDATE skills_training_profiles SET batch_id=NULL, updated_at=now() WHERE beneficiary_service_id=:b")->execute([':b' => $bsId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error('Failed to unassign training: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Training unassigned.']);
}

// ─── Lookup lists (Qualifications / Purposes) ────────────────────────────────

function stListQualifications() {
    $s = db()->query("SELECT qualification_id, qualification_name FROM skills_training_qualifications WHERE is_active=true ORDER BY qualification_id");
    json(['status' => 'ok', 'data' => array_map(function($r) {
        return ['id' => (int)$r['qualification_id'], 'name' => $r['qualification_name']];
    }, $s->fetchAll())]);
}

function stListPurposes() {
    $s = db()->query("SELECT purpose_id, purpose_name FROM skills_training_purposes WHERE is_active=true ORDER BY purpose_id");
    json(['status' => 'ok', 'data' => array_map(function($r) {
        return ['id' => (int)$r['purpose_id'], 'name' => $r['purpose_name']];
    }, $s->fetchAll())]);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────

function stSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    $docs = is_array($d['attachedDocuments'] ?? null) ? $d['attachedDocuments'] : [];

    $keep = [];
    foreach ($docs as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='Skills Training'");
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
         VALUES(:bid,:bsid,'Skills Training',:dtype,:title,:fname,:fpath,:size,:mime,:uid)"
    );
    foreach ($docs as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'skills_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $dtype  = stNullStr($doc['documentType'] ?? '');
        $custom = stNullStr($doc['customName'] ?? $doc['name'] ?? '');
        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':dtype' => $dtype,
            ':title' => $custom ?? ($dtype ?? $origName),
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function stFetchSavedDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='Skills Training' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    return array_map(function ($r) {
        return [
            'id' => (string) $r['document_id'], 'documentType' => $r['document_type'] ?? '',
            'customName' => $r['title'] ?? '', 'fileName' => $r['file_name'],
            'fileSize' => stFormatBytes($r['file_size'] ?? 0), 'url' => stUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function stBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.date_applied, bs.received_by,
                sp.skills_training_profile_id, sp.application_status, sp.batch_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         JOIN skills_training_profiles sp ON sp.beneficiary_service_id = bs.beneficiary_service_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND bs.service_id = :sid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':sid' => stServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int)$b['beneficiary_service_id'];
    $profileId = (int)$b['skills_training_profile_id'];

    $clsS = db()->prepare("SELECT classification, classification_other FROM beneficiary_classifications WHERE beneficiary_id=:id");
    $clsS->execute([':id' => $bid]);
    $clsRows = $clsS->fetchAll();
    $classification = [];
    $classificationOther = [];
    foreach ($clsRows as $row) {
        if ($row['classification'] === 'Other') { if (!empty($row['classification_other'])) $classificationOther[] = $row['classification_other']; }
        else { $classification[] = stDenormalizeClassification($row['classification']); }
    }

    $qS = db()->prepare(
        "SELECT q.qualification_name, pq.other_qualification
         FROM skills_training_profile_qualifications pq
         JOIN skills_training_qualifications q ON q.qualification_id = pq.qualification_id
         WHERE pq.skills_training_profile_id = :pid"
    );
    $qS->execute([':pid' => $profileId]);
    $desiredQualification = [];
    $qualificationOther = [];
    foreach ($qS->fetchAll() as $row) {
        if ($row['qualification_name'] === 'Other') { if (!empty($row['other_qualification'])) $qualificationOther[] = $row['other_qualification']; }
        else { $desiredQualification[] = $row['qualification_name']; }
    }

    $puS = db()->prepare(
        "SELECT pu.purpose_name, pp.other_purpose
         FROM skills_training_profile_purposes pp
         JOIN skills_training_purposes pu ON pu.purpose_id = pp.purpose_id
         WHERE pp.skills_training_profile_id = :pid"
    );
    $puS->execute([':pid' => $profileId]);
    $purposeOfTraining = [];
    $purposeOther = [];
    foreach ($puS->fetchAll() as $row) {
        if ($row['purpose_name'] === 'Other') { if (!empty($row['other_purpose'])) $purposeOther[] = $row['other_purpose']; }
        else { $purposeOfTraining[] = $row['purpose_name']; }
    }

    $assignS = db()->prepare(
        "SELECT a.activity_id, a.activity_title, a.status
         FROM skills_training_activity_participants p
         JOIN skills_training_activities a ON a.activity_id = p.activity_id
         WHERE p.beneficiary_service_id = :bsid
         ORDER BY p.activity_id DESC LIMIT 1"
    );
    $assignS->execute([':bsid' => $bsId]);
    $assigned = $assignS->fetch();

    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (new DateTime($b['birth_date']))->diff(new DateTime('today'))->y;
    }

    return [
        'id'                      => $bid,
        'beneficiaryServiceId'    => $bsId,
        'lastName'                => $b['last_name'],
        'firstName'               => $b['first_name'],
        'middleName'              => $b['middle_name'] ?? '',
        'sex'                     => $b['sex'] ?? '',
        'birthdate'               => $b['birth_date'] ?? '',
        'age'                     => $age,
        'civilStatus'             => $b['civil_status'] ?? '',
        'contactNumber'           => $b['contact_no'] ?? '',
        'streetPurok'             => $b['street_address'] ?? '',
        'barangay'                => $b['barangay_name'] ?? '',
        'barangayId'              => (int)($b['barangay_id'] ?? 0),
        'cityMunicipality'        => $b['city_name'] ?? '',
        'province'                => $b['province_name'] ?? '',
        'region'                  => $b['region_name'] ?? '',
        'classification'          => $classification,
        'classificationOther'     => $classificationOther,
        'desiredQualification'    => $desiredQualification,
        'qualificationOther'      => $qualificationOther,
        'purposeOfTraining'       => $purposeOfTraining,
        'purposeOther'            => $purposeOther,
        'assignedTrainingId'      => $assigned ? (int)$assigned['activity_id'] : null,
        'assignedTrainingTitle'   => $assigned ? $assigned['activity_title'] : '',
        'assignedTrainingStatus'  => $assigned ? $assigned['status'] : null,
        'dateApplicationReceived' => $b['date_applied'] ?? '',
        'receivedBy'              => $b['received_by'] ?? '',
        'status'                  => $b['application_status'] ?? 'Waitlisted',
        'attachedDocuments'       => stFetchSavedDocuments($bid),
    ];
}

function stListProfiles() {
    $s = db()->prepare(
        "SELECT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => stServiceId()]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $p = stBuildProfile((int)$bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function stGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = stBuildProfile((int)$id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

// Insert classification/qualification/purpose junction rows for a profile.
// Shared by create + update (update calls this after clearing old rows).
function stSyncSelections($pdo, $bid, $profileId, $d) {
    $validCls = stValidClassifications();
    $rawCls   = is_array($d['classification'] ?? null) ? $d['classification'] : [];
    $clsOtherList = is_array($d['classificationOther'] ?? null) ? array_filter($d['classificationOther']) : [];
    $insCls = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification,classification_other) VALUES(:bid,:cls,:other)");
    foreach ($rawCls as $c) {
        $norm = stNormalizeClassification($c);
        if (in_array($norm, $validCls, true)) {
            $insCls->execute([':bid' => $bid, ':cls' => $norm, ':other' => null]);
        }
    }
    foreach ($clsOtherList as $other) {
        $insCls->execute([':bid' => $bid, ':cls' => 'Other', ':other' => $other]);
    }

    $qualMap = [];
    foreach (db()->query("SELECT qualification_id, qualification_name FROM skills_training_qualifications")->fetchAll() as $r) {
        $qualMap[$r['qualification_name']] = (int)$r['qualification_id'];
    }
    $rawQual = is_array($d['desiredQualification'] ?? null) ? $d['desiredQualification'] : [];
    $qualOtherList = is_array($d['qualificationOther'] ?? null) ? array_filter($d['qualificationOther']) : [];
    $insQual = $pdo->prepare("INSERT INTO skills_training_profile_qualifications(skills_training_profile_id,qualification_id,other_qualification) VALUES(:pid,:qid,:other)");
    foreach ($rawQual as $q) {
        if (isset($qualMap[$q])) $insQual->execute([':pid' => $profileId, ':qid' => $qualMap[$q], ':other' => null]);
    }
    if (!empty($qualOtherList) && isset($qualMap['Other'])) {
        foreach ($qualOtherList as $other) {
            $insQual->execute([':pid' => $profileId, ':qid' => $qualMap['Other'], ':other' => $other]);
        }
    }

    $purMap = [];
    foreach (db()->query("SELECT purpose_id, purpose_name FROM skills_training_purposes")->fetchAll() as $r) {
        $purMap[$r['purpose_name']] = (int)$r['purpose_id'];
    }
    $rawPur = is_array($d['purposeOfTraining'] ?? null) ? $d['purposeOfTraining'] : [];
    $purOtherList = is_array($d['purposeOther'] ?? null) ? array_filter($d['purposeOther']) : [];
    $insPur = $pdo->prepare("INSERT INTO skills_training_profile_purposes(skills_training_profile_id,purpose_id,other_purpose) VALUES(:pid,:puid,:other)");
    foreach ($rawPur as $p) {
        if (isset($purMap[$p])) $insPur->execute([':pid' => $profileId, ':puid' => $purMap[$p], ':other' => null]);
    }
    if (!empty($purOtherList) && isset($purMap['Other'])) {
        foreach ($purOtherList as $other) {
            $insPur->execute([':pid' => $profileId, ':puid' => $purMap['Other'], ':other' => $other]);
        }
    }
}

function stCreateProfile() {
    $uid = requireLogin();
    $d   = body();
    if (stNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (stNullStr($d['lastName'] ?? '')  === null) error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male','Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = stDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    $validCivil = ['Single','Married','Widowed','Separated','Divorced'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;
    if (!$civil) error('Civil Status is required.', 422);

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int)$d['barangayId'] > 0)
             ? (int)$d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    $validStatus = ['Pending','Accepted','Waitlisted','Rejected'];
    $appStatus = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : 'Waitlisted';

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare(
            "INSERT INTO beneficiaries(first_name,middle_name,last_name,sex,birth_date,civil_status,street_address,barangay_id,contact_no,status)
             VALUES(:fn,:mn,:ln,:sex,:bdate,:civil,:street,:bgy,:contact,'Active') RETURNING beneficiary_id"
        );
        $s->execute([
            ':fn' => trim($d['firstName']), ':mn' => stNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil, ':street' => stNullStr($d['streetPurok'] ?? ''),
            ':bgy' => $bgyId, ':contact' => stNullStr($d['contactNumber'] ?? ''),
        ]);
        $bid = (int)$s->fetchColumn();

        $s2 = $pdo->prepare("INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by) VALUES(:bid,:sid,'Active',:date,:rby) RETURNING beneficiary_service_id");
        $s2->execute([':bid' => $bid, ':sid' => stServiceId(), ':date' => stDate($d['dateApplicationReceived'] ?? '') ?? date('Y-m-d'), ':rby' => stNullStr($d['receivedBy'] ?? '')]);
        $bsId = (int)$s2->fetchColumn();

        $s3 = $pdo->prepare("INSERT INTO skills_training_profiles(beneficiary_service_id,application_status,created_at,updated_at) VALUES(:bsid,:st,now(),now()) RETURNING skills_training_profile_id");
        $s3->execute([':bsid' => $bsId, ':st' => $appStatus]);
        $profileId = (int)$s3->fetchColumn();

        stSyncSelections($pdo, $bid, $profileId, $d);
        stSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => stBuildProfile($bid)]);
}

function stUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $uid = requireLogin();
    $d   = body();
    $bid = (int)$id;
    if (stNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (stNullStr($d['lastName'] ?? '')  === null) error('Last name is required.', 422);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id, sp.skills_training_profile_id FROM beneficiary_services bs JOIN skills_training_profiles sp ON sp.beneficiary_service_id=bs.beneficiary_service_id WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => stServiceId()]);
    $row = $chk->fetch();
    if (!$row) error('Skills Training profile not found.', 404);
    $bsId = (int)$row['beneficiary_service_id'];
    $profileId = (int)$row['skills_training_profile_id'];

    $sex = in_array($d['sex'] ?? '', ['Male','Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = stDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    $validCivil = ['Single','Married','Widowed','Separated','Divorced'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;
    if (!$civil) error('Civil Status is required.', 422);

    if (empty($d['barangayId']) || !is_numeric($d['barangayId']) || (int)$d['barangayId'] <= 0) {
        error('Barangay is required.', 422);
    }

    $validStatus = ['Pending','Accepted','Waitlisted','Rejected'];
    $appStatus = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : 'Waitlisted';

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,sex=:sex,birth_date=:bdate,
                civil_status=:civil,street_address=:street,barangay_id=:bgy,contact_no=:contact,updated_at=now()
             WHERE beneficiary_id=:bid"
        )->execute([
            ':fn' => trim($d['firstName']), ':mn' => stNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil, ':street' => stNullStr($d['streetPurok'] ?? ''),
            ':bgy' => (int)$d['barangayId'], ':contact' => stNullStr($d['contactNumber'] ?? ''), ':bid' => $bid,
        ]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby,date_applied=:date WHERE beneficiary_service_id=:bsid")
            ->execute([':rby' => stNullStr($d['receivedBy'] ?? ''), ':date' => stDate($d['dateApplicationReceived'] ?? '') ?? date('Y-m-d'), ':bsid' => $bsId]);

        $pdo->prepare("UPDATE skills_training_profiles SET application_status=:st, updated_at=now() WHERE skills_training_profile_id=:pid")
            ->execute([':st' => $appStatus, ':pid' => $profileId]);

        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id=:bid")->execute([':bid' => $bid]);
        $pdo->prepare("DELETE FROM skills_training_profile_qualifications WHERE skills_training_profile_id=:pid")->execute([':pid' => $profileId]);
        $pdo->prepare("DELETE FROM skills_training_profile_purposes WHERE skills_training_profile_id=:pid")->execute([':pid' => $profileId]);
        stSyncSelections($pdo, $bid, $profileId, $d);

        stSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => stBuildProfile($bid)]);
}

function stDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int)$id;
    $uid = requireLogin();

    // Cannot delete an applicant with a live (not yet completed) training
    // assignment -- same lock spirit as CDSP/GIP/SPES/DILP/TUPAD/SLP/CLPEP.
    $chk = db()->prepare(
        "SELECT 1 FROM skills_training_activity_participants p
         JOIN skills_training_activities a ON a.activity_id = p.activity_id
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
         WHERE bs.beneficiary_id = :bid AND a.status IN ('Planned','Ongoing')
         LIMIT 1"
    );
    $chk->execute([':bid' => $bid]);
    if ($chk->fetchColumn()) {
        error('This applicant cannot be deleted because they are currently assigned to an active training. Unassign them first (only possible while the training is still Planned), or wait until it is marked Completed.', 409);
    }

    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Applicant moved to recycle bin.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN (soft-deleted Skills Training applicants) — mirrors cdsp.php's pattern.
// ═══════════════════════════════════════════════════════════════════════════════

function stRecycleMap() {
    return ['skillsTrainingApplicant' => ['beneficiaries', 'beneficiary_id']];
}

function stRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(stRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

function stListDeleted() {
    $s = db()->prepare(
        "SELECT b.beneficiary_id AS id,
                CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL THEN ' ' || LEFT(b.middle_name, 1) || '.' ELSE '' END) AS name,
                b.deleted_at, u.username AS deleted_by
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         LEFT JOIN users u ON u.user_id = b.deleted_by
         WHERE bs.service_id = :sid AND b.deleted_at IS NOT NULL"
    );
    $s->execute([':sid' => stServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType' => 'skillsTrainingApplicant', 'id' => (int) $r['id'], 'name' => $r['name'],
            'module' => 'Skills Training Applicants', 'description' => 'Skills Training applicant record',
            'deletedBy' => $r['deleted_by'] ?? '', 'deletedAt' => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

function stRestoreRecord() {
    [$type, $id] = stRecycleTarget();
    [$table, $pk] = stRecycleMap()[$type];
    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);
    json(['status' => 'ok', 'message' => 'Record restored.']);
}

function stPurgeRecord() {
    [$type, $id] = stRecycleTarget();
    [$table, $pk] = stRecycleMap()[$type];
    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);
    stHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

function stHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id = :id AND bs.service_id = :sid");
        $bsStmt->execute([':id' => $bid, ':sid' => stServiceId()]);
        foreach ($bsStmt->fetchAll(PDO::FETCH_COLUMN) as $bsId) {
            $bsId = (int)$bsId;
            $pdo->prepare("DELETE FROM skills_training_activity_participants WHERE beneficiary_service_id = :id")->execute([':id' => $bsId]);
            $pidS = $pdo->prepare("SELECT skills_training_profile_id FROM skills_training_profiles WHERE beneficiary_service_id = :id");
            $pidS->execute([':id' => $bsId]);
            $pid = $pidS->fetchColumn();
            if ($pid) {
                $pdo->prepare("DELETE FROM skills_training_profile_qualifications WHERE skills_training_profile_id = :id")->execute([':id' => $pid]);
                $pdo->prepare("DELETE FROM skills_training_profile_purposes WHERE skills_training_profile_id = :id")->execute([':id' => $pid]);
            }
            $pdo->prepare("DELETE FROM skills_training_profiles WHERE beneficiary_service_id = :id")->execute([':id' => $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'Skills Training'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'Skills Training'")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        // Matches cdsp.php/gip.php/employment.php's hard-delete exactly: unscoped
        // deletes of beneficiary_services/beneficiaries, not just this module's rows.
        // Pre-existing, deliberately-kept-consistent cross-module behavior (see
        // cdspHardDeleteApplicant's comment) -- not something to "fix" per-module.
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
