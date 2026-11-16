<?php
// What a signed-in employer sees and can ask for. EVERY query is scoped by the employer of the signed-in account (taken
// from the session, never from anything the browser sends), so one company can never read another's data.
//
// Employers never change the PESO's records: they send REQUESTS (referral status, placement status, company details),
// which staff accept or reject in Employers tab -> Employer Updates (modules/employer_updates.php).
//
// Applicant details: only people referred to / hired by THIS company, and only Sections I-VIII of the application form.
// Section IX (Referred Program: which PESO programs the applicant was referred to) and Section X ("For PESO Office Only":
// date applied, received by, uploaded documents, the photo) are never returned: they are PESO-internal, not about the applicant's work.
//
// Uploaded documents are the one exception, and only the ones staff CHOSE to send for a referral of this company (Refer screen /
// Referrals tab -> Shared Documents), and only while that referral is active or hired. Files are streamed from here after the
// checks below, never linked directly.

include_once __DIR__ . '/../core/employer_session.php';
include_once __DIR__ . '/../core/ef_actions.php';
include_once __DIR__ . '/../core/ef_applicant.php';
include_once __DIR__ . '/../core/ef_vacancy_rules.php';
include_once __DIR__ . '/../core/ef_shared_documents.php';

function handle($action, $id, $method)
{
    employerNoStore();
    employerRequirePortalHeader();
    switch ($action) {
        case 'overview':        $a = requireEmployer(); return epOverview($a);
        case 'company':         $a = requireEmployer(); return epCompany($a);
        case 'vacancies':       $a = requireEmployer(); return epVacancies($a);
        case 'referrals':       $a = requireEmployer(); return epReferrals($a);
        case 'placements':      $a = requireEmployer(); return epPlacements($a);
        case 'applicant':       $a = requireEmployer(); return epApplicant($a, $id);
        case 'document':        $a = requireEmployer(); return epDocument($a, $id);
        case 'requests':        $a = requireEmployer(); return epRequests($a);
        case 'submitRequest':   $a = requireEmployer(); return epSubmitRequest($a);
        case 'withdrawRequest': $a = requireEmployer(); return epWithdrawRequest($a, $id);
        default:
            error("Unknown employer_portal action: {$action}", 404);
    }
}

const EP_REFERRAL_TARGETS  = ['Interviewed', 'Hired', 'Not Hired'];
const EP_PLACEMENT_TARGETS = ['Resigned', 'Terminated', 'Completed'];
// The company fields an employer may ask to change: payload key => employers column.
const EP_COMPANY_FIELDS = [
    'contactPersonName'     => 'contact_person_name',
    'contactPersonPosition' => 'contact_person_position',
    'contactNumber'         => 'contact_number',
    'emailAddress'          => 'email_address',
    'buildingNo'            => 'building_no',
    'street'                => 'street',
];

// Sections I-VIII of the application form. Anything not listed here (Section IX: referred program; Section X: date applied,
// received by, uploaded documents, the photo; and the program placeholders) is never sent to an employer.
const EP_APPLICANT_KEYS = [
    // I. Personal information
    'surname', 'firstName', 'middleName', 'suffix', 'dateOfBirth', 'sex', 'religion', 'civilStatus', 'height',
    'houseNo', 'barangay', 'municipality', 'province', 'hasDisability', 'disabilityOther', 'tin', 'contactNumber', 'email',
    'employmentStatus', 'employmentType', 'selfEmploymentType', 'selfEmploymentOther', 'unemploymentReason', 'unemploymentReasonOther', 'monthsLookingForWork',
    'isOFW', 'ofwCountry', 'isFormerOFW', 'formerOFWCountry', 'formerOFWReturnDate', 'is4PsBeneficiary', 'householdIdNo',
    // II. Job preference   III. Language   IV. Education   V. Training   VI. Eligibility / license   VII. Work experience   VIII. Other skills
    'jobPrefEmploymentType', 'jobPrefWorkLocation', 'jobPreferences',
    'languages',
    'currentlyInSchool', 'elementary', 'secondary', 'tertiary', 'graduateStudies',
    'trainings', 'eligibilities', 'professionalLicenses', 'workExperiences',
    'otherSkills', 'otherSkillsSpecify',
];

