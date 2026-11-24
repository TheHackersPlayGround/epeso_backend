<?php
// The rules for a promotion that an EMPLOYER asks for. Used twice, so both sides always agree: when the employer sends the request
// (employer_portal.php) and again when staff accept it (employer_updates.php), against the placement as it is at that moment.

include_once __DIR__ . '/ef_vacancy_rules.php';   // vacancyRequestMoney()

const PROMOTION_REQUEST_FIELDS = ['promotionDate', 'newJobTitle', 'newSalaryMin', 'newSalaryMax', 'remarks'];

// The job title a placement has NOW: its latest promotion's title, or the opening's title if it was never promoted.
// Read from the same data staff see; $pdo is the connection in use (the staff one or the limited portal one).
function promotionCurrentTitle($pdo, $placementId)
{
    try {
        $s = $pdo->prepare(
            "SELECT COALESCE(
                      (SELECT new_job_title FROM placement_promotions WHERE placement_id = p.placement_id ORDER BY promotion_date DESC, promotion_id DESC LIMIT 1),
                      v.job_title)
             FROM employment_facilitation_placements p JOIN vacancies v ON v.vacancy_id = p.vacancy_id WHERE p.placement_id = :id"
        );
        $s->execute([':id' => (int) $placementId]);
    } catch (PDOException $e) {
        // the portal login has no right to read promotions until migration 089 is run: fall back to the opening's title
        error_log('promotionCurrentTitle: ' . $e->getMessage());
        $s = $pdo->prepare("SELECT v.job_title FROM employment_facilitation_placements p JOIN vacancies v ON v.vacancy_id = p.vacancy_id WHERE p.placement_id = :id");
        $s->execute([':id' => (int) $placementId]);
    }
    $t = $s->fetchColumn();
    return $t === false ? null : (string) $t;
}

// Cleans what an employer typed. $dateHired = the placement's hire date (Y-m-d) or null; $currentTitle = its job title now.
// Returns ['error' => string|null, 'clean' => the validated values (optional ones only when given)].
function promotionRequestClean(array $in, $dateHired, $currentTitle)
{
    $clean = [];
    $str = fn ($k) => trim((string) ($in[$k] ?? ''));

    $d = $str('promotionDate');
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if ($d === '' || !$dt || $dt->format('Y-m-d') !== $d) return ['error' => 'Enter the promotion date.', 'clean' => []];
    if ($d > date('Y-m-d')) return ['error' => 'The promotion date cannot be in the future.', 'clean' => []];
    if ($dateHired && $d < substr((string) $dateHired, 0, 10)) return ['error' => 'The promotion date cannot be before the date hired.', 'clean' => []];
    $clean['promotionDate'] = $d;

    $title = $str('newJobTitle');
    if (mb_strlen($title) < 2) return ['error' => 'Enter the new job title.', 'clean' => []];
    if (mb_strlen($title) > 255) return ['error' => 'The new job title is too long.', 'clean' => []];
    $clean['newJobTitle'] = $title;

    foreach (['newSalaryMin' => 'minimum salary', 'newSalaryMax' => 'maximum salary'] as $k => $label) {
        $raw = array_key_exists($k, $in) ? trim((string) $in[$k]) : '';
        if ($raw === '') continue;   // the salary is optional
        $v = vacancyRequestMoney($raw);
        if ($v === null) return ['error' => "Enter the new {$label} as a number (for example 18000).", 'clean' => []];
        $clean[$k] = $v;
    }
    if (isset($clean['newSalaryMin'], $clean['newSalaryMax']) && $clean['newSalaryMin'] > $clean['newSalaryMax']) {
        return ['error' => 'The minimum salary cannot be higher than the maximum salary.', 'clean' => []];
    }

    $remarks = $str('remarks');
    if (mb_strlen($remarks) > 500) return ['error' => 'The remarks are too long (500 characters at most).', 'clean' => []];
    if ($remarks !== '') $clean['remarks'] = $remarks;

    // a "promotion" that changes nothing is not a promotion
    if ($currentTitle !== null && mb_strtolower($title) === mb_strtolower(trim((string) $currentTitle)) && !isset($clean['newSalaryMin']) && !isset($clean['newSalaryMax'])) {
        return ['error' => 'That is already the current job title. Enter the new title, or a new salary.', 'clean' => []];
    }

    return ['error' => null, 'clean' => $clean];
}
