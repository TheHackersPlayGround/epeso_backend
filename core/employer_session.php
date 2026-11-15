<?php
// Sessions for employer portal accounts. They are completely separate from staff sessions: a different cookie, a different
// session variable, and a different table of accounts, so an employer login can never satisfy a staff check
// (requireLogin() looks for the staff user id, which an employer session never has).

function employerIsHttps()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return stripos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"') !== false;   // set by Cloudflare
}

// The visitor's address. Behind Cloudflare the server only sees the tunnel, so the real address is in CF-Connecting-IP
// (Cloudflare sets it; a visitor cannot forge it on the public address).
function employerClientIp()
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return substr((string) $ip, 0, 45);
}

function employerSessionStart()
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_cache_limiter('');   // PHP's default would replace our Cache-Control: no-store
    session_name('EPESOEMPLOYER');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => employerIsHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function employerEndSession()
{
    employerSessionStart();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}

const EMPLOYER_IDLE_MINUTES = 120;

// The signed-in, still-active employer account with its company, or null. Idle sessions end; an account (or company) the
// PESO switched off is refused on the very next request.
function employerCurrentAccount()
{
    employerSessionStart();
    $id = $_SESSION['employer_account_id'] ?? null;
    if (!$id) return null;
    if (time() - (int) ($_SESSION['employer_last_seen'] ?? 0) > EMPLOYER_IDLE_MINUTES * 60) { employerEndSession(); return null; }

    $s = db()->prepare(
        "SELECT a.account_id, a.employer_id, a.username, a.status, a.must_change_password, e.company_name,
                e.status::text AS company_status, e.deleted_at AS company_deleted_at
         FROM employer_portal_accounts a JOIN employers e ON e.employer_id = a.employer_id WHERE a.account_id = :id"
    );
    $s->execute([':id' => (int) $id]);
    $acc = $s->fetch();
    if (!$acc || $acc['status'] !== 'Active' || $acc['company_deleted_at'] !== null || $acc['company_status'] !== 'Active') {
        employerEndSession();
        $GLOBALS['employer_session_end_reason'] = 'ACCOUNT_INACTIVE';   // requireEmployer() passes this on so the page can explain it
        return null;
    }
    $_SESSION['employer_last_seen'] = time();
    return $acc;
}

// Stops the request unless an employer is signed in. Until the first-login password change is done, only
// the change-password call (and me/logout) are allowed.
function requireEmployer($allowMustChange = false)
{
    $acc = employerCurrentAccount();
    if (!$acc) {
        if (($GLOBALS['employer_session_end_reason'] ?? '') === 'ACCOUNT_INACTIVE') {
            error('Your account is inactive. Please contact the PESO.', 401, ['code' => 'ACCOUNT_INACTIVE']);
        }
        error('Not signed in.', 401);
    }
    if ($acc['must_change_password'] && !$allowMustChange) {
        error('Please choose your own password first.', 403, ['code' => 'PASSWORD_CHANGE_REQUIRED']);
    }
    return $acc;
}

// Writes are only accepted from the employer page itself: a custom header can't be sent by another site's form or link
// (browsers block it cross-origin unless CORS allows it), which on top of SameSite cookies stops forged requests.
function employerRequirePortalHeader()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_SERVER['HTTP_X_PORTAL'] ?? '') !== '1') error('Request refused.', 403);
}

function employerNoStore()
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}
