<?php
// OFW: profiles (beneficiary spine + ofw_profiles) + request types + agencies +
// attachments (ELPOR forms / OWWA Welfare Case form / generic documents, all
// via the universal `documents` table).
//
// Design notes (confirmed against the live schema before writing this):
//  - No batch/project/assignment concept exists for OFW (unlike every other
//    Livelihood/GIP/SPES-style module) -- it's a flat case-record list, closer
//    in shape to DILP/TUPAD (beneficiary spine + one profile row) than to
//    anything with an assign/unassign flow. No delete guard needed beyond the
//    standard soft-delete.
//  - ofw_profiles.status (Pending/Approved/Ongoing/Completed/Rejected) is a
//    real, manually-set stored column -- not derived, since there's nothing
//    to derive it from.
//  - Type of Request is multi-select against the ofw_request_types lookup via
//    ofw_profile_request_types, mirroring CDSP/DILP's classification pattern.
//    The two "please specify" checkboxes (inquiry / other DOLE program) each
//    write their free text into that same junction row's other_specification
//    column -- no separate columns needed.
//  - Agencies is a repeatable free-text list (the frontend's "+ Add Agency"
//    adds another blank text input, not a pick from a fixed list) -> its own
//    child table (ofw_profile_agencies), not a lookup+junction pair.
//  - ELPOR Forms (5 named slots) and the OWWA Welfare Case form are each a
//    single named attachment slot, not a list -- stored in the universal
//    `documents` table keyed by a fixed `document_type` value per slot
//    (document_source='OFW'), same table generic "Attached Documents" also uses.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listProfiles':      requirePermission('ofw','Viewer'); return ofwListProfiles();
        case 'getProfile':        requirePermission('ofw','Viewer'); return ofwGetProfile($id);
        case 'createProfile':     requirePermission('ofw','Editor'); return ofwCreateProfile();
        case 'updateProfile':     requirePermission('ofw','Editor'); return ofwUpdateProfile($id);
        case 'updateStatus':      requirePermission('ofw','Editor'); return ofwUpdateStatus($id);
        case 'deleteProfile':     requirePermission('ofw','Editor'); return ofwDeleteProfile($id);

        case 'listRequestTypes':  requirePermission('ofw','Viewer'); return ofwListRequestTypes();

        case 'listDeleted':       requirePermission('ofw','Viewer'); return ofwListDeleted();
        case 'restoreRecord':     requirePermission('ofw','Editor'); return ofwRestoreRecord();
        case 'purgeRecord':       requirePermission('ofw','Editor'); return ofwPurgeRecord();

        default: error("Unknown OFW action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function ofwNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function ofwDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

function ofwServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='OFW' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('OFW service not found.', 500);
    return (int)$sid;
}

function ofwUploadBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function ofwFormatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// The 5 ELPOR form slots + the OWWA Welfare Case form each occupy a fixed,
// reserved document_type value in the universal `documents` table -- anything
// else stored for this beneficiary is a generic "attached document".
function ofwNamedAttachmentTypes() {
    return ['ELPOR Form A', 'ELPOR Form A2', 'ELPOR Form B', 'ELPOR Form B1', 'ELPOR Form C', 'OWWA Welfare Case Form'];
}

// ─── Request Types (lookup) ──────────────────────────────────────────────────

function ofwListRequestTypes() {
    $s = db()->query("SELECT request_type_id, request_type_name FROM ofw_request_types ORDER BY request_type_id");
    json(['status' => 'ok', 'data' => array_map(function($r) {
        return ['id' => (int)$r['request_type_id'], 'name' => $r['request_type_name']];
    }, $s->fetchAll())]);
}

// ─── Documents (applicant) ────────────────────────────────────────────────────
//
// $d['namedAttachments'] -> { [documentType]: { fileName, dataUrl } | null }
//   one entry per slot in ofwNamedAttachmentTypes(); a slot with no dataUrl and
//   no existing row is simply absent/null (nothing to keep or create).
// $d['attachedDocuments'] -> [{ id?, name, fileName, dataUrl? }] generic list,
//   same shape/rules as every other module's attachment editor.

