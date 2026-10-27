<?php
// Employers, vacancies, referrals, placements, EF profiles. Creating an applicant writes
// across the beneficiary spine plus resume sub-tables in a single transaction.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

// Router entry point. index.php calls this with the parsed action/id/method.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'listApplicants':
            requirePermission('employment', 'Viewer');
            return employmentListApplicants();
        case 'getApplicant':
            requirePermission('employment', 'Viewer');
            return employmentGetApplicant($id);
        case 'getApplicantPhoto':
            requirePermission('employment', 'Viewer');
            return employmentGetApplicantPhoto($id);
        case 'getApplicantHistory':
            requirePermission('employment', 'Viewer');
            return efGetApplicantHistory($id);
        case 'createApplicant':
            requirePermission('employment', 'Editor');
            return employmentCreateApplicant();
        case 'importApplicantsBulk':
            requirePermission('employment', 'Editor');
            return employmentImportApplicantsBulk();
        case 'updateApplicant':
            requirePermission('employment', 'Editor');
            return employmentUpdateApplicant($id);
        case 'deleteApplicant':
            requirePermission('employment', 'Editor');
            return employmentDeleteApplicant($id);
        case 'listIndustries':    requirePermission('employment', 'Viewer'); return efListIndustries();
        case 'listEmployers':
            requirePermission('employment', 'Viewer');
            return efListEmployers();
        case 'createEmployer':
            requirePermission('employment', 'Editor');
            return efCreateEmployer();
        case 'importEmployersBulk':
            requirePermission('employment', 'Editor');
            return efImportEmployersBulk();
        case 'updateEmployer':
            requirePermission('employment', 'Editor');
            return efUpdateEmployer($id);
        case 'deleteEmployer':
            requirePermission('employment', 'Editor');
            return efDeleteEmployer($id);
        case 'listVacancies':
            requirePermission('employment', 'Viewer');
            return efListVacancies();
        case 'createVacancy':
            requirePermission('employment', 'Editor');
            return efCreateVacancy();
        case 'updateVacancy':
            requirePermission('employment', 'Editor');
            return efUpdateVacancy($id);
        case 'toggleVacancyStatus':
            requirePermission('employment', 'Editor');
            return efToggleVacancyStatus($id);
        case 'listReferrals':
            requirePermission('employment', 'Viewer');
            return efListReferrals();
        case 'createReferral':
            requirePermission('employment', 'Editor');
            return efCreateReferral();
        case 'updateReferralStatus':
            requirePermission('employment', 'Editor');
            return efUpdateReferralStatus($id);
        case 'checkDuplicateReferral':
            requirePermission('employment', 'Viewer');
            return efCheckDuplicateReferral();
        case 'deleteReferral':
            requirePermission('employment', 'Editor');
            return efDeleteReferral($id);
        case 'listPlacements':
            requirePermission('employment', 'Viewer');
            return efListPlacements();
        case 'updatePlacement':
            requirePermission('employment', 'Editor');
            return efUpdatePlacement($id);
        case 'updatePlacementStatus':
            requirePermission('employment', 'Editor');
            return efUpdatePlacementStatus($id);
        case 'monthlyReport':
            requirePermission('employment', 'Viewer');
            return efMonthlyReport();
        case 'listPromotions':
            requirePermission('employment', 'Viewer');
            return efListPromotions($id);
        case 'createPromotion':
            requirePermission('employment', 'Editor');
            return efCreatePromotion($id);
        case 'listDeleted':
            requirePermission('employment', 'Viewer');
            return efListDeleted();
        case 'restoreRecord':
            requirePermission('employment', 'Editor');
            return efRestoreRecord();
        case 'purgeRecord':
            requirePermission('employment', 'Editor');
            return efPurgeRecord();
        default:
            error("Unknown employment action: {$action}", 404);
    }
}

// ─── Small mapping helpers ──────────────────────────────────────────────────

// "Yes"/true -> true, anything else -> false.
function efYes($v)
{
    return $v === 'Yes' || $v === true || $v === 'yes' || $v === 1 || $v === '1';
}

// Trimmed string, or null when empty.
function efNull($v)
{
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}

// Integer or null.
function efIntOrNull($v)
{
    return is_numeric($v) ? (int) $v : null;
}

// A 4-digit-ish year as smallint, or null.
function efYearOrNull($v)
{
    if (!is_numeric($v)) return null;
    $y = (int) $v;
    return ($y >= 1900 && $y <= 2200) ? $y : null;
}

// A plain YYYY-MM-DD or null.
function efDateOrNull($v)
{
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

// Parses a height value into a positive numeric(5,2), or null.
function efHeightCm($v)
{
    if (is_numeric($v)) {
        $n = (float) $v;
    } elseif (is_string($v) && preg_match('/\d+(?:\.\d+)?/', $v, $m)) {
        $n = (float) $m[0];
    } else {
        return null;
    }
    return ($n > 0 && $n < 1000) ? $n : null;
}

// True when an education level has any data worth saving.
function efEduHasData($row)
{
    if (!is_array($row)) return false;
    foreach (['schoolName', 'graduated', 'yearGraduated', 'levelReached', 'yearLastAttended', 'course', 'strand', 'seniorHighStrand', 'type'] as $k) {
        if (efNull($row[$k] ?? '') !== null) return true;
    }
    return false;
}

// Best-effort educational_attainment_enum from the education section.
function efDeriveEducation($d)
{
    $tert = isset($d['tertiary']) && is_array($d['tertiary']) ? $d['tertiary'] : [];
    $sec  = isset($d['secondary']) && is_array($d['secondary']) ? $d['secondary'] : [];
    $elem = isset($d['elementary']) && is_array($d['elementary']) ? $d['elementary'] : [];

    if (efYes($tert['graduated'] ?? '')) return 'College Graduate';
    if (efEduHasData($tert)) return 'College Level';
    if (efYes($sec['graduated'] ?? '')) return 'Senior High School Graduate';
    if (efEduHasData($sec)) return 'Senior High School Level';
    if (efYes($elem['graduated'] ?? '')) return 'Elementary Graduate';
    if (efEduHasData($elem)) return 'Elementary Level';
    return null;
}

// Returns the referred_program_enum value, or null if invalid.
function efReferredProgram($d)
{
    $valid = ['SPES', 'GIP', 'DILEEP', 'TESDA Training', 'TUPAD', 'JobStart', 'Others'];
    $prog  = $d['referredProgram'] ?? '';
    return in_array($prog, $valid, true) ? $prog : null;
}

// The Employment Facilitation service_id (cached per request).
function efServiceId()
{
    static $sid = null;
    if ($sid !== null) return $sid;
    $stmt = db()->query("SELECT service_id FROM services WHERE service_code = 'EF'");
    $sid = $stmt->fetchColumn();
    if ($sid === false) {
        error('Employment Facilitation service is not configured. Seed the services table.', 500);
    }
    return (int) $sid;
}

// ─── Create ─────────────────────────────────────────────────────────────────

// POST /api/employment/createApplicant   body = ApplicantFormData
function employmentCreateApplicant()
{
    $uid = requireLogin();
    $d   = body();

    $errors = employmentValidateApplicant($d);
    if ($errors) {
        error($errors, 422);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $beneficiaryId = employmentInsertBeneficiary($pdo, $d);

        // Enroll in the Employment Facilitation service.
        $stmt = $pdo->prepare(
            "INSERT INTO beneficiary_services (beneficiary_id, service_id, date_applied, received_by)
             VALUES (:bid, :sid, CURRENT_DATE, :uid)
             RETURNING beneficiary_service_id"
        );
        $stmt->execute([':bid' => $beneficiaryId, ':sid' => efServiceId(), ':uid' => $uid]);
        $bsId = (int) $stmt->fetchColumn();

        employmentInsertEfProfile($pdo, $bsId, $d);
        employmentInsertResumeTables($pdo, $beneficiaryId, $d);
        employmentInsertDisabilities($pdo, $beneficiaryId, $d);
        employmentSavePhoto($pdo, $beneficiaryId, $bsId, $uid, $d);
        employmentSyncDocuments($pdo, $beneficiaryId, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save applicant: ' . $e->getMessage(), 500);
    }

    logActivity($uid, 'Create Applicant', 'employment', "Created applicant: " . trim($d['surname']) . ", " . trim($d['firstName']));
    json(['status' => 'ok', 'message' => 'Applicant saved.', 'data' => ['id' => $beneficiaryId]]);
}

// Bulk-imports EF applicants.
function employmentImportApplicantsBulk()
{
    $uid  = requireLogin();
    $body = body();
    $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];

    if (!$rows) {
        error('No rows to import.', 422);
    }

    $errors = [];
    foreach ($rows as $i => $d) {
        $err = employmentValidateApplicant($d);
        if ($err) $errors[] = ['row' => $i, 'error' => $err];
    }
    if ($errors) {
        error('Validation failed. No records were imported.', 422, ['errors' => $errors]);
    }

    $pdo = db();
    $ids = [];
    try {
        $pdo->beginTransaction();
        foreach ($rows as $d) {
            $beneficiaryId = employmentInsertBeneficiary($pdo, $d);

            $stmt = $pdo->prepare(
                "INSERT INTO beneficiary_services (beneficiary_id, service_id, date_applied, received_by)
                 VALUES (:bid, :sid, CURRENT_DATE, :uid)
                 RETURNING beneficiary_service_id"
            );
            $stmt->execute([':bid' => $beneficiaryId, ':sid' => efServiceId(), ':uid' => $uid]);
            $bsId = (int) $stmt->fetchColumn();

            employmentInsertEfProfile($pdo, $bsId, $d);
            employmentInsertResumeTables($pdo, $beneficiaryId, $d);
            employmentInsertDisabilities($pdo, $beneficiaryId, $d);
            employmentSavePhoto($pdo, $beneficiaryId, $bsId, $uid, $d);
            employmentSyncDocuments($pdo, $beneficiaryId, $bsId, $uid, $d);

            $ids[] = $beneficiaryId;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save applicants. No records were imported.', 500, [
            'failedIndex' => count($ids),
            'reason'      => 'An unexpected error occurred while saving this record.',
        ]);
    }

    foreach ($rows as $d) {
        logActivity($uid, 'Create Applicant', 'employment', "Created applicant: " . trim($d['surname']) . ", " . trim($d['firstName']));
    }

    json(['status' => 'ok', 'message' => 'Applicants saved.', 'data' => ['ids' => $ids, 'count' => count($ids)]]);
}

