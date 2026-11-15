<?php
// The rules for a job opening that an EMPLOYER asks for ("new opening" and "change/close an opening" requests).
// Used twice, so both sides always agree: when the employer sends the request (employer_portal.php) and again when staff
// accept it (employer_updates.php), against whatever the opening looks like at that moment.

const VACANCY_REQUEST_JOB_TYPES = ['Full-time', 'Part-time', 'Contract'];
const VACANCY_REQUEST_FIELDS    = ['jobTitle', 'jobType', 'description', 'requirements', 'salaryMin', 'salaryMax', 'slotsTotal'];
const VACANCY_REQUEST_MAX_WAITING = 10;   // "new opening" requests an employer may have waiting at the same time

// "1,500" / "1500.50" / 1500 -> float with 2 decimals, or null when it is not a plain non-negative amount.
function vacancyRequestMoney($v)
{
    if (is_int($v) || is_float($v)) $s = (string) $v;
    else $s = str_replace(',', '', trim((string) $v));
    if ($s === '' || !preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) return null;
    return round((float) $s, 2);
}

// Cleans what an employer typed.
//   $isNew  = true  -> every field except the description is required.
//   $isNew  = false -> any subset of the fields (and 'status' Open/Closed) is allowed; $current (the opening as it is now) fills in the rest.
// Returns ['error' => string|null, 'clean' => the validated values, only those that were given].
function vacancyRequestClean(array $in, $isNew, array $current = [])
{
    $clean = [];
    $has = fn ($k) => array_key_exists($k, $in);
    $str = fn ($k) => trim((string) ($in[$k] ?? ''));

    if ($isNew || $has('jobTitle')) {
        $v = $str('jobTitle');
        if (strlen($v) < 2) return ['error' => 'Enter the job title.', 'clean' => []];
        if (mb_strlen($v) > 255) return ['error' => 'The job title is too long.', 'clean' => []];
        $clean['jobTitle'] = $v;
    }
    if ($isNew || $has('jobType')) {
        $v = $str('jobType');
        if (!in_array($v, VACANCY_REQUEST_JOB_TYPES, true)) return ['error' => 'Choose full-time, part-time or contract.', 'clean' => []];
        $clean['jobType'] = $v;
    }
    if ($has('description')) {
        $v = $str('description');
        if (mb_strlen($v) > 4000) return ['error' => 'The description is too long (4,000 characters at most).', 'clean' => []];
        $clean['description'] = $v;
    } elseif ($isNew) {
        $clean['description'] = '';
    }
    if ($isNew || $has('requirements')) {
        $v = $str('requirements');
        if ($v === '') return ['error' => 'Enter the requirements for the job.', 'clean' => []];
        if (mb_strlen($v) > 4000) return ['error' => 'The requirements are too long (4,000 characters at most).', 'clean' => []];
        $clean['requirements'] = $v;
    }
    foreach (['salaryMin' => 'minimum salary', 'salaryMax' => 'maximum salary'] as $k => $label) {
        if ($isNew || $has($k)) {
            $v = vacancyRequestMoney($in[$k] ?? '');
            if ($v === null) return ['error' => "Enter the {$label} as a number (for example 15000).", 'clean' => []];
            $clean[$k] = $v;
        }
    }
    if ($isNew || $has('slotsTotal')) {
        $v = trim((string) ($in['slotsTotal'] ?? ''));
        if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 1000) return ['error' => 'Enter how many people you need (1 to 1,000).', 'clean' => []];
        $clean['slotsTotal'] = (int) $v;
    }
    if (!$isNew && $has('status')) {
        $v = $str('status');
        if (!in_array($v, ['Open', 'Closed'], true)) return ['error' => 'Status must be Open or Closed.', 'clean' => []];
        $clean['status'] = $v;
    }

    // the salary range has to make sense once the changes are applied
    $min = $clean['salaryMin'] ?? (isset($current['salaryMin']) ? (float) $current['salaryMin'] : null);
    $max = $clean['salaryMax'] ?? (isset($current['salaryMax']) ? (float) $current['salaryMax'] : null);
    if ($min !== null && $max !== null && $min > $max) return ['error' => 'The minimum salary cannot be higher than the maximum salary.', 'clean' => []];

    return ['error' => null, 'clean' => $clean];
}

// An opening as the validation above sees it, from a vacancies row.
function vacancyRequestCurrent(array $row)
{
    return [
        'jobTitle' => $row['job_title'], 'jobType' => $row['job_type'], 'description' => (string) ($row['description'] ?? ''),
        'requirements' => (string) ($row['requirements'] ?? ''),
        'salaryMin' => $row['salary_min'] !== null ? (float) $row['salary_min'] : null,
        'salaryMax' => $row['salary_max'] !== null ? (float) $row['salary_max'] : null,
        'slotsTotal' => (int) $row['slots_total'], 'status' => $row['status'],
    ];
}

// Only what really differs from the opening as it is now (so the PESO sees just the change).
function vacancyRequestChanges(array $clean, array $current)
{
    $out = [];
    foreach ($clean as $k => $v) {
        $cur = $current[$k] ?? null;
        if (in_array($k, ['salaryMin', 'salaryMax'], true)) { if ($cur === null || abs((float) $cur - (float) $v) > 0.001) $out[$k] = $v; }
        elseif ((string) $cur !== (string) $v) $out[$k] = $v;
    }
    return $out;
}
