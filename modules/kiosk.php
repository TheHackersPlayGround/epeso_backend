<?php
// Public endpoints for the applicant self-service kiosk (/apply/). NO LOGIN is required here,
// so this module is deliberately small and write-only:
//   status                 is the kiosk open, and which programs does it offer
//   provinces / cities /   read-only address lists for the form's pickers
//   barangaysByCity / allCities
//   cdspServices           the CDSP service names the CDSP form offers
//   skillsOptions          the qualification / purpose lists the Skills Training form offers
//   submit                 store a PENDING submission for staff to review
// Nothing here can read applicant data, and a submission never becomes a real applicant until
// staff approve it (see modules/submissions.php). Which programs exist, and which fields an
// applicant may send for each, is defined in core/kiosk_programs.php.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/location_queries.php';
include_once __DIR__ . '/../core/activity_log.php';
include_once __DIR__ . '/../core/submission_cleanup.php';
include_once __DIR__ . '/../core/kiosk_programs.php';

// Largest submission accepted (the forms have no file uploads, so it is a few KB in practice).
const KIOSK_MAX_BODY_BYTES = 200000;
// At most this many submissions per device (IP) within the window below.
const KIOSK_MAX_PER_IP = 20;
const KIOSK_WINDOW_MINUTES = 10;
// Stop accepting once this many are waiting, so the inbox can't be flooded.
const KIOSK_MAX_PENDING = 2000;

// Router entry point. index.php calls this with the parsed action/id/method.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'status':          return kioskStatus();
        case 'provinces':       return kioskProvinces();
        case 'cities':          return kioskCities();
        case 'barangaysByCity': return kioskBarangaysByCity();
        case 'allCities':       return kioskAllCities();
        case 'cdspServices':    return kioskCdspServices();
        case 'skillsOptions':   return kioskSkillsOptions();
        case 'submit':
            if ($method !== 'POST') error('Use POST.', 405);
            return kioskSubmit();
        default:
            error("Unknown kiosk action: {$action}", 404);
    }
}

// Whether the kiosk switch is on. A missing table (migration not run yet) counts as off.
function kioskIsEnabled()
{
    try {
        $v = db()->query("SELECT setting_value FROM app_settings WHERE setting_key = 'kiosk_enabled'")->fetchColumn();
        return $v === 'true';
    } catch (Throwable $e) {
        return false;
    }
}

// GET /api/kiosk/status -> { enabled, programs: [{ id, label }] }   (only the programs switched on)
function kioskStatus()
{
    $registry = kioskProgramRegistry();
    $programs = [];
    foreach (kioskEnabledPrograms() as $id) {
        $programs[] = ['id' => $id, 'label' => $registry[$id]['label']];
    }
    json(['status' => 'ok', 'data' => ['enabled' => kioskIsEnabled(), 'programs' => $programs]]);
}

// ── Address lists (read-only reference data) ───────────────────────────────

function kioskSearchTerm()
{
    return isset($_GET['search']) ? mb_substr(trim($_GET['search']), 0, 100) : '';
}

function kioskProvinces()
{
    json(['status' => 'ok', 'data' => locQueryProvinces(kioskSearchTerm())]);
}

function kioskCities()
{
    $pid = isset($_GET['provinceId']) && is_numeric($_GET['provinceId']) ? (int) $_GET['provinceId'] : 0;
    json(['status' => 'ok', 'data' => locQueryCities($pid, kioskSearchTerm())]);
}

function kioskBarangaysByCity()
{
    $cid = isset($_GET['cityId']) && is_numeric($_GET['cityId']) ? (int) $_GET['cityId'] : 0;
    json(['status' => 'ok', 'data' => locQueryBarangaysByCity($cid, kioskSearchTerm())]);
}

function kioskAllCities()
{
    json(['status' => 'ok', 'data' => locQueryAllCities(kioskSearchTerm())]);
}

// ── Program option lists (read-only) ───────────────────────────────────────

