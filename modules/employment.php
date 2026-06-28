<?php
// Employers, vacancies, referrals, placements, EF profiles
//
// This sprint: the Applicants tab. An "applicant" is a beneficiary enrolled in
// the Employment Facilitation service, so creating one writes across the
// beneficiary spine in a single transaction:
//   beneficiaries
//   -> beneficiary_services (service = EF)
//      -> employment_facilitation_profiles
//   -> resume sub-tables (educations, trainings, eligibilities, licenses,
//      work_experiences, job_preferences, languages, skills)
//
// Fields the EF schema has no column for are NOT persisted yet: referred
// program / project info, gov IDs (Pag-IBIG/PhilHealth/SSS), disability detail,
// uploaded documents, and the 2x2 photo.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

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
        case 'createApplicant':
            requirePermission('employment', 'Editor');
            return employmentCreateApplicant();
        case 'updateApplicant':
            requirePermission('employment', 'Editor');
            return employmentUpdateApplicant($id);
        case 'deleteApplicant':
            requirePermission('employment', 'Editor');
            return employmentDeleteApplicant($id);
        case 'listEmployers':
            requirePermission('employment', 'Viewer');
            return efListEmployers();
        case 'createEmployer':
            requirePermission('employment', 'Editor');
            return efCreateEmployer();
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

// Form month strings are "YYYY-MM" (or "Present"/empty). Normalize to a real
// date or null. Also accepts a full "YYYY-MM-DD".
function efMonthToDate($v)
{
    $s = is_string($v) ? trim($v) : '';
    if ($s === '' || strtolower($s) === 'present') return null;
    if (preg_match('/^\d{4}-\d{2}$/', $s)) return $s . '-01';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    return null;
}

// A plain YYYY-MM-DD or null.
function efDateOrNull($v)
{
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

// height_cm must be a positive number (check constraint) or null. The form's
// free-text height (e.g. 5'4") can't be parsed reliably, so only keep numerics.
function efHeightCm($v)
{
    return (is_numeric($v) && (float) $v > 0) ? (float) $v : null;
}

// Best-effort educational_attainment_enum from the education section.
function efDeriveEducation($d)
{
    $tert = isset($d['tertiary']) && is_array($d['tertiary']) ? $d['tertiary'] : [];
    $sec  = isset($d['secondary']) && is_array($d['secondary']) ? $d['secondary'] : [];
    $elem = isset($d['elementary']) && is_array($d['elementary']) ? $d['elementary'] : [];

    if (efYes($tert['graduated'] ?? '')) return 'College Graduate';
    if (efNull($tert['schoolName'] ?? '') !== null) return 'College Undergraduate';
    if (efYes($sec['graduated'] ?? '')) return 'Senior High School Graduate';
    if (efNull($sec['schoolName'] ?? '') !== null) return 'Senior High School Undergraduate';
    if (efYes($elem['graduated'] ?? '')) return 'Elementary Graduate';
    if (efNull($elem['schoolName'] ?? '') !== null) return 'Elementary Undergraduate';
    return null;
}

// referred_program_enum value, or null if the form sent nothing valid.
// Form values match the enum 1:1 (SPES, GIP, DILEEP, TESDA Training, TUPAD, JobStart, Others).
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
            "INSERT INTO beneficiary_services (beneficiary_id, service_id, status, date_applied, received_by)
             VALUES (:bid, :sid, 'Active', CURRENT_DATE, :uid)
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

    json(['status' => 'ok', 'message' => 'Applicant saved.', 'data' => ['id' => $beneficiaryId]]);
}

// Required-field + enum validation. Returns an error string, or '' if valid.
function employmentValidateApplicant($d)
{
    foreach (['firstName', 'surname', 'dateOfBirth', 'sex', 'civilStatus'] as $f) {
        if (efNull($d[$f] ?? '') === null) {
            return "Missing required field: {$f}";
        }
    }
    if (!in_array($d['sex'], ['Male', 'Female'], true)) {
        return 'Invalid sex.';
    }
    if (!in_array($d['civilStatus'], ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'], true)) {
        return 'Invalid civil status.';
    }
    if (efDateOrNull($d['dateOfBirth'] ?? '') === null) {
        return 'Invalid date of birth.';
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
             street_address, barangay_id, contact_no, email, is_4ps_beneficiary, status, educational_attainment)
         VALUES
            (:first, :middle, :last, :suffix, :sex, :bdate, :civil,
             :street, :bgy, :contact, :email, :is4ps, 'Active', :educ)
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
             household_id_no, currently_in_school, referred_program, other_referred_program)
         VALUES
            (:bsid, :tin, :religion, :height, :is_ofw, :ofw_country,
             :is_former, :former_country, :former_date,
             :household, :in_school, :referred, :referred_other)"
    );
    $referred = efReferredProgram($d);
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
    ]);
}

