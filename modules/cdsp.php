<?php
// CDSP: profiles (beneficiary spine), activities, sub-services, participants

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listServices':             requirePermission('cdsp','Viewer'); return cdspListServices();
        case 'createService':            requirePermission('cdsp','Editor'); return cdspCreateService();
        case 'deleteService':            requirePermission('cdsp','Editor'); return cdspDeleteService($id);
        case 'listActivities':           requirePermission('cdsp','Viewer'); return cdspListActivities();
        case 'createActivity':           requirePermission('cdsp','Editor'); return cdspCreateActivity();
        case 'updateActivity':           requirePermission('cdsp','Editor'); return cdspUpdateActivity($id);
        case 'updateActivityStatus':     requirePermission('cdsp','Editor'); return cdspUpdateActivityStatus($id);
        case 'deleteActivity':           requirePermission('cdsp','Editor'); return cdspDeleteActivity($id);
        case 'listActivityParticipants': requirePermission('cdsp','Viewer'); return cdspListActivityParticipants($id);
        case 'addParticipant':           requirePermission('cdsp','Editor'); return cdspAddParticipant();
        case 'removeParticipant':        requirePermission('cdsp','Editor'); return cdspRemoveParticipant();
        case 'updateAttendance':         requirePermission('cdsp','Editor'); return cdspUpdateAttendance();
        case 'listProfiles':             requirePermission('cdsp','Viewer'); return cdspListProfiles();
        case 'getProfile':               requirePermission('cdsp','Viewer'); return cdspGetProfile($id);
        case 'createProfile':            requirePermission('cdsp','Editor'); return cdspCreateProfile();
        case 'updateProfile':            requirePermission('cdsp','Editor'); return cdspUpdateProfile($id);
        case 'deleteProfile':            requirePermission('cdsp','Editor'); return cdspDeleteProfile($id);
        default: error("Unknown CDSP action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function cdspNullStr($v) {
    $s = is_string($v) ? trim($v) : $v;
    return ($s === '' || $s === null) ? null : $s;
}
function cdspDate($v) {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function cdspIntOrNull($v) { return is_numeric($v) ? (int)$v : null; }
function cdspYearOrNull($v) {
    if (!is_numeric($v)) return null;
    $y = (int)$v;
    return ($y >= 1900 && $y <= 2100) ? $y : null;
}

// Map frontend education labels to educational_attainment enum.
function cdspMapEducation($v) {
    $map = [
        'No Formal Education'         => 'No Formal Education',
        'Elementary Level'            => 'Elementary Undergraduate',
        'Elementary Graduate'         => 'Elementary Graduate',
        'High School Level'           => 'Junior High School Undergraduate',
        'High School Graduate'        => 'Junior High School Graduate',
        'Senior High School Level'    => 'Senior High School Undergraduate',
        'Senior High School Graduate' => 'Senior High School Graduate',
        'Vocational / Technical'      => 'Vocational Graduate',
        'College Level'               => 'College Undergraduate',
        'College Level (2nd Year)'    => 'College Undergraduate',
        'College Level (3rd Year)'    => 'College Undergraduate',
        'College Level (4th Year)'    => 'College Undergraduate',
        'College Graduate'            => 'College Graduate',
        "Master's Level"              => "Master's Degree",
        "Master's Graduate"           => "Master's Degree",
        'Doctoral'                    => 'Doctorate Degree',
    ];
    return isset($map[$v]) ? $map[$v] : null;
}

// Strip parenthetical suffixes so values match DB enum.
function cdspNormalizeClassification($c) {
    $map = [
        'Out of School Youth (OSY)'    => 'Out of School Youth',
        'Person with Disability (PWD)' => 'Person with Disability',
    ];
    return isset($map[$c]) ? $map[$c] : $c;
}

function cdspValidClassifications() {
    return ['Student','Fresh Graduate','Employed','Underemployed','Unemployed',
            'Out of School Youth','Person with Disability','Solo Parent',
            'Women','Senior Citizen','Returning OFW','Other','Indigenous People'];
}

function cdspParentServiceId() {
    static $sid = null;
    if ($sid !== null) return $sid;
    $s = db()->query("SELECT service_id FROM services WHERE service_code='CDSP' LIMIT 1");
    $sid = $s->fetchColumn();
    if ($sid === false) error('CDSP parent service not found.', 500);
    return (int)$sid;
}

function cdspServiceIdByName($name) {
    $s = db()->prepare("SELECT service_id FROM services WHERE service_name=:n AND parent_service_id=:p LIMIT 1");
    $s->execute([':n' => $name, ':p' => cdspParentServiceId()]);
    $id = $s->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// ─── Sub-services ─────────────────────────────────────────────────────────────

function cdspListServices() {
    $s = db()->prepare("SELECT service_id,service_code,service_name FROM services WHERE parent_service_id=:p AND is_active=true ORDER BY service_id");
    $s->execute([':p' => cdspParentServiceId()]);
    $out = array_map(function($r) {
        return ['id' => (int)$r['service_id'], 'code' => $r['service_code'], 'name' => $r['service_name']];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $out]);
}

function cdspCreateService() {
    $d    = body();
    $name = trim($d['serviceName'] ?? '');
    $code = trim($d['serviceCode'] ?? '');
    if ($name === '') error('Service name is required.', 422);
    $autoGenerated = ($code === '');
    if ($autoGenerated) {
        $words = preg_split('/\s+/', $name);
        $code  = 'CDSP-' . strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), $words)));
    }
    $pid = cdspParentServiceId();
    $chk = db()->prepare("SELECT 1 FROM services WHERE service_name=:n AND parent_service_id=:p LIMIT 1");
    $chk->execute([':n' => $name, ':p' => $pid]);
    if ($chk->fetchColumn()) error('A service with that name already exists.', 409);
    $chk2 = db()->prepare("SELECT 1 FROM services WHERE service_code=:c LIMIT 1");
    $chk2->execute([':c' => $code]);
    if ($chk2->fetchColumn()) {
        if (!$autoGenerated) error('Service code "' . $code . '" is already in use. Try a different code.', 409);
        // Auto-generated code collided with an existing one (e.g. "Job Matching" vs
        // "Job Mentoring" both reduce to "CDSP-JM") — append a numeric suffix until unique.
        $baseCode = $code;
        $suffix = 2;
        do {
            $code = $baseCode . $suffix;
            $chk2->execute([':c' => $code]);
            $suffix++;
        } while ($chk2->fetchColumn());
    }
    $s = db()->prepare("INSERT INTO services(parent_service_id,service_code,service_name,is_active,created_at,updated_at) VALUES(:pid,:code,:name,true,now(),now()) RETURNING service_id,service_code,service_name");
    $s->execute([':pid' => $pid, ':code' => $code, ':name' => $name]);
    $row = $s->fetch();
    json(['status' => 'ok', 'message' => 'Service created.', 'data' => ['id' => (int)$row['service_id'], 'code' => $row['service_code'], 'name' => $row['service_name']]]);
}