const EP_NAME_SQL = "CONCAT(b.last_name, ', ', b.first_name, CASE WHEN b.middle_name IS NOT NULL THEN ' ' || LEFT(b.middle_name, 1) || '.' ELSE '' END)";

// The employer's currently open (Pending) requests, so a list can show "waiting for the PESO" on a row.
function epOpenRequests($employerId)
{
    $s = db()->prepare("SELECT * FROM employer_requests WHERE employer_id = :e AND status = 'Pending'");
    $s->execute([':e' => (int) $employerId]);
    $out = ['referral' => [], 'placement' => [], 'vacancy' => [], 'company' => null];
    foreach ($s->fetchAll() as $r) {
        $p = json_decode((string) $r['payload'], true) ?: [];
        $item = ['id' => (int) $r['request_id'], 'requestedStatus' => $p['status'] ?? null, 'dateHired' => $p['dateHired'] ?? null, 'submittedAt' => $r['submitted_at']];
        if ($r['request_type'] === 'referral_status')       $out['referral'][(int) $r['referral_id']] = $item;
        elseif ($r['request_type'] === 'placement_status')  $out['placement'][(int) $r['placement_id']] = $item;
        elseif ($r['request_type'] === 'vacancy_update')    $out['vacancy'][(int) $r['vacancy_id']] = ['id' => (int) $r['request_id'], 'changes' => $p, 'submittedAt' => $r['submitted_at']];
        elseif ($r['request_type'] === 'company_info')      $out['company'] = ['id' => (int) $r['request_id'], 'changes' => $p, 'submittedAt' => $r['submitted_at']];
    }
    return $out;
}