// Names of the active CDSP services (Career Coaching, ...): the choices on the CDSP form.
function kioskCdspServiceNames()
{
    $stmt = db()->query(
        "SELECT s.service_name FROM services s
         WHERE s.is_active = true
           AND s.parent_service_id = (SELECT service_id FROM services WHERE service_code = 'CDSP' LIMIT 1)
         ORDER BY s.service_id"
    );
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// GET /api/kiosk/cdspServices -> ["Career Coaching", ...]
function kioskCdspServices()
{
    json(['status' => 'ok', 'data' => kioskCdspServiceNames()]);
}

// GET /api/kiosk/skillsOptions -> { qualifications: [{id,name}], purposes: [{id,name}] }
function kioskSkillsOptions()
{
    $load = function ($type) {
        $s = db()->prepare("SELECT option_id, option_name FROM skills_training_options WHERE option_type = :t AND is_active = true ORDER BY option_id");
        $s->execute([':t' => $type]);
        return array_map(function ($r) { return ['id' => (int) $r['option_id'], 'name' => $r['option_name']]; }, $s->fetchAll());
    };
    json(['status' => 'ok', 'data' => ['qualifications' => $load('Qualification'), 'purposes' => $load('Purpose')]]);
}

// ── Submit ─────────────────────────────────────────────────────────────────

// Trims every string and enforces sane limits on length, list size and nesting, so a
// submission can't smuggle in huge or deeply nested data. Returns the cleaned value; sets
// $problem when a limit is exceeded.
function kioskClean($v, $depth, &$problem)
{
    if ($depth > 4) { $problem = 'The form data is too deeply nested.'; return null; }
    if (is_string($v)) {
        $v = trim($v);
        if (mb_strlen($v) > 1000) { $problem = 'One of the answers is too long.'; return ''; }
        return $v;
    }
    if (is_array($v)) {
        // A list (trainings, work experience, ...) is capped; a keyed object (the form itself, which
        // has ~75 fields) gets a much higher cap that only stops absurd payloads.
        $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
        if (($isList && count($v) > 60) || (!$isList && count($v) > 250)) {
            $problem = 'One of the lists has too many entries.';
            return [];
        }
        $out = [];
        foreach ($v as $k => $item) {
            if (!is_int($k) && !is_string($k)) continue;
            $out[$k] = kioskClean($item, $depth + 1, $problem);
        }
        return $out;
    }
    if (is_bool($v) || is_int($v) || is_float($v) || $v === null) return $v;
    return null;
}

// Drops everything an applicant isn't allowed to send for this program (see core/kiosk_programs.php).
function kioskFilterFields($cfg, $d)
{
    if (is_array($cfg['allowed'])) {
        return array_intersect_key($d, array_flip($cfg['allowed']));
    }
    foreach ($cfg['staffOnly'] as $k) unset($d[$k]);
    return $d;
}

// Required-field checks. Returns an error message, or '' when fine. (Staff run the full
// validation again when they approve, so this only guards what the inbox needs.)
function kioskValidate($program, $cfg, $d)
{
    $first = (string) ($d[$cfg['first']] ?? '');
    $last  = (string) ($d[$cfg['last']] ?? '');
    if ($first === '' || $last === '') return 'First name and last name are required.';
    if (mb_strlen($first) > 100 || mb_strlen((string) ($d['middleName'] ?? '')) > 100 || mb_strlen($last) > 100) {
        return 'A name is too long.';
    }
    if (!in_array($d['sex'] ?? '', ['Male', 'Female'], true)) return 'Please choose Male or Female.';
    if (($cfg['civilRequired'] ?? true) && !in_array($d['civilStatus'] ?? '', ['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Annulled'], true)) {
        return 'Please choose a civil status.';
    }

    $dob = (string) ($d[$cfg['birth']] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return 'Please enter a valid date of birth.';
    }
    if ($dob > date('Y-m-d')) return 'Date of birth cannot be in the future.';
    if ((int) $m[1] < 1900) return 'Please enter a valid date of birth.';

    $contact = (string) ($d['contactNumber'] ?? '');
    if (($contact !== '' || $cfg['contactRequired']) && !preg_match('/^[0-9+()\-\s]{7,20}$/', $contact)) {
        return 'Enter a valid contact number.';
    }
    $email = (string) ($d['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid email address.';

    if (!isset($d['barangayId']) || !is_numeric($d['barangayId'])) return 'Please select your barangay from the list.';
    $chk = db()->prepare("SELECT 1 FROM barangays WHERE barangay_id = :id");
    $chk->execute([':id' => (int) $d['barangayId']]);
    if (!$chk->fetchColumn()) return 'Please select your barangay from the list.';

    return kioskProgramValidate($program, $d);
}

// The extra required answers of one program ('' when fine).
function kioskProgramValidate($program, $d)
{
    if ($program === 'cdsp') {
        if (empty($d['highestEducation'])) return 'Please choose your highest educational attainment.';
        if (empty($d['employmentStatus'])) return 'Please choose your employment status.';
        if (!in_array($d['serviceAvailed'] ?? '', kioskCdspServiceNames(), true)) return 'Please choose a CDSP service.';
    }
    if ($program === 'slp' && !isset($d['is4PsBeneficiary'])) return 'Please choose your participant type (4Ps or Non-4Ps).';
    if ($program === 'ofw') {
        if (!in_array($d['employmentStatus'] ?? '', ['Employed', 'Unemployed', 'Self Employed', 'Underemployed'], true)) return 'Please choose your employment status.';
        if (isset($d['typeOfRequest']) && !is_array($d['typeOfRequest'])) return 'Invalid request type.';
    }
    return '';
}

// A short, unambiguous code the applicant can quote to staff, e.g. EF-261110-7KQ4.
function kioskReference($prefix)
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
    $code = '';
    for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $prefix . '-' . date('ymd') . '-' . $code;
}

// POST /api/kiosk/submit  { program, data: <the form>, website: <honeypot, must be empty> }
function kioskSubmit()
{
    if (!kioskIsEnabled()) {
        error('Self-service registration is currently closed. Please see the staff at the front desk.', 403);
    }

    // Reject oversized requests before reading the body.
    $len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($len > KIOSK_MAX_BODY_BYTES) error('The submission is too large.', 413);

    $req = body();

    $program  = (string) ($req['program'] ?? '');
    $registry = kioskProgramRegistry();
    if (!isset($registry[$program])) error('Unknown program.', 422);
    $cfg = $registry[$program];

    // Honeypot: a real person never fills this hidden field. Pretend it worked, store nothing.
    if (!empty($req['website'])) {
        json(['status' => 'ok', 'data' => ['referenceNo' => $cfg['prefix'] . '-' . date('ymd') . '-0000']], 201);
    }

    if (!in_array($program, kioskEnabledPrograms(), true)) {
        error('This service is not taking self-service applications right now. Please see the staff at the front desk.', 403);
    }
    if (!isset($req['data']) || !is_array($req['data'])) error('The form is empty.', 422);

    $problem = '';
    $d = kioskClean($req['data'], 0, $problem);
    if ($problem !== '') error($problem, 422);
    $d = kioskFilterFields($cfg, $d);

    $msg = kioskValidate($program, $cfg, $d);
    if ($msg !== '') error($msg, 422);

    $pdo = db();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        // Per-device rate limit, and a cap on how many can pile up unreviewed.
        $s = $pdo->prepare(
            "SELECT count(*) FROM applicant_submissions
             WHERE submitted_ip = :ip AND submitted_at > now() - make_interval(mins => :mins::int)"
        );
        $s->execute([':ip' => $ip, ':mins' => KIOSK_WINDOW_MINUTES]);
        if ((int) $s->fetchColumn() >= KIOSK_MAX_PER_IP) {
            error('Too many submissions from this device. Please wait a few minutes or ask the staff for help.', 429);
        }
        $pending = (int) $pdo->query("SELECT count(*) FROM applicant_submissions WHERE status = 'Pending'")->fetchColumn();
        if ($pending >= KIOSK_MAX_PENDING) {
            error('The queue is full right now. Please ask the staff for help.', 503);
        }

        $middle = (string) ($d['middleName'] ?? '');
        $name = trim($d[$cfg['last']] . ', ' . $d[$cfg['first']]
            . ($middle !== '' ? ' ' . mb_substr($middle, 0, 1) . '.' : '')
            . (!empty($d['suffix']) ? ' ' . $d['suffix'] : ''));

        $ins = $pdo->prepare(
            "INSERT INTO applicant_submissions (reference_no, program, applicant_name, birth_date, payload, submitted_ip)
             VALUES (:ref, :program, :name, :dob, :payload::jsonb, :ip)
             RETURNING submission_id"
        );
        $ref = null;
        for ($attempt = 0; $attempt < 5 && $ref === null; $attempt++) {
            $candidate = kioskReference($cfg['prefix']);
            try {
                $ins->execute([
                    ':ref' => $candidate, ':program' => $program, ':name' => mb_substr($name, 0, 255),
                    ':dob' => $d[$cfg['birth']], ':payload' => json_encode($d, JSON_UNESCAPED_UNICODE), ':ip' => $ip,
                ]);
                $ref = $candidate;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23505') throw $e; // only retry a reference-number collision
            }
        }
        if ($ref === null) error('Could not create a reference number. Please try again.', 500);
    } catch (PDOException $e) {
        error('Could not save your application. Please ask the staff for help.', 500);
    }

    logActivity(null, 'Kiosk Submission', $cfg['module'], "Self-service application received: {$ref}");
    submissionsPurgeExpiredRejected(); // keeps the inbox tidy even if nobody opens it for a while
    json(['status' => 'ok', 'data' => ['referenceNo' => $ref]], 201);
}