function cdspDeleteService($id) {
    $id = (int)$id;
    if (!$id) error('Invalid service ID.', 422);
    $pid = cdspParentServiceId();
    $chk = db()->prepare("SELECT 1 FROM services WHERE service_id=:id AND parent_service_id=:pid LIMIT 1");
    $chk->execute([':id' => $id, ':pid' => $pid]);
    if (!$chk->fetchColumn()) error('Service not found or not a CDSP service.', 404);
    $cntS = db()->prepare("SELECT COUNT(*) FROM cdsp_activities WHERE service_id=:id");
    $cntS->execute([':id' => $id]);
    $cnt = (int)$cntS->fetchColumn();
    if ($cnt > 0) error("Cannot delete: $cnt activit" . ($cnt === 1 ? 'y uses' : 'ies use') . ' this service.', 409);
    db()->prepare("DELETE FROM services WHERE service_id=:id")->execute([':id' => $id]);
    json(['status' => 'ok', 'message' => 'Service deleted.']);
}

// ─── Activities ───────────────────────────────────────────────────────────────

function cdspListActivities() {
    $s = db()->prepare(
        "SELECT ca.activity_id, ca.service_id, sv.service_name,
                ca.activity_title, ca.description, ca.activity_date,
                ca.location, ca.facilitator, ca.participant_count,
                ca.status, ca.counselor, ca.session_duration,
                (SELECT COUNT(*) FROM cdsp_activity_participants cap WHERE cap.activity_id = ca.activity_id) AS assigned_count
         FROM cdsp_activities ca
         JOIN services sv ON sv.service_id = ca.service_id
         ORDER BY ca.activity_date DESC, ca.activity_id DESC"
    );
    $s->execute();
    json(['status' => 'ok', 'data' => array_map('cdspFormatActivity', $s->fetchAll())]);
}

