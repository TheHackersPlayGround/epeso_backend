<?php
// Employer sign-in: login, logout, me, change password. (Employer accounts are created by an administrator:
// Employers tab -> Portal Account; see modules/employer_accounts.php.)

include_once __DIR__ . '/../core/employer_session.php';

function handle($action, $id, $method)
{
    employerNoStore();
    employerRequirePortalHeader();
    switch ($action) {
        case 'login':          return eauthLogin();
        case 'logout':         return eauthLogout();
        case 'me':             return eauthMe();
        case 'changePassword': return eauthChangePassword();
        default:
            error("Unknown employer_auth action: {$action}", 404);
    }
}

const EAUTH_MAX_FAILS_PER_USER = 5;    // wrong passwords for one username ...
const EAUTH_MAX_FAILS_PER_IP   = 25;   // ... or from one address, within the window below
const EAUTH_LOCK_MINUTES       = 15;

function eauthPayload($acc)
{
    return [
        'username'           => $acc['username'],
        'mustChangePassword' => (bool) $acc['must_change_password'],
        'employerId'         => (int) $acc['employer_id'],
        'companyName'        => $acc['company_name'],
    ];
}

function eauthIsLocked($username, $ip)
{
    $s = db()->prepare(
        "SELECT COUNT(*) FILTER (WHERE username = :u) AS by_user, COUNT(*) FILTER (WHERE ip_address = :ip) AS by_ip
         FROM employer_login_attempts WHERE created_at > now() - make_interval(mins => :m)"
    );
    $s->execute([':u' => $username, ':ip' => $ip, ':m' => EAUTH_LOCK_MINUTES]);
    $r = $s->fetch();
    return (int) $r['by_user'] >= EAUTH_MAX_FAILS_PER_USER || (int) $r['by_ip'] >= EAUTH_MAX_FAILS_PER_IP;
}

function eauthRecordFailure($username, $ip)
{
    db()->prepare("INSERT INTO employer_login_attempts (username, ip_address) VALUES (:u, :ip)")->execute([':u' => $username, ':ip' => $ip]);
    db()->exec("DELETE FROM employer_login_attempts WHERE created_at < now() - interval '1 day'");
}

// POST /api/employer_auth/login  { username, password }
function eauthLogin()
{
    $d = body();
    $username = strtolower(trim((string) ($d['username'] ?? '')));
    $password = (string) ($d['password'] ?? '');
    if ($username === '' || $password === '') error('Enter your username and password.', 422);
    if (strlen($username) > 50 || strlen($password) > 200) error('Incorrect username or password.', 401);

    $ip = employerClientIp();
    if (eauthIsLocked($username, $ip)) {
        error('Too many sign-in attempts. Please wait ' . EAUTH_LOCK_MINUTES . ' minutes and try again.', 429);
    }

    $s = db()->prepare(
        "SELECT a.account_id, a.employer_id, a.username, a.password_hash, a.status, a.must_change_password, e.company_name,
                e.status::text AS company_status, e.deleted_at AS company_deleted_at
         FROM employer_portal_accounts a JOIN employers e ON e.employer_id = a.employer_id WHERE a.username = :u"
    );
    $s->execute([':u' => $username]);
    $acc = $s->fetch();

    // Same work and same message whether the username exists or not (no hint which usernames are real).
    static $dummy = '$2y$10$OprwiPtgoWafpgtIRtpru.wFTovSseLuDOQirrqkswEHMaUR4ByHy';
    $ok = password_verify($password, $acc ? $acc['password_hash'] : $dummy);
    if (!$acc || !$ok) {
        eauthRecordFailure($username, $ip);
        error('Incorrect username or password.', 401);
    }
    if ($acc['status'] !== 'Active' || $acc['company_deleted_at'] !== null || $acc['company_status'] !== 'Active') {
        error('Your account is inactive. Please contact the PESO.', 403);
    }

    db()->prepare("DELETE FROM employer_login_attempts WHERE username = :u")->execute([':u' => $username]);
    employerSessionStart();
    session_regenerate_id(true);
    $_SESSION['employer_account_id'] = (int) $acc['account_id'];
    $_SESSION['employer_last_seen']  = time();
    db()->prepare("UPDATE employer_portal_accounts SET last_login = now() WHERE account_id = :id")->execute([':id' => (int) $acc['account_id']]);
    eauthLog((int) $acc['account_id'], (int) $acc['employer_id'], 'login');

    json(['status' => 'ok', 'data' => eauthPayload($acc)]);
}

