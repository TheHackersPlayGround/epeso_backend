<?php
// Employment Facilitation helpers shared between the staff Employment module and the employer portal module
// (each module file defines its own handle(), so two modules can't be loaded together; shared code lives here).
// Moved here unchanged from modules/employment.php.

include_once __DIR__ . '/response.php';

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

// The Employment Facilitation public upload base URL (for serving stored files).
function efUploadBaseUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
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

// Build the frontend Applicant object (summary + fullFormData) for one beneficiary.
function employmentBuildApplicant($bid)
{
    $stmt = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, c.city_id, p.province_name, p.province_id, r.region_name,
                bs.beneficiary_service_id, bs.date_applied, bs.received_by
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
        // For PESO Office Only
        'dateApplicationReceived' => $b['date_applied'] ?? '', 'receivedBy' => $b['received_by'] ?? '',
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