function cdspFormatActivity($r) {
    return [
        'id'              => (int)$r['activity_id'],
        'serviceId'       => (int)$r['service_id'],
        'service'         => $r['service_name'],
        'program'         => 'CDSP',
        'title'           => $r['activity_title'],
        'description'     => $r['description'] ?? '',
        'date'            => $r['activity_date'] ?? '',
        'location'        => $r['location'] ?? '',
        'facilitator'     => $r['facilitator'] ?? '',
        'participants'    => isset($r['participant_count']) ? (int)$r['participant_count'] : null,
        'assignedCount'   => isset($r['assigned_count']) ? (int)$r['assigned_count'] : 0,
        'status'          => $r['status'],
        'counselor'       => $r['counselor'] ?? '',
        'sessionDuration' => $r['session_duration'] ?? '',
    ];
}

function cdspGetActivityById($id) {
    $s = db()->prepare(
        "SELECT ca.activity_id, ca.service_id, sv.service_name,
                ca.activity_title, ca.description, ca.activity_date,
                ca.location, ca.facilitator, ca.participant_count,
                ca.status, ca.counselor, ca.session_duration,
                (SELECT COUNT(*) FROM cdsp_activity_participants cap WHERE cap.activity_id = ca.activity_id) AS assigned_count
         FROM cdsp_activities ca
         JOIN services sv ON sv.service_id = ca.service_id
         WHERE ca.activity_id = :id"
    );
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? cdspFormatActivity($r) : null;
}

function cdspCreateActivity() {
    $d = body();
    if (cdspNullStr($d['title'] ?? '') === null) error('Activity title is required.', 422);
    if (cdspDate($d['date'] ?? '') === null)      error('Activity date is required.', 422);
    $sid = null;
    if (!empty($d['serviceName'])) $sid = cdspServiceIdByName($d['serviceName']);
    elseif (!empty($d['serviceId'])) $sid = cdspIntOrNull($d['serviceId']);
    if (!$sid) error('Valid CDSP sub-service is required.', 422);
    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';
    $s = db()->prepare(
        "INSERT INTO cdsp_activities(service_id,activity_title,description,activity_date,location,facilitator,participant_count,status,counselor,session_duration,created_at,updated_at)
         VALUES(:sid,:t,:desc,:date,:loc,:fac,:pc,:st,:c,:dur,now(),now()) RETURNING activity_id"
    );
    $s->execute([':sid'=>$sid,':t'=>trim($d['title']),':desc'=>cdspNullStr($d['description']??''),':date'=>cdspDate($d['date']),':loc'=>cdspNullStr($d['location']??''),':fac'=>cdspNullStr($d['facilitator']??''),':pc'=>cdspIntOrNull($d['participants']??''),':st'=>$status,':c'=>cdspNullStr($d['counselor']??''),':dur'=>cdspNullStr($d['sessionDuration']??'')]);
    json(['status' => 'ok', 'message' => 'Activity created.', 'data' => cdspGetActivityById((int)$s->fetchColumn())]);
}

function cdspUpdateActivity($id) {
    if (!is_numeric($id)) error('Invalid activity id.', 422);
    $d = body();
    if (cdspNullStr($d['title'] ?? '') === null) error('Activity title is required.', 422);
    if (cdspDate($d['date'] ?? '') === null)      error('Activity date is required.', 422);
    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : 'Planned';

    $curS = db()->prepare("SELECT status FROM cdsp_activities WHERE activity_id=:id");
    $curS->execute([':id' => (int)$id]);
    $prevStatus = $curS->fetchColumn();
    // Only stamp completed_at on the actual Planned/Ongoing -> Completed transition,
    // so re-saving an already-completed activity doesn't keep bumping its completion date.
    $completedAt = ($status === 'Completed' && $prevStatus !== 'Completed') ? ',completed_at=now()' : '';

    db()->prepare(
        "UPDATE cdsp_activities SET activity_title=:t,description=:desc,activity_date=:date,location=:loc,facilitator=:fac,participant_count=:pc,status=:st,counselor=:c,session_duration=:dur,updated_at=now(){$completedAt} WHERE activity_id=:id"
    )->execute([':t'=>trim($d['title']),':desc'=>cdspNullStr($d['description']??''),':date'=>cdspDate($d['date']),':loc'=>cdspNullStr($d['location']??''),':fac'=>cdspNullStr($d['facilitator']??''),':pc'=>cdspIntOrNull($d['participants']??''),':st'=>$status,':c'=>cdspNullStr($d['counselor']??''),':dur'=>cdspNullStr($d['sessionDuration']??''),':id'=>(int)$id]);
    json(['status' => 'ok', 'message' => 'Activity updated.', 'data' => cdspGetActivityById((int)$id)]);
}