// GET /api/employer_portal/overview
function epOverview($a)
{
    $e = (int) $a['employer_id'];
    $one = function ($sql) use ($e) { $s = db()->prepare($sql); $s->execute([':e' => $e]); return (int) $s->fetchColumn(); };

    $recent = db()->prepare(
        "SELECT q.request_id, q.request_type, q.status, q.review_note, q.submitted_at, q.reviewed_at, q.payload,
                COALESCE(rn.n, pn.n) AS applicant_name, COALESCE(rv.job_title, pv.job_title, vv.job_title) AS job_title
         FROM employer_requests q
         LEFT JOIN LATERAL (SELECT " . EP_NAME_SQL . " AS n, r.vacancy_id FROM employment_facilitation_referrals r
                            JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
                            JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id WHERE r.referral_id = q.referral_id) rn ON TRUE
         LEFT JOIN vacancies rv ON rv.vacancy_id = rn.vacancy_id
         LEFT JOIN LATERAL (SELECT " . EP_NAME_SQL . " AS n, p.vacancy_id FROM employment_facilitation_placements p
                            JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
                            JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id WHERE p.placement_id = q.placement_id) pn ON TRUE
         LEFT JOIN vacancies pv ON pv.vacancy_id = pn.vacancy_id
         LEFT JOIN vacancies vv ON vv.vacancy_id = CAST(to_jsonb(q)->>'vacancy_id' AS bigint)
         WHERE q.employer_id = :e AND q.status <> 'Pending'
         ORDER BY COALESCE(q.reviewed_at, q.submitted_at) DESC, q.request_id DESC LIMIT 6"
    );
    $recent->execute([':e' => $e]);

    json(['status' => 'ok', 'data' => [
        'companyName'      => $a['company_name'],
        'openReferrals'    => $one("SELECT COUNT(*) FROM employment_facilitation_referrals r JOIN vacancies v ON v.vacancy_id = r.vacancy_id
                                    WHERE v.employer_id = :e AND r.deleted_at IS NULL AND r.status IN ('Pending', 'Interviewed')"),
        'activePlacements' => $one("SELECT COUNT(*) FROM employment_facilitation_placements WHERE employer_id = :e AND status = 'Active'"),
        'openVacancies'    => $one("SELECT COUNT(*) FROM vacancies WHERE employer_id = :e AND status = 'Open'"),
        'pendingRequests'  => $one("SELECT COUNT(*) FROM employer_requests WHERE employer_id = :e AND status = 'Pending'"),
        'recentDecisions'  => array_map('epRequestRow', $recent->fetchAll()),
    ]]);
}

// GET /api/employer_portal/company
function epCompany($a)
{
    $s = db()->prepare(
        "SELECT e.employer_id, e.company_name, e.industry, e.business_type, e.tin_number, e.contact_person_name, e.contact_person_position,
                e.contact_number, e.email_address, e.building_no, e.street, e.status::text AS status, e.date_registered,
                bgy.barangay_name, c.city_name, p.province_name
         FROM employers e
         LEFT JOIN barangays bgy ON bgy.barangay_id = e.barangay_id
         LEFT JOIN cities c      ON c.city_id = bgy.city_id
         LEFT JOIN provinces p   ON p.province_id = c.province_id
         WHERE e.employer_id = :e"
    );
    $s->execute([':e' => (int) $a['employer_id']]);
    $r = $s->fetch();
    $open = epOpenRequests($a['employer_id']);
    $addr = implode(', ', array_filter([$r['building_no'], $r['street'], $r['barangay_name'], $r['city_name'], $r['province_name']], fn ($x) => $x !== null && trim((string) $x) !== ''));
    json(['status' => 'ok', 'data' => [
        'id' => (int) $r['employer_id'], 'companyName' => $r['company_name'], 'industry' => $r['industry'], 'businessType' => $r['business_type'],
        'tinNumber' => $r['tin_number'], 'contactPersonName' => $r['contact_person_name'], 'contactPersonPosition' => $r['contact_person_position'],
        'contactNumber' => $r['contact_number'], 'emailAddress' => $r['email_address'], 'buildingNo' => $r['building_no'], 'street' => $r['street'],
        'address' => $addr, 'status' => $r['status'], 'dateRegistered' => $r['date_registered'], 'openRequest' => $open['company'],
    ]]);
}

// GET /api/employer_portal/vacancies
function epVacancies($a)
{
    $s = db()->prepare(
        "SELECT v.vacancy_id, v.job_title, v.job_type::text AS job_type, v.description, v.requirements, v.status::text AS status, v.salary_min, v.salary_max, v.slots_total,
                to_jsonb(v)->>'source' AS source,
                (SELECT COUNT(*) FROM employment_facilitation_placements p WHERE p.vacancy_id = v.vacancy_id AND p.status = 'Active') AS active_placements
         FROM vacancies v WHERE v.employer_id = :e ORDER BY v.vacancy_id DESC"
    );
    $s->execute([':e' => (int) $a['employer_id']]);
    $open = epOpenRequests($a['employer_id']);
    json(['status' => 'ok', 'data' => array_map(fn ($r) => [
        'id' => (int) $r['vacancy_id'], 'jobTitle' => $r['job_title'], 'jobType' => $r['job_type'], 'description' => $r['description'],
        'requirements' => $r['requirements'], 'status' => $r['status'],
        'salaryMin' => $r['salary_min'] !== null ? (float) $r['salary_min'] : null, 'salaryMax' => $r['salary_max'] !== null ? (float) $r['salary_max'] : null,
        'slotsTotal' => $r['slots_total'] !== null ? (int) $r['slots_total'] : null,
        'hired' => (int) $r['active_placements'], 'source' => $r['source'] ?: 'Staff',
        'openRequest' => $open['vacancy'][(int) $r['vacancy_id']] ?? null,
    ], $s->fetchAll())]);
}

// GET /api/employer_portal/referrals
function epReferrals($a)
{
    $s = db()->prepare(
        "SELECT r.referral_id, bs.beneficiary_id AS applicant_id, " . EP_NAME_SQL . " AS applicant_name, v.job_title, r.date_referred, r.status::text AS status
         FROM employment_facilitation_referrals r
         JOIN vacancies v ON v.vacancy_id = r.vacancy_id
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         WHERE v.employer_id = :e AND r.deleted_at IS NULL AND b.deleted_at IS NULL ORDER BY r.referral_id DESC"
    );
    $s->execute([':e' => (int) $a['employer_id']]);
    $open = epOpenRequests($a['employer_id']);
    json(['status' => 'ok', 'data' => array_map(fn ($r) => [
        'id' => (int) $r['referral_id'], 'applicantId' => (int) $r['applicant_id'], 'applicantName' => $r['applicant_name'],
        'jobTitle' => $r['job_title'], 'dateReferred' => $r['date_referred'], 'status' => $r['status'],
        'openRequest' => $open['referral'][(int) $r['referral_id']] ?? null,
    ], $s->fetchAll())]);
}

// GET /api/employer_portal/placements
function epPlacements($a)
{
    $s = db()->prepare(
        "SELECT p.placement_id, p.vacancy_id, bs.beneficiary_id AS applicant_id, " . EP_NAME_SQL . " AS applicant_name, v.job_title, p.date_hired, p.status::text AS status
         FROM employment_facilitation_placements p
         JOIN vacancies v ON v.vacancy_id = p.vacancy_id
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         WHERE p.employer_id = :e AND b.deleted_at IS NULL ORDER BY p.placement_id DESC"
    );
    $s->execute([':e' => (int) $a['employer_id']]);
    $open = epOpenRequests($a['employer_id']);
    json(['status' => 'ok', 'data' => array_map(fn ($r) => [
        'id' => (int) $r['placement_id'], 'vacancyId' => (int) $r['vacancy_id'], 'applicantId' => (int) $r['applicant_id'], 'applicantName' => $r['applicant_name'],
        'jobTitle' => $r['job_title'], 'dateHired' => $r['date_hired'], 'status' => $r['status'],
        'openRequest' => $open['placement'][(int) $r['placement_id']] ?? null,
    ], $s->fetchAll())]);
}

// GET /api/employer_portal/applicant/{id}  -- only someone referred to / hired by THIS employer; the view goes in the access log.
function epApplicant($a, $id)
{
    if ($id === null || !ctype_digit((string) $id)) error('Invalid applicant id.', 422);
    $s = db()->prepare(
        "SELECT 1 FROM employment_facilitation_referrals r
           JOIN vacancies v ON v.vacancy_id = r.vacancy_id
           JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
          WHERE v.employer_id = :e AND r.deleted_at IS NULL AND bs.beneficiary_id = :a
         UNION ALL
         SELECT 1 FROM employment_facilitation_placements p
           JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
          WHERE p.employer_id = :e AND bs.beneficiary_id = :a
         LIMIT 1"
    );
    $s->execute([':e' => (int) $a['employer_id'], ':a' => (int) $id]);
    // Not one of this employer's applicants: the same answer as "does not exist".
    if (!$s->fetchColumn()) error('Applicant not found.', 404);

    $applicant = employmentBuildApplicant((int) $id);
    if ($applicant === null) error('Applicant not found.', 404);
    $full = $applicant['fullFormData'] ?? [];
    $data = [];
    foreach (EP_APPLICANT_KEYS as $k) if (array_key_exists($k, $full)) $data[$k] = $full[$k];

    db()->prepare("INSERT INTO employer_access_log (account_id, employer_id, action, applicant_id, ip_address) VALUES (:acc, :e, 'view_applicant', :a, :ip)")
        ->execute([':acc' => (int) $a['account_id'], ':e' => (int) $a['employer_id'], ':a' => (int) $id, ':ip' => employerClientIp()]);

    $documents = array_map(fn ($r) => [
        'id' => (int) $r['document_id'], 'label' => efDocumentLabel($r), 'type' => (string) $r['document_type'], 'fileName' => $r['file_name'],
        'fileSize' => (int) $r['file_size'], 'previewable' => (EP_FILE_TYPES[epDocumentExt($r)][1] ?? false),
    ], array_values(array_filter(epSharedDocuments($a['employer_id'], 'bs.beneficiary_id = :a', [':a' => (int) $id]), fn ($r) => isset(EP_FILE_TYPES[epDocumentExt($r)]))));

    json(['status' => 'ok', 'data' => ['id' => (int) $id, 'name' => $applicant['name'], 'data' => $data, 'documents' => $documents]]);
}

// Documents the PESO shared with THIS employer. A document counts only if staff selected it for a referral of this company that is
// not deleted and not "Not Hired", and the applicant is not deleted. $where narrows it (a document, or an applicant).
function epSharedDocsAvailable()
{
    return (bool) db()->query("SELECT to_regclass('public.referral_shared_documents') IS NOT NULL")->fetchColumn();   // false until migration 087 is run
}

function epSharedDocuments($employerId, $where, array $args)
{
    if (!epSharedDocsAvailable()) return [];
    $s = db()->prepare(
        "SELECT DISTINCT d.document_id, d.beneficiary_id, d.document_type, d.title, d.file_name, d.file_path, d.file_size
         FROM referral_shared_documents rsd
         JOIN employment_facilitation_referrals r ON r.referral_id = rsd.referral_id
         JOIN vacancies v ON v.vacancy_id = r.vacancy_id
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         JOIN attached_documents d ON d.document_id = rsd.document_id AND d.beneficiary_id = bs.beneficiary_id AND d.document_source = 'Employment Facilitation'
         WHERE v.employer_id = :e AND r.deleted_at IS NULL AND b.deleted_at IS NULL AND r.status::text <> 'Not Hired' AND {$where}
         ORDER BY d.document_id"
    );
    $s->execute([':e' => (int) $employerId] + $args);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// What an employer may open, and how it is served. Anything else is refused, whatever its name says.
const EP_FILE_TYPES = [
    'pdf'  => ['application/pdf', true],
    'jpg'  => ['image/jpeg', true], 'jpeg' => ['image/jpeg', true], 'png' => ['image/png', true], 'gif' => ['image/gif', true], 'webp' => ['image/webp', true],
    'doc'  => ['application/msword', false],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
    'xls'  => ['application/vnd.ms-excel', false],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
];

function epDocumentExt($row)
{
    return strtolower(pathinfo((string) $row['file_path'], PATHINFO_EXTENSION));
}

// GET /api/employer_portal/document/{id}[?download=1]  -- one shared document: the file itself, after the checks.
function epDocument($a, $id)
{
    if ($id === null || !ctype_digit((string) $id)) error('Invalid document id.', 422);
    $rows = epSharedDocuments($a['employer_id'], 'd.document_id = :d', [':d' => (int) $id]);
    // Not shared with this employer (or no longer): the same answer as "does not exist".
    if (!$rows) error('Document not found.', 404);
    $row = $rows[0];

    $ext = epDocumentExt($row);
    if (!isset(EP_FILE_TYPES[$ext])) error('This file cannot be opened here.', 404);
    // The file must really be inside the uploads folder (a stored path can never lead anywhere else) and really be what its extension says.
    $base = realpath(__DIR__ . '/../uploads');
    $abs  = realpath(__DIR__ . '/../' . ltrim((string) $row['file_path'], '/\\'));
    if ($base === false || $abs === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) error('Document not found.', 404);
    $head = file_get_contents($abs, false, null, 0, 8192);
    if ($head === false || !uploadContentMatches($ext, $head)) error('This file cannot be opened here.', 404);

    [$mime, $previewable] = EP_FILE_TYPES[$ext];
    // Word (.docx) files are shown by the employer page itself (it fetches the file and draws it), so ?view=1 counts as a view, not a download.
    $viewInPage = $ext === 'docx' && ($_GET['view'] ?? '') === '1';
    $download = ($_GET['download'] ?? '') === '1' || (!$previewable && !$viewInPage);
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string) $row['file_name'])) ?: ('document.' . $ext);

    // Only a download gets its own line in the access log. Looking at a shared document on screen is part of viewing the
    // applicant (already logged as view_applicant when the details were opened), so it is not logged again.
    if ($download) {
        db()->prepare("INSERT INTO employer_access_log (account_id, employer_id, action, applicant_id, document_id, ip_address) VALUES (:acc, :e, 'download_document', :ap, :d, :ip)")
            ->execute([':acc' => (int) $a['account_id'], ':e' => (int) $a['employer_id'],
                       ':ap' => (int) $row['beneficiary_id'], ':d' => (int) $row['document_id'], ':ip' => employerClientIp()]);
    }

    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