function ofwSyncDocuments($pdo, $bid, $bsId, $uid, $d) {
    $named = is_array($d['namedAttachments'] ?? null) ? $d['namedAttachments'] : [];
    $generic = is_array($d['attachedDocuments'] ?? null) ? $d['attachedDocuments'] : [];
    $namedTypes = ofwNamedAttachmentTypes();

    // Figure out which existing rows to keep (generic docs kept by id; named
    // slots kept if the incoming payload didn't send a fresh dataUrl replacing them).
    $keepIds = [];
    foreach ($generic as $doc) {
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keepIds[] = (int) $doc['id'];
        }
    }

    $sel = $pdo->prepare("SELECT document_id, document_type, file_path FROM documents WHERE beneficiary_id=:bid AND document_source='OFW'");
    $sel->execute([':bid' => $bid]);
    foreach ($sel->fetchAll() as $row) {
        $type = $row['document_type'];
        $isNamed = in_array($type, $namedTypes, true);
        $shouldDelete = $isNamed
            ? (isset($named[$type]) && is_array($named[$type]) && isset($named[$type]['dataUrl'])) // replaced with a new upload
                || !isset($named[$type]) // slot removed entirely
            : !in_array((int) $row['document_id'], $keepIds, true);
        if ($shouldDelete) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id=:id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO documents(beneficiary_id,beneficiary_service_id,document_source,document_type,title,file_name,file_path,file_size,mime_type,uploaded_by)
         VALUES(:bid,:bsid,'OFW',:dtype,:title,:fname,:fpath,:size,:mime,:uid)"
    );

    // Named slots (ELPOR A/A2/B/B1/C, OWWA Welfare Case Form).
    foreach ($namedTypes as $type) {
        if (!isset($named[$type]) || !is_array($named[$type])) continue;
        $dataUrl = $named[$type]['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;
        $origName = (string) ($named[$type]['fileName'] ?? 'file');
        $ext = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored = 'ofw_' . $bid . '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $type) . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;
        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':dtype' => $type, ':title' => $type,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }

    // Generic attached documents.
    foreach ($generic as $doc) {
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;
        $origName = (string) ($doc['fileName'] ?? $doc['name'] ?? 'file');
        $ext = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored = 'ofw_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;
        $custom = ofwNullStr($doc['name'] ?? '');
        $ins->execute([
            ':bid' => $bid, ':bsid' => $bsId, ':dtype' => null, ':title' => $custom ?? $origName,
            ':fname' => $origName, ':fpath' => 'uploads/' . $stored,
            ':size' => strlen($binary), ':mime' => $m[1], ':uid' => $uid,
        ]);
    }
}