function cdspUpdateActivityStatus($id) {
    if (!is_numeric($id)) error('Invalid activity id.', 422);
    $d = body();
    $valid  = ['Planned', 'Ongoing', 'Completed'];
    $status = in_array($d['status'] ?? '', $valid, true) ? $d['status'] : null;
    if (!$status) error('Valid status required.', 422);
    $completedAt = $status === 'Completed' ? ',completed_at=now()' : '';
    db()->prepare("UPDATE cdsp_activities SET status=:s,updated_at=now(){$completedAt} WHERE activity_id=:id")->execute([':s'=>$status,':id'=>(int)$id]);
    json(['status' => 'ok', 'message' => 'Status updated.']);
}

function cdspDeleteActivity($id) {
    if (!is_numeric($id)) error('Invalid activity id.', 422);
    db()->prepare("DELETE FROM cdsp_activity_participants WHERE activity_id=:id")->execute([':id'=>(int)$id]);
    db()->prepare("DELETE FROM cdsp_activities WHERE activity_id=:id")->execute([':id'=>(int)$id]);
    json(['status' => 'ok', 'message' => 'Activity deleted.']);
}

// ─── Participants ─────────────────────────────────────────────────────────────

function cdspListActivityParticipants($activityId) {
    if (!is_numeric($activityId)) error('Invalid activity id.', 422);
    $s = db()->prepare(
        "SELECT b.beneficiary_id, b.first_name, b.last_name, b.middle_name, b.contact_no,
                bs.beneficiary_service_id, bs.status AS bs_status,
                sv.service_name AS service_availed, cap.attended,
                array_agg(bc.classification) FILTER (WHERE bc.classification IS NOT NULL) AS cls
         FROM cdsp_activity_participants cap
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = cap.beneficiary_service_id
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         JOIN services sv ON sv.service_id = bs.service_id
         LEFT JOIN beneficiary_classifications bc ON bc.beneficiary_id = b.beneficiary_id
         WHERE cap.activity_id = :aid
         GROUP BY b.beneficiary_id,b.first_name,b.last_name,b.middle_name,b.contact_no,
                  bs.beneficiary_service_id,bs.status,sv.service_name,cap.attended
         ORDER BY b.last_name, b.first_name"
    );
    $s->execute([':aid' => (int)$activityId]);
    $out = array_map(function($r) {
        $cls = $r['cls'];
        if (is_string($cls)) {
            $cls = trim($cls, '{}');
            $cls = $cls === '' ? [] : array_map(function($v) { return trim($v, '"'); }, str_getcsv($cls));
        } else { $cls = []; }
        return ['id'=>(int)$r['beneficiary_id'],'beneficiaryServiceId'=>(int)$r['beneficiary_service_id'],'lastName'=>$r['last_name'],'firstName'=>$r['first_name'],'middleName'=>$r['middle_name']??'','contactNumber'=>$r['contact_no']??'','serviceAvailed'=>$r['service_availed'],'classification'=>$cls,'status'=>$r['bs_status'],'attended'=>$r['attended'] === null ? null : (bool)$r['attended']];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $out]);
}

function cdspAddParticipant() {
    $d = body();
    $actId = cdspIntOrNull($d['activityId'] ?? '');
    $bsId  = cdspIntOrNull($d['beneficiaryServiceId'] ?? '');
    if (!$actId || !$bsId) error('activityId and beneficiaryServiceId required.', 422);

    $capS = db()->prepare("SELECT participant_count FROM cdsp_activities WHERE activity_id=:a");
    $capS->execute([':a'=>$actId]);
    $cap = $capS->fetchColumn();
    if ($cap !== false && $cap !== null) {
        $cntS = db()->prepare(
            "SELECT COUNT(*) FROM cdsp_activity_participants
             WHERE activity_id=:a AND beneficiary_service_id != :b"
        );
        $cntS->execute([':a'=>$actId, ':b'=>$bsId]);
        $current = (int)$cntS->fetchColumn();
        if ($current >= (int)$cap) {
            error("This activity is already at full capacity ({$current}/{$cap}).", 409);
        }
    }

    db()->prepare("INSERT INTO cdsp_activity_participants(activity_id,beneficiary_service_id,date_assigned) VALUES(:a,:b,now()) ON CONFLICT DO NOTHING")->execute([':a'=>$actId,':b'=>$bsId]);
    json(['status' => 'ok', 'message' => 'Participant added.']);
}