// One request as the employer sees it.
function epRequestRow($r)
{
    $p = json_decode((string) ($r['payload'] ?? '[]'), true) ?: [];
    unset($p['_before']);   // staff-side record of the values before an accepted change; not shown to employers
    return [
        'id' => (int) $r['request_id'], 'type' => $r['request_type'], 'status' => $r['status'],
        'applicantName' => $r['applicant_name'] ?? null,
        'jobTitle' => $r['request_type'] === 'vacancy_new' ? ($p['jobTitle'] ?? null) : ($r['job_title'] ?? null),
        'requestedStatus' => $p['status'] ?? null, 'dateHired' => $p['dateHired'] ?? null,
        'changes' => in_array($r['request_type'], ['company_info', 'vacancy_new', 'vacancy_update'], true) ? $p : null,
        'reviewNote' => $r['review_note'] ?? null, 'submittedAt' => $r['submitted_at'] ?? null, 'reviewedAt' => $r['reviewed_at'] ?? null,
    ];
}

// GET /api/employer_portal/requests
function epRequests($a)
{
    $s = db()->prepare(
        "SELECT q.request_id, q.request_type, q.status, q.review_note, q.submitted_at, q.reviewed_at, q.payload,
                COALESCE(rn.n, pn.n) AS applicant_name, COALESCE(rv.job_title, pv.job_title, vv.job_title) AS job_title
         FROM employer_requests q
         LEFT JOIN LATERAL (SELECT " . EP_NAME_SQL . " AS n, r.vacancy_id FROM employment_facilitation_referrals r
                            JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
                            JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id WHERE r.referral_id = q.referral_id) rn ON TRUE
         LEFT JOIN vacancies rv ON rv.vacancy_id = rn.vacancy_id
         LEFT JOIN LATERAL (SELECT " . EP_NAME_SQL . " AS n, p.vacancy_id FROM employment_facilitation_placements p
                            JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
                            JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id WHERE p.placement_id = q.placement_id) pn ON TRUE
         LEFT JOIN vacancies pv ON pv.vacancy_id = pn.vacancy_id
         LEFT JOIN vacancies vv ON vv.vacancy_id = CAST(to_jsonb(q)->>'vacancy_id' AS bigint)
         WHERE q.employer_id = :e ORDER BY q.submitted_at DESC, q.request_id DESC LIMIT 200"
    );
    $s->execute([':e' => (int) $a['employer_id']]);
    json(['status' => 'ok', 'data' => array_map('epRequestRow', $s->fetchAll())]);
}