function ofwFetchDocuments($bid) {
    $s = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM documents WHERE beneficiary_id=:bid AND document_source='OFW' ORDER BY document_id"
    );
    $s->execute([':bid' => $bid]);
    $rows = $s->fetchAll();
    $namedTypes = ofwNamedAttachmentTypes();

    $named = [];
    $generic = [];
    foreach ($rows as $r) {
        $att = [
            'id' => (string) $r['document_id'], 'name' => $r['title'] ?? $r['file_name'],
            'fileName' => $r['file_name'], 'fileSize' => ofwFormatBytes($r['file_size'] ?? 0),
            'url' => ofwUploadBaseUrl() . $r['file_path'],
        ];
        if ($r['document_type'] !== null && in_array($r['document_type'], $namedTypes, true)) {
            $named[$r['document_type']] = $att;
        } else {
            $generic[] = $att;
        }
    }
    return ['named' => $named, 'generic' => $generic];
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function ofwBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.date_applied, bs.received_by,
                op.ofw_profile_id, op.reference_no, op.date_filed, op.employment_status,
                op.desired_position, op.type_of_skill, op.status, op.remarks
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         JOIN ofw_profiles op ON op.beneficiary_service_id = bs.beneficiary_service_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND bs.service_id = :sid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':sid' => ofwServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int)$b['beneficiary_service_id'];
    $profileId = (int)$b['ofw_profile_id'];

    $rtS = db()->prepare(
        "SELECT rt.request_type_name, prt.other_specification
         FROM ofw_profile_request_types prt
         JOIN ofw_request_types rt ON rt.request_type_id = prt.request_type_id
         WHERE prt.ofw_profile_id = :pid"
    );
    $rtS->execute([':pid' => $profileId]);
    $typeOfRequest = [];
    $inquirySpecify = '';
    $otherProgramSpecify = '';
    foreach ($rtS->fetchAll() as $row) {
        $typeOfRequest[] = $row['request_type_name'];
        if ($row['request_type_name'] === 'inquiry (pls specify)') $inquirySpecify = $row['other_specification'] ?? '';
        if ($row['request_type_name'] === 'other DOLE program (please specify)') $otherProgramSpecify = $row['other_specification'] ?? '';
    }

    $agS = db()->prepare("SELECT agency_name FROM ofw_profile_agencies WHERE ofw_profile_id=:pid ORDER BY ofw_profile_agency_id");
    $agS->execute([':pid' => $profileId]);
    $agencies = $agS->fetchAll(PDO::FETCH_COLUMN);

    $docs = ofwFetchDocuments($bid);
    $namedTypes = ofwNamedAttachmentTypes();
    $elporFiles = [];
    foreach ($namedTypes as $type) {
        if ($type === 'OWWA Welfare Case Form') continue;
        if (isset($docs['named'][$type])) $elporFiles[$type] = $docs['named'][$type];
    }

    return [
        'id'                      => $bid,
        'beneficiaryServiceId'    => $bsId,
        'referenceNumber'         => $b['reference_no'],
        'lastName'                => $b['last_name'],
        'firstName'               => $b['first_name'],
        'middleName'              => $b['middle_name'] ?? '',
        'suffix'                  => $b['suffix'] ?? '',
        'name'                    => trim($b['last_name'] . ', ' . $b['first_name'] . (!empty($b['middle_name']) ? ' ' . mb_substr($b['middle_name'], 0, 1) . '.' : '') . (!empty($b['suffix']) ? ' ' . $b['suffix'] : '')),
        'sex'                     => $b['sex'] ?? '',
        'birthdate'               => $b['birth_date'] ?? '',
        'civilStatus'             => $b['civil_status'] ?? '',
        'contactNumber'           => $b['contact_no'] ?? '',
        'email'                   => $b['email'] ?? '',
        'address'                 => $b['street_address'] ?? '',
        'barangay'                => $b['barangay_name'] ?? '',
        'barangayId'              => (int)($b['barangay_id'] ?? 0),
        'municipality'            => $b['city_name'] ?? '',
        'province'                => $b['province_name'] ?? '',
        'region'                  => $b['region_name'] ?? '',
        'dateFiled'               => $b['date_filed'] ?? '',
        'employmentStatus'        => $b['employment_status'] ?? '',
        'typeOfRequest'           => $typeOfRequest,
        'status'                  => $b['status'] ?? 'Pending',
        'remarks'                 => $b['remarks'] ?? '',
        'desiredPosition'         => $b['desired_position'] ?? '',
        'typeOfSkill'             => $b['type_of_skill'] ?? '',
        'agencies'                => array_values($agencies),
        'inquirySpecify'          => $inquirySpecify,
        'otherProgramSpecify'     => $otherProgramSpecify,
        'owwaWelfareFile'         => $docs['named']['OWWA Welfare Case Form'] ?? null,
        'elporFiles'              => $elporFiles,
        'dateApplicationReceived' => $b['date_applied'] ?? '',
        'receivedBy'              => $b['received_by'] ?? '',
        'attachedDocuments'       => $docs['generic'],
    ];
}