// Required-field + enum validation. Returns an error string, or '' if valid.
function employmentValidateApplicant($d)
{
    $labels = [
        'firstName'   => 'First Name',
        'surname'     => 'Surname',
        'dateOfBirth' => 'Date of Birth',
        'sex'         => 'Sex',
        'civilStatus' => 'Civil Status',
    ];
    foreach ($labels as $f => $label) {
        if (efNull($d[$f] ?? '') === null) {
            return "Missing required field: {$label}";
        }
    }
    if (!in_array($d['sex'], ['Male', 'Female'], true)) {
        return 'Sex must be either Male or Female.';
    }
    if (!in_array($d['civilStatus'], ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'], true)) {
        return 'Civil Status must be one of: Single, Married, Widowed, Separated, Divorced.';
    }
    if (efDateOrNull($d['dateOfBirth'] ?? '') === null) {
        return 'Date of Birth is not a valid date.';
    }
    if (efDateOrNull($d['dateOfBirth'] ?? '') > date('Y-m-d')) {
        return 'Date of Birth cannot be in the future.';
    }
    // barangay_id is required by the DB and comes from the address combobox.
    if (!isset($d['barangayId']) || !is_numeric($d['barangayId'])) {
        return 'Please select a barangay from the dropdown.';
    }
    $chk = db()->prepare("SELECT 1 FROM barangays WHERE barangay_id = :id");
    $chk->execute([':id' => (int) $d['barangayId']]);
    if (!$chk->fetchColumn()) {
        return 'Selected barangay was not found.';
    }
    return '';
}

// Insert the beneficiaries row, return the new id.
function employmentInsertBeneficiary($pdo, $d)
{
    $stmt = $pdo->prepare(
        "INSERT INTO beneficiaries
            (first_name, middle_name, last_name, suffix, sex, birth_date, civil_status,
             street_address, barangay_id, contact_no, email, is_4ps_beneficiary, educational_attainment)
         VALUES
            (:first, :middle, :last, :suffix, :sex, :bdate, :civil,
             :street, :bgy, :contact, :email, :is4ps, :educ)
         RETURNING beneficiary_id"
    );
    $stmt->execute([
        ':first'   => trim($d['firstName']),
        ':middle'  => efNull($d['middleName'] ?? ''),
        ':last'    => trim($d['surname']),
        ':suffix'  => efNull($d['suffix'] ?? ''),
        ':sex'     => $d['sex'],
        ':bdate'   => $d['dateOfBirth'],
        ':civil'   => $d['civilStatus'],
        ':street'  => efNull($d['houseNo'] ?? ''),
        ':bgy'     => (int) $d['barangayId'],
        ':contact' => efNull($d['contactNumber'] ?? ''),
        ':email'   => efNull($d['email'] ?? ''),
        ':is4ps'   => efYes($d['is4PsBeneficiary'] ?? '') ? 'true' : 'false',
        ':educ'    => efDeriveEducation($d),
    ]);
    return (int) $stmt->fetchColumn();
}

// Insert the employment_facilitation_profiles row.
function employmentInsertEfProfile($pdo, $bsId, $d)
{
    $isOfw       = efYes($d['isOFW'] ?? '');
    $isFormerOfw = efYes($d['isFormerOFW'] ?? '');

    $stmt = $pdo->prepare(
        "INSERT INTO employment_facilitation_profiles
            (beneficiary_service_id, tin, religion, height_cm, is_ofw, ofw_country,
             is_former_ofw, former_ofw_country, former_ofw_return_date,
             household_id_no, currently_in_school, referred_program, other_referred_program,
             employment_status, employment_type, self_employment_type,
             unemployment_reason, other_reason, months_looking_for_work)
         VALUES
            (:bsid, :tin, :religion, :height, :is_ofw, :ofw_country,
             :is_former, :former_country, :former_date,
             :household, :in_school, :referred, :referred_other,
             :emp_status, :emp_type, :self_type,
             :unemp_reason, :other_reason, :months)"
    );
    $referred = efReferredProgram($d);
    $emp = efEmploymentFields($d);
    $stmt->execute([
        ':bsid'           => $bsId,
        ':tin'            => efNull($d['tin'] ?? ''),
        ':religion'       => efNull($d['religion'] ?? ''),
        ':height'         => efHeightCm($d['height'] ?? ''),
        ':is_ofw'         => $isOfw ? 'true' : 'false',
        // Check constraint: ofw_country must be non-null when is_ofw is true.
        ':ofw_country'    => $isOfw ? (string) ($d['ofwCountry'] ?? '') : null,
        ':is_former'      => $isFormerOfw ? 'true' : 'false',
        ':former_country' => $isFormerOfw ? (string) ($d['formerOFWCountry'] ?? '') : null,
        ':former_date'    => efDateOrNull($d['formerOFWReturnDate'] ?? ''),
        ':household'      => efNull($d['householdIdNo'] ?? ''),
        ':in_school'      => efYes($d['currentlyInSchool'] ?? '') ? 'true' : 'false',
        ':referred'       => $referred,
        ':referred_other' => ($referred === 'Others') ? efNull($d['referredProgramOther'] ?? '') : null,
        ':emp_status'     => $emp['status'],
        ':emp_type'       => $emp['type'],
        ':self_type'      => $emp['selfType'],
        ':unemp_reason'   => $emp['reason'],
        ':other_reason'   => $emp['otherReason'],
        ':months'         => $emp['months'],
    ]);
}

// Normalize the Employment Status / Type form fields to DB-safe enum values.
function efEmploymentFields($d)
{
    $statusValid = ['Employed', 'Unemployed'];
    $typeValid   = ['Wage Employed', 'Self-employed'];
    $reasonValid = ['New Entrant/Fresh Graduate', 'Finished Contract', 'Resigned',
                    'Retired', 'Terminated/Laid-off', 'Terminated due to Calamity', 'Others'];

    $status = in_array($d['employmentStatus'] ?? '', $statusValid, true) ? $d['employmentStatus'] : null;

    $type = null; $selfType = null;
    if ($status === 'Employed') {
        $type = in_array($d['employmentType'] ?? '', $typeValid, true) ? $d['employmentType'] : null;
        if ($type === 'Self-employed') {
            $selfType = (($d['selfEmploymentType'] ?? '') === 'Others')
                ? efNull($d['selfEmploymentOther'] ?? '')
                : efNull($d['selfEmploymentType'] ?? '');
        }
    }

    $reason = null; $otherReason = null; $months = null;
    if ($status === 'Unemployed') {
        $reason = in_array($d['unemploymentReason'] ?? '', $reasonValid, true) ? $d['unemploymentReason'] : null;
        if ($reason === 'Others') $otherReason = efNull($d['unemploymentReasonOther'] ?? '');
        $months = efIntOrNull($d['monthsLookingForWork'] ?? '');
    }

    return compact('status', 'type', 'selfType', 'reason', 'otherReason', 'months');
}

// ─── Disabilities + 2x2 photo (separate tables) ──────────────────────────────

// Inserts an applicant's disability rows.
function employmentInsertDisabilities($pdo, $bid, $d)
{
    $list = is_array($d['hasDisability'] ?? null) ? $d['hasDisability'] : [];
    if (!$list) return;

    $standard = ['Visual', 'Speech', 'Hearing', 'Physical', 'Mental'];
    $other    = efNull($d['disabilityOther'] ?? '');
    $stmt = $pdo->prepare(
        "INSERT INTO disabilities (beneficiary_id, disability_name)
         VALUES (:bid, :name) ON CONFLICT (beneficiary_id, disability_name) DO NOTHING"
    );
    foreach ($list as $name) {
        if (in_array($name, $standard, true)) {
            $stmt->execute([':bid' => $bid, ':name' => $name]);
        } elseif ($name === 'Other') {
            $stmt->execute([':bid' => $bid, ':name' => $other ?? 'Other']);
        }
    }
}

// The Employment Facilitation public upload base URL (for serving stored files).
function efUploadBaseUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

// Saves the applicant's 2x2 photo as a file and a documents row.
function employmentSavePhoto($pdo, $bid, $bsId, $uid, $d)
{
    $img = $d['profileImage'] ?? '';
    if (!is_string($img) || strpos($img, 'data:') !== 0) return; // only new uploads

    if (!preg_match('#^data:(image/[\w.+-]+);base64,(.+)$#s', $img, $m)) return;
    $mime   = $m[1];
    $binary = base64_decode($m[2], true);
    if ($binary === false) return;

    $ext      = ($mime === 'image/png') ? 'png' : 'jpg';
    $fileName = 'ef_' . $bid . '_2x2_' . time() . '.' . $ext;
    $absPath  = __DIR__ . '/../uploads/' . $fileName;
    if (file_put_contents($absPath, $binary) === false) return;

    $stmt = $pdo->prepare(
        "INSERT INTO attached_documents
            (beneficiary_id, beneficiary_service_id, document_source, document_type,
             title, file_name, file_path, file_size, mime_type, uploaded_by)
         VALUES
            (:bid, :bsid, 'Employment Facilitation', '2x2 ID Picture',
             '2x2 ID Picture', :fname, :fpath, :size, :mime, :uid)"
    );
    $stmt->execute([
        ':bid'   => $bid,
        ':bsid'  => $bsId,
        ':fname' => $fileName,
        ':fpath' => 'uploads/' . $fileName,
        ':size'  => strlen($binary),
        ':mime'  => $mime,
        ':uid'   => $uid,
    ]);
}

// Remove a beneficiary's 2x2 photo document(s) and their files.
function employmentDeletePhotos($pdo, $bid)
{
    $sel = $pdo->prepare("SELECT file_path FROM attached_documents WHERE beneficiary_id = :bid AND document_type = '2x2 ID Picture'");
    $sel->execute([':bid' => $bid]);
    foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    $pdo->prepare("DELETE FROM attached_documents WHERE beneficiary_id = :bid AND document_type = '2x2 ID Picture'")
        ->execute([':bid' => $bid]);
}

// Replaces or clears an applicant's 2x2 photo on update.
function employmentUpdatePhoto($pdo, $bid, $bsId, $uid, $d)
{
    $img = $d['profileImage'] ?? '';
    if (is_string($img) && strpos($img, 'data:') === 0) {
        employmentDeletePhotos($pdo, $bid);
        employmentSavePhoto($pdo, $bid, $bsId, $uid, $d);
    } elseif ($img === '') {
        employmentDeletePhotos($pdo, $bid);
    }
}

// Human-readable file size from a byte count.
function efFormatBytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Saves non-photo attachment uploads for an EF applicant.
function employmentSyncDocuments($pdo, $bid, $bsId, $uid, $d)
{
    $docs = is_array($d['savedDocuments'] ?? null) ? $d['savedDocuments'] : [];

    // Document IDs to keep (existing files the form still lists, i.e. no new dataUrl).
    $keep = [];
    foreach ($docs as $doc) {
        if (($doc['documentType'] ?? '') === '2x2 ID Picture') continue;
        $isNew = isset($doc['dataUrl']) && is_string($doc['dataUrl']) && strpos($doc['dataUrl'], 'data:') === 0;
        if (!$isNew && isset($doc['id']) && ctype_digit((string) $doc['id'])) {
            $keep[] = (int) $doc['id'];
        }
    }

    // Remove non-photo docs that are no longer present (unlink file + delete row).
    $sel = $pdo->prepare("SELECT document_id, file_path FROM attached_documents WHERE beneficiary_id = :bid AND document_type IS DISTINCT FROM '2x2 ID Picture'");
    $sel->execute([':bid' => $bid]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM attached_documents WHERE document_id = :id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    // Insert the newly-uploaded files.
    $ins = $pdo->prepare(
        "INSERT INTO attached_documents
            (beneficiary_id, beneficiary_service_id, document_source, document_type,
             title, file_name, file_path, file_size, mime_type, uploaded_by)
         VALUES
            (:bid, :bsid, 'Employment Facilitation', :dtype, :title, :fname, :fpath, :size, :mime, :uid)"
    );
    foreach ($docs as $doc) {
        $dtype = $doc['documentType'] ?? '';
        if ($dtype === '2x2 ID Picture') continue;
        $dataUrl = $doc['dataUrl'] ?? '';
        if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) continue;
        $binary = base64_decode($m[2], true);
        if ($binary === false) continue;

        $origName = (string) ($doc['fileName'] ?? 'file');
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
        $stored   = 'ef_' . $bid . '_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) continue;

        $custom = efNull($doc['customName'] ?? '');
        $ins->execute([
            ':bid'   => $bid,
            ':bsid'  => $bsId,
            ':dtype' => ($dtype !== '' ? $dtype : null),
            ':title' => $custom ?? ($dtype !== '' ? $dtype : $origName),
            ':fname' => $origName,
            ':fpath' => 'uploads/' . $stored,
            ':size'  => strlen($binary),
            ':mime'  => $m[1],
            ':uid'   => $uid,
        ]);
    }
}

// Unlink every stored file for a beneficiary's documents (used before deleting).
function employmentUnlinkDocs($pdo, $bid)
{
    $sel = $pdo->prepare("SELECT file_path FROM attached_documents WHERE beneficiary_id = :id");
    $sel->execute([':id' => $bid]);
    foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
}

// Insert all resume sub-tables for a beneficiary from the form payload.
function employmentInsertResumeTables($pdo, $bid, $d)
{
    // Educations: the three fixed levels + any graduate studies. A level is saved
    // when it has any data (including a school name).
    $eduInsert = $pdo->prepare(
        "INSERT INTO educations (beneficiary_id, education_level, school_name, course, year_graduated, year_last_attended, graduated, strand, level_reached, school_type)
         VALUES (:bid, :level, :school, :course, :ygrad, :ylast, :grad, :strand, :lvl, :stype)"
    );
    $levels = [
        ['Elementary', $d['elementary'] ?? null],
        ['Secondary',  $d['secondary'] ?? null],
        ['Tertiary',   $d['tertiary'] ?? null],
    ];
    foreach ($levels as [$levelName, $row]) {
        if (efEduHasData($row)) {
            $eduInsert->execute([
                ':bid'    => $bid,
                ':level'  => $levelName,
                ':school' => efNull($row['schoolName'] ?? ''),
                ':course' => efNull($row['course'] ?? ''),
                ':ygrad'  => efYearOrNull($row['yearGraduated'] ?? ''),
                ':ylast'  => efYearOrNull($row['yearLastAttended'] ?? ''),
                ':grad'   => efYes($row['graduated'] ?? '') ? 'true' : 'false',
                ':strand' => $levelName === 'Secondary' ? efNull($row['seniorHighStrand'] ?? '') : null,
                ':lvl'    => efNull($row['levelReached'] ?? ''),
                ':stype'  => $levelName === 'Secondary' ? efNull($row['type'] ?? '') : null,
            ]);
        }
    }
    foreach (($d['graduateStudies'] ?? []) as $row) {
        if (efEduHasData($row)) {
            $eduInsert->execute([
                ':bid'    => $bid,
                ':level'  => 'Graduate',
                ':school' => efNull($row['schoolName'] ?? ''),
                ':course' => efNull($row['course'] ?? ''),
                ':ygrad'  => efYearOrNull($row['yearGraduated'] ?? ''),
                ':ylast'  => efYearOrNull($row['yearLastAttended'] ?? ''),
                ':grad'   => efYes($row['graduated'] ?? '') ? 'true' : 'false',
                ':strand' => null,
                ':lvl'    => efNull($row['levelReached'] ?? ''),
                ':stype'  => null,
            ]);
        }
    }

    // Trainings.
    $trainInsert = $pdo->prepare(
        "INSERT INTO trainings (beneficiary_id, course, hours_of_training, institution, skills_acquired, certificate_received)
         VALUES (:bid, :course, :hours, :inst, :skills, :cert)"
    );
    foreach (($d['trainings'] ?? []) as $row) {
        if (is_array($row) && efNull($row['course'] ?? '') !== null) {
            $trainInsert->execute([
                ':bid'    => $bid,
                ':course' => trim($row['course']),
                ':hours'  => efIntOrNull($row['hoursOfTraining'] ?? ''),
                ':inst'   => efNull($row['institution'] ?? ''),
                ':skills' => efNull($row['skillsAcquired'] ?? ''),
                ':cert'   => efNull($row['certificateReceived'] ?? ''),
            ]);
        }
    }

    // Eligibilities.
    $eligInsert = $pdo->prepare(
        "INSERT INTO applicant_credentials (beneficiary_id, credential_type, credential_name, date_taken)
         VALUES (:bid, 'Eligibility', :name, :date)"
    );
    foreach (($d['eligibilities'] ?? []) as $row) {
        if (is_array($row) && efNull($row['eligibility'] ?? '') !== null) {
            $eligInsert->execute([
                ':bid'  => $bid,
                ':name' => trim($row['eligibility']),
                ':date' => efDateOrNull($row['dateTaken'] ?? ''),
            ]);
        }
    }

    // Professional licenses.
    $licInsert = $pdo->prepare(
        "INSERT INTO applicant_credentials (beneficiary_id, credential_type, credential_name, valid_until)
         VALUES (:bid, 'License', :name, :valid)"
    );
    foreach (($d['professionalLicenses'] ?? []) as $row) {
        if (is_array($row) && efNull($row['license'] ?? '') !== null) {
            $licInsert->execute([
                ':bid'   => $bid,
                ':name'  => trim($row['license']),
                ':valid' => efDateOrNull($row['validUntil'] ?? ''),
            ]);
        }
    }

    // Work experiences: company, address (city), position, months, status.
    $workInsert = $pdo->prepare(
        "INSERT INTO work_experiences (beneficiary_id, company_name, company_city_id, position, number_of_months, employment_status)
         VALUES (:bid, :company, :cityid, :position, :months, :status)"
    );
    $workStatusValid = ['Permanent', 'Contractual', 'Part-time', 'Probationary'];
    foreach (($d['workExperiences'] ?? []) as $row) {
        if (is_array($row) && (efNull($row['companyName'] ?? '') !== null || efNull($row['position'] ?? '') !== null)) {
            $workInsert->execute([
                ':bid'      => $bid,
                ':company'  => efNull($row['companyName'] ?? ''),
                ':cityid'   => (isset($row['companyCityId']) && is_numeric($row['companyCityId'])) ? (int) $row['companyCityId'] : null,
                ':position' => efNull($row['position'] ?? ''),
                ':months'   => efIntOrNull($row['numberOfMonths'] ?? ''),
                ':status'   => in_array($row['status'] ?? '', $workStatusValid, true) ? $row['status'] : null,
            ]);
        }
    }

    // Job preferences. The location is stored in one column plus a location_type
    // ('Local' / 'Overseas') so the local-vs-overseas distinction is preserved.
    $jpInsert = $pdo->prepare(
        "INSERT INTO job_preferences (beneficiary_id, occupation, employment_type, preferred_location, location_type)
         VALUES (:bid, :occ, :type, :loc, :loctype)"
    );
    $jpType = is_array($d['jobPrefEmploymentType'] ?? null) ? implode(', ', $d['jobPrefEmploymentType']) : '';
    foreach (($d['jobPreferences'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $occupation = efNull($row['occupation'] ?? '');
        $local      = efNull($row['localCity'] ?? '');
        $overseas   = efNull($row['overseasCountry'] ?? '');
        // Keep the row if any of its three fields is filled in, even without an employment type ticked.
        if ($occupation === null && $local === null && $overseas === null) continue;
        // localCity takes priority when both are somehow present.
        $loc     = $local ?? $overseas;
        $locType = $local !== null ? 'Local' : ($overseas !== null ? 'Overseas' : null);
        $jpInsert->execute([
            ':bid'     => $bid,
            ':occ'     => $occupation,
            ':type'    => efNull($jpType),
            ':loc'     => $loc,
            ':loctype' => $locType,
        ]);
    }

    // Languages — only the ones with at least one ability ticked.
    $langInsert = $pdo->prepare(
        "INSERT INTO languages (beneficiary_id, language, can_read, can_write, can_speak, can_understand)
         VALUES (:bid, :lang, :r, :w, :s, :u)"
    );
    foreach (($d['languages'] ?? []) as $row) {
        if (!is_array($row) || efNull($row['language'] ?? '') === null) continue;
        $r = !empty($row['read']); $w = !empty($row['write']); $s = !empty($row['speak']); $u = !empty($row['understand']);
        if (!$r && !$w && !$s && !$u) continue;
        $langInsert->execute([
            ':bid'  => $bid,
            ':lang' => trim($row['language']),
            ':r' => $r ? 'true' : 'false',
            ':w' => $w ? 'true' : 'false',
            ':s' => $s ? 'true' : 'false',
            ':u' => $u ? 'true' : 'false',
        ]);
    }

    // Other skills: predefined checkboxes plus free-text "OTHERS: specify" entries, both stored as plain skill rows.
    $skillInsert = $pdo->prepare("INSERT INTO skills (beneficiary_id, skill_name) VALUES (:bid, :name)");
    $seenSkills = [];
    foreach (array_merge($d['otherSkills'] ?? [], $d['otherSkillsSpecify'] ?? []) as $name) {
        if ($name === 'OTHERS') continue; // UI marker, not a real skill
        $clean = efNull($name);
        if ($clean === null) continue;
        $clean = trim($clean);
        $key = mb_strtolower($clean);
        if (isset($seenSkills[$key])) continue; // avoid duplicate rows
        $seenSkills[$key] = true;
        $skillInsert->execute([':bid' => $bid, ':name' => $clean]);
    }
}

// ─── Read ───────────────────────────────────────────────────────────────────

// GET /api/employment/listApplicants
function employmentListApplicants()
{
    // Every beneficiary that has an EF service enrollment, newest first.
    $stmt = db()->prepare(
        "SELECT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
         GROUP BY b.beneficiary_id
         ORDER BY b.beneficiary_id DESC"
    );
    $stmt->execute([':sid' => efServiceId()]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $out = [];
    foreach ($ids as $bid) {
        $out[] = employmentBuildApplicant((int) $bid);
    }
    json(['status' => 'ok', 'data' => $out]);
}

// Returns an applicant's 2x2 photo as a base64 data URL, or null.
function employmentGetApplicantPhoto($id)
{
    if (!is_numeric($id)) {
        error('Invalid applicant id.', 422);
    }
    $stmt = db()->prepare(
        "SELECT file_path, mime_type FROM attached_documents
         WHERE beneficiary_id = :id AND document_type = '2x2 ID Picture'
         ORDER BY document_id DESC LIMIT 1"
    );
    $stmt->execute([':id' => (int) $id]);
    $row = $stmt->fetch();

    $dataUrl = null;
    if ($row) {
        $abs = __DIR__ . '/../' . $row['file_path'];
        if (is_file($abs)) {
            $mime = $row['mime_type'] ?: 'image/jpeg';
            $dataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
        }
    }
    json(['status' => 'ok', 'data' => ['dataUrl' => $dataUrl]]);
}

// GET /api/employment/getApplicant/{id}
function employmentGetApplicant($id)
{
    if (!is_numeric($id)) {
        error('Invalid applicant id.', 422);
    }
    $applicant = employmentBuildApplicant((int) $id);
    if ($applicant === null) {
        error('Applicant not found.', 404);
    }
    json(['status' => 'ok', 'data' => $applicant]);
}

// Build the frontend Applicant object (summary + fullFormData) for one beneficiary.
function employmentBuildApplicant($bid)
{
    $stmt = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, c.city_id, p.province_name, p.province_id, r.region_name,
                bs.beneficiary_service_id, bs.date_applied
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id AND bs.service_id = :sid
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities    c   ON c.city_id       = bgy.city_id
         LEFT JOIN provinces p   ON p.province_id   = c.province_id
         LEFT JOIN regions   r   ON r.region_id     = p.region_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':sid' => efServiceId(), ':bid' => $bid]);
    $b = $stmt->fetch();
    if (!$b) return null;

    $bsId = (int) $b['beneficiary_service_id'];

    // EF profile.
    $ef = db()->prepare("SELECT * FROM employment_facilitation_profiles WHERE beneficiary_service_id = :id");
    $ef->execute([':id' => $bsId]);
    $ef = $ef->fetch() ?: [];

    // Sub-tables.
    $educations   = employmentFetchAll("SELECT * FROM educations WHERE beneficiary_id = :id ORDER BY education_id", $bid);
    $trainings    = employmentFetchAll("SELECT * FROM trainings WHERE beneficiary_id = :id ORDER BY training_id", $bid);
    // Eligibilities and professional licenses share one table, told apart by credential_type.
    $eligibilities = employmentFetchAll("SELECT * FROM applicant_credentials WHERE beneficiary_id = :id AND credential_type = 'Eligibility' ORDER BY credential_id", $bid);
    $licenses     = employmentFetchAll("SELECT * FROM applicant_credentials WHERE beneficiary_id = :id AND credential_type = 'License' ORDER BY credential_id", $bid);
    $workExp      = employmentFetchAll(
        "SELECT w.*, (c.city_name || ', ' || p.province_name) AS company_city_name
         FROM work_experiences w
         LEFT JOIN cities c    ON c.city_id     = w.company_city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         WHERE w.beneficiary_id = :id ORDER BY w.experience_id",
        $bid
    );
    $jobPrefs     = employmentFetchAll("SELECT * FROM job_preferences WHERE beneficiary_id = :id ORDER BY preference_id", $bid);
    $languages    = employmentFetchAll("SELECT * FROM languages WHERE beneficiary_id = :id ORDER BY language_id", $bid);
    $skills       = employmentFetchAll("SELECT skill_name FROM skills WHERE beneficiary_id = :id ORDER BY skill_id", $bid);
    $disabilities = employmentFetchAll("SELECT disability_name FROM disabilities WHERE beneficiary_id = :id ORDER BY disability_id", $bid);

    // ── Rebuild disabilities (standard checkboxes vs "Other" free text) ──
    $standardDis = ['Visual', 'Speech', 'Hearing', 'Physical', 'Mental'];
    $hasDisability = []; $disabilityOther = '';
    foreach ($disabilities as $row) {
        $name = $row['disability_name'];
        if (in_array($name, $standardDis, true)) {
            $hasDisability[] = $name;
        } else {
            if (!in_array('Other', $hasDisability, true)) $hasDisability[] = 'Other';
            if ($name !== 'Other') $disabilityOther = $name;
        }
    }

    // ── 2x2 photo document → public URL (so the form can display it) ──
    $photo = db()->prepare("SELECT file_path FROM attached_documents WHERE beneficiary_id = :id AND document_type = '2x2 ID Picture' ORDER BY document_id DESC LIMIT 1");
    $photo->execute([':id' => $bid]);
    $photoPath = $photo->fetchColumn();
    $profileImage = $photoPath ? efUploadBaseUrl() . $photoPath : '';

    // Attachments: includes the 2x2 ID Picture (also surfaced separately as profileImage).
    $docRows = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM attached_documents
         WHERE beneficiary_id = :id
         ORDER BY document_id"
    );
    $docRows->execute([':id' => $bid]);
    $savedDocuments = array_map(function ($r) {
        $dtype = $r['document_type'] ?? '';
        return [
            'id'           => (string) $r['document_id'],
            'documentType' => $dtype,
            'customName'   => ($dtype === 'Others (Specify)') ? ($r['title'] ?? '') : null,
            'fileName'     => $r['file_name'],
            'fileSize'     => efFormatBytes($r['file_size'] ?? 0),
            'url'          => efUploadBaseUrl() . $r['file_path'],
        ];
    }, $docRows->fetchAll());

    // ── Rebuild education levels ──
    $eduBlank = ['schoolName' => '', 'course' => '', 'graduated' => '', 'yearGraduated' => '', 'levelReached' => '', 'yearLastAttended' => ''];
    $elem = $eduBlank; $sec = $eduBlank + ['type' => '', 'seniorHighStrand' => '']; $tert = $eduBlank; $grad = [];
    foreach ($educations as $e) {
        $obj = [
            'schoolName'       => $e['school_name'] ?? '',
            'course'           => $e['course'] ?? '',
            'graduated'        => $e['graduated'] ? 'Yes' : 'No',
            'yearGraduated'    => $e['year_graduated'] !== null ? (string) $e['year_graduated'] : '',
            'levelReached'     => $e['level_reached'] ?? '',
            'yearLastAttended' => $e['year_last_attended'] !== null ? (string) $e['year_last_attended'] : '',
        ];
        if ($e['education_level'] === 'Elementary') $elem = $obj;
        elseif ($e['education_level'] === 'Secondary') $sec = $obj + ['type' => $e['school_type'] ?? '', 'seniorHighStrand' => $e['strand'] ?? ''];
        elseif ($e['education_level'] === 'Tertiary') $tert = $obj;
        elseif ($e['education_level'] === 'Graduate') $grad[] = $obj;
    }

    $skillNames = array_map(fn($s) => $s['skill_name'], $skills);

    // Split stored skills back into the form's two inputs: predefined skills go
    // to the checkboxes (otherSkills), the rest to the free-text "OTHERS" list.
    $predefinedSkills = ['AUTO MECHANIC', 'BEAUTICIAN', 'CARPENTRY WORK', 'COMPUTER LITERATE', 'DOMESTIC CHORES',
        'DRIVER', 'ELECTRICIAN', 'EMBROIDERY', 'GARDENING', 'MASONRY', 'PAINTER/ARTIST', 'PAINTING JOBS',
        'PHOTOGRAPHY', 'PLUMBING', 'SEWING DRESSES', 'STENOGRAPHY', 'TAILORING'];
    $otherSkillsChecks = [];
    $otherSkillsCustom = [];
    foreach ($skillNames as $sn) {
        if (in_array($sn, $predefinedSkills, true)) $otherSkillsChecks[] = $sn;
        else $otherSkillsCustom[] = $sn;
    }
    if ($otherSkillsCustom) $otherSkillsChecks[] = 'OTHERS';

    // Split the stored self_employment_type back into the dropdown + "Others" text.
    $selfPredefined = ['Fisherman/Fisherfolk', 'Vendor/Retailer', 'Home-based worker', 'Transport', 'Domestic Worker', 'Freelancer', 'Artisan/Craft Worker'];
    $selfTypeRaw = $ef['self_employment_type'] ?? '';
    $selfEmploymentType = ''; $selfEmploymentOther = '';
    if ($selfTypeRaw !== '' && $selfTypeRaw !== null) {
        if (in_array($selfTypeRaw, $selfPredefined, true)) { $selfEmploymentType = $selfTypeRaw; }
        else { $selfEmploymentType = 'Others'; $selfEmploymentOther = $selfTypeRaw; }
    }

    // ── fullFormData (ApplicantFormData shape; unmapped fields default empty) ──
    $full = [
        'surname' => $b['last_name'], 'firstName' => $b['first_name'], 'middleName' => $b['middle_name'] ?? '', 'suffix' => $b['suffix'] ?? '',
        'dateOfBirth' => $b['birth_date'], 'sex' => $b['sex'], 'religion' => $ef['religion'] ?? '', 'civilStatus' => $b['civil_status'],
        'height' => isset($ef['height_cm']) && $ef['height_cm'] !== null ? (string) $ef['height_cm'] : '',
        'houseNo' => $b['street_address'] ?? '',
        'barangay' => $b['barangay_name'] ?? '', 'barangayId' => $b['barangay_id'] !== null ? (int) $b['barangay_id'] : null,
        'municipality' => $b['city_name'] ?? '', 'cityId' => isset($b['city_id']) && $b['city_id'] !== null ? (int) $b['city_id'] : null,
        'province' => $b['province_name'] ?? '', 'provinceId' => isset($b['province_id']) && $b['province_id'] !== null ? (int) $b['province_id'] : null,
        'hasDisability' => $hasDisability, 'disabilityOther' => $disabilityOther,
        'tin' => $ef['tin'] ?? '', 'contactNumber' => $b['contact_no'] ?? '', 'email' => $b['email'] ?? '',
        'isOFW' => !empty($ef['is_ofw']) ? 'Yes' : 'No', 'ofwCountry' => $ef['ofw_country'] ?? '',
        'isFormerOFW' => !empty($ef['is_former_ofw']) ? 'Yes' : 'No', 'formerOFWCountry' => $ef['former_ofw_country'] ?? '',
        'formerOFWReturnDate' => $ef['former_ofw_return_date'] ?? '',
        'is4PsBeneficiary' => !empty($b['is_4ps_beneficiary']) ? 'Yes' : 'No', 'householdIdNo' => $ef['household_id_no'] ?? '',
        'employmentStatus' => $ef['employment_status'] ?? '', 'employmentType' => $ef['employment_type'] ?? '',
        'selfEmploymentType' => $selfEmploymentType, 'selfEmploymentOther' => $selfEmploymentOther,
        'unemploymentReason' => $ef['unemployment_reason'] ?? '', 'unemploymentReasonOther' => $ef['other_reason'] ?? '',
        'monthsLookingForWork' => isset($ef['months_looking_for_work']) && $ef['months_looking_for_work'] !== null ? (string) $ef['months_looking_for_work'] : '',
        'jobPrefEmploymentType' => !empty($jobPrefs) && !empty($jobPrefs[0]['employment_type'])
            ? array_values(array_filter(array_map('trim', explode(',', $jobPrefs[0]['employment_type']))))
            : [],
        'jobPrefWorkLocation' => array_values(array_unique(array_filter(array_map(fn($j) => $j['location_type'] ?? null, $jobPrefs)))),
        'jobPreferences' => array_map(fn($j) => [
            'occupation'      => $j['occupation'] ?? '',
            // Route the single stored location to the right field by its type.
            // Legacy rows (no location_type) default to localCity, as before.
            'localCity'       => ($j['location_type'] ?? '') === 'Overseas' ? '' : ($j['preferred_location'] ?? ''),
            'overseasCountry' => ($j['location_type'] ?? '') === 'Overseas' ? ($j['preferred_location'] ?? '') : '',
        ], $jobPrefs),
        'languages' => array_map(fn($l) => ['language' => $l['language'], 'read' => (bool) $l['can_read'], 'write' => (bool) $l['can_write'], 'speak' => (bool) $l['can_speak'], 'understand' => (bool) $l['can_understand']], $languages),
        'currentlyInSchool' => !empty($ef['currently_in_school']) ? 'Yes' : 'No',
        'elementary' => $elem, 'secondary' => $sec, 'tertiary' => $tert, 'graduateStudies' => $grad,
        'trainings' => array_map(fn($t) => ['course' => $t['course'], 'hoursOfTraining' => $t['hours_of_training'] !== null ? (string) $t['hours_of_training'] : '', 'institution' => $t['institution'] ?? '', 'skillsAcquired' => $t['skills_acquired'] ?? '', 'certificateReceived' => $t['certificate_received'] ?? ''], $trainings),
        'eligibilities' => array_map(fn($e) => ['eligibility' => $e['credential_name'], 'dateTaken' => $e['date_taken'] ?? ''], $eligibilities),
        'professionalLicenses' => array_map(fn($l) => ['license' => $l['credential_name'], 'validUntil' => $l['valid_until'] ?? ''], $licenses),
        'workExperiences' => array_map(fn($w) => [
            'companyName' => $w['company_name'] ?? '',
            'companyCity' => $w['company_city_name'] ?? '',
            'companyCityId' => isset($w['company_city_id']) && $w['company_city_id'] !== null ? (int) $w['company_city_id'] : null,
            'position' => $w['position'] ?? '',
            'numberOfMonths' => isset($w['number_of_months']) && $w['number_of_months'] !== null ? (string) $w['number_of_months'] : '',
            'status' => $w['employment_status'] ?? '',
        ], $workExp),
        'otherSkills' => $otherSkillsChecks, 'otherSkillsSpecify' => $otherSkillsCustom ?: [''],
        // Referred program (stored on the EF profile).
        'referredProgram' => $ef['referred_program'] ?? '', 'referredProgramOther' => $ef['other_referred_program'] ?? '',
        // 2x2 photo (stored in the documents table).
        'profileImage' => $profileImage,
        // Cross-program project detail — belongs to those program modules, not EF.
        'cdspPrograms' => [], 'livelihoodPrograms' => [], 'dileepPrograms' => [],
        'projectIdNumber' => '', 'projectLocation' => '', 'projectRegion' => '', 'projectCity' => '',
        'projectDetails' => ['type' => [], 'programComponent' => [], 'wayOfImplementation' => [], 'nameOfProject' => ''],
        'otherProgramName' => '', 'otherProgramNo' => '',
        'documentType' => '', 'documentOtherSpecify' => '', 'savedDocuments' => $savedDocuments,
    ];

    // ── Summary fields for the table/filters ──
    $mi  = (!empty($b['middle_name'])) ? ' ' . substr($b['middle_name'], 0, 1) . '.' : '';
    $suf = (!empty($b['suffix'])) ? ' ' . $b['suffix'] : '';
    $name = $b['last_name'] . ', ' . $b['first_name'] . $mi . $suf;
    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (int) (new DateTime())->diff(new DateTime($b['birth_date']))->y;
    }
    // Prefer the tertiary course; otherwise use the derived educational_attainment.
    $education = $tert['course'] ? $tert['course'] . ' (Tertiary)'
        : ($b['educational_attainment'] ?? '');

    // All languages with their abilities, e.g. "ENGLISH (Read, Write), FILIPINO (Speak)".
    $languageSummary = implode(', ', array_map(function ($l) {
        $abil = [];
        if (!empty($l['can_read'])) $abil[] = 'Read';
        if (!empty($l['can_write'])) $abil[] = 'Write';
        if (!empty($l['can_speak'])) $abil[] = 'Speak';
        if (!empty($l['can_understand'])) $abil[] = 'Understand';
        return $l['language'] . ($abil ? ' (' . implode(', ', $abil) . ')' : '');
    }, $languages));

    return [
        'id' => (int) $b['beneficiary_id'],
        'name' => $name,
        'gender' => $b['sex'],
        'age' => $age,
        'education' => $education,
        'skills' => implode(', ', $skillNames),
        'trainingCourses' => implode(', ', array_filter(array_map(fn($t) => trim($t['course'] ?? ''), $trainings))),
        'employmentStatus' => $ef['employment_status'] ?? 'Unemployed',
        'contactNumber' => $b['contact_no'] ?? '',
        'email' => $b['email'] ?? '',
        'address' => implode(', ', array_filter([$b['barangay_name'] ?? '', $b['city_name'] ?? '', $b['province_name'] ?? ''])),
        'civilStatus' => $b['civil_status'],
        'hasDisability' => count($disabilities) > 0,
        'isOFW' => !empty($ef['is_ofw']),
        'isFormerOFW' => !empty($ef['is_former_ofw']),
        'is4PsBeneficiary' => !empty($b['is_4ps_beneficiary']),
        'jobPreference' => $jobPrefs[0]['occupation'] ?? '',
        'language' => $languageSummary,
        'referralState' => efApplicantReferralState($bsId),
        'dateApplicationReceived' => $b['date_applied'] ?? '',
        'fullFormData' => $full,
    ];
}

