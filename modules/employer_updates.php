<?php
// Staff side of the employer portal: what employers asked for, waiting for staff to accept or reject
// (the "Employer Updates" option in the Employers tab).
//
// An employer never changes the main data itself. It sends a request (a referral status, a placement status,
// or new company details); staff look at it here and Accept (the change is applied with the same code staff use
// by hand, in one transaction) or Reject (with a reason the employer sees).
//
// Who may do what: employment Viewer lists/reads, employment Editor (or an administrator) accepts/rejects.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';
include_once __DIR__ . '/../core/ef_actions.php';
include_once __DIR__ . '/../core/ef_vacancy_rules.php';
include_once __DIR__ . '/../core/ef_promotion_rules.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'list':   requirePermission('employment', 'Viewer'); return euList();
        case 'counts': requirePermission('employment', 'Viewer'); return euCounts();
        case 'accept': requirePermission('employment', 'Editor'); return euAccept($id);
        case 'reject': requirePermission('employment', 'Editor'); return euReject($id);
        default:
            error("Unknown employer_updates action: {$action}", 404);
    }
}

// The company fields an employer may ask to change: payload key => employers column.
const EU_COMPANY_FIELDS = [
    'contactPersonName'     => 'contact_person_name',
    'contactPersonPosition' => 'contact_person_position',
    'contactNumber'         => 'contact_number',
    'emailAddress'          => 'email_address',
    'buildingNo'            => 'building_no',
    'street'                => 'street',
];

const EU_REFERRAL_TARGETS  = ['Interviewed', 'Hired', 'Not Hired'];
const EU_PLACEMENT_TARGETS = ['Resigned', 'Terminated', 'Completed'];
// The labels staff see for the fields of a promotion request (the saved "before" of an accepted request is keyed by these).
const EU_PROMOTION_LABELS = [
    'promotionDate' => 'Promotion date', 'newJobTitle' => 'New job title', 'newSalaryMin' => 'New minimum salary', 'newSalaryMax' => 'New maximum salary', 'remarks' => 'Remarks',
];

function euPayload($row)
{
    $p = json_decode((string) $row['payload'], true);
    return is_array($p) ? $p : [];
}

