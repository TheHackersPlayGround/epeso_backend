<?php
// Possible-duplicate detection: is this person (same first name, last name and birthdate)
// already recorded in the same program? Used by the Add forms (modules/duplicates.php)
// and by the Excel imports. Only warns -- the caller decides whether to go ahead.

// Per-program settings: the permission needed to add records and the service codes that
// count as "the same program" (CDSP counts its three services as one).
function dupPrograms()
{
    return [
        'cdsp'   => ['permission' => 'cdsp',       'codes' => ['CDSP'], 'withChildren' => true],
        'gip'    => ['permission' => 'gip',        'codes' => ['GIP']],
        'spes'   => ['permission' => 'spes',       'codes' => ['SPES']],
        'skills' => ['permission' => 'skills',     'codes' => ['SKILLS']],
        'dilp'   => ['permission' => 'livelihood', 'codes' => ['DILP']],
        'tupad'  => ['permission' => 'livelihood', 'codes' => ['TUPAD']],
        'slp'    => ['permission' => 'livelihood', 'codes' => ['SLP']],
        'clpep'  => ['permission' => 'livelihood', 'codes' => ['CLPEP']],
        'ofw'    => ['permission' => 'ofw',        'codes' => ['OFW']],
        'ef'     => ['permission' => 'employment', 'codes' => ['EF']],
    ];
}

// service_ids that make up a program.
function dupServiceIds($program)
{
    $all = dupPrograms();
    if (!isset($all[$program])) error('Unknown program.', 422);
    $meta = $all[$program];
    $sql = "SELECT service_id FROM services WHERE service_code = ANY(:codes::text[])";
    if (!empty($meta['withChildren'])) {
        $sql .= " OR parent_service_id IN (SELECT service_id FROM services WHERE service_code = ANY(:codes::text[]))";
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([':codes' => '{' . implode(',', $meta['codes']) . '}']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Lower-cased name with runs of spaces collapsed, for comparing names.
function dupNormalizeName($s)
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
}

// YYYY-MM-DD, or null when the value isn't a usable date.
function dupNormalizeDate($s)
{
    $s = trim((string) $s);
    if ($s === '') return null;
    $ts = strtotime($s);
    return $ts === false ? null : date('Y-m-d', $ts);
}

// People already in the program with this first name, last name and birthdate (not the
// person being edited). Includes records sitting in the recycle bin, flagged as such.
function dupFindExisting($serviceIds, $first, $last, $birthdate, $excludeBeneficiaryId = null)
{
    $first = dupNormalizeName($first);
    $last  = dupNormalizeName($last);
    $birth = dupNormalizeDate($birthdate);
    if ($first === '' || $last === '' || $birth === null || empty($serviceIds)) return [];

    $stmt = db()->prepare(
        "SELECT DISTINCT ON (b.beneficiary_id)
                b.beneficiary_id, b.first_name, b.middle_name, b.last_name, b.suffix, b.birth_date, b.deleted_at,
                bs.date_applied, s.service_name
         FROM beneficiaries b
         JOIN beneficiary_services bs ON bs.beneficiary_id = b.beneficiary_id
         JOIN services s ON s.service_id = bs.service_id
         WHERE bs.service_id = ANY(:sids::bigint[])
           AND lower(regexp_replace(btrim(b.first_name), '\\s+', ' ', 'g')) = :first
           AND lower(regexp_replace(btrim(b.last_name),  '\\s+', ' ', 'g')) = :last
           AND b.birth_date = :birth
           AND (:ex::bigint IS NULL OR b.beneficiary_id <> :ex::bigint)
         ORDER BY b.beneficiary_id, bs.date_applied DESC"
    );
    $stmt->execute([
        ':sids'  => '{' . implode(',', $serviceIds) . '}',
        ':first' => $first, ':last' => $last, ':birth' => $birth,
        ':ex'    => $excludeBeneficiaryId !== null ? (int) $excludeBeneficiaryId : null,
    ]);

    return array_map(function ($r) {
        $name = trim($r['last_name'] . ', ' . $r['first_name']
            . ($r['middle_name'] ? ' ' . $r['middle_name'] : '') . ($r['suffix'] ? ' ' . $r['suffix'] : ''));
        return [
            'id'           => (int) $r['beneficiary_id'],
            'name'         => $name,
            'birthdate'    => $r['birth_date'],
            'program'      => $r['service_name'],
            'dateApplied'  => $r['date_applied'],
            'inRecycleBin' => $r['deleted_at'] !== null,
        ];
    }, $stmt->fetchAll());
}

// Gate for the Excel imports. Looks for rows that repeat inside the file and rows whose person is
// already in the program. With no decision yet ($_body['duplicates'] missing) and any duplicate found,
// it answers {status:'duplicates'} and saves nothing, so the user can choose. Rows repeated inside the
// file are always dropped after their first occurrence. Returns [rowsToImport, skippedCount].
function dupGateImport($program, array $rows, $firstKey, $lastKey, $birthKey, array $requestBody)
{
    $sids = dupServiceIds($program);
    $mode = isset($requestBody['duplicates']) && in_array($requestBody['duplicates'], ['skip', 'import'], true)
        ? $requestBody['duplicates'] : null;

    $seen = [];
    $inFile = [];      // later repeats of a row already seen in this file
    $existing = [];    // rows whose person is already in the program
    foreach ($rows as $i => $d) {
        $first = $d[$firstKey] ?? '';
        $last  = $d[$lastKey] ?? '';
        $birth = $d[$birthKey] ?? '';
        $key = dupNormalizeName($first) . '|' . dupNormalizeName($last) . '|' . (dupNormalizeDate($birth) ?? '');
        if (isset($seen[$key])) {
            $inFile[$i] = ['row' => $i, 'firstRow' => $seen[$key], 'name' => trim($last . ', ' . $first), 'birthdate' => dupNormalizeDate($birth)];
            continue;
        }
        $seen[$key] = $i;
        $found = dupFindExisting($sids, $first, $last, $birth);
        if ($found) {
            $existing[$i] = ['row' => $i, 'name' => trim($last . ', ' . $first), 'birthdate' => dupNormalizeDate($birth), 'matches' => $found];
        }
    }

    if (!$inFile && !$existing) return [$rows, 0];

    if ($mode === null) {
        json(['status' => 'duplicates', 'data' => [
            'existing' => array_values($existing),
            'inFile'   => array_values($inFile),
        ]]);
    }

    $drop = $inFile;
    if ($mode === 'skip') $drop += $existing;
    $kept = [];
    foreach ($rows as $i => $d) {
        if (!isset($drop[$i])) $kept[] = $d;
    }
    return [$kept, count($rows) - count($kept)];
}
