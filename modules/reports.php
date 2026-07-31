<?php
// Cross-program aggregates for the General PESO Report.
//
// Every program module (EF, CDSP, GIP, SPES, DILP/TUPAD/SLP/CLPEP, Skills
// Training, OFW) threads its applicants through the shared beneficiaries /
// beneficiary_services spine, disambiguated by beneficiary_services.service_id
// -> services.service_code. That means a single grouped query gets every
// program's participant headcount (and sex split) at once, and a genuine
// unique-client count (a person counted once even if they used two programs)
// is just COUNT(DISTINCT beneficiary_id) over the same rows.
//
// "Activities conducted" has no shared schema across modules though, so each
// program's own batches/activities/projects table is counted separately.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'summary':
            requirePermission('report', 'Viewer');
            return reportsSummary();
        default:
            error("Unknown action: {$action}", 404);
    }
}

// Program key (matches the frontend's report category ids) -> display label,
// the service_code(s) that roll up into it (Livelihood combines 4 programs),
// and the table/date-column used to count "activities conducted" for it.
// null activityTable means the concept doesn't apply (OFW: every row IS the
// request, there's no separate "activity").
function reportPrograms()
{
    return [
        'employment-facilitation' => ['label' => 'Employment Facilitation', 'services' => ['EF'],
            'activityTable' => 'vacancies', 'activityDateCol' => 'created_at'],
        'cdsp' => ['label' => 'CDSP', 'services' => ['CDSP'],
            'activityTable' => 'cdsp_activities', 'activityDateCol' => 'activity_date'],
        'gip' => ['label' => 'GIP', 'services' => ['GIP'],
            'activityTable' => 'gip_batches', 'activityDateCol' => 'start_date'],
        'spes' => ['label' => 'SPES', 'services' => ['SPES'],
            'activityTable' => 'spes_batches', 'activityDateCol' => 'program_start_date'],
        'skills-training' => ['label' => 'Skills Training', 'services' => ['SKILLS'],
            'activityTable' => 'skills_training_activities', 'activityDateCol' => 'activity_date'],
        'livelihood' => ['label' => 'Livelihood', 'services' => ['DILP', 'TUPAD', 'SLP', 'CLPEP'],
            'activityTable' => null, 'activityDateCol' => null],
        'ofw-services' => ['label' => 'OFW Services', 'services' => ['OFW'],
            'activityTable' => null, 'activityDateCol' => null],
    ];
}

// COUNT(*) rows in a single-table "activity" (batch/session/project) whose
// date column falls in [from, to]. Every one of these tables has a deleted_at
// soft-delete column EXCEPT vacancies, which has none (matches
// efReportVacancyCount()'s existing query in employment.php).
function reportsCountActivities($table, $dateCol, $from, $to)
{
    $deletedClause = $table === 'vacancies' ? '' : 'deleted_at IS NULL AND ';
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE {$deletedClause}{$dateCol} BETWEEN :from AND :to"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    return (int) $stmt->fetchColumn();
}

// GET /api/reports/summary?from=&to=&programs=cdsp,gip,...
//   (or ?year=&month= — see resolveReportWindow())
// programs is a comma-separated list of the keys from reportPrograms(); if
// omitted, defaults to every program. Returns per-program participant counts
// (sex-disaggregated), activities conducted, and a true unique-client count
// across whichever programs were selected — all read live from the database,
// nothing hardcoded or seeded.
function reportsSummary()
{
    $window = resolveReportWindow();
    $from = $window['from'];
    $to   = $window['to'];

    $all = reportPrograms();
    $requested = isset($_GET['programs']) && trim($_GET['programs']) !== ''
        ? array_filter(array_map('trim', explode(',', $_GET['programs'])))
        : array_keys($all);

    $selected = array_intersect_key($all, array_flip($requested));
    if (empty($selected)) {
        error('Select at least one program.', 422);
    }

    // Flatten to the service_codes actually needed (Livelihood alone expands to 4).
    $codes = [];
    foreach ($selected as $meta) {
        $codes = array_merge($codes, $meta['services']);
    }
    $codes = array_values(array_unique($codes));

    $placeholders = implode(',', array_map(fn($i) => ":c{$i}", array_keys($codes)));
    $codeBind = [];
    foreach ($codes as $i => $c) {
        $codeBind[":c{$i}"] = $c;
    }

    // Participants + sex split, scoped to the requested service_codes only —
    // this matters for the unique-client count below, so an excluded program
    // never silently contributes to the dedup total.
    $stmt = db()->prepare(
        "SELECT s.service_code,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE LOWER(b.sex::text) = 'male')   AS male,
                COUNT(*) FILTER (WHERE LOWER(b.sex::text) = 'female') AS female
         FROM beneficiary_services bs
         JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
         JOIN services s ON s.service_id = bs.service_id
         WHERE b.deleted_at IS NULL AND bs.date_applied BETWEEN :from AND :to
           AND s.service_code IN ({$placeholders})
         GROUP BY s.service_code"
    );
    $stmt->execute(array_merge([':from' => $from, ':to' => $to], $codeBind));
    $bySvc = [];
    foreach ($stmt->fetchAll() as $r) {
        $bySvc[$r['service_code']] = $r;
    }

    // Employment Facilitation is the only program with a clean single-outcome
    // metric (placements) — everything else stays null rather than forcing a
    // number onto a program the concept doesn't apply to.
    $efSid = null;
    if (isset($selected['employment-facilitation'])) {
        $sidStmt = db()->query("SELECT service_id FROM services WHERE service_code = 'EF' LIMIT 1");
        $efSid = (int) $sidStmt->fetchColumn();
    }
    $efPlacements = null;
    if ($efSid !== null) {
        $plStmt = db()->prepare(
            "SELECT COUNT(DISTINCT bs.beneficiary_id)
             FROM employment_facilitation_placements p
             JOIN beneficiary_services bs ON bs.beneficiary_service_id = p.beneficiary_service_id
             WHERE bs.service_id = :sid AND p.date_hired BETWEEN :from AND :to"
        );
        $plStmt->execute([':sid' => $efSid, ':from' => $from, ':to' => $to]);
        $efPlacements = (int) $plStmt->fetchColumn();
    }

    $rows = [];
    foreach ($selected as $key => $meta) {
        $total = 0;
        $male = 0;
        $female = 0;
        foreach ($meta['services'] as $code) {
            $s = $bySvc[$code] ?? ['total' => 0, 'male' => 0, 'female' => 0];
            $total += (int) $s['total'];
            $male += (int) $s['male'];
            $female += (int) $s['female'];
        }

        if ($key === 'livelihood') {
            $activities = reportsCountActivities('dilp_projects', 'date_released', $from, $to)
                + reportsCountActivities('tupad_projects', 'project_date', $from, $to)
                + reportsCountActivities('slp_projects', 'date_started', $from, $to)
                + reportsCountActivities('clpep_interventions', 'intervention_date', $from, $to);
        } elseif ($meta['activityTable'] === null) {
            $activities = null;
        } else {
            $activities = reportsCountActivities($meta['activityTable'], $meta['activityDateCol'], $from, $to);
        }

        $rows[] = [
            'key' => $key,
            'program' => $meta['label'],
            'participants' => $total,
            'male' => $male,
            'female' => $female,
            'activities' => $activities,
            'placements' => $key === 'employment-facilitation' ? $efPlacements : null,
        ];
    }

    json(['status' => 'ok', 'data' => [
        'from' => $from,
        'to' => $to,
        'programs' => $rows,
    ]]);
}
