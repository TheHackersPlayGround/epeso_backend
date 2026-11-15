<?php
// Staff side: choose which of an applicant's uploaded documents are sent to the employer for a referral.
//   GET  /api/referral_documents/list?applicantId=..[&referralId=..]   the applicant's documents (with the ones already shared marked)
//   POST /api/referral_documents/set/{referralId}  { documentIds: [..] }   replace what is shared for that referral
//
// Permission: the Employment module (Viewer to look, Editor to change).

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';
include_once __DIR__ . '/../core/ef_shared_documents.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'list': requirePermission('employment', 'Viewer'); return rdList();
        case 'set':  requirePermission('employment', 'Editor'); return rdSet($id);
        default:
            error("Unknown referral_documents action: {$action}", 404);
    }
}

// The applicant's referral (not deleted) with the applicant's id, or null.
function rdReferral($referralId)
{
    $s = db()->prepare(
        "SELECT r.referral_id, bs.beneficiary_id, v.job_title, e.company_name
         FROM employment_facilitation_referrals r
         JOIN beneficiary_services bs ON bs.beneficiary_service_id = r.beneficiary_service_id
         JOIN vacancies v ON v.vacancy_id = r.vacancy_id
         JOIN employers e ON e.employer_id = v.employer_id
         WHERE r.referral_id = :r AND r.deleted_at IS NULL"
    );
    $s->execute([':r' => (int) $referralId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rdList()
{
    $applicantId = isset($_GET['applicantId']) && ctype_digit((string) $_GET['applicantId']) ? (int) $_GET['applicantId'] : 0;
    if ($applicantId < 1) error('applicantId is required.', 422);
    $referralId = isset($_GET['referralId']) && ctype_digit((string) $_GET['referralId']) ? (int) $_GET['referralId'] : 0;

    $shared = [];
    if ($referralId > 0) {
        $ref = rdReferral($referralId);
        if (!$ref || (int) $ref['beneficiary_id'] !== $applicantId) error('Referral not found.', 404);
        $s = db()->prepare("SELECT document_id FROM referral_shared_documents WHERE referral_id = :r");
        $s->execute([':r' => $referralId]);
        $shared = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
    $docs = efApplicantDocuments(db(), $applicantId);
    foreach ($docs as &$d) $d['shared'] = in_array($d['id'], $shared, true);
    json(['status' => 'ok', 'data' => $docs]);
}

function rdSet($referralId)
{
    if (!is_numeric($referralId)) error('Invalid referral id.', 422);
    $ref = rdReferral($referralId);
    if (!$ref) error('Referral not found.', 404);

    $res = efValidateDocumentIds(db(), (int) $ref['beneficiary_id'], body()['documentIds'] ?? []);
    if ($res['error']) error($res['error'], 422);

    $pdo = db();
    $uid = currentUserId();
    try {
        $pdo->beginTransaction();
        efReplaceSharedDocuments($pdo, (int) $referralId, $res['ids'], $uid);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error('Failed to save the shared documents. Please try again.', 500);
    }
    logActivity($uid, 'Share Documents with Employer', 'employment',
        'Referral #' . (int) $referralId . " ({$ref['job_title']} at {$ref['company_name']}): " . count($res['ids']) . ' document(s) shared');
    json(['status' => 'ok', 'message' => 'Shared documents saved.', 'data' => ['count' => count($res['ids'])]]);
}