// Returns an applicant's refer-action lock state: Hired, Referred, or Refer.
function efApplicantReferralState($bsId)
{
    $pl = db()->prepare(
        "SELECT 1 FROM employment_facilitation_placements
         WHERE beneficiary_service_id = :id AND status = 'Active' LIMIT 1"
    );
    $pl->execute([':id' => (int) $bsId]);
    if ($pl->fetchColumn()) return 'Hired';

    $rf = db()->prepare(
        "SELECT 1 FROM employment_facilitation_referrals
         WHERE beneficiary_service_id = :id AND status IN ('Pending', 'Interviewed') AND deleted_at IS NULL LIMIT 1"
    );
    $rf->execute([':id' => (int) $bsId]);
    if ($rf->fetchColumn()) return 'Referred';

    return 'Refer';
}

// Runs a prepared query by beneficiary id and returns all rows.
function employmentFetchAll($sql, $bid)
{
    $stmt = db()->prepare($sql);
    $stmt->execute([':id' => $bid]);
    return $stmt->fetchAll();
}

// ─── Update ─────────────────────────────────────────────────────────────────

// POST /api/employment/updateApplicant/{id}   body = ApplicantFormData
function employmentUpdateApplicant($id)
{
    $uid = requireLogin();
    if (!is_numeric($id)) {
        error('Invalid applicant id.', 422);
    }
    $bid = (int) $id;
    $d   = body();

    $errors = employmentValidateApplicant($d);
    if ($errors) {
        error($errors, 422);
    }

    // Confirm this beneficiary is an EF applicant.
    $bs = db()->prepare(
        "SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :bid AND service_id = :sid LIMIT 1"
    );
    $bs->execute([':bid' => $bid, ':sid' => efServiceId()]);
    $bsId = $bs->fetchColumn();
    if ($bsId === false) {
        error('Applicant not found.', 404);
    }
    $bsId = (int) $bsId;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare(
            "UPDATE beneficiaries SET
                first_name = :first, middle_name = :middle, last_name = :last, suffix = :suffix,
                sex = :sex, birth_date = :bdate, civil_status = :civil, street_address = :street,
                barangay_id = :bgy, contact_no = :contact, email = :email,
                is_4ps_beneficiary = :is4ps, educational_attainment = :educ, updated_at = now()
             WHERE beneficiary_id = :bid"
        );
        $upd->execute([
            ':first' => trim($d['firstName']), ':middle' => efNull($d['middleName'] ?? ''),
            ':last' => trim($d['surname']), ':suffix' => efNull($d['suffix'] ?? ''),
            ':sex' => $d['sex'], ':bdate' => $d['dateOfBirth'], ':civil' => $d['civilStatus'],
            ':street' => efNull($d['houseNo'] ?? ''), ':bgy' => (int) $d['barangayId'],
            ':contact' => efNull($d['contactNumber'] ?? ''), ':email' => efNull($d['email'] ?? ''),
            ':is4ps' => efYes($d['is4PsBeneficiary'] ?? '') ? 'true' : 'false', ':educ' => efDeriveEducation($d), ':bid' => $bid,
        ]);

        // Replace the EF profile + all resume rows (simplest correct update).
        $pdo->prepare("DELETE FROM employment_facilitation_profiles WHERE beneficiary_service_id = :id")->execute([':id' => $bsId]);
        employmentInsertEfProfile($pdo, $bsId, $d);

        foreach (['educations', 'trainings', 'applicant_credentials', 'work_experiences', 'job_preferences', 'languages', 'skills'] as $t) {
            $pdo->prepare("DELETE FROM {$t} WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        }
        employmentInsertResumeTables($pdo, $bid, $d);

        // Disabilities + 2x2 photo (separate tables).
        $pdo->prepare("DELETE FROM disabilities WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        employmentInsertDisabilities($pdo, $bid, $d);
        employmentUpdatePhoto($pdo, $bid, $bsId, $uid, $d);
        employmentSyncDocuments($pdo, $bid, $bsId, $uid, $d);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update applicant. Please try again.', 500);
    }

    logActivity($uid, 'Update Applicant', 'employment', "Updated applicant: " . trim($d['surname']) . ", " . trim($d['firstName']));
    json(['status' => 'ok', 'message' => 'Applicant updated.', 'data' => ['id' => $bid]]);
}

// ─── Delete ─────────────────────────────────────────────────────────────────

// Soft-deletes an EF applicant.
function employmentDeleteApplicant($id)
{
    $uid = requireLogin();
    if (!is_numeric($id)) {
        error('Invalid applicant id.', 422);
    }
    $bid = (int) $id;

    // Must be an EF applicant that is not already in the recycle bin.
    $bs = db()->prepare(
        "SELECT bs.beneficiary_service_id
         FROM beneficiary_services bs
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         WHERE bs.beneficiary_id = :bid AND bs.service_id = :sid AND b.deleted_at IS NULL
         LIMIT 1"
    );
    $bs->execute([':bid' => $bid, ':sid' => efServiceId()]);
    $bsId = $bs->fetchColumn();
    if ($bsId === false) {
        error('Applicant not found.', 404);
    }

    // Cannot delete an applicant with a live engagement — same lock the system
    // already uses to prevent re-referral. Resolve the referral/placement first.
    $state = efApplicantReferralState((int) $bsId);
    if ($state === 'Hired')    error('This applicant cannot be deleted because they are currently placed in a job. Update the placement status first.', 409);
    if ($state === 'Referred') error('This applicant cannot be deleted because they have an active referral. Resolve the referral first.', 409);

    $nameS = db()->prepare("SELECT first_name, last_name FROM beneficiaries WHERE beneficiary_id=:id");
    $nameS->execute([':id' => $bid]);
    $nameRow = $nameS->fetch();

    try {
        db()->prepare("UPDATE beneficiaries SET deleted_at = now(), deleted_by = :uid WHERE beneficiary_id = :id")
            ->execute([':uid' => $uid, ':id' => $bid]);
    } catch (Throwable $e) {
        error('Failed to delete applicant. Please try again.', 500);
    }

    logActivity($uid, 'Delete Applicant', 'employment', "Moved to recycle bin: " . ($nameRow ? $nameRow['last_name'] . ', ' . $nameRow['first_name'] : "#{$bid}"));
    json(['status' => 'ok', 'message' => 'Applicant moved to recycle bin.']);
}

// Counts an applicant's placement/referral history (used before a purge).
function efApplicantHistoryCounts($bid)
{
    $pdo = db();
    $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
    $bsStmt->execute([':id' => $bid, ':sid' => efServiceId()]);
    $bsId = $bsStmt->fetchColumn();
    if ($bsId === false) return ['placements' => 0, 'referrals' => 0];
    $bsId = (int) $bsId;

    $pl = $pdo->prepare("SELECT COUNT(*) FROM employment_facilitation_placements WHERE beneficiary_service_id = :id");
    $pl->execute([':id' => $bsId]);
    $rf = $pdo->prepare("SELECT COUNT(*) FROM employment_facilitation_referrals WHERE beneficiary_service_id = :id");
    $rf->execute([':id' => $bsId]);

    return ['placements' => (int) $pl->fetchColumn(), 'referrals' => (int) $rf->fetchColumn()];
}

// Permanently deletes an EF applicant and all related data.
function employmentHardDeleteApplicant($bid)
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $bsStmt = $pdo->prepare("SELECT beneficiary_service_id FROM beneficiary_services WHERE beneficiary_id = :id AND service_id = :sid LIMIT 1");
        $bsStmt->execute([':id' => $bid, ':sid' => efServiceId()]);
        $bsId = $bsStmt->fetchColumn();
        if ($bsId !== false) {
            $bsId = (int) $bsId;
            // Placements must be deleted before referrals: placements.referral_id is ON DELETE RESTRICT.
            $pdo->prepare("DELETE FROM employment_facilitation_placements WHERE beneficiary_service_id = :id")->execute([':id' => $bsId]);
            $pdo->prepare("DELETE FROM employment_facilitation_referrals WHERE beneficiary_service_id = :id")->execute([':id' => $bsId]);
            $pdo->prepare("DELETE FROM employment_facilitation_profiles WHERE beneficiary_service_id = :id")->execute([':id' => $bsId]);
        }
        employmentUnlinkDocs($pdo, $bid); // unlink all document files first
        foreach (['educations', 'trainings', 'applicant_credentials', 'work_experiences', 'job_preferences', 'languages', 'skills', 'beneficiary_classifications', 'disabilities', 'attached_documents'] as $t) {
            $pdo->prepare("DELETE FROM {$t} WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        }
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to permanently delete applicant. Please try again.', 500);
    }
}

// Employers

// Industry helpers (employers.industry is a plain text column)

// Normalizes an industry name's spelling and casing before it is saved.
function efNormalizeIndustry($name)
{
    $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name)) : '';
    if ($name === '') return null;
    foreach (efStandardIndustries() as $std) {
        if (mb_strtolower($std) === mb_strtolower($name)) return $std;
    }
    $sel = db()->prepare("SELECT industry FROM employers WHERE industry IS NOT NULL AND lower(industry) = lower(:n) ORDER BY employer_id LIMIT 1");
    $sel->execute([':n' => $name]);
    $existing = $sel->fetchColumn();
    return $existing !== false ? $existing : $name;
}

