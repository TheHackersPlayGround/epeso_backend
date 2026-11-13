<?php
// Automatic clean-up of REJECTED kiosk submissions: once staff have turned a submission down it
// only holds a person's details for no reason, so it is deleted 30 days after it was rejected
// (counted from the rejection, not from when it was submitted). Pending and Approved ones are
// never touched. Like the recycle bin, there is no scheduled job: the sweep runs whenever the
// Pending inbox is opened and whenever the kiosk receives a submission, so it needs no setup.

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/activity_log.php';

const SUBMISSION_REJECTED_RETENTION_DAYS = 30;

// Deletes rejected submissions past the retention window and writes ONE log line saying how many
// (no names or references: the point is that the data is gone). Never breaks the caller; a missing
// table (migration 083 not run yet) is simply ignored.
function submissionsPurgeExpiredRejected()
{
    try {
        $stmt = db()->prepare(
            "DELETE FROM applicant_submissions
             WHERE status = 'Rejected' AND reviewed_at IS NOT NULL
               AND reviewed_at < now() - make_interval(days => :days::int)"
        );
        $stmt->execute([':days' => SUBMISSION_REJECTED_RETENTION_DAYS]);
        $n = $stmt->rowCount();
        if ($n > 0) {
            logActivity(null, 'Auto-Purge Submissions', 'employment',
                "Automatically deleted {$n} rejected kiosk submission" . ($n === 1 ? '' : 's')
                . " after " . SUBMISSION_REJECTED_RETENTION_DAYS . "-day retention");
        }
    } catch (Throwable $e) {
        // Clean-up must never block the request it piggybacks on.
    }
}
