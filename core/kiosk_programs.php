<?php
// The programs the applicant kiosk can take a form for, in ONE place so the public kiosk module
// (modules/kiosk.php) and the staff Pending inbox (modules/submissions.php) always agree on each
// program's name, permission, which fields an applicant may send, and how to find the person's
// name and birthdate inside the stored form.
//
// To add a program to the kiosk: add it here, give its create endpoint the `submissionId` hook
// (core/submission_approval.php), then add its form to the kiosk and to the Pending page.

include_once __DIR__ . '/db.php';

// Fields every program's applicant form has in common (CDSP, GIP, SPES, Skills Training).
function kioskCoreFields()
{
    return ['lastName', 'firstName', 'middleName', 'sex', 'birthdate', 'age', 'civilStatus', 'contactNumber', 'email',
            'streetPurok', 'barangay', 'barangayId', 'cityMunicipality', 'province', 'region'];
}

// id => settings. `allowed` is a whitelist of the applicant-facing fields: anything else an applicant
// sends (status, dates, received-by, documents, assignments...) is thrown away. Employment
// Facilitation uses a blacklist instead (`staffOnly`) because its form has ~100 fields.
//   permission  the staff permission module that guards the program (who may see / approve)
//   module      the slug written to the activity log
//   dup         the key used by core/duplicates.php to find "already in this program"
//   first/last/birth  where the person's name and birthdate live inside the stored form
function kioskProgramRegistry()
{
    $core = kioskCoreFields();
    $classification = ['classification', 'classificationOther'];
    $education = ['highestEducation', 'schoolName', 'course', 'strand', 'yearLevel', 'yearGraduated'];

    return [
        'ef' => [
            'label' => 'Employment Facilitation', 'short' => 'Employment Facilitation', 'prefix' => 'EF',
            'permission' => 'employment', 'module' => 'employment', 'dup' => 'ef',
            'first' => 'firstName', 'last' => 'surname', 'birth' => 'dateOfBirth',
            'contactRequired' => true, 'allowed' => null,
            'staffOnly' => [
                'dateApplicationReceived', 'receivedBy',
                'referredProgram', 'referredProgramOther', 'cdspPrograms', 'livelihoodPrograms', 'dileepPrograms',
                'projectIdNumber', 'projectLocation', 'projectRegion', 'projectCity', 'projectDetails',
                'pagIbigNo', 'philHealthNo', 'sssNo', 'otherProgramName', 'otherProgramNo',
                'documentType', 'documentOtherSpecify', 'savedDocuments', 'profileImage', 'submissionId',
            ],
        ],
        'cdsp' => [
            'label' => 'Career Development Support Program', 'short' => 'CDSP', 'prefix' => 'CD',
            'permission' => 'cdsp', 'module' => 'cdsp', 'dup' => 'cdsp',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false,
            'allowed' => array_merge($core, $classification, $education, ['employmentStatus', 'currentOccupation', 'serviceAvailed']),
        ],
        'gip' => [
            'label' => 'Government Internship Program', 'short' => 'GIP', 'prefix' => 'GI',
            'permission' => 'gip', 'module' => 'gip', 'dup' => 'gip',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false,
            'allowed' => array_merge($core, $classification, $education),
        ],
        'spes' => [
            'label' => 'Special Program for Employment of Students', 'short' => 'SPES', 'prefix' => 'SP',
            'permission' => 'spes', 'module' => 'spes', 'dup' => 'spes',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false,
            'allowed' => array_merge($core, $classification, ['schoolName', 'schoolType', 'gradeYearLevel', 'course', 'annualFamilyIncome', 'numberOfDependents']),
        ],
        'skills' => [
            'label' => 'Skills Training', 'short' => 'Skills Training', 'prefix' => 'ST',
            'permission' => 'skills', 'module' => 'skills', 'dup' => 'skills',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false,
            'allowed' => array_merge(
                array_diff($core, ['email']), // the Skills Training form has no email field
                ['classification', 'classificationOther', 'desiredQualification', 'qualificationOther', 'purposeOfTraining', 'purposeOther']
            ),
        ],
        // ── Livelihood programs (staff permission "livelihood") and OFW ──
        // Not on the kiosk: assignments to a project/intervention, status, dates, received-by, remarks,
        // documents, and (SLP) the assessment results + eligibility type + participant ID: staff set those.
        'dilp' => [
            'label' => 'DOLE Integrated Livelihood Program', 'short' => 'DILP', 'prefix' => 'DL',
            'permission' => 'livelihood', 'module' => 'livelihood', 'dup' => 'dilp',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false,
            'allowed' => array_merge($core, ['nameExtension', 'beneficiaryClassification', 'beneficiaryClassificationOther', 'is4PsBeneficiary', 'yearGraduated4Ps']),
        ],
        'tupad' => [
            'label' => 'Tulong Panghanapbuhay sa Disadvantaged Workers', 'short' => 'TUPAD', 'prefix' => 'TP',
            'permission' => 'livelihood', 'module' => 'livelihood', 'dup' => 'tupad',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false,
            'allowed' => array_merge(array_diff($core, ['email']), ['nameExtension']),
        ],
        'slp' => [
            'label' => 'Sustainable Livelihood Program', 'short' => 'SLP', 'prefix' => 'SL',
            'permission' => 'livelihood', 'module' => 'livelihood', 'dup' => 'slp',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false,
            'allowed' => array_merge($core, [
                'nameExtension', 'is4PsBeneficiary', 'sector', 'sectorOthersSpecify', 'sectorIpGroupSpecify',
                'sectorDisabilitySpecify', 'educationalAttainment', 'sourceOfIncome', 'totalHouseholdMonthlyIncome',
            ]),
        ],
        'clpep' => [
            'label' => 'Child Labor Prevention and Elimination Program', 'short' => 'CLPEP', 'prefix' => 'CL',
            'permission' => 'livelihood', 'module' => 'livelihood', 'dup' => 'clpep',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => false, 'civilRequired' => false, // the child's form has no civil status or own contact
            'allowed' => array_merge(array_diff($core, ['email', 'civilStatus', 'contactNumber']), [
                'nameExtension', 'childLaborStatus', 'schoolStatus', 'natureOfWork', 'currentlyWorking', 'hoursWorkedPerWeek',
                'schoolName', 'gradeYearLevel', 'guardianName', 'guardianRelationship', 'guardianContactNumber',
            ]),
        ],
        'ofw' => [
            'label' => 'OFW Assistance', 'short' => 'OFW', 'prefix' => 'OF',
            'permission' => 'ofw', 'module' => 'ofw', 'dup' => 'ofw',
            'first' => 'firstName', 'last' => 'lastName', 'birth' => 'birthdate',
            'contactRequired' => true,
            // Reference No., Date Filed, status, received-by, documents and the OWWA / ELPOR attachments are staff-side.
            'allowed' => [
                'lastName', 'firstName', 'middleName', 'suffix', 'sex', 'birthdate', 'civilStatus', 'contactNumber', 'email',
                'address', 'barangay', 'barangayId', 'municipality', 'province', 'region', 'employmentStatus',
                'typeOfRequest', 'desiredPosition', 'typeOfSkill', 'agencies', 'inquirySpecify', 'otherProgramSpecify',
            ],
        ],
    ];
}

// Program ids the administrator has switched on for the kiosk (setting `kiosk_programs`, a comma list).
// No setting yet = every program is on. A missing table (migration not run) also counts as all on;
// the kiosk as a whole is then closed by its own switch anyway.
function kioskEnabledPrograms()
{
    $all = array_keys(kioskProgramRegistry());
    try {
        $v = db()->query("SELECT setting_value FROM app_settings WHERE setting_key = 'kiosk_programs'")->fetchColumn();
    } catch (Throwable $e) {
        return $all;
    }
    if ($v === false) return $all;
    $picked = array_filter(array_map('trim', explode(',', (string) $v)), function ($s) { return $s !== ''; });
    return array_values(array_intersect($all, $picked)); // keeps the registry's order
}