// Resolve the effective industry name from form data (handles the "Other" case).
function efResolveIndustryName($d)
{
    $ind = efNull($d['industry'] ?? '');
    if ($ind === null) return null;
    if (strcasecmp($ind, 'Other') === 0) return efNormalizeIndustry($d['industryOther'] ?? '');
    return efNormalizeIndustry($ind);
}

// Returns the standard and custom industry names for dropdowns and filters.
function efListIndustries()
{
    $standard = efStandardIndustries();
    $lower = array_map('mb_strtolower', $standard);
    $rows = db()->query(
        "SELECT DISTINCT industry FROM employers
         WHERE deleted_at IS NULL AND industry IS NOT NULL AND btrim(industry) <> ''
         ORDER BY industry"
    )->fetchAll(PDO::FETCH_COLUMN);
    $custom = [];
    foreach ($rows as $name) {
        $l = mb_strtolower($name);
        if ($l === 'other' || in_array($l, $lower, true)) continue;
        $custom[] = $name;
    }
    usort($custom, fn($a, $b) => strcasecmp($a, $b));
    json(['status' => 'ok', 'data' => ['standard' => $standard, 'custom' => $custom]]);
}

// Standard industry names (used when reading back to detect "Other" case).
function efStandardIndustries()
{
    return ['Manufacturing', 'Information Technology', 'Agriculture', 'Retail', 'Healthcare',
            'Education', 'Construction', 'Hospitality', 'Transportation', 'Financial Services'];
}

