<?php
// The "approve a kiosk submission" hook shared by every program's create endpoint. When staff approve
// a submission from the Pending inbox the form is saved through the program's normal create endpoint
// with an extra `submissionId`; these helpers make sure that submission is still Pending BEFORE the
// save, and mark it Approved INSIDE the same database transaction, so a record can never be saved
// without the submission being closed (or the other way round).

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/response.php';

// The submission id sent with a create request, or null for a normal Add.
function submissionIdFromBody($d)
{
    return isset($d['submissionId']) && is_numeric($d['submissionId']) && (int) $d['submissionId'] > 0
        ? (int) $d['submissionId'] : null;
}

// Stops with 404/409 unless the submission exists for this program and is still Pending.
function submissionApprovalPrecheck($program, $submissionId)
{
    $stmt = db()->prepare("SELECT status::text FROM applicant_submissions WHERE submission_id = :id AND program = :p");
    $stmt->execute([':id' => $submissionId, ':p' => $program]);
    $status = $stmt->fetchColumn();
    if ($status === false) error('Submission not found.', 404);
    if ($status !== 'Pending') error('This submission was already approved or rejected.', 409);
}

// Marks it Approved and links the new record. Throws if someone else handled it in the meantime, so the
// caller's transaction (and the save) is rolled back.
function submissionApprovalMark($pdo, $program, $submissionId, $uid, $beneficiaryId)
{
    $mark = $pdo->prepare(
        "UPDATE applicant_submissions
         SET status = 'Approved', reviewed_by = :uid, reviewed_at = now(), beneficiary_id = :bid
         WHERE submission_id = :id AND program = :p AND status = 'Pending'"
    );
    $mark->execute([':uid' => $uid, ':bid' => $beneficiaryId, ':id' => $submissionId, ':p' => $program]);
    if ($mark->rowCount() !== 1) {
        throw new RuntimeException('This submission was already approved or rejected.');
    }
}

// Text appended to the activity-log line of an approved save ("" for a normal Add).
function submissionApprovalLogNote($submissionId)
{
    return $submissionId !== null ? " (approved from kiosk submission #{$submissionId})" : '';
}