function cdspRemoveParticipant() {
    $d = body();
    $actId = cdspIntOrNull($d['activityId'] ?? '');
    $bsId  = cdspIntOrNull($d['beneficiaryServiceId'] ?? '');
    if (!$actId || !$bsId) error('activityId and beneficiaryServiceId required.', 422);

    // Removing a participant hard-deletes this row, which is the only place
    // date_assigned/completed_at for that history entry live. Once the activity
    // is Completed, that would silently erase the completion record, so block it.
    $statS = db()->prepare("SELECT status FROM cdsp_activities WHERE activity_id=:a");
    $statS->execute([':a' => $actId]);
    $status = $statS->fetchColumn();
    if ($status === 'Completed') {
        error('This activity is already completed — unassigning would erase its completion record.', 409);
    }

    db()->prepare("DELETE FROM cdsp_activity_participants WHERE activity_id=:a AND beneficiary_service_id=:b")->execute([':a'=>$actId,':b'=>$bsId]);
    json(['status' => 'ok', 'message' => 'Participant removed.']);
}

function cdspUpdateAttendance() {
    $d      = body();
    $actId  = cdspIntOrNull($d['activityId'] ?? '');
    $bsId   = cdspIntOrNull($d['beneficiaryServiceId'] ?? '');
    if (!$actId || !$bsId) error('activityId and beneficiaryServiceId required.', 422);
    if (!isset($d['attended']) || !is_bool($d['attended'])) error('attended must be true or false.', 422);
    db()->prepare("UPDATE cdsp_activity_participants SET attended=:att WHERE activity_id=:a AND beneficiary_service_id=:b")
        ->execute([':att'=>$d['attended'] ? 'true' : 'false',':a'=>$actId,':b'=>$bsId]);
    json(['status' => 'ok', 'message' => 'Attendance updated.']);
}

// ─── Profiles ─────────────────────────────────────────────────────────────────

function cdspListProfiles() {
    $s = db()->prepare(
        "SELECT DISTINCT b.beneficiary_id
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         JOIN services sv ON sv.service_id = bs.service_id
         WHERE sv.parent_service_id = :p AND b.deleted_at IS NULL
         ORDER BY b.beneficiary_id DESC"
    );
    $s->execute([':p' => cdspParentServiceId()]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $bid) {
        $p = cdspBuildProfile((int)$bid);
        if ($p !== null) $out[] = $p;
    }
    json(['status' => 'ok', 'data' => $out]);
}

function cdspGetProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $p = cdspBuildProfile((int)$id);
    if (!$p) error('Profile not found.', 404);
    json(['status' => 'ok', 'data' => $p]);
}