// Clean a proposed hire date: Y-m-d, not in the future. Empty -> today.
function epHireDate($v)
{
    $v = trim((string) $v);
    if ($v === '') return date('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) error('The date hired is not a valid date.', 422);
    if ($v > date('Y-m-d')) error('The date hired cannot be in the future.', 422);
    return $v;
}

// POST /api/employer_portal/submitRequest  { type, referralId | placementId, payload }
function epSubmitRequest($a)
{
    $d = body();
    $e = (int) $a['employer_id'];
    $type = (string) ($d['type'] ?? '');
    $payloadIn = is_array($d['payload'] ?? null) ? $d['payload'] : [];
    $referralId = null; $placementId = null; $vacancyId = null; $payload = [];

    if ($type === 'referral_status') {
        $referralId = ctype_digit((string) ($d['referralId'] ?? '')) ? (int) $d['referralId'] : 0;
        $s = db()->prepare(
            "SELECT r.status::text AS status, r.beneficiary_service_id
             FROM employment_facilitation_referrals r
             JOIN vacancies v ON v.vacancy_id = r.vacancy_id
             JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
             JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
             WHERE r.referral_id = :r AND v.employer_id = :e AND r.deleted_at IS NULL AND b.deleted_at IS NULL"
        );
        $s->execute([':r' => $referralId, ':e' => $e]);
        $row = $s->fetch();
        if (!$row) error('Referral not found.', 404);
        $cur = $row['status'];
        $target = (string) ($payloadIn['status'] ?? '');
        if (!in_array($target, EP_REFERRAL_TARGETS, true)) error('Choose a valid status.', 422);
        if (!in_array($cur, ['Pending', 'Interviewed'], true) || $target === $cur) error("This referral is already marked {$cur}.", 409);
        if ($target === 'Hired' && efHasActivePlacement(db(), $row['beneficiary_service_id'])) {
            error('This applicant is already hired, so they cannot be hired again.', 409);
        }
        $payload = ['status' => $target];
        if ($target === 'Hired') $payload['dateHired'] = epHireDate($payloadIn['dateHired'] ?? '');
    } elseif ($type === 'placement_status') {
        $placementId = ctype_digit((string) ($d['placementId'] ?? '')) ? (int) $d['placementId'] : 0;
        $s = db()->prepare("SELECT status::text FROM employment_facilitation_placements WHERE placement_id = :p AND employer_id = :e");
        $s->execute([':p' => $placementId, ':e' => $e]);
        $cur = $s->fetchColumn();
        if ($cur === false) error('Placement not found.', 404);
        $target = (string) ($payloadIn['status'] ?? '');
        if (!in_array($target, EP_PLACEMENT_TARGETS, true)) error('Choose a valid status.', 422);
        if ($cur !== 'Active') error("This placement is already marked {$cur}.", 409);
        $payload = ['status' => $target];
    } elseif ($type === 'vacancy_new') {
        $s = db()->prepare("SELECT COUNT(*) FROM employer_requests WHERE employer_id = :e AND request_type = 'vacancy_new' AND status = 'Pending'");
        $s->execute([':e' => $e]);
        if ((int) $s->fetchColumn() >= VACANCY_REQUEST_MAX_WAITING) {
            error('You already have ' . VACANCY_REQUEST_MAX_WAITING . ' new openings waiting for the PESO. Please wait for them to be reviewed first.', 409);
        }
        $res = vacancyRequestClean($payloadIn, true);
        if ($res['error']) error($res['error'], 422);
        $payload = $res['clean'];
        $s = db()->prepare("SELECT 1 FROM employer_requests WHERE employer_id = :e AND request_type = 'vacancy_new' AND status = 'Pending' AND lower(payload->>'jobTitle') = lower(:t)");
        $s->execute([':e' => $e, ':t' => $payload['jobTitle']]);
        if ($s->fetchColumn()) error('You already asked for an opening with this job title, and it is waiting for the PESO.', 409);
    } elseif ($type === 'vacancy_update') {
        $vacancyId = ctype_digit((string) ($d['vacancyId'] ?? '')) ? (int) $d['vacancyId'] : 0;
        $s = db()->prepare(
            "SELECT v.vacancy_id, v.job_title, v.job_type::text AS job_type, v.description, v.requirements, v.salary_min, v.salary_max, v.slots_total, v.status::text AS status,
                    (SELECT COUNT(*) FROM employment_facilitation_placements p WHERE p.vacancy_id = v.vacancy_id AND p.status = 'Active') AS active_placements
             FROM vacancies v WHERE v.vacancy_id = :v AND v.employer_id = :e"
        );
        $s->execute([':v' => $vacancyId, ':e' => $e]);
        $v = $s->fetch();
        if (!$v) error('Opening not found.', 404);
        $current = vacancyRequestCurrent($v);
        $res = vacancyRequestClean($payloadIn, false, $current);
        if ($res['error']) error($res['error'], 422);
        $payload = vacancyRequestChanges($res['clean'], $current);
        if (!$payload) error('You have not changed anything.', 422);
        if (isset($payload['slotsTotal']) && $payload['slotsTotal'] < (int) $v['active_placements']) {
            error("This opening already has {$v['active_placements']} hired employee(s), so it cannot be lowered to {$payload['slotsTotal']}.", 409);
        }
    } elseif ($type === 'company_info') {
        $s = db()->prepare("SELECT contact_person_name, contact_person_position, contact_number, email_address, building_no, street FROM employers WHERE employer_id = :e");
        $s->execute([':e' => $e]);
        $cur = $s->fetch();
        foreach (EP_COMPANY_FIELDS as $key => $col) {
            if (!array_key_exists($key, $payloadIn)) continue;
            $v = trim((string) $payloadIn[$key]);
            if (strlen($v) > 255) error('One of the values is too long.', 422);
            if ($key === 'emailAddress' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) error('Enter a valid email address.', 422);
            if ($key === 'contactPersonName' && $v === '') error('The contact person name cannot be empty.', 422);
            if ($v !== trim((string) ($cur[$col] ?? ''))) $payload[$key] = $v;   // only what really changed
        }
        if (!$payload) error('You have not changed anything.', 422);
    } else {
        error('Unknown request type.', 422);
    }

    try {
        $args = [':e' => $e, ':by' => $a['username'], ':t' => $type, ':r' => $referralId, ':p' => $placementId, ':pl' => json_encode($payload, JSON_UNESCAPED_UNICODE)];
        $cols = 'employer_id, submitted_by, request_type, referral_id, placement_id, payload';
        $vals = ':e, :by, :t, :r, :p, CAST(:pl AS jsonb)';
        if ($type === 'vacancy_update') { $cols .= ', vacancy_id'; $vals .= ', :v'; $args[':v'] = $vacancyId; }
        $ins = db()->prepare("INSERT INTO employer_requests ({$cols}) VALUES ({$vals}) RETURNING request_id");
        $ins->execute($args);
        $id = (int) $ins->fetchColumn();
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23505') error('There is already an open request for this. Wait for the PESO to review it, or withdraw it first.', 409);
        // The employer only sees a plain message; the real reason goes to the server log (for example a migration that has not been run).
        error_log('Employer request could not be saved: ' . $ex->getMessage());
        error('Could not send your request. Please try again.', 500);
    }
    json(['status' => 'ok', 'message' => 'Request sent to the PESO.', 'data' => ['id' => $id]], 201);
}

// POST /api/employer_portal/withdrawRequest/{id}  -- only your own request, only while it is still waiting.
function epWithdrawRequest($a, $id)
{
    if ($id === null || !ctype_digit((string) $id)) error('Invalid request id.', 422);
    $u = db()->prepare("UPDATE employer_requests SET status = 'Withdrawn' WHERE request_id = :id AND employer_id = :e AND status = 'Pending'");
    $u->execute([':id' => (int) $id, ':e' => (int) $a['employer_id']]);
    if ($u->rowCount() === 0) {
        $s = db()->prepare("SELECT status FROM employer_requests WHERE request_id = :id AND employer_id = :e");
        $s->execute([':id' => (int) $id, ':e' => (int) $a['employer_id']]);
        $st = $s->fetchColumn();
        if ($st === false) error('Request not found.', 404);
        error("This request was already {$st}, so it can't be withdrawn.", 409);
    }
    json(['status' => 'ok', 'message' => 'Request withdrawn.']);
}