function euLoadReferral($pdo, $rid)
{
    $s = $pdo->prepare(
        "SELECT r.referral_id, r.status::text AS status, r.date_referred, r.beneficiary_service_id, r.vacancy_id,
                r.deleted_at, v.job_title, v.employer_id, e.company_name, b.deleted_at AS applicant_deleted,
                CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL THEN ' ' || LEFT(b.middle_name, 1) || '.' ELSE '' END) AS applicant_name
         FROM employment_facilitation_referrals r
         JOIN vacancies v             ON v.vacancy_id = r.vacancy_id
         JOIN employers e             ON e.employer_id = v.employer_id
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
         JOIN beneficiaries b         ON b.beneficiary_id = bs.beneficiary_id
         WHERE r.referral_id = :id"
    );
    $s->execute([':id' => (int) $rid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function euLoadPlacement($pdo, $pid)
{
    $s = $pdo->prepare(
        "SELECT p.placement_id, p.status::text AS status, p.date_hired, p.employer_id, v.job_title, e.company_name,
                CONCAT(b.last_name, ', ', b.first_name,
                       CASE WHEN b.middle_name IS NOT NULL THEN ' ' || LEFT(b.middle_name, 1) || '.' ELSE '' END) AS applicant_name
         FROM employment_facilitation_placements p
         JOIN vacancies v             ON v.vacancy_id = p.vacancy_id
         JOIN employers e             ON e.employer_id = p.employer_id
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
         JOIN beneficiaries b         ON b.beneficiary_id = bs.beneficiary_id
         WHERE p.placement_id = :id"
    );
    $s->execute([':id' => (int) $pid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function euLoadVacancy($pdo, $vid)
{
    $s = $pdo->prepare(
        "SELECT v.vacancy_id, v.employer_id, v.job_title, v.job_type::text AS job_type, v.description, v.requirements, v.salary_min, v.salary_max,
                v.slots_total, v.status::text AS status,
                (SELECT COUNT(*) FROM employment_facilitation_placements p WHERE p.vacancy_id = v.vacancy_id AND p.status = 'Active') AS active_placements
         FROM vacancies v WHERE v.vacancy_id = :id"
    );
    $s->execute([':id' => (int) $vid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function euLoadEmployer($pdo, $eid)
{
    $s = $pdo->prepare(
        "SELECT employer_id, company_name, contact_person_name, contact_person_position, contact_number,
                email_address, building_no, street
         FROM employers WHERE employer_id = :id AND deleted_at IS NULL"
    );
    $s->execute([':id' => (int) $eid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Cleans a date the employer proposed; returns Y-m-d or null (and sets $bad when it is present but invalid).
function euDate($v, &$bad)
{
    $bad = false;
    $v = trim((string) $v);
    if ($v === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v || $v > date('Y-m-d')) { $bad = true; return null; }
    return $v;
}

// Can this request be accepted right now? Returns ['problem' => string|null, 'ctx' => row|null, 'clean' => cleaned values].
// Used by the list (to warn staff) and by accept (to refuse), so both always agree.
function euCheck($pdo, $q, $dateOverride = null)
{
    $p = euPayload($q);
    $type = $q['request_type'];

    if ($type === 'referral_status') {
        $r = euLoadReferral($pdo, $q['referral_id']);
        if (!$r || $r['deleted_at'] !== null)            return ['problem' => 'This referral no longer exists.', 'ctx' => $r];
        if ($r['applicant_deleted'] !== null)            return ['problem' => 'This applicant has been deleted.', 'ctx' => $r];
        if ((int) $r['employer_id'] !== (int) $q['employer_id']) return ['problem' => 'This referral no longer belongs to this employer.', 'ctx' => $r];
        $target = (string) ($p['status'] ?? '');
        if (!in_array($target, EU_REFERRAL_TARGETS, true)) return ['problem' => 'The requested status is not valid.', 'ctx' => $r];
        if (!in_array($r['status'], ['Pending', 'Interviewed'], true) || $r['status'] === $target) {
            return ['problem' => "This referral is already marked {$r['status']}.", 'ctx' => $r];
        }
        $date = null;
        if ($target === 'Hired') {
            if (efHasActivePlacement($pdo, $r['beneficiary_service_id'])) {
                return ['problem' => 'This applicant is already hired (an active placement exists), so they cannot be hired again.', 'ctx' => $r];
            }
            $date = euDate($dateOverride !== null && $dateOverride !== '' ? $dateOverride : ($p['dateHired'] ?? ''), $bad);
            if ($bad) return ['problem' => 'The date hired is not valid (it cannot be in the future).', 'ctx' => $r];
        }
        return ['problem' => null, 'ctx' => $r, 'clean' => ['status' => $target, 'dateHired' => $date]];
    }

    if ($type === 'placement_status') {
        $pl = euLoadPlacement($pdo, $q['placement_id']);
        if (!$pl)                                         return ['problem' => 'This placement no longer exists.', 'ctx' => null];
        if ((int) $pl['employer_id'] !== (int) $q['employer_id']) return ['problem' => 'This placement no longer belongs to this employer.', 'ctx' => $pl];
        $target = (string) ($p['status'] ?? '');
        if (!in_array($target, EU_PLACEMENT_TARGETS, true)) return ['problem' => 'The requested status is not valid.', 'ctx' => $pl];
        if ($pl['status'] !== 'Active')                   return ['problem' => "This placement is already marked {$pl['status']}.", 'ctx' => $pl];
        return ['problem' => null, 'ctx' => $pl, 'clean' => ['status' => $target]];
    }

    if ($type === 'placement_promotion') {
        $pl = euLoadPlacement($pdo, $q['placement_id']);
        if (!$pl)                                         return ['problem' => 'This placement no longer exists.', 'ctx' => null];
        if ((int) $pl['employer_id'] !== (int) $q['employer_id']) return ['problem' => 'This placement no longer belongs to this employer.', 'ctx' => $pl];
        if ($pl['status'] !== 'Active')                   return ['problem' => "This placement is already marked {$pl['status']}, so it cannot be promoted.", 'ctx' => $pl];
        $res = promotionRequestClean($p, $pl['date_hired'], promotionCurrentTitle($pdo, $q['placement_id']));
        if ($res['error']) return ['problem' => $res['error'], 'ctx' => $pl];
        return ['problem' => null, 'ctx' => $pl, 'clean' => $res['clean']];
    }

    if ($type === 'vacancy_new') {
        $e = euLoadEmployer($pdo, $q['employer_id']);
        if (!$e) return ['problem' => 'This employer no longer exists.', 'ctx' => null];
        $res = vacancyRequestClean($p, true);
        if ($res['error']) return ['problem' => $res['error'], 'ctx' => $e];
        return ['problem' => null, 'ctx' => $e, 'clean' => $res['clean']];
    }

    if ($type === 'vacancy_update') {
        $v = euLoadVacancy($pdo, $q['vacancy_id']);
        if (!$v) return ['problem' => 'This opening no longer exists.', 'ctx' => null];
        if ((int) $v['employer_id'] !== (int) $q['employer_id']) return ['problem' => 'This opening no longer belongs to this employer.', 'ctx' => $v];
        $current = vacancyRequestCurrent($v);
        $res = vacancyRequestClean($p, false, $current);
        if ($res['error']) return ['problem' => $res['error'], 'ctx' => $v];
        $changes = vacancyRequestChanges($res['clean'], $current);
        if (!$changes) return ['problem' => 'The opening already looks like this, so there is nothing to change.', 'ctx' => $v];
        if (isset($changes['slotsTotal']) && $changes['slotsTotal'] < (int) $v['active_placements']) {
            return ['problem' => "This opening already has {$v['active_placements']} hired employee(s), so it cannot be lowered to {$changes['slotsTotal']}.", 'ctx' => $v];
        }
        return ['problem' => null, 'ctx' => $v, 'clean' => $changes];
    }

    // company_info
    $e = euLoadEmployer($pdo, $q['employer_id']);
    if (!$e) return ['problem' => 'This employer no longer exists.', 'ctx' => null];
    $clean = [];
    foreach (EU_COMPANY_FIELDS as $key => $col) {
        if (!array_key_exists($key, $p)) continue;
        $v = trim((string) $p[$key]);
        if (strlen($v) > 255) return ['problem' => 'One of the values is too long.', 'ctx' => $e];
        if ($key === 'emailAddress' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) return ['problem' => 'The email address is not valid.', 'ctx' => $e];
        if ($key === 'contactPersonName' && $v === '') return ['problem' => 'The contact person name cannot be empty.', 'ctx' => $e];
        $clean[$col] = $v === '' ? null : $v;
    }
    if (!$clean) return ['problem' => 'There is nothing to update.', 'ctx' => $e];
    return ['problem' => null, 'ctx' => $e, 'clean' => $clean];
}

// What the request is about, in words staff can read.
function euDescribe($pdo, $q)
{
    $p = euPayload($q);
    $out = [
        'applicantName' => null, 'jobTitle' => null, 'currentStatus' => null,
        'requestedStatus' => null, 'requestedDateHired' => null, 'changes' => null,
    ];
    if ($q['request_type'] === 'referral_status') {
        $r = euLoadReferral($pdo, $q['referral_id']);
        $out['applicantName']       = $r['applicant_name'] ?? null;
        $out['jobTitle']            = $r['job_title'] ?? null;
        $out['currentStatus']       = $r['status'] ?? null;
        $out['requestedStatus']     = $p['status'] ?? null;
        $out['requestedDateHired']  = $p['dateHired'] ?? null;
    } elseif ($q['request_type'] === 'placement_status') {
        $pl = euLoadPlacement($pdo, $q['placement_id']);
        $out['applicantName']   = $pl['applicant_name'] ?? null;
        $out['jobTitle']        = $pl['job_title'] ?? null;
        $out['currentStatus']   = $pl['status'] ?? null;
        $out['requestedStatus'] = $p['status'] ?? null;
    } elseif ($q['request_type'] === 'placement_promotion') {
        $pl = euLoadPlacement($pdo, $q['placement_id']);
        $title = promotionCurrentTitle($pdo, $q['placement_id']);
        $out['applicantName'] = $pl['applicant_name'] ?? null;
        $out['jobTitle']      = $title ?? ($pl['job_title'] ?? null);
        $out['currentStatus'] = $pl['status'] ?? null;
        $changes = [];
        foreach (PROMOTION_REQUEST_FIELDS as $key) {
            if (!array_key_exists($key, $p)) continue;
            $changes[] = ['field' => EU_PROMOTION_LABELS[$key], 'current' => $key === 'newJobTitle' ? (string) $title : '', 'requested' => trim((string) $p[$key])];
        }
        $out['changes'] = $changes;
    } elseif ($q['request_type'] === 'vacancy_new' || $q['request_type'] === 'vacancy_update') {
        $labels = [
            'jobTitle' => 'Job title', 'jobType' => 'Job type', 'description' => 'Description', 'requirements' => 'Requirements',
            'salaryMin' => 'Minimum salary', 'salaryMax' => 'Maximum salary', 'slotsTotal' => 'Openings', 'status' => 'Status',
        ];
        $cur = [];
        if ($q['request_type'] === 'vacancy_update') {
            $v = euLoadVacancy($pdo, $q['vacancy_id']);
            if ($v) $cur = vacancyRequestCurrent($v);
            $out['jobTitle'] = $v['job_title'] ?? null;
        } else {
            $out['jobTitle'] = $p['jobTitle'] ?? null;
        }
        $changes = [];
        foreach (array_merge(VACANCY_REQUEST_FIELDS, ['status']) as $key) {
            if (!array_key_exists($key, $p)) continue;
            $changes[] = ['field' => $labels[$key], 'current' => isset($cur[$key]) ? (string) $cur[$key] : '', 'requested' => trim((string) $p[$key])];
        }
        $out['changes'] = $changes;
    } else {
        $e = euLoadEmployer($pdo, $q['employer_id']);
        $labels = [
            'contactPersonName' => 'Contact person', 'contactPersonPosition' => 'Position', 'contactNumber' => 'Contact number',
            'emailAddress' => 'Email', 'buildingNo' => 'Building no.', 'street' => 'Street',
        ];
        $changes = [];
        foreach (EU_COMPANY_FIELDS as $key => $col) {
            if (!array_key_exists($key, $p)) continue;
            $changes[] = ['field' => $labels[$key], 'current' => $e[$col] ?? '', 'requested' => trim((string) $p[$key])];
        }
        $out['changes'] = $changes;
    }

    // Once a request was accepted the live values already show the NEW state, so a finished request shows what it was
    // when staff accepted it (saved then as _before). Older accepted requests have no such record: show only the new value.
    if (($q['status'] ?? '') === 'Approved') {
        $before = is_array($p['_before'] ?? null) ? $p['_before'] : null;
        $out['currentStatus'] = $before['currentStatus'] ?? null;
        if ($out['changes'] !== null) {
            $was = is_array($before['changes'] ?? null) ? $before['changes'] : [];
            foreach ($out['changes'] as &$c) $c['current'] = (string) ($was[$c['field']] ?? '');
            unset($c);
        }
    }
    return $out;
}

// The "before" values of a request about to be accepted, kept with it (see euDescribe).
function euBeforeSnapshot($pdo, $q)
{
    $d = euDescribe($pdo, $q);
    $changes = [];
    foreach ($d['changes'] ?? [] as $c) $changes[$c['field']] = (string) $c['current'];
    return ['_before' => ['currentStatus' => $d['currentStatus'], 'changes' => $changes]];
}

// Openings this employer already has with the same title (case-insensitive), so staff can spot a duplicate request.
function euDuplicateOpenings($pdo, $employerId, $title)
{
    $title = trim((string) $title);
    if ($title === '') return [];
    $s = $pdo->prepare("SELECT vacancy_id, job_title, status::text AS status FROM vacancies WHERE employer_id = :e AND lower(job_title) = lower(:t) ORDER BY vacancy_id DESC LIMIT 5");
    $s->execute([':e' => (int) $employerId, ':t' => $title]);
    return array_map(fn ($r) => ['id' => (int) $r['vacancy_id'], 'jobTitle' => $r['job_title'], 'status' => $r['status']], $s->fetchAll(PDO::FETCH_ASSOC));
}

// GET /api/employer_updates/list?status=Pending|Approved|Rejected|Withdrawn|all   (default Pending; oldest first)
function euList()
{
    $status = (string) ($_GET['status'] ?? 'Pending');
    $where = '';
    $params = [];
    if ($status !== 'all') {
        if (!in_array($status, ['Pending', 'Approved', 'Rejected', 'Withdrawn'], true)) error('Invalid status filter.', 422);
        $where = 'WHERE q.status = :st';
        $params[':st'] = $status;
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT q.*, e.company_name,
                NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '') AS reviewed_by_name
         FROM employer_requests q
         JOIN employers e ON e.employer_id = q.employer_id
         LEFT JOIN users u ON u.user_id = q.reviewed_by
         {$where}
         ORDER BY " . ($status === 'Pending' ? 'q.submitted_at ASC' : 'COALESCE(q.reviewed_at, q.submitted_at) DESC') . ", q.request_id
         LIMIT 300"
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $d = euDescribe($pdo, $q);
        $conflict = null;
        if ($q['status'] === 'Pending') {
            $chk = euCheck($pdo, $q);
            $conflict = $chk['problem'];
        }
        $out[] = array_merge([
            'id'          => (int) $q['request_id'],
            'employerId'  => (int) $q['employer_id'],
            'companyName' => $q['company_name'],
            'type'        => $q['request_type'],
            'status'      => $q['status'],
            'submittedBy' => $q['submitted_by'],
            'submittedAt' => $q['submitted_at'],
            'reviewedBy'  => $q['reviewed_by_name'],
            'reviewedAt'  => $q['reviewed_at'],
            'reviewNote'  => $q['review_note'],
            // Set when the request can't be accepted any more (the data changed since the employer asked).
            'conflict'    => $conflict,
            // Pending new openings only: openings this employer already has with the same job title (a possible duplicate).
            // Not for decided requests: once accepted, the opening the request created would match itself.
            'duplicates'  => ($q['request_type'] === 'vacancy_new' && $q['status'] === 'Pending') ? euDuplicateOpenings($pdo, $q['employer_id'], euPayload($q)['jobTitle'] ?? '') : [],
        ], $d);
    }
    json(['status' => 'ok', 'data' => $out]);
}

// GET /api/employer_updates/counts -> { pending, signature }; the signature changes whenever anything in the list changes.
function euCounts()
{
    $r = db()->query(
        "SELECT COUNT(*) FILTER (WHERE status = 'Pending') AS pending, COUNT(*) AS total,
                COALESCE(MAX(request_id), 0) AS max_id,
                COUNT(*) FILTER (WHERE status = 'Withdrawn') AS withdrawn
         FROM employer_requests"
    )->fetch(PDO::FETCH_ASSOC);
    json(['status' => 'ok', 'data' => [
        'pending'   => (int) $r['pending'],
        'signature' => "{$r['pending']}-{$r['total']}-{$r['max_id']}-{$r['withdrawn']}",
    ]]);
}

// Loads a request row and locks it for the rest of the transaction.
function euLockPending($pdo, $id)
{
    if (!is_numeric($id)) error('Invalid request id.', 422);
    $s = $pdo->prepare("SELECT * FROM employer_requests WHERE request_id = :id FOR UPDATE");
    $s->execute([':id' => (int) $id]);
    $q = $s->fetch(PDO::FETCH_ASSOC);
    if (!$q) { $pdo->rollBack(); error('Request not found.', 404); }
    if ($q['status'] !== 'Pending') {
        $pdo->rollBack();
        error("This request was already {$q['status']}.", 409);
    }
    return $q;
}

// POST /api/employer_updates/accept/{id}   { dateHired? }  -- applies the change, then marks the request Approved.
function euAccept($id)
{
    $uid = currentUserId();
    $d = body();
    $pdo = db();
    $pdo->beginTransaction();
    $q = euLockPending($pdo, $id);

    $chk = euCheck($pdo, $q, isset($d['dateHired']) ? (string) $d['dateHired'] : null);
    if ($chk['problem'] !== null) { $pdo->rollBack(); error($chk['problem'], 409); }
    $ctx = $chk['ctx'];
    $clean = $chk['clean'];
    $note = '';
    $beforeJson = json_encode(euBeforeSnapshot($pdo, $q), JSON_UNESCAPED_UNICODE);   // taken before anything is changed

    try {
        if ($q['request_type'] === 'referral_status') {
            $placementId = efApplyReferralStatus($pdo, (int) $q['referral_id'], $ctx, $clean['status'], $clean['dateHired']);
            $note = "Referral #{$q['referral_id']} ({$ctx['applicant_name']}) -> {$clean['status']}" . ($placementId ? ", placement #{$placementId} created" : '');
        } elseif ($q['request_type'] === 'placement_status') {
            $pdo->prepare("UPDATE employment_facilitation_placements SET status = :s, updated_at = now() WHERE placement_id = :id")
                ->execute([':s' => $clean['status'], ':id' => (int) $q['placement_id']]);
            $note = "Placement #{$q['placement_id']} ({$ctx['applicant_name']}) -> {$clean['status']}";
        } elseif ($q['request_type'] === 'placement_promotion') {
            // The same record staff create by hand (Placements -> Record Promotion); created_by is the staff member who accepted it.
            $pdo->prepare(
                "INSERT INTO placement_promotions (placement_id, promotion_date, new_job_title, new_salary_min, new_salary_max, remarks, created_by)
                 VALUES (:pid, :d, :t, :smin, :smax, :rem, :uid)"
            )->execute([':pid' => (int) $q['placement_id'], ':d' => $clean['promotionDate'], ':t' => $clean['newJobTitle'],
                        ':smin' => $clean['newSalaryMin'] ?? null, ':smax' => $clean['newSalaryMax'] ?? null, ':rem' => $clean['remarks'] ?? null, ':uid' => $uid]);
            $note = "Placement #{$q['placement_id']} ({$ctx['applicant_name']}) promoted to \"{$clean['newJobTitle']}\"";
        } elseif ($q['request_type'] === 'vacancy_new') {
            $ins = $pdo->prepare(
                "INSERT INTO vacancies (employer_id, job_title, slots_total, job_type, salary_min, salary_max, description, requirements, status, source)
                 VALUES (:e, :t, :slots, CAST(:jt AS vacancy_job_type_enum), :smin, :smax, :d, :r, 'Open', 'Employer') RETURNING vacancy_id"
            );
            $ins->execute([':e' => (int) $q['employer_id'], ':t' => $clean['jobTitle'], ':slots' => $clean['slotsTotal'], ':jt' => $clean['jobType'],
                           ':smin' => $clean['salaryMin'], ':smax' => $clean['salaryMax'], ':d' => $clean['description'], ':r' => $clean['requirements']]);
            $newVacancyId = (int) $ins->fetchColumn();
            $pdo->prepare("UPDATE employer_requests SET created_vacancy_id = :v WHERE request_id = :id")->execute([':v' => $newVacancyId, ':id' => (int) $q['request_id']]);
            $note = "New opening #{$newVacancyId} \"{$clean['jobTitle']}\" created for {$ctx['company_name']}";
        } elseif ($q['request_type'] === 'vacancy_update') {
            $map = ['jobTitle' => 'job_title', 'jobType' => 'job_type', 'description' => 'description', 'requirements' => 'requirements',
                    'salaryMin' => 'salary_min', 'salaryMax' => 'salary_max', 'slotsTotal' => 'slots_total', 'status' => 'status'];
            $sets = []; $vals = [':id' => (int) $q['vacancy_id']];
            foreach ($clean as $k => $v) {
                $col = $map[$k];
                $cast = $col === 'job_type' ? 'CAST(:%s AS vacancy_job_type_enum)' : ($col === 'status' ? 'CAST(:%s AS vacancy_status_enum)' : ':%s');
                $sets[] = "{$col} = " . sprintf($cast, $col); $vals[":{$col}"] = $v;
            }
            $pdo->prepare("UPDATE vacancies SET " . implode(', ', $sets) . ", updated_at = now() WHERE vacancy_id = :id")->execute($vals);
            $note = "Opening #{$q['vacancy_id']} ({$ctx['job_title']}) updated: " . implode(', ', array_keys($clean));
        } else {
            $sets = [];
            $vals = [':id' => (int) $q['employer_id']];
            foreach ($clean as $col => $v) { $sets[] = "{$col} = :{$col}"; $vals[":{$col}"] = $v; }
            $pdo->prepare("UPDATE employers SET " . implode(', ', $sets) . ", updated_at = now() WHERE employer_id = :id")->execute($vals);
            $note = 'Company details updated: ' . implode(', ', array_keys($clean));
        }
        $pdo->prepare(
            "UPDATE employer_requests SET status = 'Approved', reviewed_by = :uid, reviewed_at = now(), payload = payload || CAST(:b AS jsonb) WHERE request_id = :id"
        )->execute([':uid' => $uid, ':b' => $beforeJson, ':id' => (int) $q['request_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to apply the request. Nothing was changed. Please try again.', 500);
    }

    logActivity($uid, 'Accept Employer Update', 'employment', "Accepted employer request #{$q['request_id']}: {$note}");
    json(['status' => 'ok', 'message' => 'Request accepted and applied.']);
}

// POST /api/employer_updates/reject/{id}   { reason }
function euReject($id)
{
    $uid = currentUserId();
    $reason = trim((string) (body()['reason'] ?? ''));
    if (strlen($reason) < 3) error('Please give a short reason (at least 3 characters).', 422);
    if (strlen($reason) > 500) error('The reason is too long (500 characters at most).', 422);

    $pdo = db();
    $pdo->beginTransaction();
    $q = euLockPending($pdo, $id);
    $pdo->prepare(
        "UPDATE employer_requests SET status = 'Rejected', reviewed_by = :uid, reviewed_at = now(), review_note = :r WHERE request_id = :id"
    )->execute([':uid' => $uid, ':r' => $reason, ':id' => (int) $q['request_id']]);
    $pdo->commit();

    logActivity($uid, 'Reject Employer Update', 'employment', "Rejected employer request #{$q['request_id']}: {$reason}");
    json(['status' => 'ok', 'message' => 'Request rejected.']);
}
