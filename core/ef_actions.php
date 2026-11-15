<?php
// Employment Facilitation actions shared by more than one module (staff Employment module and the employer-update review).

// Sets a referral's status; when it is Hired, also creates the placement (date hired = $dateHired, default today).
// Returns the new placement id, or null for any other status. Does NOT open a transaction: callers that need the
// hire to be all-or-nothing (efUpdateReferralStatus, and accepting an employer request) wrap it in their own.
// $referral needs beneficiary_service_id, vacancy_id and employer_id (the row efUpdateReferralStatus loads).
function efApplyReferralStatus($pdo, $id, $referral, $status, $dateHired = null)
{
    if ($status !== 'Hired') {
        $pdo->prepare("UPDATE employment_facilitation_referrals SET status = :s, updated_at = now() WHERE referral_id = :id")
            ->execute([':s' => $status, ':id' => (int) $id]);
        return null;
    }

    // Mark referral as Hired
    $pdo->prepare("UPDATE employment_facilitation_referrals SET status = 'Hired', updated_at = now() WHERE referral_id = :id")
        ->execute([':id' => (int) $id]);

    // Create placement
    $ins = $pdo->prepare(
        "INSERT INTO employment_facilitation_placements
            (referral_id, beneficiary_service_id, vacancy_id, employer_id,
             date_hired, status)
         VALUES
            (:rid, :bsid, :vid, :eid,
             COALESCE(CAST(:dh AS date), CURRENT_DATE), 'Active')
         RETURNING placement_id"
    );
    $ins->execute([
        ':rid'  => (int) $id,
        ':bsid' => (int) $referral['beneficiary_service_id'],
        ':vid'  => (int) $referral['vacancy_id'],
        ':eid'  => (int) $referral['employer_id'],
        ':dh'   => $dateHired,
    ]);

    // No vacancy_count decrement: remaining openings are derived from active placements.
    return (int) $ins->fetchColumn();
}

// True if the applicant (by beneficiary_service_id) currently has an Active placement, i.e. is hired.
function efHasActivePlacement($pdo, $bsId)
{
    $s = $pdo->prepare("SELECT 1 FROM employment_facilitation_placements WHERE beneficiary_service_id = :id AND status = 'Active' LIMIT 1");
    $s->execute([':id' => (int) $bsId]);
    return (bool) $s->fetchColumn();
}