// One line in the employer access log (administrators read it in Security -> Employer Activity). Never stops the request.
function eauthLog($accountId, $employerId, $action)
{
    try {
        db()->prepare("INSERT INTO employer_access_log (account_id, employer_id, action, ip_address) VALUES (:a, :e, :act, :ip)")
            ->execute([':a' => $accountId, ':e' => $employerId, ':act' => $action, ':ip' => employerClientIp()]);
    } catch (Throwable $e) {
        error_log('employer_auth: could not write the access log: ' . $e->getMessage());
    }
}

// POST /api/employer_auth/logout
function eauthLogout()
{
    // Record who is signing out (if anyone is signed in) before the session is ended.
    employerSessionStart();
    $accountId = (int) ($_SESSION['employer_account_id'] ?? 0);
    if ($accountId > 0) {
        try {
            $s = db()->prepare("SELECT employer_id FROM employer_portal_accounts WHERE account_id = :id");
            $s->execute([':id' => $accountId]);
            $employerId = $s->fetchColumn();
            if ($employerId !== false) eauthLog($accountId, (int) $employerId, 'logout');
        } catch (Throwable $e) {
            error_log('employer_auth: could not record the logout: ' . $e->getMessage());
        }
    }
    employerEndSession();
    json(['status' => 'ok']);
}

// GET /api/employer_auth/me -> the signed-in account, or 401
function eauthMe()
{
    $acc = requireEmployer(true);
    json(['status' => 'ok', 'data' => eauthPayload($acc)]);
}

// POST /api/employer_auth/changePassword  { newPassword, currentPassword? }
// The forced first change (right after signing in with the temporary password the PESO gave) does not ask for that password
// again: they typed it a moment ago and cannot do anything else until this is done. Any other change needs the current password.
function eauthChangePassword()
{
    $acc = requireEmployer(true);
    $d = body();
    $current = (string) ($d['currentPassword'] ?? '');
    $new     = (string) ($d['newPassword'] ?? '');

    $s = db()->prepare("SELECT password_hash FROM employer_portal_accounts WHERE account_id = :id");
    $s->execute([':id' => (int) $acc['account_id']]);
    $hash = $s->fetchColumn();
    $forced = (bool) $acc['must_change_password'];
    if (!$forced && !password_verify($current, (string) $hash)) error('Your current password is not correct.', 422);

    if (strlen($new) < 8)                                   error('Your new password must be at least 8 characters.', 422);
    if (strlen($new) > 200)                                 error('Your new password is too long.', 422);
    if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) error('Your new password needs at least one letter and one number.', 422);
    if (password_verify($new, (string) $hash))              error($forced ? 'Your new password must be different from the temporary one.' : 'Your new password must be different from the current one.', 422);
    if (strtolower($new) === strtolower($acc['username']))  error('Your password cannot be the same as your username.', 422);

    db()->prepare("UPDATE employer_portal_accounts SET password_hash = :h, must_change_password = FALSE, updated_at = now() WHERE account_id = :id")
        ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => (int) $acc['account_id']]);
    session_regenerate_id(true);

    db()->prepare("INSERT INTO employer_access_log (account_id, employer_id, action, ip_address) VALUES (:a, :e, 'change_password', :ip)")
        ->execute([':a' => (int) $acc['account_id'], ':e' => (int) $acc['employer_id'], ':ip' => employerClientIp()]);
    json(['status' => 'ok', 'message' => 'Password changed.']);
}