function cdspBuildProfile($bid) {
    $s = db()->prepare(
        "SELECT b.*, bgy.barangay_name, c.city_name, p.province_name, r.region_name,
                bs.beneficiary_service_id, bs.service_id AS enrolled_service_id,
                bs.date_applied, bs.status AS bs_status, bs.received_by, sv.service_name AS service_availed
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         JOIN services sv ON sv.service_id = bs.service_id
         LEFT JOIN barangays bgy ON bgy.barangay_id = b.barangay_id
         LEFT JOIN cities c ON c.city_id = bgy.city_id
         LEFT JOIN provinces p ON p.province_id = c.province_id
         LEFT JOIN regions r ON r.region_id = p.region_id
         WHERE b.beneficiary_id = :bid AND b.deleted_at IS NULL AND sv.parent_service_id = :pid
         ORDER BY bs.beneficiary_service_id DESC LIMIT 1"
    );
    $s->execute([':bid' => $bid, ':pid' => cdspParentServiceId()]);
    $b = $s->fetch();
    if (!$b) return null;
    $bsId = (int)$b['beneficiary_service_id'];

    $cpS = db()->prepare("SELECT * FROM cdsp_profiles WHERE beneficiary_service_id=:id LIMIT 1");
    $cpS->execute([':id' => $bsId]);
    $cp = $cpS->fetch() ?: [];

    $clsS = db()->prepare("SELECT classification FROM beneficiary_classifications WHERE beneficiary_id=:id");
    $clsS->execute([':id' => $bid]);
    $classifications = $clsS->fetchAll(PDO::FETCH_COLUMN);

    $partS = db()->prepare(
        "SELECT cap.activity_id, ca.activity_title, ca.status AS activity_status, ca.activity_date,
                cap.date_assigned, ca.completed_at
         FROM cdsp_activity_participants cap
         JOIN cdsp_activities ca ON ca.activity_id = cap.activity_id
         WHERE cap.beneficiary_service_id = :bsid
         ORDER BY ca.activity_date ASC, cap.activity_id ASC"
    );
    $partS->execute([':bsid' => $bsId]);
    $participants = $partS->fetchAll();

    $history = [];
    foreach ($participants as $p) {
        $history[] = [
            'activityId'    => (int)$p['activity_id'],
            'activityTitle' => $p['activity_title'],
            'assignedDate'  => $p['date_assigned'] ?? $p['activity_date'] ?? '',
            'completedDate' => ($p['activity_status'] === 'Completed') ? ($p['completed_at'] ?? $p['activity_date'] ?? '') : null,
        ];
    }

    $currentAct = null;
    foreach (array_reverse($participants) as $p) {
        if ($p['activity_status'] !== 'Completed') { $currentAct = $p; break; }
    }
    if (!$currentAct && !empty($participants)) $currentAct = end($participants);

    $age = 0;
    if (!empty($b['birth_date'])) {
        $age = (new DateTime($b['birth_date']))->diff(new DateTime('today'))->y;
    }
    $bsStatus = $b['bs_status'] ?? 'Active';
    $frontendStatus = in_array($bsStatus, ['Active','Approved'], true) ? 'Active' : 'Inactive';

    return [
        'id'                      => $bid,
        'beneficiaryServiceId'    => $bsId,
        'serviceId'               => (int)$b['enrolled_service_id'],
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
        'barangayId'              => (int)($b['barangay_id'] ?? 0),
        'cityMunicipality'        => $b['city_name'] ?? '',
        'province'                => $b['province_name'] ?? '',
        'region'                  => $b['region_name'] ?? '',
        'classification'          => array_values($classifications),
        'classificationOther'     => '',
        'highestEducation'        => $b['educational_attainment'] ?? '',
        'course'                  => $cp['course_program'] ?? '',
        'strand'                  => $cp['strand'] ?? '',
        'yearLevel'               => $cp['year_level'] ?? '',
        'yearGraduated'           => isset($cp['year_graduated']) ? (string)$cp['year_graduated'] : '',
        'employmentStatus'        => $cp['employment_status'] ?? '',
        'currentOccupation'       => $cp['current_occupation'] ?? '',
        'serviceAvailed'          => $b['service_availed'] ?? '',
        'assignedActivity'        => $currentAct ? $currentAct['activity_title'] : '',
        'assignedActivityId'      => $currentAct ? (int)$currentAct['activity_id'] : null,
        'assignmentHistory'       => $history,
        'dateApplicationReceived' => $cp['date_received'] ?? ($b['date_applied'] ?? ''),
        'receivedBy'              => $b['received_by'] ?? '',
        'remarks'                 => $cp['remarks'] ?? '',
        'status'                  => $frontendStatus,
        'attachedDocuments'       => [],
        // Legacy compat fields
        'schoolName'=>'','employerName'=>'','employmentType'=>'','monthlyIncome'=>'',
        'careerGoal'=>'','coachingType'=>'','careerAssessmentResult'=>'',
        'targetJob'=>'','industriesOfInterest'=>[],'preEmploymentRequirements'=>[],
        'school'=>'','courseProgram'=>$cp['course_program']??'',
        'yearLevel'=>'','expectedGraduation'=>'',
        'applicantSignature'=>'','dateSignature'=>'','counselorName'=>'',
    ];
}