// Returns the standard spelling of an industry name, or null if it isn't one.
function efStandardIndustryMatch($name)
{
    foreach (efStandardIndustries() as $std) {
        if (strcasecmp($std, (string) $name) === 0) return $std;
    }
    return null;
}

// Build the frontend Employer object from a DB row (with joined fields).
function efBuildEmployer($row)
{
    $industryName  = $row['industry'] ?? '';
    $standardMatch = efStandardIndustryMatch($industryName);   // the standard spelling, if it is one

    return [
        'id'                => (int) $row['employer_id'],
        'companyName'       => $row['company_name'],
        // industry / industryOther drive the form's dropdown + "specify" box; industryName is
        // the real name, for lists, filters and exports (a custom industry shows as itself).
        'industry'          => $standardMatch ?? ($industryName !== '' ? 'Other' : ''),
        'industryOther'     => ($standardMatch === null && $industryName !== '') ? $industryName : '',
        'industryName'      => $standardMatch ?? $industryName,
        'businessType'      => $row['business_type'] ?? '',
        'tinNumber'         => $row['tin_number'] ?? '',
        'contactPersonName' => $row['contact_person_name'] ?? '',
        'position'          => $row['contact_person_position'] ?? '',
        'contactNumber'     => $row['contact_number'] ?? '',
        'email'             => $row['email_address'] ?? '',
        'buildingNo'        => $row['building_no'] ?? '',
        'street'            => $row['street'] ?? '',
        'barangay'          => $row['barangay_name'] ?? '',
        'city'              => $row['city_name'] ?? '',
        'province'          => $row['province_name'] ?? '',
        'region'            => $row['region_name'] ?? '',
        'barangayId'        => $row['barangay_id'] !== null ? (int) $row['barangay_id'] : null,
        'cityId'            => isset($row['city_id']) && $row['city_id'] !== null ? (int) $row['city_id'] : null,
        'provinceId'        => isset($row['province_id']) && $row['province_id'] !== null ? (int) $row['province_id'] : null,
        'status'            => $row['status'],
        'dateRegistered'    => $row['date_registered'],
        'remarks'           => $row['remarks'] ?? '',
    ];
}

// ─── Employer CRUD ───────────────────────────────────────────────────────────

// GET /api/employment/listEmployers
function efListEmployers()
{
    $stmt = db()->prepare(
        "SELECT e.*,
                bgy.barangay_name, c.city_name, c.city_id, p.province_name, p.province_id, r.region_name
         FROM employers e
         LEFT JOIN barangays  bgy ON bgy.barangay_id = e.barangay_id
         LEFT JOIN cities     c   ON c.city_id        = bgy.city_id
         LEFT JOIN provinces  p   ON p.province_id    = c.province_id
         LEFT JOIN regions    r   ON r.region_id      = p.region_id
         WHERE e.deleted_at IS NULL
         ORDER BY e.employer_id DESC"
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = efBuildEmployer($row);
    }
    json(['status' => 'ok', 'data' => $out]);
}

// Validates employer input, returning an error string or ''.
function efValidateEmployer($d)
{
    if (efNull($d['companyName']      ?? '') === null) return 'Company name is required.';
    if (efNull($d['tinNumber']        ?? '') === null) return 'TIN number is required.';
    if (efNull($d['contactPersonName'] ?? '') === null) return 'Contact person name is required.';
    if (efNull($d['contactNumber']    ?? '') === null) return 'Contact number is required.';
    if (efNull($d['dateRegistered']   ?? '') === null) return 'Date registered is required.';
    if (efDateOrNull($d['dateRegistered'] ?? '') === null) return 'Invalid date registered format.';
    if (efDateOrNull($d['dateRegistered'] ?? '') > date('Y-m-d')) return 'Date registered cannot be in the future.';
    // "Other" needs the industry typed in -- otherwise nothing would be saved, silently.
    $ind = efNull($d['industry'] ?? '');
    if ($ind !== null && strcasecmp($ind, 'Other') === 0) {
        $other = trim(preg_replace('/\s+/u', ' ', (string) ($d['industryOther'] ?? '')));
        if ($other === '' || strcasecmp($other, 'Other') === 0) return 'Please specify the industry when "Other" is selected.';
    }
    return '';
}

// Binds employer form fields onto a prepared insert/update statement.
function efBindEmployer($stmt, $d, $extra = [])
{
    $industryName = efResolveIndustryName($d);

    $params = array_merge([
        ':name'    => trim($d['companyName']),
        ':industry' => $industryName,
        ':tin'     => efNull($d['tinNumber'] ?? ''),
        ':cname'   => trim($d['contactPersonName']),
        ':cpos'    => efNull($d['position'] ?? ''),
        ':cnum'    => trim($d['contactNumber']),
        ':email'   => efNull($d['email'] ?? ''),
        ':bldg'    => efNull($d['buildingNo'] ?? ''),
        ':street'  => efNull($d['street'] ?? ''),
        ':bgid'    => (isset($d['barangayId']) && is_numeric($d['barangayId'])) ? (int) $d['barangayId'] : null,
        ':status'  => in_array($d['status'] ?? '', ['Active', 'Inactive'], true) ? $d['status'] : 'Active',
        ':dreg'    => $d['dateRegistered'],
        ':remarks' => efNull($d['remarks'] ?? ''),
        ':btype'   => efNull($d['businessType'] ?? ''),
    ], $extra);

    $stmt->execute($params);
}

// POST /api/employment/createEmployer
function efCreateEmployer()
{
    $d = body();
    $err = efValidateEmployer($d);
    if ($err) error($err, 422);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO employers
                (company_name, industry, tin_number,
                 contact_person_name, contact_person_position, contact_number, email_address,
                 building_no, street, barangay_id, status, date_registered, remarks, business_type)
             VALUES
                (:name, :industry, :tin,
                 :cname, :cpos, :cnum, :email,
                 :bldg, :street, :bgid, :status, :dreg, :remarks, :btype)
             RETURNING employer_id"
        );
        efBindEmployer($stmt, $d);
        $id = (int) $stmt->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save employer. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Create Employer', 'employment', "Created employer: " . trim($d['companyName']));
    json(['status' => 'ok', 'message' => 'Employer saved.', 'data' => ['id' => $id]]);
}

// Bulk-imports employers.
function efImportEmployersBulk()
{
    $body = body();
    $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];

    if (!$rows) {
        error('No rows to import.', 422);
    }

    $errors = [];
    foreach ($rows as $i => $d) {
        $err = efValidateEmployer($d);
        if ($err) $errors[] = ['row' => $i, 'error' => $err];
    }
    if ($errors) {
        error('Validation failed. No records were imported.', 422, ['errors' => $errors]);
    }

    $pdo = db();
    $ids = [];
    try {
        $pdo->beginTransaction();
        foreach ($rows as $d) {
            $stmt = $pdo->prepare(
                "INSERT INTO employers
                    (company_name, industry, tin_number,
                     contact_person_name, contact_person_position, contact_number, email_address,
                     building_no, street, barangay_id, status, date_registered, remarks, business_type)
                 VALUES
                    (:name, :industry, :tin,
                     :cname, :cpos, :cnum, :email,
                     :bldg, :street, :bgid, :status, :dreg, :remarks, :btype)
                 RETURNING employer_id"
            );
            efBindEmployer($stmt, $d);
            $ids[] = (int) $stmt->fetchColumn();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save employers. No records were imported.', 500, [
            'failedIndex' => count($ids),
            'reason'      => 'An unexpected error occurred while saving this record.',
        ]);
    }

    $uid = currentUserId();
    foreach ($rows as $d) {
        logActivity($uid, 'Create Employer', 'employment', "Created employer: " . trim($d['companyName']));
    }

    json(['status' => 'ok', 'message' => 'Employers saved.', 'data' => ['ids' => $ids, 'count' => count($ids)]]);
}