// ─── Disabilities + 2x2 photo (separate tables) ──────────────────────────────

// Insert disability rows. Standard checkbox values (Visual/Speech/Hearing/Physical)
// are stored verbatim; the "Other" checkbox stores the free-text description.
function employmentInsertDisabilities($pdo, $bid, $d)
{
    $list = is_array($d['hasDisability'] ?? null) ? $d['hasDisability'] : [];
    if (!$list) return;

    $standard = ['Visual', 'Speech', 'Hearing', 'Physical'];
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

// Save the 2x2 photo (a base64 data URL) as a real file + a documents row.
// The documents table is the universal store; the 2x2 photo is a document too.
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
        "INSERT INTO documents
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
    $sel = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :bid AND document_type = '2x2 ID Picture'");
    $sel->execute([':bid' => $bid]);
    foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    $pdo->prepare("DELETE FROM documents WHERE beneficiary_id = :bid AND document_type = '2x2 ID Picture'")
        ->execute([':bid' => $bid]);
}

// On update: a new base64 photo replaces the old one; an empty value clears it;
// an existing http URL (unchanged) is left as-is.
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

// Human-readable file size from a byte count (mirrors the form's formatFileSize).
function efFormatBytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Sync non-photo attachments to the documents table (the universal file store).
//   - savedDocuments entries WITH a base64 dataUrl  → new uploads (saved to disk + inserted)
//   - entries WITHOUT a dataUrl (existing files)     → kept by their document_id
//   - existing non-photo docs no longer in the list  → deleted (file unlinked)
// The 2x2 ID Picture is excluded here; it is managed via profileImage / employmentSavePhoto.
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
    $sel = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE beneficiary_id = :bid AND document_type IS DISTINCT FROM '2x2 ID Picture'");
    $sel->execute([':bid' => $bid]);
    foreach ($sel->fetchAll() as $row) {
        if (!in_array((int) $row['document_id'], $keep, true)) {
            $abs = __DIR__ . '/../' . $row['file_path'];
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM documents WHERE document_id = :id")->execute([':id' => (int) $row['document_id']]);
        }
    }

    // Insert the newly-uploaded files.
    $ins = $pdo->prepare(
        "INSERT INTO documents
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
    $sel = $pdo->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id");
    $sel->execute([':id' => $bid]);
    foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
}