function ofwListProfiles() {
    $s = db()->prepare(
        "SELECT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':sid' => ofwServiceId()]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $p = ofwBuildProfile((int)$bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function ofwGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = ofwBuildProfile((int)$id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

// Insert request-type + agency rows for a profile. Shared by create + update
// (update calls this after clearing old rows).
function ofwSyncSelections($pdo, $profileId, $d) {
    $rtMap = [];
    foreach (db()->query("SELECT request_type_id, request_type_name FROM ofw_request_types")->fetchAll() as $r) {
        $rtMap[$r['request_type_name']] = (int)$r['request_type_id'];
    }
    $rawTypes = is_array($d['typeOfRequest'] ?? null) ? $d['typeOfRequest'] : [];
    $inquirySpecify = ofwNullStr($d['inquirySpecify'] ?? '');
    $otherProgramSpecify = ofwNullStr($d['otherProgramSpecify'] ?? '');
    $insRt = $pdo->prepare("INSERT INTO ofw_profile_request_types(ofw_profile_id,request_type_id,other_specification) VALUES(:pid,:rtid,:other)");
    foreach ($rawTypes as $t) {
        if (!isset($rtMap[$t])) continue;
        $other = null;
        if ($t === 'inquiry (pls specify)') $other = $inquirySpecify;
        if ($t === 'other DOLE program (please specify)') $other = $otherProgramSpecify;
        $insRt->execute([':pid' => $profileId, ':rtid' => $rtMap[$t], ':other' => $other]);
    }

    $rawAgencies = is_array($d['agencies'] ?? null) ? array_filter(array_map('trim', $d['agencies'])) : [];
    $insAg = $pdo->prepare("INSERT INTO ofw_profile_agencies(ofw_profile_id,agency_name) VALUES(:pid,:name)");
    foreach ($rawAgencies as $name) {
        $insAg->execute([':pid' => $profileId, ':name' => $name]);
    }
}

function ofwValidateProfileInput($d) {
    if (ofwNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (ofwNullStr($d['lastName'] ?? '')  === null) error('Last name is required.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male', 'Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $birth = ofwDate($d['birthdate'] ?? '');
    if (!$birth) error('Birthdate is required.', 422);

    $validCivil = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;
    if (!$civil) error('Civil Status is required.', 422);

    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int)$d['barangayId'] > 0)
             ? (int)$d['barangayId'] : null;
    if (!$bgyId) error('Barangay is required.', 422);

    $validEmp = ['Employed', 'Unemployed', 'Self Employed', 'Underemployed'];
    $emp = in_array($d['employmentStatus'] ?? '', $validEmp, true) ? $d['employmentStatus'] : null;
    if (!$emp) error('Employment Status is required.', 422);

    $refNo = ofwNullStr($d['referenceNumber'] ?? '');
    if (!$refNo) error('Reference Number is required.', 422);

    $filed = ofwDate($d['dateFiled'] ?? '');
    if (!$filed) error('Date Filed is required.', 422);

    return [$sex, $birth, $civil, $bgyId, $emp, $refNo, $filed];
}

function ofwCreateProfile() {
    $uid = requireLogin();
    $d = body();
    [$sex, $birth, $civil, $bgyId, $emp, $refNo, $filed] = ofwValidateProfileInput($d);

    $validStatus = ['Pending', 'Approved', 'Ongoing', 'Completed', 'Rejected'];
    $status = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : 'Pending';

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare(
            "INSERT INTO beneficiaries(first_name,middle_name,last_name,suffix,sex,birth_date,civil_status,street_address,barangay_id,contact_no,email,status)
             VALUES(:fn,:mn,:ln,:sfx,:sex,:bdate,:civil,:street,:bgy,:contact,:email,'Active') RETURNING beneficiary_id"
        );
        $s->execute([
            ':fn' => trim($d['firstName']), ':mn' => ofwNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => ofwNullStr($d['suffix'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => ofwNullStr($d['address'] ?? ''), ':bgy' => $bgyId,
            ':contact' => ofwNullStr($d['contactNumber'] ?? ''), ':email' => ofwNullStr($d['email'] ?? ''),
        ]);
        $bid = (int)$s->fetchColumn();

        $s2 = $pdo->prepare("INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by) VALUES(:bid,:sid,'Active',:date,:rby) RETURNING beneficiary_service_id");
        $s2->execute([':bid' => $bid, ':sid' => ofwServiceId(), ':date' => ofwDate($d['dateApplicationReceived'] ?? '') ?? date('Y-m-d'), ':rby' => ofwNullStr($d['receivedBy'] ?? '')]);
        $bsId = (int)$s2->fetchColumn();

        try {
            $s3 = $pdo->prepare(
                "INSERT INTO ofw_profiles(beneficiary_service_id,reference_no,date_filed,employment_status,desired_position,type_of_skill,status,remarks,created_at,updated_at)
                 VALUES(:bsid,:refno,:filed,:emp,:dp,:tos,:st,:rmk,now(),now()) RETURNING ofw_profile_id"
            );
            $s3->execute([
                ':bsid' => $bsId, ':refno' => $refNo, ':filed' => $filed, ':emp' => $emp,
                ':dp' => ofwNullStr($d['desiredPosition'] ?? ''), ':tos' => ofwNullStr($d['typeOfSkill'] ?? ''),
                ':st' => $status, ':rmk' => ofwNullStr($d['remarks'] ?? ''),
            ]);
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'ofw_profiles_reference_no_key') !== false) {
                error("Reference Number \"{$refNo}\" is already in use. Please use a different one.", 409);
            }
            throw $e;
        }
        $profileId = (int)$s3->fetchColumn();

        ofwSyncSelections($pdo, $profileId, $d);
        ofwSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile saved.', 'data' => ofwBuildProfile($bid)]);
}

function ofwUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $uid = requireLogin();
    $d = body();
    $bid = (int)$id;
    [$sex, $birth, $civil, $bgyId, $emp, $refNo, $filed] = ofwValidateProfileInput($d);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id, op.ofw_profile_id FROM beneficiary_services bs JOIN ofw_profiles op ON op.beneficiary_service_id=bs.beneficiary_service_id WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => ofwServiceId()]);
    $row = $chk->fetch();
    if (!$row) error('OFW profile not found.', 404);
    $bsId = (int)$row['beneficiary_service_id'];
    $profileId = (int)$row['ofw_profile_id'];

    $validStatus = ['Pending', 'Approved', 'Ongoing', 'Completed', 'Rejected'];
    $status = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : 'Pending';

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "UPDATE beneficiaries SET first_name=:fn,middle_name=:mn,last_name=:ln,suffix=:sfx,sex=:sex,birth_date=:bdate,
                civil_status=:civil,street_address=:street,barangay_id=:bgy,contact_no=:contact,email=:email,updated_at=now()
             WHERE beneficiary_id=:bid"
        )->execute([
            ':fn' => trim($d['firstName']), ':mn' => ofwNullStr($d['middleName'] ?? ''), ':ln' => trim($d['lastName']),
            ':sfx' => ofwNullStr($d['suffix'] ?? ''), ':sex' => $sex, ':bdate' => $birth, ':civil' => $civil,
            ':street' => ofwNullStr($d['address'] ?? ''), ':bgy' => $bgyId,
            ':contact' => ofwNullStr($d['contactNumber'] ?? ''), ':email' => ofwNullStr($d['email'] ?? ''), ':bid' => $bid,
        ]);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby,date_applied=:date WHERE beneficiary_service_id=:bsid")
            ->execute([':rby' => ofwNullStr($d['receivedBy'] ?? ''), ':date' => ofwDate($d['dateApplicationReceived'] ?? '') ?? date('Y-m-d'), ':bsid' => $bsId]);

        try {
            $pdo->prepare(
                "UPDATE ofw_profiles SET reference_no=:refno,date_filed=:filed,employment_status=:emp,desired_position=:dp,type_of_skill=:tos,status=:st,remarks=:rmk,updated_at=now()
                 WHERE ofw_profile_id=:pid"
            )->execute([
                ':refno' => $refNo, ':filed' => $filed, ':emp' => $emp,
                ':dp' => ofwNullStr($d['desiredPosition'] ?? ''), ':tos' => ofwNullStr($d['typeOfSkill'] ?? ''),
                ':st' => $status, ':rmk' => ofwNullStr($d['remarks'] ?? ''), ':pid' => $profileId,
            ]);
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'ofw_profiles_reference_no_key') !== false) {
                error("Reference Number \"{$refNo}\" is already in use. Please use a different one.", 409);
            }
            throw $e;
        }

        $pdo->prepare("DELETE FROM ofw_profile_request_types WHERE ofw_profile_id=:pid")->execute([':pid' => $profileId]);
        $pdo->prepare("DELETE FROM ofw_profile_agencies WHERE ofw_profile_id=:pid")->execute([':pid' => $profileId]);
        ofwSyncSelections($pdo, $profileId, $d);

        ofwSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status' => 'ok', 'message' => 'Profile updated.', 'data' => ofwBuildProfile($bid)]);
}