// POST /api/employment/updateEmployer/{id}
function efUpdateEmployer($id)
{
    if (!is_numeric($id)) error('Invalid employer id.', 422);
    $d = body();
    $err = efValidateEmployer($d);
    if ($err) error($err, 422);

    try {
        $stmt = db()->prepare(
            "UPDATE employers SET
                company_name = :name, industry = :industry, tin_number = :tin,
                contact_person_name = :cname, contact_person_position = :cpos,
                contact_number = :cnum, email_address = :email,
                building_no = :bldg, street = :street, barangay_id = :bgid,
                status = :status, date_registered = :dreg, remarks = :remarks,
                business_type = :btype, updated_at = now()
             WHERE employer_id = :eid"
        );
        efBindEmployer($stmt, $d, [':eid' => (int) $id]);
    } catch (Throwable $e) {
        error('Failed to update employer. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Update Employer', 'employment', "Updated employer: " . trim($d['companyName']));
    json(['status' => 'ok', 'message' => 'Employer updated.']);
}

// POST /api/employment/deleteEmployer/{id}  — soft delete (moves to recycle bin)
function efDeleteEmployer($id)
{
    $uid = requireLogin();
    if (!is_numeric($id)) error('Invalid employer id.', 422);

    $check = db()->prepare("SELECT COUNT(*) FROM vacancies WHERE employer_id = :id");
    $check->execute([':id' => (int) $id]);
    if ((int) $check->fetchColumn() > 0) {
        error('Cannot delete: this employer has vacancies. Remove the vacancies first.', 409);
    }

    $nameS = db()->prepare("SELECT company_name FROM employers WHERE employer_id=:id");
    $nameS->execute([':id' => (int) $id]);
    $name = $nameS->fetchColumn();

    try {
        $stmt = db()->prepare("UPDATE employers SET deleted_at = now(), deleted_by = :uid WHERE employer_id = :id AND deleted_at IS NULL");
        $stmt->execute([':uid' => $uid, ':id' => (int) $id]);
    } catch (Throwable $e) {
        error('Failed to delete employer. Please try again.', 500);
    }
    if ($stmt->rowCount() === 0) error('Employer not found.', 404);

    logActivity($uid, 'Delete Employer', 'employment', "Moved to recycle bin: {$name}");
    json(['status' => 'ok', 'message' => 'Employer moved to recycle bin.']);
}

// Permanently remove an employer row. Used by the recycle bin's permanent-delete.
function employmentHardDeleteEmployer($id)
{
    db()->prepare("DELETE FROM employers WHERE employer_id = :id")->execute([':id' => (int) $id]);
}

// Vacancies

// Map frontend job type to the vacancy_job_type_enum (Full-time/Part-time/Contract).
function efJobType($jobType)
{
    $valid = ['Full-time', 'Part-time', 'Contract'];
    return in_array($jobType, $valid, true) ? $jobType : 'Contract';
}

// Formats numeric salary bounds into a display string, or '' if both are null.
function efFormatSalary($min, $max)
{
    $peso = function ($n) {
        $f = (float) $n;
        return '₱' . ($f == floor($f) ? number_format($f) : number_format($f, 2));
    };
    if ($min === null && $max === null) return '';
    if ($min !== null && $max !== null) return $peso($min) . ' - ' . $peso($max);
    return $peso($min ?? $max);
}

// Coerce an incoming salary bound to a numeric value or null (tolerates ₱/commas).
function efSalaryNum($v)
{
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return $v + 0;
    $clean = preg_replace('/[^0-9.]/', '', (string) $v);
    return $clean === '' ? null : $clean + 0;
}

// Builds the frontend Vacancy object from a DB row.
function efBuildVacancy($row)
{
    $min = $row['salary_min'] ?? null;
    $max = $row['salary_max'] ?? null;

    $slotsTotal = (int) ($row['slots_total'] ?? 0);
    $active    = isset($row['active_placements']) ? (int) $row['active_placements'] : 0;
    $remaining = max(0, $slotsTotal - $active);
    $manual    = $row['status'];
    $effective = ($manual === 'Closed' || $remaining === 0) ? 'Closed' : 'Open';

    return [
        'id'             => (int) $row['vacancy_id'],
        'jobTitle'       => $row['job_title'],
        'employer'       => $row['company_name'] ?? '',
        'employerId'     => (int) $row['employer_id'],
        'vacanciesCount' => $remaining,   // remaining openings (derived)
        'slotsTotal'     => $slotsTotal,  // original openings solicited
        'industry'       => efStandardIndustryMatch($row['industry'] ?? '') ?? ($row['industry'] ?? ''),
        'jobType'        => $row['job_type'],
        'salaryMin'      => $min !== null ? (float) $min : null,
        'salaryMax'      => $max !== null ? (float) $max : null,
        // Display string derived from the atomic bounds (source of truth).
        'salaryRange'    => efFormatSalary($min, $max),
        'description'    => $row['description'] ?? '',
        'requirements'   => $row['requirements'] ?? '',
        'status'         => $effective,   // effective (display/filter/referral)
        'manualStatus'   => $manual,      // officer intent (drives the toggle)
    ];
}

// ─── Vacancy CRUD ────────────────────────────────────────────────────────────

// GET /api/employment/listVacancies
function efListVacancies()
{
    $stmt = db()->prepare(
        "SELECT v.*, e.company_name, e.industry,
                (SELECT COUNT(*) FROM employment_facilitation_placements p
                 WHERE p.vacancy_id = v.vacancy_id AND p.status = 'Active') AS active_placements
         FROM vacancies v
         JOIN employers e    ON e.employer_id  = v.employer_id
         ORDER BY v.vacancy_id DESC"
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = efBuildVacancy($row);
    }
    json(['status' => 'ok', 'data' => $out]);
}

// Validates vacancy input, returning an error string or ''.
function efValidateVacancy($d)
{
    if (efNull($d['jobTitle'] ?? '') === null) return 'Job title is required.';
    if (!isset($d['employerId']) || !is_numeric($d['employerId'])) return 'Please select an employer.';
    return '';
}

// POST /api/employment/createVacancy
function efCreateVacancy()
{
    $d = body();
    $err = efValidateVacancy($d);
    if ($err) error($err, 422);

    $emp = db()->prepare("SELECT 1 FROM employers WHERE employer_id = :id");
    $emp->execute([':id' => (int) $d['employerId']]);
    if (!$emp->fetchColumn()) error('Employer not found.', 404);

    $salMin = efSalaryNum($d['salaryMin'] ?? null);
    $salMax = efSalaryNum($d['salaryMax'] ?? null);
    if ($salMin !== null && $salMax !== null && $salMin > $salMax) {
        error('Minimum salary cannot exceed maximum salary.', 422);
    }

    try {
        $stmt = db()->prepare(
            // slots_total = original openings (source of truth). Remaining is derived.
            "INSERT INTO vacancies
                (employer_id, job_title, slots_total, job_type,
                 salary_min, salary_max, description, requirements, status)
             VALUES
                (:eid, :title, :cnt, :jtype,
                 :smin, :smax, :desc, :req, 'Open')
             RETURNING vacancy_id"
        );
        $stmt->execute([
            ':eid'    => (int) $d['employerId'],
            ':title'  => trim($d['jobTitle']),
            ':cnt'    => is_numeric($d['vacanciesCount'] ?? 1) ? (int) $d['vacanciesCount'] : 1,
            ':jtype'  => efJobType($d['jobType'] ?? 'Full-time'),
            ':smin'   => $salMin,
            ':smax'   => $salMax,
            ':desc'   => trim($d['description'] ?? ''),
            ':req'    => trim($d['requirements'] ?? ''),
        ]);
        $id = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error('Failed to save vacancy. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Create Vacancy', 'employment', "Created vacancy: " . trim($d['jobTitle']));
    json(['status' => 'ok', 'message' => 'Vacancy saved.', 'data' => ['id' => $id]]);
}

// POST /api/employment/updateVacancy/{id}
function efUpdateVacancy($id)
{
    if (!is_numeric($id)) error('Invalid vacancy id.', 422);
    $d = body();
    $err = efValidateVacancy($d);
    if ($err) error($err, 422);

    $emp = db()->prepare("SELECT 1 FROM employers WHERE employer_id = :id");
    $emp->execute([':id' => (int) $d['employerId']]);
    if (!$emp->fetchColumn()) error('Employer not found.', 404);

    $salMin = efSalaryNum($d['salaryMin'] ?? null);
    $salMax = efSalaryNum($d['salaryMax'] ?? null);
    if ($salMin !== null && $salMax !== null && $salMin > $salMax) {
        error('Minimum salary cannot exceed maximum salary.', 422);
    }

    try {
        $stmt = db()->prepare(
            "UPDATE vacancies SET
                employer_id = :eid, job_title = :title, slots_total = :cnt,
                job_type = :jtype, salary_min = :smin, salary_max = :smax,
                description = :desc, requirements = :req, updated_at = now()
             WHERE vacancy_id = :vid"
        );
        $stmt->execute([
            ':eid'    => (int) $d['employerId'],
            ':title'  => trim($d['jobTitle']),
            ':cnt'    => is_numeric($d['vacanciesCount'] ?? 1) ? (int) $d['vacanciesCount'] : 1,
            ':jtype'  => efJobType($d['jobType'] ?? 'Full-time'),
            ':smin'   => $salMin,
            ':smax'   => $salMax,
            ':desc'   => trim($d['description'] ?? ''),
            ':req'    => trim($d['requirements'] ?? ''),
            ':vid'    => (int) $id,
        ]);
    } catch (Throwable $e) {
        error('Failed to update vacancy. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Update Vacancy', 'employment', "Updated vacancy: " . trim($d['jobTitle']));
    json(['status' => 'ok', 'message' => 'Vacancy updated.']);
}

// POST /api/employment/toggleVacancyStatus/{id}
function efToggleVacancyStatus($id)
{
    if (!is_numeric($id)) error('Invalid vacancy id.', 422);

    $cur = db()->prepare("SELECT status FROM vacancies WHERE vacancy_id = :id");
    $cur->execute([':id' => (int) $id]);
    $status = $cur->fetchColumn();
    if ($status === false) error('Vacancy not found.', 404);

    $next = ($status === 'Open') ? 'Closed' : 'Open';

    try {
        db()->prepare("UPDATE vacancies SET status = :s, updated_at = now() WHERE vacancy_id = :id")
            ->execute([':s' => $next, ':id' => (int) $id]);
    } catch (Throwable $e) {
        error('Failed to update vacancy status. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Toggle Vacancy Status', 'employment', "Set vacancy #{$id} status to {$next}");
    json(['status' => 'ok', 'message' => "Vacancy {$next}.", 'data' => ['status' => $next]]);
}

// Referrals

// EF service_id = 1 (Employment Facilitation)
define('EF_SERVICE_ID', 1);

// Returns beneficiary_service_id for an EF applicant, or null if not found.
function efGetBsId($beneficiaryId)
{
    $stmt = db()->prepare(
        "SELECT beneficiary_service_id FROM beneficiary_services
         WHERE beneficiary_id = :bid AND service_id = :sid LIMIT 1"
    );
    $stmt->execute([':bid' => (int) $beneficiaryId, ':sid' => EF_SERVICE_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int) $row['beneficiary_service_id'] : null;
}

// Build the frontend Referral object from a DB row.
function efBuildReferral($row)
{
    return [
        'id'            => (int) $row['referral_id'],
        'applicantId'   => (int) $row['beneficiary_id'],
        'applicantName' => $row['applicant_name'],
        'vacancyId'     => (int) $row['vacancy_id'],
        'jobTitle'      => $row['job_title'],
        'employer'      => $row['company_name'],
        'referralDate'  => $row['date_referred'],
        'status'        => $row['status'],
    ];
}

// Returns an applicant's full referral-to-placement history, oldest first.
function efGetApplicantHistory($id)
{
    if (!is_numeric($id)) error('Invalid applicant id.', 422);

    $bsId = efGetBsId((int) $id);
    if (!$bsId) error('Applicant is not enrolled in Employment Facilitation.', 404);

    // Applicant display name (same CONCAT pattern used elsewhere).
    $nameStmt = db()->prepare(
        "SELECT CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL
                            THEN ' ' || LEFT(b.middle_name, 1) || '.'
                            ELSE '' END) AS applicant_name
         FROM beneficiaries b WHERE b.beneficiary_id = :bid"
    );
    $nameStmt->execute([':bid' => (int) $id]);
    $applicantName = $nameStmt->fetchColumn() ?: '';

    $stmt = db()->prepare(
        "SELECT r.referral_id, r.date_referred, r.status AS referral_status,
                v.job_title, e.company_name,
                p.placement_id, p.status AS placement_status, p.date_hired
         FROM employment_facilitation_referrals r
         JOIN vacancies v ON v.vacancy_id = r.vacancy_id
         JOIN employers e ON e.employer_id = v.employer_id
         LEFT JOIN employment_facilitation_placements p ON p.referral_id = r.referral_id
         WHERE r.beneficiary_service_id = :bsid AND r.deleted_at IS NULL
         ORDER BY r.referral_id ASC"
    );
    $stmt->execute([':bsid' => $bsId]);

    $history = array_map(function ($row) {
        return [
            'referralId'   => (int) $row['referral_id'],
            'employer'     => $row['company_name'],
            'jobTitle'     => $row['job_title'],
            'referralDate' => $row['date_referred'],
            'status'       => $row['referral_status'],
            'placement'    => $row['placement_id'] !== null ? [
                'placementId' => (int) $row['placement_id'],
                'status'      => $row['placement_status'],
                'dateHired'   => $row['date_hired'],
            ] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    json(['status' => 'ok', 'data' => [
        'applicantId'   => (int) $id,
        'applicantName' => $applicantName,
        'history'       => $history,
    ]]);
}

// Returns the working list of unresolved referral attempts.
function efListReferrals()
{
    $stmt = db()->prepare(
        "SELECT r.referral_id, r.vacancy_id, r.date_referred, r.status,
                bs.beneficiary_id,
                CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL
                            THEN ' ' || LEFT(b.middle_name, 1) || '.'
                            ELSE '' END) AS applicant_name,
                v.job_title, e.company_name
         FROM employment_facilitation_referrals r
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
         JOIN beneficiaries b         ON b.beneficiary_id          = bs.beneficiary_id
         JOIN vacancies v             ON v.vacancy_id              = r.vacancy_id
         JOIN employers e             ON e.employer_id             = v.employer_id
         WHERE r.status != 'Hired' AND r.deleted_at IS NULL AND b.deleted_at IS NULL
           AND NOT EXISTS (
               SELECT 1 FROM employment_facilitation_placements p
               WHERE p.beneficiary_service_id = r.beneficiary_service_id AND p.status = 'Active'
           )
         ORDER BY r.referral_id DESC"
    );
    $stmt->execute();
    $out = array_map('efBuildReferral', $stmt->fetchAll(PDO::FETCH_ASSOC));
    json(['status' => 'ok', 'data' => $out]);
}

// Returns the applicant's most recent matching referral/placement at the same employer and job title, if any.
function efCheckDuplicateReferral()
{
    $applicantId = isset($_GET['applicantId']) && is_numeric($_GET['applicantId']) ? (int) $_GET['applicantId'] : null;
    $vacancyId   = isset($_GET['vacancyId'])   && is_numeric($_GET['vacancyId'])   ? (int) $_GET['vacancyId']   : null;
    if (!$applicantId || !$vacancyId) error('applicantId and vacancyId are required.', 422);

    // Target employer + position for the vacancy being referred to.
    $vStmt = db()->prepare(
        "SELECT v.employer_id, v.job_title, e.company_name
         FROM vacancies v JOIN employers e ON e.employer_id = v.employer_id
         WHERE v.vacancy_id = :vid"
    );
    $vStmt->execute([':vid' => $vacancyId]);
    $vac = $vStmt->fetch(PDO::FETCH_ASSOC);
    $bsId = efGetBsId($applicantId);
    if (!$vac || !$bsId) { json(['status' => 'ok', 'data' => ['hasDuplicate' => false]]); }

    $params = [':bsid' => $bsId, ':eid' => $vac['employer_id'], ':title' => $vac['job_title']];

    // Most recent prior referral to the same employer + position.
    $rStmt = db()->prepare(
        "SELECT r.date_referred AS d, r.status::text AS outcome
         FROM employment_facilitation_referrals r JOIN vacancies v ON v.vacancy_id = r.vacancy_id
         WHERE r.beneficiary_service_id = :bsid AND r.deleted_at IS NULL
           AND v.employer_id = :eid AND v.job_title = :title
         ORDER BY r.date_referred DESC LIMIT 1"
    );
    $rStmt->execute($params);
    $ref = $rStmt->fetch(PDO::FETCH_ASSOC);

    // Most recent prior placement at the same employer + position.
    $pStmt = db()->prepare(
        "SELECT p.date_hired AS d, p.status::text AS outcome
         FROM employment_facilitation_placements p JOIN vacancies v ON v.vacancy_id = p.vacancy_id
         WHERE p.beneficiary_service_id = :bsid
           AND v.employer_id = :eid AND v.job_title = :title
         ORDER BY p.date_hired DESC LIMIT 1"
    );
    $pStmt->execute($params);
    $pl = $pStmt->fetch(PDO::FETCH_ASSOC);

    // Pick the most recent of the two.
    $best = null; $kind = null;
    if ($ref)                                     { $best = $ref; $kind = 'referral'; }
    if ($pl && (!$best || $pl['d'] > $best['d'])) { $best = $pl; $kind = 'placement'; }
    if (!$best) { json(['status' => 'ok', 'data' => ['hasDuplicate' => false]]); }

    json(['status' => 'ok', 'data' => [
        'hasDuplicate' => true,
        'employer'     => $vac['company_name'],
        'position'     => $vac['job_title'],
        'date'         => $best['d'],
        'kind'         => $kind,       // 'referral' | 'placement'
        'outcome'      => $best['outcome'],
    ]]);
}

// POST /api/employment/createReferral  { applicantId, vacancyId }
function efCreateReferral()
{
    $d = body();
    $applicantId = isset($d['applicantId']) && is_numeric($d['applicantId']) ? (int) $d['applicantId'] : null;
    $vacancyId   = isset($d['vacancyId'])   && is_numeric($d['vacancyId'])   ? (int) $d['vacancyId']   : null;

    if (!$applicantId) error('Applicant is required.', 422);
    if (!$vacancyId)   error('Vacancy is required.', 422);

    $bsId = efGetBsId($applicantId);
    if (!$bsId) error('Applicant is not enrolled in Employment Facilitation.', 404);

    // Verify the vacancy is available: manually Open AND has a free slot
    // (remaining = slots_total - active placements, derived — never a stale count).
    $vac = db()->prepare(
        "SELECT vacancy_id FROM vacancies v
         WHERE v.vacancy_id = :id AND v.status = 'Open'
           AND v.slots_total >
               (SELECT COUNT(*) FROM employment_facilitation_placements p
                WHERE p.vacancy_id = v.vacancy_id AND p.status = 'Active')"
    );
    $vac->execute([':id' => $vacancyId]);
    if (!$vac->fetchColumn()) error('Vacancy is not available (closed or all slots filled).', 409);

    // Global lock: an applicant already hired or with a live referral can't be referred again until released.
    $state = efApplicantReferralState($bsId);
    if ($state === 'Hired')    error('This applicant is already hired and cannot be referred.', 409);
    if ($state === 'Referred') error('This applicant already has an active referral.', 409);

    try {
        $stmt = db()->prepare(
            "INSERT INTO employment_facilitation_referrals
                (beneficiary_service_id, vacancy_id, date_referred, status)
             VALUES (:bsid, :vid, CURRENT_DATE, 'Pending')
             RETURNING referral_id"
        );
        $stmt->execute([
            ':bsid'  => $bsId,
            ':vid'   => $vacancyId,
        ]);
        $id = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error('Failed to create referral. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Create Referral', 'employment', "Created referral: applicant #{$applicantId} to vacancy #{$vacancyId}");
    json(['status' => 'ok', 'message' => 'Referral created.', 'data' => ['id' => $id]]);
}

// Updates a referral's status, creating a placement when set to Hired.
function efUpdateReferralStatus($id)
{
    if (!is_numeric($id)) error('Invalid referral id.', 422);
    $d      = body();
    $status = $d['status'] ?? '';

    $valid = ['Pending', 'Interviewed', 'Not Hired', 'Hired'];
    if (!in_array($status, $valid, true)) error('Invalid status.', 422);

    $ref = db()->prepare(
        "SELECT r.referral_id, r.beneficiary_service_id, r.vacancy_id,
                v.job_title, v.job_type, v.employer_id,
                e.company_name
         FROM employment_facilitation_referrals r
         JOIN vacancies v ON v.vacancy_id   = r.vacancy_id
         JOIN employers e ON e.employer_id  = v.employer_id
         WHERE r.referral_id = :id"
    );
    $ref->execute([':id' => (int) $id]);
    $referral = $ref->fetch(PDO::FETCH_ASSOC);
    if (!$referral) error('Referral not found.', 404);

    if ($status !== 'Hired') {
        db()->prepare("UPDATE employment_facilitation_referrals SET status = :s, updated_at = now() WHERE referral_id = :id")
            ->execute([':s' => $status, ':id' => (int) $id]);
        logActivity(currentUserId(), 'Update Referral Status', 'employment', "Set referral #{$id} ({$referral['company_name']} - {$referral['job_title']}) status to {$status}");
        json(['status' => 'ok', 'message' => "Referral status updated to {$status}."]);
        return;
    }

    // Hired: create placement atomically
    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Mark referral as Hired
        $pdo->prepare("UPDATE employment_facilitation_referrals SET status = 'Hired', updated_at = now() WHERE referral_id = :id")
            ->execute([':id' => (int) $id]);

        // Create placement
        $ins = $pdo->prepare(
            "INSERT INTO employment_facilitation_placements
                (referral_id, beneficiary_service_id, vacancy_id, employer_id,
                 date_hired, status)
             VALUES
                (:rid, :bsid, :vid, :eid,
                 CURRENT_DATE, 'Active')
             RETURNING placement_id"
        );
        $ins->execute([
            ':rid'  => (int) $id,
            ':bsid' => (int) $referral['beneficiary_service_id'],
            ':vid'  => (int) $referral['vacancy_id'],
            ':eid'  => (int) $referral['employer_id'],
        ]);
        $placementId = (int) $ins->fetchColumn();

        // No vacancy_count decrement: remaining openings are derived from active placements.

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error('Failed to update referral. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Update Referral Status', 'employment', "Referral #{$id} -> Hired ({$referral['company_name']} - {$referral['job_title']}), placement #{$placementId} created");
    json(['status' => 'ok', 'message' => 'Applicant hired and placement created.', 'data' => ['placementId' => $placementId]]);
}

// POST /api/employment/deleteReferral/{id}  — soft delete (moves to recycle bin)
function efDeleteReferral($id)
{
    $uid = requireLogin();
    if (!is_numeric($id)) error('Invalid referral id.', 422);

    $check = db()->prepare("SELECT status FROM employment_facilitation_referrals WHERE referral_id = :id AND deleted_at IS NULL");
    $check->execute([':id' => (int) $id]);
    $status = $check->fetchColumn();
    if ($status === false) error('Referral not found.', 404);

    // Courtesy guard only, to stop accidental deletion of a referral already acted on.
    if ($status !== 'Pending') {
        error("Cannot delete: this referral is no longer pending (current status: {$status}). Only pending referrals can be deleted.", 409);
    }

    // Placements have ON DELETE RESTRICT referral_id; a referral with a placement must stay restorable.
    $placementCheck = db()->prepare("SELECT COUNT(*) FROM employment_facilitation_placements WHERE referral_id = :id");
    $placementCheck->execute([':id' => (int) $id]);
    if ((int) $placementCheck->fetchColumn() > 0) {
        error('Cannot delete: this referral resulted in a placement (hire) and cannot be removed while that history exists.', 409);
    }

    try {
        db()->prepare("UPDATE employment_facilitation_referrals SET deleted_at = now(), deleted_by = :uid WHERE referral_id = :id")
            ->execute([':uid' => $uid, ':id' => (int) $id]);
    } catch (Throwable $e) {
        error('Failed to delete referral. Please try again.', 500);
    }

    logActivity($uid, 'Delete Referral', 'employment', "Moved referral #{$id} to recycle bin");
    json(['status' => 'ok', 'message' => 'Referral moved to recycle bin.']);
}

// Permanently remove a referral row. Used by the recycle bin's permanent-delete.
function employmentHardDeleteReferral($id)
{
    db()->prepare("DELETE FROM employment_facilitation_referrals WHERE referral_id = :id")->execute([':id' => (int) $id]);
}

// Placements

// Build the frontend Placement object from a DB row.
function efBuildPlacement($row)
{
    return [
        'id'              => (int) $row['placement_id'],
        'applicantId'     => (int) $row['beneficiary_id'],
        'applicantName'   => $row['applicant_name'],
        'jobTitle'        => $row['vacancy_job_title'] ?? '',
        // Current title is derived from the latest promotion, never stored on the placement itself.
        'currentJobTitle' => $row['latest_promotion_title'] ?? $row['vacancy_job_title'] ?? '',
        'employer'        => $row['company_name'] ?? '',
        'dateHired'       => $row['date_hired'],
        'status'          => $row['status'],
        'employmentType'  => $row['vacancy_job_type'] ?? '',
        'referralId'      => $row['referral_id'] !== null ? (int) $row['referral_id'] : null,
        'vacancyId'       => $row['vacancy_id']   !== null ? (int) $row['vacancy_id']  : null,
        // Display string derived from the vacancy's atomic bounds (source of truth).
        'salaryRange'     => efFormatSalary($row['vacancy_salary_min'] ?? null, $row['vacancy_salary_max'] ?? null),
    ];
}

// GET /api/employment/listPlacements
function efListPlacements()
{
    $stmt = db()->prepare(
        "SELECT p.*,
                bs.beneficiary_id,
                CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL
                            THEN ' ' || LEFT(b.middle_name, 1) || '.'
                            ELSE '' END) AS applicant_name,
                v.job_title AS vacancy_job_title,
                v.job_type  AS vacancy_job_type,
                v.salary_min AS vacancy_salary_min,
                v.salary_max AS vacancy_salary_max,
                e.company_name,
                lp.new_job_title AS latest_promotion_title
         FROM employment_facilitation_placements p
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
         JOIN beneficiaries b         ON b.beneficiary_id          = bs.beneficiary_id
         LEFT JOIN vacancies v        ON v.vacancy_id              = p.vacancy_id
         LEFT JOIN employers e        ON e.employer_id             = p.employer_id
         LEFT JOIN (
             SELECT DISTINCT ON (placement_id) placement_id, new_job_title
             FROM placement_promotions
             ORDER BY placement_id, promotion_date DESC, promotion_id DESC
         ) lp ON lp.placement_id = p.placement_id
         WHERE b.deleted_at IS NULL
         ORDER BY p.placement_id DESC"
    );
    $stmt->execute();
    $out = array_map('efBuildPlacement', $stmt->fetchAll(PDO::FETCH_ASSOC));
    json(['status' => 'ok', 'data' => $out]);
}

// POST /api/employment/updatePlacement/{id}  { dateHired, status }
function efUpdatePlacement($id)
{
    if (!is_numeric($id)) error('Invalid placement id.', 422);
    $d = body();

    if (efNull($d['dateHired'] ?? '') === null) error('Date hired is required.', 422);
    if (efDateOrNull($d['dateHired'] ?? '') > date('Y-m-d')) error('Date hired cannot be in the future.', 422);

    $validStatus = ['Active', 'Resigned', 'Terminated', 'Completed'];
    $status = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : 'Active';

    try {
        db()->prepare(
            "UPDATE employment_facilitation_placements SET
                date_hired = :date, status = :status, updated_at = now()
             WHERE placement_id = :pid"
        )->execute([
            ':date'   => $d['dateHired'],
            ':status' => $status,
            ':pid'    => (int) $id,
        ]);
    } catch (Throwable $e) {
        error('Failed to update placement. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Update Placement', 'employment', "Updated placement #{$id}");
    json(['status' => 'ok', 'message' => 'Placement updated.']);
}

// POST /api/employment/updatePlacementStatus/{id}  { status }
function efUpdatePlacementStatus($id)
{
    if (!is_numeric($id)) error('Invalid placement id.', 422);
    $d = body();

    $validStatus = ['Active', 'Resigned', 'Terminated', 'Completed'];
    $status = $d['status'] ?? '';
    if (!in_array($status, $validStatus, true)) error('Invalid status.', 422);

    try {
        db()->prepare("UPDATE employment_facilitation_placements SET status = :s, updated_at = now() WHERE placement_id = :id")
            ->execute([':s' => $status, ':id' => (int) $id]);
    } catch (Throwable $e) {
        error('Failed to update placement status. Please try again.', 500);
    }

    logActivity(currentUserId(), 'Update Placement Status', 'employment', "Set placement #{$id} status to {$status}");
    json(['status' => 'ok', 'message' => "Placement status updated to {$status}."]);
}

// Returns a placement's promotion history, newest first.
function efListPromotions($id)
{
    if (!is_numeric($id)) error('Invalid placement id.', 422);

    $stmt = db()->prepare(
        "SELECT promotion_id, promotion_date, new_job_title,
                new_salary_min, new_salary_max, remarks, created_at
         FROM placement_promotions
         WHERE placement_id = :pid
         ORDER BY promotion_date DESC, promotion_id DESC"
    );
    $stmt->execute([':pid' => (int) $id]);

    $out = array_map(function ($row) {
        $min = $row['new_salary_min'] ?? null;
        $max = $row['new_salary_max'] ?? null;
        return [
            'id'             => (int) $row['promotion_id'],
            'promotionDate'  => $row['promotion_date'],
            'newJobTitle'    => $row['new_job_title'],
            'newSalaryMin'   => $min !== null ? (float) $min : null,
            'newSalaryMax'   => $max !== null ? (float) $max : null,
            'newSalaryRange' => efFormatSalary($min, $max),
            'remarks'        => $row['remarks'] ?? '',
            'createdAt'      => $row['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    json(['status' => 'ok', 'data' => $out]);
}

// Records a promotion event against a placement.
function efCreatePromotion($id)
{
    if (!is_numeric($id)) error('Invalid placement id.', 422);
    $uid = requireLogin();
    $d = body();

    if (efNull($d['promotionDate'] ?? '') === null) error('Promotion date is required.', 422);
    if (efNull($d['newJobTitle'] ?? '') === null)   error('New job title is required.', 422);

    $salMin = efSalaryNum($d['newSalaryMin'] ?? null);
    $salMax = efSalaryNum($d['newSalaryMax'] ?? null);
    if ($salMin !== null && $salMax !== null && $salMin > $salMax) {
        error('Minimum salary cannot exceed maximum salary.', 422);
    }

    // The placement must exist before we can attach a promotion to it.
    $exists = db()->prepare("SELECT 1 FROM employment_facilitation_placements WHERE placement_id = :pid");
    $exists->execute([':pid' => (int) $id]);
    if (!$exists->fetchColumn()) error('Placement not found.', 404);

    try {
        db()->prepare(
            "INSERT INTO placement_promotions
                (placement_id, promotion_date, new_job_title,
                 new_salary_min, new_salary_max, remarks, created_by)
             VALUES (:pid, :date, :title, :smin, :smax, :remarks, :uid)"
        )->execute([
            ':pid'     => (int) $id,
            ':date'    => $d['promotionDate'],
            ':title'   => trim($d['newJobTitle']),
            ':smin'    => $salMin,
            ':smax'    => $salMax,
            ':remarks' => efNull($d['remarks'] ?? ''),
            ':uid'     => $uid,
        ]);
    } catch (Throwable $e) {
        error('Failed to record promotion. Please try again.', 500);
    }

    logActivity($uid, 'Create Promotion', 'employment', "Recorded promotion for placement #{$id}: " . trim($d['newJobTitle']));
    json(['status' => 'ok', 'message' => 'Promotion recorded.']);
}

// PESO Monthly Report (LMI/SPRS: registered, referred, placed + summary)

// "Employed (Wage Employed)" / "Unemployed (Resigned)" — matches the PESO forms.
function efFormatEmploymentStatus($status, $type, $reason)
{
    if ($status === 'Employed')   return $type   ? "Employed ({$type})"   : 'Employed';
    if ($status === 'Unemployed') return $reason ? "Unemployed ({$reason})" : 'Unemployed';
    return $status ?? '';
}

// Total months of work experience -> "3 years 6 months".
function efFormatExperience($months)
{
    $months = (int) $months;
    if ($months <= 0) return '';
    $y = intdiv($months, 12);
    $mo = $months % 12;
    $parts = [];
    if ($y)  $parts[] = $y . ' year'  . ($y  > 1 ? 's' : '');
    if ($mo) $parts[] = $mo . ' month' . ($mo > 1 ? 's' : '');
    return implode(' ', $parts);
}

// Applicant full name in PESO order: FIRST MIDDLE LAST SUFFIX.
function efReportNameExpr($alias = 'b')
{
    return "TRIM(REGEXP_REPLACE(CONCAT_WS(' ', {$alias}.first_name, {$alias}.middle_name, {$alias}.last_name, {$alias}.suffix), '\\s+', ' ', 'g'))";
}

// Count vacancies solicited (sum of ORIGINAL slots) posted within [from, to].
function efReportVacancyCount($from, $to)
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(slots_total), 0) FROM vacancies
         WHERE created_at::date BETWEEN :from AND :to"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    return (int) $stmt->fetchColumn();
}

// Count for one pipeline table over [from, to] (used for the prior-period comparison).
function efReportCount($sql, $from, $to)
{
    $stmt = db()->prepare($sql);
    $stmt->execute([':sid' => efServiceId(), ':from' => $from, ':to' => $to]);
    return (int) $stmt->fetchColumn();
}

// Returns the PESO LMI/SPRS monthly report data for a date range.
function efMonthlyReport()
{
    // Resolve the reporting window (shared with reports.php's summary action).
    ['from' => $from, 'to' => $to, 'pfrom' => $pfrom, 'pto' => $pto, 'curYear' => $curYear, 'prevYear' => $prevYear] = resolveReportWindow();

    $sid  = efServiceId();
    $name = efReportNameExpr('b');

    // ── Applicants Registered (EF enrollment date in range) ──
    $regStmt = db()->prepare(
        "SELECT {$name} AS name, b.sex, b.birth_date,
                EXTRACT(YEAR FROM age(b.birth_date))::int AS age,
                b.civil_status, b.educational_attainment,
                ef.employment_status, ef.employment_type, ef.unemployment_reason,
                jp.occupation,
                COALESCE(we.total_months, 0) AS total_months
         FROM beneficiary_services bs
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         LEFT JOIN employment_facilitation_profiles ef ON ef.beneficiary_service_id = bs.beneficiary_service_id
         LEFT JOIN LATERAL (SELECT occupation FROM job_preferences jp
                            WHERE jp.beneficiary_id = b.beneficiary_id ORDER BY preference_id LIMIT 1) jp ON true
         LEFT JOIN LATERAL (SELECT COALESCE(SUM(number_of_months), 0) AS total_months FROM work_experiences w
                            WHERE w.beneficiary_id = b.beneficiary_id) we ON true
         WHERE bs.service_id = :sid AND b.deleted_at IS NULL
           AND bs.date_applied BETWEEN :from AND :to
         ORDER BY bs.date_applied, b.last_name, b.first_name"
    );
    $regStmt->execute([':sid' => $sid, ':from' => $from, ':to' => $to]);
    $registered = array_map(function ($r) {
        return [
            'name'                 => $r['name'],
            'occupation'           => $r['occupation'] ?? '',
            'sex'                  => $r['sex'] ?? '',
            'birthdate'            => $r['birth_date'],
            'age'                  => $r['age'] !== null ? (int) $r['age'] : null,
            'civilStatus'          => $r['civil_status'] ?? '',
            'educationalAttainment'=> $r['educational_attainment'] ?? '',
            'yearsWorkExperience'  => efFormatExperience($r['total_months']),
            'employmentStatus'     => efFormatEmploymentStatus($r['employment_status'] ?? null, $r['employment_type'] ?? null, $r['unemployment_reason'] ?? null),
        ];
    }, $regStmt->fetchAll(PDO::FETCH_ASSOC));

    // Applicants Referred: distinct persons in the period, one row per applicant.
    $refStmt = db()->prepare(
        "SELECT {$name} AS name, b.sex, MAX(jp.occupation) AS occupation
         FROM employment_facilitation_referrals r
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         LEFT JOIN LATERAL (SELECT occupation FROM job_preferences jp
                            WHERE jp.beneficiary_id = b.beneficiary_id ORDER BY preference_id LIMIT 1) jp ON true
         WHERE r.deleted_at IS NULL AND b.deleted_at IS NULL
           AND r.date_referred BETWEEN :from AND :to
         GROUP BY b.beneficiary_id
         ORDER BY b.last_name, b.first_name"
    );
    $refStmt->execute([':from' => $from, ':to' => $to]);
    $referred = array_map(fn($r) => [
        'name'       => $r['name'],
        'occupation' => $r['occupation'] ?? '',
        'sex'        => $r['sex'] ?? '',
    ], $refStmt->fetchAll(PDO::FETCH_ASSOC));

    // ── Applicants Placed — DISTINCT persons in the period (one row per applicant;
    //    if placed more than once, show the most recent job title).
    $plStmt = db()->prepare(
        "SELECT {$name} AS name, b.sex,
                (array_agg(v.job_title ORDER BY p.date_hired DESC))[1] AS placed_as
         FROM employment_facilitation_placements p
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         LEFT JOIN vacancies v ON v.vacancy_id = p.vacancy_id
         WHERE b.deleted_at IS NULL
           AND p.date_hired BETWEEN :from AND :to
         GROUP BY b.beneficiary_id
         ORDER BY b.last_name, b.first_name"
    );
    $plStmt->execute([':from' => $from, ':to' => $to]);
    $placed = array_map(fn($r) => [
        'name'     => $r['name'],
        'placedAs' => $r['placed_as'] ?? '',
        'sex'      => $r['sex'] ?? '',
    ], $plStmt->fetchAll(PDO::FETCH_ASSOC));

    // ── Sex disaggregation (case-insensitive; enum is Male/Female) ──
    $bySex = function ($rows) {
        $male = 0; $female = 0;
        foreach ($rows as $r) {
            $s = strtolower($r['sex'] ?? '');
            if ($s === 'male') $male++;
            elseif ($s === 'female') $female++;
        }
        return ['male' => $male, 'female' => $female];
    };

    $vacancies    = efReportVacancyCount($from, $to);
    $regCount     = count($registered);
    $refCount     = count($referred);
    $plCount      = count($placed);
    $placementRate = $refCount > 0 ? $plCount / $refCount : 0;

    // ── Prior-period comparison (same span, one year earlier; counts only) ──
    // Counts are DISTINCT persons (consistent with the current-period lists above).
    $regSql = "SELECT COUNT(DISTINCT bs.beneficiary_id) FROM beneficiary_services bs JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
               WHERE bs.service_id = :sid AND b.deleted_at IS NULL
                 AND bs.date_applied BETWEEN :from AND :to";
    $refSql = "SELECT COUNT(DISTINCT bs.beneficiary_id) FROM employment_facilitation_referrals r JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
               JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
               WHERE r.deleted_at IS NULL AND b.deleted_at IS NULL AND bs.service_id = :sid
                 AND r.date_referred BETWEEN :from AND :to";
    $plSql  = "SELECT COUNT(DISTINCT bs.beneficiary_id) FROM employment_facilitation_placements p JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
               JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
               WHERE b.deleted_at IS NULL AND bs.service_id = :sid
                 AND p.date_hired BETWEEN :from AND :to";

    $prevRef = efReportCount($refSql, $pfrom, $pto);
    $prevPl  = efReportCount($plSql, $pfrom, $pto);
    $comparison = [
        'currentYear' => $curYear,
        'previousYear' => $prevYear,
        'current'  => [
            'vacancies'  => $vacancies,
            'registered' => $regCount,
            'referred'   => $refCount,
            'placed'     => $plCount,
            'placementRate' => $placementRate,
        ],
        'previous' => [
            'vacancies'  => efReportVacancyCount($pfrom, $pto),
            'registered' => efReportCount($regSql, $pfrom, $pto),
            'referred'   => $prevRef,
            'placed'     => $prevPl,
            'placementRate' => $prevRef > 0 ? $prevPl / $prevRef : 0,
        ],
    ];

    json(['status' => 'ok', 'data' => [
        'from'  => $from,
        'to'    => $to,
        'summary' => [
            'vacanciesSolicited'  => $vacancies,
            'applicantsRegistered'=> $regCount,
            'applicantsReferred'  => $refCount,
            'applicantsPlaced'    => $plCount,
            'placementRate'       => $placementRate,
            'local'               => $vacancies, // all vacancies treated as Local
            'overseas'            => 0,
            'registeredBySex'     => $bySex($registered),
            'referredBySex'       => $bySex($referred),
            'placedBySex'         => $bySex($placed),
        ],
        'comparison' => $comparison,
        'registered' => $registered,
        'referred'   => $referred,
        'placed'     => $placed,
    ]]);
}

// Recycle bin (soft-deleted EF records: applicants, employers, referrals)

// Maps each recycle-bin record type to its table and primary key.
function efRecycleMap()
{
    return [
        'applicant' => ['beneficiaries', 'beneficiary_id'],
        'employer'  => ['employers', 'employer_id'],
        'referral'  => ['employment_facilitation_referrals', 'referral_id'],
    ];
}

// Reads and validates {recordType, id} from the request body.
function efRecycleTarget()
{
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(efRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id, $d];
}

// Returns a recycle-bin record's display name.
function efRecordName($type, $id)
{
    if ($type === 'applicant') {
        $s = db()->prepare(
            "SELECT CONCAT(last_name, ', ', first_name,
                    CASE WHEN middle_name IS NOT NULL THEN ' ' || LEFT(middle_name, 1) || '.' ELSE '' END)
             FROM beneficiaries WHERE beneficiary_id = :id"
        );
    } elseif ($type === 'employer') {
        $s = db()->prepare("SELECT company_name FROM employers WHERE employer_id = :id");
    } else {
        $s = db()->prepare(
            "SELECT CONCAT(b.last_name, ', ', b.first_name, ' -> ', e.company_name, ' (', v.job_title, ')')
             FROM employment_facilitation_referrals r
             JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
             JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
             JOIN vacancies v ON v.vacancy_id = r.vacancy_id
             JOIN employers e ON e.employer_id = v.employer_id
             WHERE r.referral_id = :id"
        );
    }
    $s->execute([':id' => $id]);
    $name = $s->fetchColumn();
    return $name !== false ? $name : "#{$id}";
}

// Returns the recycle bin contents for applicants, employers, and referrals.
function efListDeleted()
{
    efPurgeExpired();
    $items = [];

    // Applicants (soft-deleted beneficiaries enrolled in EF).
    $appl = db()->prepare(
        "SELECT b.beneficiary_id AS id,
                CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL THEN ' ' || LEFT(b.middle_name, 1) || '.' ELSE '' END) AS name,
                b.deleted_at, u.username AS deleted_by
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id AND bs.service_id = :sid
         LEFT JOIN users u ON u.user_id = b.deleted_by
         WHERE b.deleted_at IS NOT NULL"
    );
    $appl->execute([':sid' => efServiceId()]);
    foreach ($appl->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'recordType'  => 'applicant',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'Applicants',
            'description' => 'Employment Facilitation applicant record',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }

    // Employers.
    $emp = db()->prepare(
        "SELECT e.employer_id AS id, e.company_name AS name, e.deleted_at, u.username AS deleted_by
         FROM employers e
         LEFT JOIN users u ON u.user_id = e.deleted_by
         WHERE e.deleted_at IS NOT NULL"
    );
    $emp->execute();
    foreach ($emp->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'recordType'  => 'employer',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'Employers',
            'description' => 'Employer profile',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }

    // Referrals.
    $ref = db()->prepare(
        "SELECT r.referral_id AS id, r.deleted_at, u.username AS deleted_by,
                CONCAT(b.last_name, ', ', b.first_name) AS applicant_name,
                v.job_title, e.company_name
         FROM employment_facilitation_referrals r
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         JOIN vacancies v ON v.vacancy_id = r.vacancy_id
         JOIN employers e ON e.employer_id = v.employer_id
         LEFT JOIN users u ON u.user_id = r.deleted_by
         WHERE r.deleted_at IS NOT NULL"
    );
    $ref->execute();
    foreach ($ref->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'recordType'  => 'referral',
            'id'          => (int) $r['id'],
            'name'        => $r['applicant_name'],
            'module'      => 'Referrals',
            'description' => 'Referral to ' . $r['company_name'] . ' — ' . $r['job_title'],
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }

    // Newest deletions first, across all record types.
    usort($items, fn($a, $b) => strcmp((string) $b['deletedAt'], (string) $a['deletedAt']));

    json(['status' => 'ok', 'data' => $items]);
}

// POST /api/employment/restoreRecord  { recordType, id }  — undo a soft delete.
function efRestoreRecord()
{
    [$type, $id] = efRecycleTarget();
    [$table, $pk] = efRecycleMap()[$type];

    $name = efRecordName($type, $id);
    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    logActivity(currentUserId(), 'Restore Record', 'employment', "Restored \"{$name}\" from recycle bin");
    json(['status' => 'ok', 'message' => 'Record restored.']);
}

// Permanently deletes a recycle-bin record by type.
function efHardDeleteByType($type, $id)
{
    if ($type === 'applicant')     employmentHardDeleteApplicant($id);
    elseif ($type === 'employer')  employmentHardDeleteEmployer($id);
    else                            employmentHardDeleteReferral($id);
}

// Permanently deletes a recycle-bin record, blocked for an applicant with unresolved history unless forced.
function efPurgeRecord()
{
    [$type, $id, $body] = efRecycleTarget();
    [$table, $pk] = efRecycleMap()[$type];

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    if ($type === 'applicant' && empty($body['force'])) {
        $counts = efApplicantHistoryCounts($id);
        if ($counts['placements'] > 0 || $counts['referrals'] > 0) {
            error(
                'This applicant has placement/referral history that will also be permanently deleted.',
                409,
                ['code' => 'has_history', 'placements' => $counts['placements'], 'referrals' => $counts['referrals']]
            );
        }
    }
    $name = efRecordName($type, $id);
    efHardDeleteByType($type, $id);

    logActivity(currentUserId(), 'Purge Record', 'employment', "Permanently deleted \"{$name}\"");
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

// Purges EF recycle bin rows past RECYCLE_BIN_RETENTION_DAYS, skipping applicants with unresolved history.
function efPurgeExpired()
{
    $uid = currentUserId();

    // beneficiaries.deleted_at is shared across modules, so scope by service_id
    // to avoid purging someone belonging to a different module.
    $appS = db()->prepare(
        "SELECT b.beneficiary_id AS id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id AND bs.service_id = :sid
         WHERE b.deleted_at IS NOT NULL AND b.deleted_at < now() - make_interval(days => :days)"
    );
    $appS->execute([':sid' => efServiceId(), ':days' => RECYCLE_BIN_RETENTION_DAYS]);
    foreach ($appS->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int) $id;
        $counts = efApplicantHistoryCounts($id);
        if ($counts['placements'] > 0 || $counts['referrals'] > 0) continue; // see efPurgeRecord()'s force guard
        $name = efRecordName('applicant', $id);
        efHardDeleteByType('applicant', $id);
        logActivity($uid, 'Auto-Purge Record', 'employment', "Automatically purged \"{$name}\" after " . RECYCLE_BIN_RETENTION_DAYS . "-day recycle bin retention");
    }

    // Employers and referrals: standalone module-specific tables, no
    // cross-module ambiguity -- safe to use the plain recycle-map-driven query.
    foreach (['employer', 'referral'] as $type) {
        [$table, $pk] = efRecycleMap()[$type];
        $s = db()->prepare(
            "SELECT {$pk} AS id FROM {$table}
             WHERE deleted_at IS NOT NULL AND deleted_at < now() - make_interval(days => :days)"
        );
        $s->execute([':days' => RECYCLE_BIN_RETENTION_DAYS]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            $name = efRecordName($type, $id);
            efHardDeleteByType($type, $id);
            logActivity($uid, 'Auto-Purge Record', 'employment', "Automatically purged \"{$name}\" after " . RECYCLE_BIN_RETENTION_DAYS . "-day recycle bin retention");
        }
    }
}