function cdspCreateProfile() {
    $uid = requireLogin();
    $d   = body();
    if (cdspNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (cdspNullStr($d['lastName'] ?? '')  === null) error('Last name is required.', 422);
    if (empty($d['highestEducation']))               error('Highest educational attainment is required.', 422);
    if (empty($d['employmentStatus']))               error('Employment status is required.', 422);
    if (empty($d['serviceAvailed']))                  error('Service availed is required.', 422);
    $serviceId = cdspServiceIdByName($d['serviceAvailed']);
    if (!$serviceId) error('Selected CDSP service not found.', 422);

    $sex = in_array($d['sex'] ?? '', ['Male','Female'], true) ? $d['sex'] : null;
    if (!$sex) error('Sex is required.', 422);

    $validCivil = ['Single','Married','Widowed','Separated','Divorced'];
    $civil = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;

    // barangayId is optional — 0 or missing becomes NULL (barangay can be added later)
    $bgyId = (!empty($d['barangayId']) && is_numeric($d['barangayId']) && (int)$d['barangayId'] > 0)
             ? (int)$d['barangayId'] : null;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare("INSERT INTO beneficiaries(first_name,middle_name,last_name,sex,birth_date,civil_status,street_address,barangay_id,contact_no,email,status,educational_attainment) VALUES(:fn,:mn,:ln,:sex,:bdate,:civil,:street,:bgy,:contact,:email,'Active',:educ) RETURNING beneficiary_id");
        $s->execute([':fn'=>trim($d['firstName']),':mn'=>cdspNullStr($d['middleName']??''),':ln'=>trim($d['lastName']),':sex'=>$sex,':bdate'=>cdspDate($d['birthdate']??''),':civil'=>$civil,':street'=>cdspNullStr($d['streetPurok']??''),':bgy'=>$bgyId,':contact'=>cdspNullStr($d['contactNumber']??''),':email'=>cdspNullStr($d['email']??''),':educ'=>cdspMapEducation($d['highestEducation']??'')]);
        $bid = (int)$s->fetchColumn();

        $s2 = $pdo->prepare("INSERT INTO beneficiary_services(beneficiary_id,service_id,status,date_applied,received_by) VALUES(:bid,:sid,'Active',:date,:rby) RETURNING beneficiary_service_id");
        $s2->execute([':bid'=>$bid,':sid'=>$serviceId,':date'=>cdspDate($d['dateApplicationReceived']??'')??date('Y-m-d'),':rby'=>cdspNullStr($d['receivedBy']??'')]);
        $bsId = (int)$s2->fetchColumn();

        $validCls = cdspValidClassifications();
        $rawCls   = is_array($d['classification'] ?? null) ? $d['classification'] : [];
        $ins = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification) VALUES(:bid,:cls) ON CONFLICT DO NOTHING");
        foreach ($rawCls as $c) {
            $norm = cdspNormalizeClassification($c);
            if (in_array($norm, $validCls, true)) $ins->execute([':bid'=>$bid,':cls'=>$norm]);
        }

        $pdo->prepare("INSERT INTO cdsp_profiles(beneficiary_service_id,course_program,strand,year_level,year_graduated,employment_status,current_occupation,date_received,remarks,status) VALUES(:bsid,:course,:strand,:ylvl,:yr,:empst,:occ,:drec,:rmk,'Active')")
            ->execute([':bsid'=>$bsId,':course'=>cdspNullStr($d['course']??''),':strand'=>cdspNullStr($d['strand']??''),':ylvl'=>cdspNullStr($d['yearLevel']??''),':yr'=>cdspYearOrNull($d['yearGraduated']??''),':empst'=>cdspNullStr($d['employmentStatus']??''),':occ'=>cdspNullStr($d['currentOccupation']??''),':drec'=>cdspDate($d['dateApplicationReceived']??''),':rmk'=>cdspNullStr($d['remarks']??'')]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save profile: ' . $e->getMessage(), 500);
    }
    json(['status'=>'ok','message'=>'Profile saved.','data'=>cdspBuildProfile($bid)]);
}

function cdspUpdateProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $d   = body();
    $bid = (int)$id;
    if (cdspNullStr($d['firstName'] ?? '') === null) error('First name is required.', 422);
    if (cdspNullStr($d['lastName'] ?? '')  === null) error('Last name is required.', 422);
    if (empty($d['highestEducation']))               error('Highest educational attainment is required.', 422);
    if (empty($d['employmentStatus']))               error('Employment status is required.', 422);

    $chk = db()->prepare("SELECT bs.beneficiary_service_id FROM beneficiary_services bs JOIN services sv ON sv.service_id=bs.service_id WHERE bs.beneficiary_id=:bid AND sv.parent_service_id=:pid ORDER BY bs.beneficiary_service_id DESC LIMIT 1");
    $chk->execute([':bid'=>$bid,':pid'=>cdspParentServiceId()]);
    $bsId = $chk->fetchColumn();
    if (!$bsId) error('CDSP profile not found.', 404);
    $bsId = (int)$bsId;

    $sex        = in_array($d['sex'] ?? '', ['Male','Female'], true) ? $d['sex'] : null;
    $validCivil = ['Single','Married','Widowed','Separated','Divorced'];
    $civil      = in_array($d['civilStatus'] ?? '', $validCivil, true) ? $d['civilStatus'] : null;

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $sets   = "first_name=:fn,middle_name=:mn,last_name=:ln,sex=:sex,birth_date=:bdate,civil_status=:civil,street_address=:street,contact_no=:contact,email=:email,educational_attainment=:educ,updated_at=now()";
        $params = [':fn'=>trim($d['firstName']),':mn'=>cdspNullStr($d['middleName']??''),':ln'=>trim($d['lastName']),':sex'=>$sex,':bdate'=>cdspDate($d['birthdate']??''),':civil'=>$civil,':street'=>cdspNullStr($d['streetPurok']??''),':contact'=>cdspNullStr($d['contactNumber']??''),':email'=>cdspNullStr($d['email']??''),':educ'=>cdspMapEducation($d['highestEducation']??''),':bid'=>$bid];
        if (!empty($d['barangayId']) && is_numeric($d['barangayId'])) {
            $sets .= ',barangay_id=:bgy';
            $params[':bgy'] = (int)$d['barangayId'];
        }
        $pdo->prepare("UPDATE beneficiaries SET {$sets} WHERE beneficiary_id=:bid")->execute($params);

        $pdo->prepare("UPDATE beneficiary_services SET received_by=:rby WHERE beneficiary_service_id=:bsid")
            ->execute([':rby'=>cdspNullStr($d['receivedBy']??''),':bsid'=>$bsId]);

        $pdo->prepare("DELETE FROM beneficiary_classifications WHERE beneficiary_id=:bid")->execute([':bid'=>$bid]);
        $validCls = cdspValidClassifications();
        $rawCls   = is_array($d['classification'] ?? null) ? $d['classification'] : [];
        $ins = $pdo->prepare("INSERT INTO beneficiary_classifications(beneficiary_id,classification) VALUES(:bid,:cls)");
        foreach ($rawCls as $c) {
            $norm = cdspNormalizeClassification($c);
            if (in_array($norm, $validCls, true)) $ins->execute([':bid'=>$bid,':cls'=>$norm]);
        }

        $ex = db()->prepare("SELECT 1 FROM cdsp_profiles WHERE beneficiary_service_id=:id");
        $ex->execute([':id'=>$bsId]);
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE cdsp_profiles SET course_program=:course,strand=:strand,year_level=:ylvl,year_graduated=:yr,employment_status=:empst,current_occupation=:occ,date_received=:drec,remarks=:rmk,updated_at=now() WHERE beneficiary_service_id=:bsid")
                ->execute([':course'=>cdspNullStr($d['course']??''),':strand'=>cdspNullStr($d['strand']??''),':ylvl'=>cdspNullStr($d['yearLevel']??''),':yr'=>cdspYearOrNull($d['yearGraduated']??''),':empst'=>cdspNullStr($d['employmentStatus']??''),':occ'=>cdspNullStr($d['currentOccupation']??''),':drec'=>cdspDate($d['dateApplicationReceived']??''),':rmk'=>cdspNullStr($d['remarks']??''),':bsid'=>$bsId]);
        } else {
            $pdo->prepare("INSERT INTO cdsp_profiles(beneficiary_service_id,course_program,strand,year_level,year_graduated,employment_status,current_occupation,date_received,remarks,status) VALUES(:bsid,:course,:strand,:ylvl,:yr,:empst,:occ,:drec,:rmk,'Active')")
                ->execute([':bsid'=>$bsId,':course'=>cdspNullStr($d['course']??''),':strand'=>cdspNullStr($d['strand']??''),':ylvl'=>cdspNullStr($d['yearLevel']??''),':yr'=>cdspYearOrNull($d['yearGraduated']??''),':empst'=>cdspNullStr($d['employmentStatus']??''),':occ'=>cdspNullStr($d['currentOccupation']??''),':drec'=>cdspDate($d['dateApplicationReceived']??''),':rmk'=>cdspNullStr($d['remarks']??'')]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to update profile: ' . $e->getMessage(), 500);
    }
    json(['status'=>'ok','message'=>'Profile updated.','data'=>cdspBuildProfile($bid)]);
}

function cdspDeleteProfile($id) {
    if (!is_numeric($id)) error('Invalid id.', 422);
    $uid = requireLogin();
    db()->prepare("UPDATE beneficiaries SET deleted_at=now(),deleted_by=:uid WHERE beneficiary_id=:id")->execute([':uid'=>$uid,':id'=>(int)$id]);
    json(['status'=>'ok','message'=>'Profile deleted.']);
}