function ofwUpdateStatus($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int)$id;
    $d = body();
    $validStatus = ['Pending', 'Approved', 'Ongoing', 'Completed', 'Rejected'];
    $status = $d['status'] ?? '';
    if (!in_array($status, $validStatus, true)) error('Invalid status.', 422);

    $chk = db()->prepare("SELECT op.ofw_profile_id FROM beneficiary_services bs JOIN ofw_profiles op ON op.beneficiary_service_id=bs.beneficiary_service_id WHERE bs.beneficiary_id=:bid AND bs.service_id=:sid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid' => $bid, ':sid' => ofwServiceId()]);
    $profileId = $chk->fetchColumn();
    if (!$profileId) error('OFW profile not found.', 404);

    db()->prepare("UPDATE ofw_profiles SET status=:st, updated_at=now() WHERE ofw_profile_id=:pid")
        ->execute([':st' => $status, ':pid' => (int)$profileId]);
    json(['status' => 'ok', 'message' => 'Status updated.', 'data' => ofwBuildProfile($bid)]);
}

function ofwDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $bid = (int)$id;
    $uid = requireLogin();
    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid' => $uid, ':id' => $bid]);
    json(['status' => 'ok', 'message' => 'Profile moved to recycle bin.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECYCLE BIN (soft-deleted OFW profiles) — mirrors cdsp.php/skills_training.php's pattern.
// ═══════════════════════════════════════════════════════════════════════════════

function ofwRecycleMap() {
    return ['ofwProfile' => ['beneficiaries', 'beneficiary_id']];
}

function ofwRecycleTarget() {
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(ofwRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

function ofwListDeleted() {
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
    $s->execute([':sid' => ofwServiceId()]);
    $items = array_map(function ($r) {
        return [
            'recordType' => 'ofwProfile', 'id' => (int) $r['id'], 'name' => $r['name'],
            'module' => 'OFW Profiles', 'description' => 'OFW assistance request record',
            'deletedBy' => $r['deleted_by'] ?? '', 'deletedAt' => $r['deleted_at'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $items]);
}

function ofwRestoreRecord() {
    [$type, $id] = ofwRecycleTarget();
    [$table, $pk] = ofwRecycleMap()[$type];
    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);
    json(['status' => 'ok', 'message' => 'Record restored.']);
}

function ofwPurgeRecord() {
    [$type, $id] = ofwRecycleTarget();
    [$table, $pk] = ofwRecycleMap()[$type];
    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);
    ofwHardDeleteApplicant($id);
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

function ofwHardDeleteApplicant($bid) {
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs WHERE bs.beneficiary_id = :id AND bs.service_id = :sid");
        $bsStmt->execute([':id' => $bid, ':sid' => ofwServiceId()]);
        foreach ($bsStmt->fetchAll(PDO::FETCH_COLUMN) as $bsId) {
            $pidS = $pdo->prepare("SELECT ofw_profile_id FROM ofw_profiles WHERE beneficiary_service_id = :id");
            $pidS->execute([':id' => (int) $bsId]);
            $pid = $pidS->fetchColumn();
            if ($pid) {
                $pdo->prepare("DELETE FROM ofw_profile_request_types WHERE ofw_profile_id = :id")->execute([':id' => $pid]);
                $pdo->prepare("DELETE FROM ofw_profile_agencies WHERE ofw_profile_id = :id")->execute([':id' => $pid]);
            }
            $pdo->prepare("DELETE FROM ofw_profiles WHERE beneficiary_service_id = :id")->execute([':id' => (int) $bsId]);
        }

        $docs = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_source = 'OFW'");
        $docs->execute([':id' => $bid]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :id AND document_source = 'OFW'")->execute([':id' => $bid]);

        // Matches cdsp.php/gip.php/skills_training.php's hard-delete exactly:
        // unscoped deletes, deliberately kept consistent cross-module behavior.
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete: ' . $e->getMessage(), 500);
    }
}
