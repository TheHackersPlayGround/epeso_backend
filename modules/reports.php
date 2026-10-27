<?php
// Cross-program aggregates for the General PESO Report. Headcounts come from the
// shared beneficiaries spine; activity counts come from each program's own table.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

// Routes a reports action to its handler function.
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

// Returns metadata (label, service codes, activity table) for each report program.
function reportPrograms()
{
    return [
        'employment-facilitation' => ['label' => 'Employment Facilitation', 'services' => ['EF'],
            'activityTable' => 'vacancies', 'activityDateCol' => 'created_at'],
        'cdsp' => ['label' => 'CDSP', 'services' => ['CDSP'],
            'activityTable' => 'cdsp_activities', 'activityDateCol' => 'activity_date', 'jobPlacements' => true],
        // gip_workplaces is a reusable directory; activity count = new workplaces added in the period.
        'gip' => ['label' => 'GIP', 'services' => ['GIP'],
            'activityTable' => 'gip_workplaces', 'activityDateCol' => 'created_at', 'jobPlacements' => true],
        'spes' => ['label' => 'SPES', 'services' => ['SPES'],
            'activityTable' => 'spes_batches', 'activityDateCol' => 'program_start_date'],
        'skills-training' => ['label' => 'Skills Training', 'services' => ['SKILLS'],
            'activityTable' => 'skills_training_activities', 'activityDateCol' => 'activity_date', 'jobPlacements' => true],
        'livelihood' => ['label' => 'Livelihood', 'services' => ['DILP', 'TUPAD', 'SLP', 'CLPEP'],
            'activityTable' => null, 'activityDateCol' => null],
        'ofw-services' => ['label' => 'OFW Services', 'services' => ['OFW'],
            'activityTable' => null, 'activityDateCol' => null],
    ];
}

// Expands a top-level program service code to include its child service codes.
function reportsExpandServiceCode($topCode)
{
    $stmt = db()->prepare(
        "SELECT service_code FROM services
         WHERE service_code = :code
            OR parent_service_id = (SELECT service_id FROM services WHERE service_code = :code)"
    );
    $stmt->execute([':code' => $topCode]);
    return array_column($stmt->fetchAll(), 'service_code');
}

// Counts rows in an activity table whose date column falls within a date range.
function reportsCountActivities($table, $dateCol, $from, $to)
{
    $deletedClause = $table === 'vacancies' ? '' : 'deleted_at IS NULL AND ';
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE {$deletedClause}{$dateCol} BETWEEN :from AND :to"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    return (int) $stmt->fetchColumn();
}

// Returns per-program participant, activity, and placement counts for a date range.
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

    // Expand each top-level program to its service_codes (incl. children); codeToTop
    // remembers which top-level code each expanded code rolls back up to for re-aggregation.
    $topCodes = [];
    foreach ($selected as $meta) {
        $topCodes = array_merge($topCodes, $meta['services']);
    }
    $topCodes = array_values(array_unique($topCodes));

    $codes = [];
    $codeToTop = [];
    foreach ($topCodes as $topCode) {
        foreach (reportsExpandServiceCode($topCode) as $childCode) {
            $codes[] = $childCode;
            $codeToTop[$childCode] = $topCode;
        }
    }
    $codes = array_values(array_unique($codes));

    $placeholders = implode(',', array_map(fn($i) => ":c{$i}", array_keys($codes)));
    $codeBind = [];
    foreach ($codes as $i => $c) {
        $codeBind[":c{$i}"] = $c;
    }

    // Participants + sex split, scoped to requested service_codes only (excluded programs
    // must not contribute to the dedup total below).
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
    // Roll each row's (possibly child) service_code back up to its top-level program code.
    $bySvc = [];
    foreach ($stmt->fetchAll() as $r) {
        $top = $codeToTop[$r['service_code']] ?? $r['service_code'];
        if (!isset($bySvc[$top])) {
            $bySvc[$top] = ['total' => 0, 'male' => 0, 'female' => 0];
        }
        $bySvc[$top]['total'] += (int) $r['total'];
        $bySvc[$top]['male'] += (int) $r['male'];
        $bySvc[$top]['female'] += (int) $r['female'];
    }

    // Placements: EF uses its own table; CDSP/GIP/Skills Training share job_placements, both counting
    // unique people hired within the window. Programs that don't track placements stay null.
    $jpTopCodes = [];
    foreach ($selected as $meta) {
        if (!empty($meta['jobPlacements'])) {
            foreach ($meta['services'] as $t) {
                $jpTopCodes[$t] = true;
            }
        }
    }
    $jpCodes = array_values(array_filter($codes, fn($c) => isset($jpTopCodes[$codeToTop[$c]])));
    $placedByTop = [];
    if (!empty($jpCodes)) {
        $jpPlaceholders = implode(',', array_map(fn($i) => ":j{$i}", array_keys($jpCodes)));
        $jpBind = [];
        foreach ($jpCodes as $i => $c) {
            $jpBind[":j{$i}"] = $c;
        }
        $jpStmt = db()->prepare(
            "SELECT DISTINCT s.service_code, bs.beneficiary_id
             FROM job_placements jp
             JOIN beneficiary_services bs ON bs.beneficiary_service_id = jp.beneficiary_service_id
             JOIN beneficiaries b ON b.beneficiary_id = bs.beneficiary_id
             JOIN services s ON s.service_id = bs.service_id
             WHERE jp.deleted_at IS NULL AND b.deleted_at IS NULL
               AND jp.date_hired BETWEEN :from AND :to
               AND s.service_code IN ({$jpPlaceholders})"
        );
        $jpStmt->execute(array_merge([':from' => $from, ':to' => $to], $jpBind));
        foreach ($jpStmt->fetchAll() as $r) {
            $top = $codeToTop[$r['service_code']] ?? $r['service_code'];
            $placedByTop[$top][$r['beneficiary_id']] = true;
        }
    }

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
            $activities = reportsCountActivities('dilp_projects', 'created_at::date', $from, $to)
                + reportsCountActivities('tupad_projects', 'project_date', $from, $to)
                + reportsCountActivities('slp_projects', 'date_started', $from, $to)
                + reportsCountActivities('clpep_interventions', 'intervention_date', $from, $to);
        } elseif ($meta['activityTable'] === null) {
            $activities = null;
        } else {
            $activities = reportsCountActivities($meta['activityTable'], $meta['activityDateCol'], $from, $to);
        }

        if ($key === 'employment-facilitation') {
            $placements = $efPlacements;
        } elseif (!empty($meta['jobPlacements'])) {
            $placements = 0;
            foreach ($meta['services'] as $code) {
                $placements += count($placedByTop[$code] ?? []);
            }
        } else {
            $placements = null;
        }

        $rows[] = [
            'key' => $key,
            'program' => $meta['label'],
            'participants' => $total,
            'male' => $male,
            'female' => $female,
            'activities' => $activities,
            'placements' => $placements,
        ];
    }

    json(['status' => 'ok', 'data' => [
        'from' => $from,
        'to' => $to,
        'programs' => $rows,
    ]]);
}