// Insert all resume sub-tables for a beneficiary from the form payload.
function employmentInsertResumeTables($pdo, $bid, $d)
{
    // Educations: the three fixed levels + any graduate studies.
    $eduInsert = $pdo->prepare(
        "INSERT INTO educations (beneficiary_id, education_level, school_name, course, year_graduated, year_last_attended, graduated, strand)
         VALUES (:bid, :level, :school, :course, :ygrad, :ylast, :grad, :strand)"
    );
    $levels = [
        ['Elementary', $d['elementary'] ?? null],
        ['Secondary',  $d['secondary'] ?? null],
        ['Tertiary',   $d['tertiary'] ?? null],
    ];
    foreach ($levels as [$levelName, $row]) {
        if (is_array($row) && efNull($row['schoolName'] ?? '') !== null) {
            $eduInsert->execute([
                ':bid'    => $bid,
                ':level'  => $levelName,
                ':school' => trim($row['schoolName']),
                ':course' => efNull($row['course'] ?? ''),
                ':ygrad'  => efYearOrNull($row['yearGraduated'] ?? ''),
                ':ylast'  => efYearOrNull($row['yearLastAttended'] ?? ''),
                ':grad'   => efYes($row['graduated'] ?? '') ? 'true' : 'false',
                ':strand' => $levelName === 'Secondary' ? efNull($row['seniorHighStrand'] ?? '') : null,
            ]);
        }
    }
    foreach (($d['graduateStudies'] ?? []) as $row) {
        if (is_array($row) && efNull($row['schoolName'] ?? '') !== null) {
            $eduInsert->execute([
                ':bid'    => $bid,
                ':level'  => 'Graduate',
                ':school' => trim($row['schoolName']),
                ':course' => efNull($row['course'] ?? ''),
                ':ygrad'  => efYearOrNull($row['yearGraduated'] ?? ''),
                ':ylast'  => efYearOrNull($row['yearLastAttended'] ?? ''),
                ':grad'   => efYes($row['graduated'] ?? '') ? 'true' : 'false',
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
                ':cert'   => efYes($row['certificateReceived'] ?? '') ? 'true' : 'false',
            ]);
        }
    }

    // Eligibilities.
    $eligInsert = $pdo->prepare(
        "INSERT INTO eligibilities (beneficiary_id, eligibility_name, date_taken) VALUES (:bid, :name, :date)"
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
        "INSERT INTO licenses (beneficiary_id, license_name, valid_until) VALUES (:bid, :name, :valid)"
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

    // Work experiences.
    $workInsert = $pdo->prepare(
        "INSERT INTO work_experiences (beneficiary_id, company_name, position, date_from, date_to, employment_status)
         VALUES (:bid, :company, :position, :dfrom, :dto, :status)"
    );
    foreach (($d['workExperiences'] ?? []) as $row) {
        if (is_array($row) && (efNull($row['companyName'] ?? '') !== null || efNull($row['position'] ?? '') !== null)) {
            $workInsert->execute([
                ':bid'      => $bid,
                ':company'  => efNull($row['companyName'] ?? ''),
                ':position' => efNull($row['position'] ?? ''),
                ':dfrom'    => efMonthToDate($row['from'] ?? ''),
                ':dto'      => efMonthToDate($row['to'] ?? ''),
                ':status'   => efNull($row['status'] ?? ''),
            ]);
        }
    }

    // Job preferences.
    $jpInsert = $pdo->prepare(
        "INSERT INTO job_preferences (beneficiary_id, occupation, employment_type, preferred_location)
         VALUES (:bid, :occ, :type, :loc)"
    );
    $jpType = is_array($d['jobPrefEmploymentType'] ?? null) ? implode(', ', $d['jobPrefEmploymentType']) : '';
    foreach (($d['jobPreferences'] ?? []) as $row) {
        if (is_array($row) && efNull($row['occupation'] ?? '') !== null) {
            $loc = efNull($row['localCity'] ?? '') ?? efNull($row['overseasCountry'] ?? '');
            $jpInsert->execute([
                ':bid'  => $bid,
                ':occ'  => trim($row['occupation']),
                ':type' => efNull($jpType),
                ':loc'  => $loc,
            ]);
        }
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

    // Other skills: the predefined checkboxes (otherSkills, minus the 'OTHERS'
    // marker) plus the free-text "OTHERS: specify" entries (otherSkillsSpecify).
    // Both are stored as plain skill rows.
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
         WHERE bs.service_id = :sid
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

// GET /api/employment/getApplicantPhoto/{id}
// Returns the applicant's 2x2 photo as a base64 data URL so the front end can
// embed it directly (e.g. in the Resume Builder PDF) without cross-origin
// canvas-tainting issues. Returns null when no photo is attached.
function employmentGetApplicantPhoto($id)
{
    if (!is_numeric($id)) {
        error('Invalid applicant id.', 422);
    }
    $stmt = db()->prepare(
        "SELECT file_path, mime_type FROM documents
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
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id AND bs.service_id = :sid
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities    c   ON c.city_id       = bgy.city_id
         LEFT JOIN provinces p   ON p.province_id   = c.province_id
         LEFT JOIN regions   r   ON r.region_id     = p.region_id
         WHERE b.beneficiary_id = :bid
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
    $eligibilities = employmentFetchAll("SELECT * FROM eligibilities WHERE beneficiary_id = :id ORDER BY eligibility_id", $bid);
    $licenses     = employmentFetchAll("SELECT * FROM licenses WHERE beneficiary_id = :id ORDER BY license_id", $bid);
    $workExp      = employmentFetchAll("SELECT * FROM work_experiences WHERE beneficiary_id = :id ORDER BY experience_id", $bid);
    $jobPrefs     = employmentFetchAll("SELECT * FROM job_preferences WHERE beneficiary_id = :id ORDER BY preference_id", $bid);
    $languages    = employmentFetchAll("SELECT * FROM languages WHERE beneficiary_id = :id ORDER BY language_id", $bid);
    $skills       = employmentFetchAll("SELECT skill_name FROM skills WHERE beneficiary_id = :id ORDER BY skill_id", $bid);
    $disabilities = employmentFetchAll("SELECT disability_name FROM disabilities WHERE beneficiary_id = :id ORDER BY disability_id", $bid);

    // ── Rebuild disabilities (standard checkboxes vs "Other" free text) ──
    $standardDis = ['Visual', 'Speech', 'Hearing', 'Physical'];
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
    $photo = db()->prepare("SELECT file_path FROM documents WHERE beneficiary_id = :id AND document_type = '2x2 ID Picture' ORDER BY document_id DESC LIMIT 1");
    $photo->execute([':id' => $bid]);
    $photoPath = $photo->fetchColumn();
    $profileImage = $photoPath ? efUploadBaseUrl() . $photoPath : '';

    // ── Attachments (documents table) → savedDocuments with download URLs ──
    // Includes the 2x2 ID Picture so it is visible in the Documents/Attachments
    // list where it was uploaded (it is also surfaced separately as profileImage).
    $docRows = db()->prepare(
        "SELECT document_id, document_type, title, file_name, file_path, file_size
         FROM documents
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
    $eduBlank = ['schoolName' => '', 'schoolCity' => '', 'schoolProvince' => '', 'course' => '', 'graduated' => '', 'yearGraduated' => '', 'levelReached' => '', 'yearLastAttended' => ''];
    $elem = $eduBlank; $sec = $eduBlank + ['type' => '', 'seniorHighStrand' => '']; $tert = $eduBlank; $grad = [];
    foreach ($educations as $e) {
        $obj = [
            'schoolName'       => $e['school_name'] ?? '',
            'schoolCity'       => '',
            'schoolProvince'   => '',
            'course'           => $e['course'] ?? '',
            'graduated'        => $e['graduated'] ? 'Yes' : 'No',
            'yearGraduated'    => $e['year_graduated'] !== null ? (string) $e['year_graduated'] : '',
            'levelReached'     => '',
            'yearLastAttended' => $e['year_last_attended'] !== null ? (string) $e['year_last_attended'] : '',
        ];
        if ($e['education_level'] === 'Elementary') $elem = $obj;
        elseif ($e['education_level'] === 'Secondary') $sec = $obj + ['type' => '', 'seniorHighStrand' => $e['strand'] ?? ''];
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

    // ── fullFormData (mirrors ApplicantFormData; unmapped fields default empty) ──
    $full = [
        'surname' => $b['last_name'], 'firstName' => $b['first_name'], 'middleName' => $b['middle_name'] ?? '', 'suffix' => $b['suffix'] ?? '',
        'dateOfBirth' => $b['birth_date'], 'sex' => $b['sex'], 'religion' => $ef['religion'] ?? '', 'civilStatus' => $b['civil_status'],
        'height' => isset($ef['height_cm']) && $ef['height_cm'] !== null ? (string) $ef['height_cm'] : '',
        'houseNo' => $b['street_address'] ?? '',
        'barangay' => $b['barangay_name'] ?? '', 'barangayId' => $b['barangay_id'] !== null ? (int) $b['barangay_id'] : null,
        'municipality' => $b['city_name'] ?? '', 'province' => $b['province_name'] ?? '',
        'hasDisability' => $hasDisability, 'disabilityOther' => $disabilityOther,
        'tin' => $ef['tin'] ?? '', 'contactNumber' => $b['contact_no'] ?? '', 'email' => $b['email'] ?? '',
        'isOFW' => !empty($ef['is_ofw']) ? 'Yes' : 'No', 'ofwCountry' => $ef['ofw_country'] ?? '',
        'isFormerOFW' => !empty($ef['is_former_ofw']) ? 'Yes' : 'No', 'formerOFWCountry' => $ef['former_ofw_country'] ?? '',
        'formerOFWReturnDate' => $ef['former_ofw_return_date'] ?? '',
        'is4PsBeneficiary' => !empty($b['is_4ps_beneficiary']) ? 'Yes' : 'No', 'householdIdNo' => $ef['household_id_no'] ?? '',
        'jobPrefEmploymentType' => !empty($jobPrefs) && !empty($jobPrefs[0]['employment_type'])
            ? array_values(array_filter(array_map('trim', explode(',', $jobPrefs[0]['employment_type']))))
            : [],
        'jobPrefWorkLocation' => [],
        'jobPreferences' => array_map(fn($j) => ['occupation' => $j['occupation'] ?? '', 'localCity' => $j['preferred_location'] ?? '', 'overseasCountry' => ''], $jobPrefs),
        'languages' => array_map(fn($l) => ['language' => $l['language'], 'read' => (bool) $l['can_read'], 'write' => (bool) $l['can_write'], 'speak' => (bool) $l['can_speak'], 'understand' => (bool) $l['can_understand']], $languages),
        'currentlyInSchool' => !empty($ef['currently_in_school']) ? 'Yes' : 'No',
        'elementary' => $elem, 'secondary' => $sec, 'tertiary' => $tert, 'graduateStudies' => $grad,
        'trainings' => array_map(fn($t) => ['course' => $t['course'], 'hoursOfTraining' => $t['hours_of_training'] !== null ? (string) $t['hours_of_training'] : '', 'institution' => $t['institution'] ?? '', 'skillsAcquired' => $t['skills_acquired'] ?? '', 'certificateReceived' => $t['certificate_received'] ? 'Yes' : 'No'], $trainings),
        'eligibilities' => array_map(fn($e) => ['eligibility' => $e['eligibility_name'], 'dateTaken' => $e['date_taken'] ?? ''], $eligibilities),
        'professionalLicenses' => array_map(fn($l) => ['license' => $l['license_name'], 'validUntil' => $l['valid_until'] ?? ''], $licenses),
        'workExperiences' => array_map(fn($w) => ['companyName' => $w['company_name'] ?? '', 'position' => $w['position'] ?? '', 'from' => $w['date_from'] ? substr($w['date_from'], 0, 7) : '', 'to' => $w['date_to'] ? substr($w['date_to'], 0, 7) : '', 'status' => $w['employment_status'] ?? ''], $workExp),
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
    $mi = (!empty($b['middle_name'])) ? ' ' . substr($b['middle_name'], 0, 1) . '.' : '';
    $name = $b['last_name'] . ', ' . $b['first_name'] . $mi;
    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (int) (new DateTime())->diff(new DateTime($b['birth_date']))->y;
    }
    // Prefer detailed resume info (course/level from the educations table);
    // fall back to the derived educational_attainment so the column isn't blank
    // when the applicant only selected a graduation level (no school name typed).
    $education = $tert['course'] ? $tert['course'] . ' (Tertiary)'
        : ($sec['schoolName'] && $sec['graduated'] === 'Yes' ? 'Senior High School Graduate'
        : ($elem['schoolName'] && $elem['graduated'] === 'Yes' ? 'Elementary Graduate'
        : ($b['educational_attainment'] ?? '')));

    return [
        'id' => (int) $b['beneficiary_id'],
        'name' => $name,
        'gender' => $b['sex'],
        'age' => $age,
        'education' => $education,
        'skills' => implode(', ', $skillNames),
        'trainingCourses' => implode(', ', array_filter(array_map(fn($t) => trim($t['course'] ?? ''), $trainings))),
        'employmentStatus' => 'Unemployed',
        'contactNumber' => $b['contact_no'] ?? '',
        'email' => $b['email'] ?? '',
        'address' => implode(', ', array_filter([$b['barangay_name'] ?? '', $b['city_name'] ?? '', $b['province_name'] ?? ''])),
        'civilStatus' => $b['civil_status'],
        'hasDisability' => count($disabilities) > 0,
        'isOFW' => !empty($ef['is_ofw']),
        'isFormerOFW' => !empty($ef['is_former_ofw']),
        'is4PsBeneficiary' => !empty($b['is_4ps_beneficiary']),
        'jobPreference' => $jobPrefs[0]['occupation'] ?? '',
        'language' => $languages[0]['language'] ?? '',
        'fullFormData' => $full,
    ];
}

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

        foreach (['educations', 'trainings', 'eligibilities', 'licenses', 'work_experiences', 'job_preferences', 'languages', 'skills'] as $t) {
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

    json(['status' => 'ok', 'message' => 'Applicant updated.', 'data' => ['id' => $bid]]);
}

// ─── Delete ─────────────────────────────────────────────────────────────────

// POST /api/employment/deleteApplicant/{id}
function employmentDeleteApplicant($id)
{
    requireLogin();
    if (!is_numeric($id)) {
        error('Invalid applicant id.', 422);
    }
    $bid = (int) $id;

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

        $pdo->prepare("DELETE FROM employment_facilitation_profiles WHERE beneficiary_service_id = :id")->execute([':id' => $bsId]);
        employmentUnlinkDocs($pdo, $bid); // unlink all document files first
        foreach (['educations', 'trainings', 'eligibilities', 'licenses', 'work_experiences', 'job_preferences', 'languages', 'skills', 'beneficiary_classifications', 'disabilities', 'documents'] as $t) {
            $pdo->prepare("DELETE FROM {$t} WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        }
        $pdo->prepare("DELETE FROM beneficiary_services WHERE beneficiary_id = :id")->execute([':id' => $bid]);
        $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = :id")->execute([':id' => $bid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to delete applicant. Please try again.', 500);
    }

    json(['status' => 'ok', 'message' => 'Applicant deleted.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// EMPLOYERS
// ═══════════════════════════════════════════════════════════════════════════════

// ─── Industry helpers ────────────────────────────────────────────────────────

// Upsert an industry by name; returns its id. Returns null for empty name.
function efGetOrCreateIndustry($name)
{
    $name = is_string($name) ? trim($name) : '';
    if ($name === '') return null;
    $pdo = db();
    $sel = $pdo->prepare("SELECT industry_id FROM industries WHERE industry_name = :n LIMIT 1");
    $sel->execute([':n' => $name]);
    $id = $sel->fetchColumn();
    if ($id !== false) return (int) $id;
    $ins = $pdo->prepare("INSERT INTO industries (industry_name, is_active) VALUES (:n, true) RETURNING industry_id");
    $ins->execute([':n' => $name]);
    return (int) $ins->fetchColumn();
}

// Resolve the effective industry name from form data (handles the "Other" case).
function efResolveIndustryName($d)
{
    $ind = efNull($d['industry'] ?? '');
    if ($ind === null) return null;
    if ($ind === 'Other') return efNull($d['industryOther'] ?? '');
    return $ind;
}

// Map the frontend company-size label to the DB employer_size_enum.
function efMapCompanySize($size)
{
    if (strpos((string) $size, 'Small') !== false) return 'Small';
    if (strpos((string) $size, 'Medium') !== false) return 'Medium';
    if (strpos((string) $size, 'Large') !== false) return 'Large';
    return null; // nullable column
}

// Map the DB employer_size_enum back to the frontend label.
function efExpandCompanySize($size)
{
    if ($size === 'Small') return 'Small (1-50 employees)';
    if ($size === 'Medium') return 'Medium (51-200 employees)';
    if ($size === 'Large') return 'Large (201+ employees)';
    return $size ?? '';
}

// Standard industry names (used when reading back to detect "Other" case).
function efStandardIndustries()
{
    return ['Manufacturing', 'Information Technology', 'Agriculture', 'Retail', 'Healthcare',
            'Education', 'Construction', 'Hospitality', 'Transportation', 'Financial Services'];
}

// Build the frontend Employer object from a DB row (with joined fields).
function efBuildEmployer($row)
{
    $industryName = $row['industry_name'] ?? '';
    $standard     = efStandardIndustries();
    $isStandard   = in_array($industryName, $standard, true);

    return [
        'id'                => (int) $row['employer_id'],
        'companyName'       => $row['company_name'],
        'industry'          => $isStandard ? $industryName : ($industryName !== '' ? 'Other' : ''),
        'industryOther'     => (!$isStandard && $industryName !== '') ? $industryName : '',
        'companySize'       => efExpandCompanySize($row['company_size'] ?? ''),
        'businessType'      => $row['business_type'] ?? '',
        'yearsInOperation'  => $row['years_in_operation'] !== null ? (string) $row['years_in_operation'] : '',
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
        'jobOpenings'       => [['jobName' => '', 'slots' => '']],
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
        "SELECT e.*, i.industry_name,
                bgy.barangay_name, c.city_name, p.province_name, r.region_name
         FROM employers e
         LEFT JOIN industries i   ON i.industry_id   = e.industry_id
         LEFT JOIN barangays  bgy ON bgy.barangay_id = e.barangay_id
         LEFT JOIN cities     c   ON c.city_id        = bgy.city_id
         LEFT JOIN provinces  p   ON p.province_id    = c.province_id
         LEFT JOIN regions    r   ON r.region_id      = p.region_id
         ORDER BY e.employer_id DESC"
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = efBuildEmployer($row);
    }
    json(['status' => 'ok', 'data' => $out]);
}

function efValidateEmployer($d)
{
    if (efNull($d['companyName']      ?? '') === null) return 'Company name is required.';
    if (efNull($d['contactPersonName'] ?? '') === null) return 'Contact person name is required.';
    if (efNull($d['contactNumber']    ?? '') === null) return 'Contact number is required.';
    if (efNull($d['dateRegistered']   ?? '') === null) return 'Date registered is required.';
    if (efDateOrNull($d['dateRegistered'] ?? '') === null) return 'Invalid date registered format.';
    return '';
}

function efBindEmployer($stmt, $d, $extra = [])
{
    $industryName = efResolveIndustryName($d);
    $industryId   = $industryName ? efGetOrCreateIndustry($industryName) : null;
    $size         = efNull($d['companySize'] ?? '') ? efMapCompanySize($d['companySize']) : null;

    $params = array_merge([
        ':name'    => trim($d['companyName']),
        ':iid'     => $industryId,
        ':size'    => $size,
        ':years'   => is_numeric($d['yearsInOperation'] ?? '') ? (int) $d['yearsInOperation'] : null,
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

    try {
        $stmt = db()->prepare(
            "INSERT INTO employers
                (company_name, industry_id, company_size, years_in_operation, tin_number,
                 contact_person_name, contact_person_position, contact_number, email_address,
                 building_no, street, barangay_id, status, date_registered, remarks, business_type)
             VALUES
                (:name, :iid, :size, :years, :tin,
                 :cname, :cpos, :cnum, :email,
                 :bldg, :street, :bgid, :status, :dreg, :remarks, :btype)
             RETURNING employer_id"
        );
        efBindEmployer($stmt, $d);
        $id = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error('Failed to save employer. Please try again.', 500);
    }

    json(['status' => 'ok', 'message' => 'Employer saved.', 'data' => ['id' => $id]]);
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
                company_name = :name, industry_id = :iid, company_size = :size,
                years_in_operation = :years, tin_number = :tin,
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

    json(['status' => 'ok', 'message' => 'Employer updated.']);
}

// POST /api/employment/deleteEmployer/{id}
function efDeleteEmployer($id)
{
    requireLogin();
    if (!is_numeric($id)) error('Invalid employer id.', 422);

    $check = db()->prepare("SELECT COUNT(*) FROM vacancies WHERE employer_id = :id");
    $check->execute([':id' => (int) $id]);
    if ((int) $check->fetchColumn() > 0) {
        error('Cannot delete: this employer has vacancies. Remove the vacancies first.', 409);
    }

    try {
        db()->prepare("DELETE FROM employers WHERE employer_id = :id")->execute([':id' => (int) $id]);
    } catch (Throwable $e) {
        error('Failed to delete employer. Please try again.', 500);
    }

    json(['status' => 'ok', 'message' => 'Employer deleted.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// VACANCIES
// ═══════════════════════════════════════════════════════════════════════════════

// Map frontend job type to the vacancy_job_type_enum (Full-time/Part-time/Contract).
function efJobType($jobType)
{
    $valid = ['Full-time', 'Part-time', 'Contract'];
    return in_array($jobType, $valid, true) ? $jobType : 'Contract';
}

// Build the frontend Vacancy object from a DB row (with joined employer/industry).
function efBuildVacancy($row)
{
    return [
        'id'             => (int) $row['vacancy_id'],
        'jobTitle'       => $row['job_title'],
        'employer'       => $row['company_name'] ?? '',
        'employerId'     => (int) $row['employer_id'],
        'vacanciesCount' => (int) $row['vacancy_count'],
        'industry'       => $row['industry_name'] ?? '',
        'jobType'        => $row['job_type'],
        'salaryRange'    => $row['salary_range'] ?? '',
        'description'    => $row['description'] ?? '',
        'requirements'   => $row['requirements'] ?? '',
        'status'         => $row['status'],
    ];
}

// ─── Vacancy CRUD ────────────────────────────────────────────────────────────

// GET /api/employment/listVacancies
function efListVacancies()
{
    $stmt = db()->prepare(
        "SELECT v.*, e.company_name, i.industry_name
         FROM vacancies v
         JOIN employers e    ON e.employer_id  = v.employer_id
         LEFT JOIN industries i ON i.industry_id = e.industry_id
         ORDER BY v.vacancy_id DESC"
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = efBuildVacancy($row);
    }
    json(['status' => 'ok', 'data' => $out]);
}

function efValidateVacancy($d)
{
    if (efNull($d['jobTitle'] ?? '') === null) return 'Job title is required.';
    if (!isset($d['employerId']) || !is_numeric($d['employerId'])) return 'Please select an employer.';
    // Description and requirements are optional (the form does not mark them
    // required); the NOT NULL columns are satisfied by an empty-string default.
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

    try {
        $stmt = db()->prepare(
            "INSERT INTO vacancies
                (employer_id, job_title, vacancy_count, job_type, salary_range, description, requirements, status)
             VALUES
                (:eid, :title, :cnt, :jtype, :salary, :desc, :req, 'Open')
             RETURNING vacancy_id"
        );
        $stmt->execute([
            ':eid'    => (int) $d['employerId'],
            ':title'  => trim($d['jobTitle']),
            ':cnt'    => is_numeric($d['vacanciesCount'] ?? 1) ? (int) $d['vacanciesCount'] : 1,
            ':jtype'  => efJobType($d['jobType'] ?? 'Full-time'),
            ':salary' => efNull($d['salaryRange'] ?? ''),
            ':desc'   => trim($d['description'] ?? ''),
            ':req'    => trim($d['requirements'] ?? ''),
        ]);
        $id = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error('Failed to save vacancy. Please try again.', 500);
    }

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

    try {
        $stmt = db()->prepare(
            "UPDATE vacancies SET
                employer_id = :eid, job_title = :title, vacancy_count = :cnt,
                job_type = :jtype, salary_range = :salary,
                description = :desc, requirements = :req, updated_at = now()
             WHERE vacancy_id = :vid"
        );
        $stmt->execute([
            ':eid'    => (int) $d['employerId'],
            ':title'  => trim($d['jobTitle']),
            ':cnt'    => is_numeric($d['vacanciesCount'] ?? 1) ? (int) $d['vacanciesCount'] : 1,
            ':jtype'  => efJobType($d['jobType'] ?? 'Full-time'),
            ':salary' => efNull($d['salaryRange'] ?? ''),
            ':desc'   => trim($d['description'] ?? ''),
            ':req'    => trim($d['requirements'] ?? ''),
            ':vid'    => (int) $id,
        ]);
    } catch (Throwable $e) {
        error('Failed to update vacancy. Please try again.', 500);
    }

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

    json(['status' => 'ok', 'message' => "Vacancy {$next}.", 'data' => ['status' => $next]]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// REFERRALS
// ═══════════════════════════════════════════════════════════════════════════════

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

// GET /api/employment/listReferrals  — excludes Hired (those become placements)
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
         WHERE r.status != 'Hired'
         ORDER BY r.referral_id DESC"
    );
    $stmt->execute();
    $out = array_map('efBuildReferral', $stmt->fetchAll(PDO::FETCH_ASSOC));
    json(['status' => 'ok', 'data' => $out]);
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

    // Verify vacancy exists and is open
    $vac = db()->prepare("SELECT vacancy_id FROM vacancies WHERE vacancy_id = :id AND status = 'Open'");
    $vac->execute([':id' => $vacancyId]);
    if (!$vac->fetchColumn()) error('Vacancy not found or is closed.', 404);

    // Prevent duplicate active referral
    $dup = db()->prepare(
        "SELECT 1 FROM employment_facilitation_referrals
         WHERE beneficiary_service_id = :bsid AND vacancy_id = :vid AND status != 'Not Hired' LIMIT 1"
    );
    $dup->execute([':bsid' => $bsId, ':vid' => $vacancyId]);
    if ($dup->fetchColumn()) error('This applicant already has an active referral for this vacancy.', 409);

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

    json(['status' => 'ok', 'message' => 'Referral created.', 'data' => ['id' => $id]]);
}

// POST /api/employment/updateReferralStatus/{id}  { status }
// When status = 'Hired': atomically creates a placement and marks referral Hired.
function efUpdateReferralStatus($id)
{
    if (!is_numeric($id)) error('Invalid referral id.', 422);
    $d      = body();
    $status = $d['status'] ?? '';

    $valid = ['Pending', 'Interviewed', 'Not Hired', 'Hired'];
    if (!in_array($status, $valid, true)) error('Invalid status.', 422);

    $ref = db()->prepare(
        "SELECT r.referral_id, r.beneficiary_service_id, r.vacancy_id,
                v.job_title, v.job_type, v.salary_range, v.employer_id,
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

        // Decrement vacancy count; auto-close when it hits 0
        $pdo->prepare(
            "UPDATE vacancies SET
                vacancy_count = GREATEST(0, vacancy_count - 1),
                status = CASE WHEN GREATEST(0, vacancy_count - 1) = 0 THEN 'Closed' ELSE status END,
                updated_at = now()
             WHERE vacancy_id = :vid"
        )->execute([':vid' => (int) $referral['vacancy_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error('Failed to update referral. Please try again.', 500);
    }

    json(['status' => 'ok', 'message' => 'Applicant hired and placement created.', 'data' => ['placementId' => $placementId]]);
}

// POST /api/employment/deleteReferral/{id}
function efDeleteReferral($id)
{
    if (!is_numeric($id)) error('Invalid referral id.', 422);

    $check = db()->prepare("SELECT 1 FROM employment_facilitation_referrals WHERE referral_id = :id");
    $check->execute([':id' => (int) $id]);
    if (!$check->fetchColumn()) error('Referral not found.', 404);

    try {
        db()->prepare("DELETE FROM employment_facilitation_referrals WHERE referral_id = :id")
            ->execute([':id' => (int) $id]);
    } catch (Throwable $e) {
        error('Failed to delete referral. Please try again.', 500);
    }

    json(['status' => 'ok', 'message' => 'Referral removed.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PLACEMENTS
// ═══════════════════════════════════════════════════════════════════════════════

// Build the frontend Placement object from a DB row.
function efBuildPlacement($row)
{
    return [
        'id'             => (int) $row['placement_id'],
        'applicantId'    => (int) $row['beneficiary_id'],
        'applicantName'  => $row['applicant_name'],
        'jobTitle'       => $row['vacancy_job_title'] ?? '',
        'employer'       => $row['company_name'] ?? '',
        'dateHired'      => $row['date_hired'],
        'status'         => $row['status'],
        'employmentType' => $row['vacancy_job_type'] ?? '',
        'referralId'     => $row['referral_id'] !== null ? (int) $row['referral_id'] : null,
        'vacancyId'      => $row['vacancy_id']   !== null ? (int) $row['vacancy_id']  : null,
        'salaryRange'    => $row['vacancy_salary_range'] ?? '',
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
                v.salary_range AS vacancy_salary_range,
                e.company_name
         FROM employment_facilitation_placements p
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
         JOIN beneficiaries b         ON b.beneficiary_id          = bs.beneficiary_id
         LEFT JOIN vacancies v        ON v.vacancy_id              = p.vacancy_id
         LEFT JOIN employers e        ON e.employer_id             = p.employer_id
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

    json(['status' => 'ok', 'message' => "Placement status updated to {$status}."]);
}
