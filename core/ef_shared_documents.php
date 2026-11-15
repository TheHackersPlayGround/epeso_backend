<?php
// Documents the PESO shares with an employer for one referral (table referral_shared_documents).
// Only an applicant's Employment Facilitation documents (the "Attached Documents" of the applicant form) can be shared, never
// another program's files. Used when a referral is created (employment.php) and by the Shared Documents screen (referral_documents.php).

include_once __DIR__ . '/response.php';

// Document types staff usually send to an employer: ticked by default in the Refer screen. Everything else starts unticked.
const EF_SUGGESTED_DOCUMENT_TYPES = ['Resume / Curriculum Vitae', 'Application Letter', 'Biodata / Personal Data Sheet'];
const EF_MAX_SHARED_DOCUMENTS = 50;

// The name staff and employers see for a document: its type, or the typed title when the type is "Others (Specify)".
function efDocumentLabel($row)
{
    $type = (string) ($row['document_type'] ?? '');
    if ($type === 'Others (Specify)' && trim((string) ($row['title'] ?? '')) !== '') return trim($row['title']);
    return $type !== '' ? $type : (string) ($row['title'] ?? 'Document');
}

// An applicant's documents that can be shared: [{id, label, type, fileName, fileSize, uploadedAt, suggested}].
function efApplicantDocuments($pdo, $beneficiaryId)
{
    $s = $pdo->prepare(
        "SELECT document_id, document_type, title, file_name, file_size, uploaded_at
         FROM attached_documents
         WHERE beneficiary_id = :b AND document_source = 'Employment Facilitation'
         ORDER BY document_id"
    );
    $s->execute([':b' => (int) $beneficiaryId]);
    return array_map(fn ($r) => [
        'id' => (int) $r['document_id'], 'label' => efDocumentLabel($r), 'type' => (string) ($r['document_type'] ?? ''),
        'fileName' => $r['file_name'], 'fileSize' => (int) $r['file_size'], 'uploadedAt' => $r['uploaded_at'],
        'suggested' => in_array($r['document_type'], EF_SUGGESTED_DOCUMENT_TYPES, true),
    ], $s->fetchAll(PDO::FETCH_ASSOC));
}

// Checks a list of document ids from the browser: whole numbers, no repeats, and every one is a shareable document of THIS applicant.
// Returns ['ids' => [int...], 'error' => string|null].
function efValidateDocumentIds($pdo, $beneficiaryId, $raw)
{
    if ($raw === null || $raw === []) return ['ids' => [], 'error' => null];
    if (!is_array($raw)) return ['ids' => [], 'error' => 'The documents to send are not valid.'];
    $ids = [];
    foreach ($raw as $v) {
        if (!(is_int($v) || (is_string($v) && ctype_digit($v)))) return ['ids' => [], 'error' => 'The documents to send are not valid.'];
        $ids[(int) $v] = true;
    }
    $ids = array_keys($ids);
    if (count($ids) > EF_MAX_SHARED_DOCUMENTS) return ['ids' => [], 'error' => 'Too many documents selected (' . EF_MAX_SHARED_DOCUMENTS . ' at most).'];
    $ok = $pdo->prepare(
        "SELECT COUNT(*) FROM attached_documents
         WHERE beneficiary_id = :b AND document_source = 'Employment Facilitation' AND document_id = ANY(CAST(:ids AS bigint[]))"
    );
    $ok->execute([':b' => (int) $beneficiaryId, ':ids' => '{' . implode(',', $ids) . '}']);
    if ((int) $ok->fetchColumn() !== count($ids)) return ['ids' => [], 'error' => 'One of the selected documents does not belong to this applicant.'];
    return ['ids' => $ids, 'error' => null];
}

// Makes the shared set of a referral exactly $ids (already validated). Call inside the caller's transaction if it has one.
function efReplaceSharedDocuments($pdo, $referralId, array $ids, $userId)
{
    $pdo->prepare("DELETE FROM referral_shared_documents WHERE referral_id = :r")->execute([':r' => (int) $referralId]);
    if (!$ids) return;
    $ins = $pdo->prepare("INSERT INTO referral_shared_documents (referral_id, document_id, shared_by) VALUES (:r, :d, :u)");
    foreach ($ids as $d) $ins->execute([':r' => (int) $referralId, ':d' => (int) $d, ':u' => $userId]);
}
