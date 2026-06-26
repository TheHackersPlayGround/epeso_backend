<?php
// Login, logout, me, change-password, security-answers

include_once __DIR__ . '/../core/helpers.php';

// Router entry point. index.php calls this with the parsed action.
function handle($action, $id, $method)
{
    // A session carries "who is logged in" across requests via a cookie.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    switch ($action) {
        case 'login':
            return authLogin();
        case 'logout':
            return authLogout();
        case 'me':
            return authMe();
        case 'change-password':
            return authChangePassword();
        case 'security-questions':
            return authSecurityQuestions();      // GET  - the master list of 10
        case 'security-answers':
            return authSetSecurityAnswers();     // POST - first-login setup (admin, logged in)
        case 'forgot-questions':
            return authForgotQuestions();        // POST {username} - that admin's 3 questions
        case 'forgot-verify':
            return authForgotVerify();           // POST {username, answers[]} - check answers only
        case 'forgot-reset':
            return authForgotReset();            // POST {username, answers[], newPassword}
        default:
            error("Unknown auth action: {$action}", 404);
    }
}

// The fixed list of 10 security questions, keyed by stable id (1-10).
function securityQuestions()
{
    return [
        1  => 'What was the name of your first school?',
        2  => 'What was the name of your first pet?',
        3  => 'What was your childhood nickname?',
        4  => "What is your mother's maiden name?",
        5  => 'What was your favorite subject in elementary school?',
        6  => 'What was the brand of your first mobile phone?',
        7  => 'What was your dream job as a child?',
        8  => 'What was the first company or organization you worked for?',
        9  => 'What was the name of your favorite teacher in elementary school?',
        10 => 'What is the first name of your oldest sibling?',
    ];
}

// Has this user already set up their security questions?
function securityQuestionsSet($userId)
{
    $stmt = db()->prepare("SELECT 1 FROM admin_security_answers WHERE user_id = :id");
    $stmt->execute([':id' => $userId]);
    return (bool) $stmt->fetchColumn();
}

// Normalize an answer before hashing/verifying so case/spacing don't matter.
function normalizeAnswer($a)
{
    return strtolower(trim((string) $a));
}

// POST /api/auth/login  { username, password }
function authLogin()
{
    $data     = body();
    $username = isset($data['username']) ? trim($data['username']) : '';
    $password = isset($data['password']) ? $data['password'] : '';

    if ($username === '' || $password === '') {
        error('Username and password are required.', 422);
    }

    $stmt = db()->prepare(
        "SELECT user_id, first_name, last_name, username, password_hash, role, status, last_login
         FROM users WHERE username = :u"
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    // Same vague message whether the user is missing or the password is wrong.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        error('Invalid username or password.', 401);
    }

    if ($user['status'] !== 'Active') {
        error('This account is inactive. Please contact an administrator.', 403);
    }

    // Record the login time.
    db()->prepare("UPDATE users SET last_login = now() WHERE user_id = :id")
        ->execute([':id' => $user['user_id']]);

    // Remember who is logged in.
    $_SESSION['user_id'] = (int) $user['user_id'];

    $pub = publicUser($user);
    $pub['permissions'] = authPermissionNames((int) $user['user_id'], $user['role']);
    // Admins must set up security questions on first login; staff never need them.
    $pub['securityQuestionsSet'] = ($user['role'] === 'Administrator')
        ? securityQuestionsSet((int) $user['user_id'])
        : true;
    json(['status' => 'ok', 'user' => $pub]);
}

// GET /api/auth/me  -> the currently logged-in user (used on page refresh)
function authMe()
{
    if (empty($_SESSION['user_id'])) {
        error('Not authenticated.', 401);
    }

    $stmt = db()->prepare(
        "SELECT user_id, first_name, last_name, username, role, status, last_login
         FROM users WHERE user_id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        // Session points at a user that no longer exists.
        session_destroy();
        error('Not authenticated.', 401);
    }

    $pub = publicUser($user);
    $pub['permissions'] = authPermissionNames((int) $user['user_id'], $user['role']);
    // Admins must set up security questions on first login; staff never need them.
    $pub['securityQuestionsSet'] = ($user['role'] === 'Administrator')
        ? securityQuestionsSet((int) $user['user_id'])
        : true;
    json(['status' => 'ok', 'user' => $pub]);
}

// Permission names (view-/manage-) for a user, rebuilt from the one-row-per-module
// store. Administrators always get the full set (every module at Editor).
function authPermissionNames($userId, $role)
{
    $modules = db()->query("SELECT permission_name FROM permissions ORDER BY permission_id")
                   ->fetchAll(PDO::FETCH_COLUMN);

    if ($role === 'Administrator') {
        $names = [];
        foreach ($modules as $m) {
            $names[] = "view-{$m}";
            $names[] = "manage-{$m}";
        }
        return $names;
    }

    $stmt = db()->prepare(
        "SELECT p.permission_name, up.permission_level
         FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = :id"
    );
    $stmt->execute([':id' => $userId]);

    $names = [];
    foreach ($stmt->fetchAll() as $r) {
        $names[] = "view-{$r['permission_name']}";
        if ($r['permission_level'] === 'Editor') {
            $names[] = "manage-{$r['permission_name']}";
        }
    }
    return $names;
}

// POST /api/auth/logout
function authLogout()
{
    $_SESSION = [];
    session_destroy();
    json(['status' => 'ok', 'message' => 'Logged out.']);
}

// POST /api/auth/change-password  { currentPassword, newPassword }
function authChangePassword()
{
    if (empty($_SESSION['user_id'])) {
        error('Not authenticated.', 401);
    }

    $data    = body();
    $current = isset($data['currentPassword']) ? $data['currentPassword'] : '';
    $new     = isset($data['newPassword']) ? $data['newPassword'] : '';

    if ($current === '' || $new === '') {
        error('Current and new password are required.', 422);
    }
    if (strlen($new) < 8) {
        error('New password must be at least 8 characters.', 422);
    }

    $stmt = db()->prepare("SELECT password_hash FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        error('Current password is incorrect.', 403);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    db()->prepare("UPDATE users SET password_hash = :h, updated_at = now() WHERE user_id = :id")
        ->execute([':h' => $hash, ':id' => $_SESSION['user_id']]);

    json(['status' => 'ok', 'message' => 'Password updated.']);
}

// GET /api/auth/security-questions -> the master list of 10 [{id, text}]
function authSecurityQuestions()
{
    $out = [];
    foreach (securityQuestions() as $id => $text) {
        $out[] = ['id' => $id, 'text' => $text];
    }
    json(['status' => 'ok', 'questions' => $out]);
}

// POST /api/auth/security-answers  { questions:[id1,id2,id3], answers:[a1,a2,a3] }
// First-login setup (or re-setup) for the logged-in Administrator.
function authSetSecurityAnswers()
{
    if (empty($_SESSION['user_id'])) {
        error('Not authenticated.', 401);
    }
    $stmt = db()->prepare("SELECT role FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    if ($stmt->fetchColumn() !== 'Administrator') {
        error('Only administrators set up security questions.', 403);
    }

    $d  = body();
    $qs = (isset($d['questions']) && is_array($d['questions'])) ? array_values($d['questions']) : [];
    $as = (isset($d['answers']) && is_array($d['answers'])) ? array_values($d['answers']) : [];

    if (count($qs) !== 3 || count($as) !== 3) {
        error('Select 3 questions and provide 3 answers.', 422);
    }

    $valid = securityQuestions();
    $qs = array_map('intval', $qs);
    foreach ($qs as $qid) {
        if (!isset($valid[$qid])) {
            error('Invalid question selected.', 422);
        }
    }
    if (count(array_unique($qs)) !== 3) {
        error('Please choose 3 different questions.', 422);
    }
    foreach ($as as $ans) {
        if (normalizeAnswer($ans) === '') {
            error('All answers are required.', 422);
        }
    }

    db()->prepare(
        "INSERT INTO admin_security_answers
            (user_id, question_1_id, question_2_id, question_3_id, answer_1_hash, answer_2_hash, answer_3_hash, created_at, updated_at)
         VALUES (:uid, :q1, :q2, :q3, :a1, :a2, :a3, now(), now())
         ON CONFLICT (user_id) DO UPDATE SET
            question_1_id = EXCLUDED.question_1_id,
            question_2_id = EXCLUDED.question_2_id,
            question_3_id = EXCLUDED.question_3_id,
            answer_1_hash = EXCLUDED.answer_1_hash,
            answer_2_hash = EXCLUDED.answer_2_hash,
            answer_3_hash = EXCLUDED.answer_3_hash,
            updated_at = now()"
    )->execute([
        ':uid' => $_SESSION['user_id'],
        ':q1'  => $qs[0], ':q2' => $qs[1], ':q3' => $qs[2],
        ':a1'  => password_hash(normalizeAnswer($as[0]), PASSWORD_DEFAULT),
        ':a2'  => password_hash(normalizeAnswer($as[1]), PASSWORD_DEFAULT),
        ':a3'  => password_hash(normalizeAnswer($as[2]), PASSWORD_DEFAULT),
    ]);

    json(['status' => 'ok', 'message' => 'Security questions saved.']);
}

// POST /api/auth/forgot-questions  { username } -> that admin's 3 chosen questions
function authForgotQuestions()
{
    $d = body();
    $username = isset($d['username']) ? trim($d['username']) : '';
    if ($username === '') {
        error('Username is required.', 422);
    }

    $stmt = db()->prepare(
        "SELECT a.question_1_id, a.question_2_id, a.question_3_id
         FROM users u
         JOIN admin_security_answers a ON a.user_id = u.user_id
         WHERE u.username = :u AND u.role = 'Administrator' AND u.status = 'Active'"
    );
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if (!$row) {
        error("Password recovery is only available for administrator accounts with security questions set up. If you're a staff member, please contact an administrator to reset your password.", 404);
    }

    $qmap = securityQuestions();
    $questions = [];
    foreach (['question_1_id', 'question_2_id', 'question_3_id'] as $i => $col) {
        $qid = (int) $row[$col];
        $questions[] = ['position' => $i + 1, 'id' => $qid, 'text' => isset($qmap[$qid]) ? $qmap[$qid] : ''];
    }

    json(['status' => 'ok', 'questions' => $questions]);
}

// POST /api/auth/forgot-verify  { username, answers:[a1,a2,a3] } -> 200 if all correct
// Checks the answers without changing anything (used before showing the reset form).
function authForgotVerify()
{
    $d = body();
    $username = isset($d['username']) ? trim($d['username']) : '';
    $answers  = (isset($d['answers']) && is_array($d['answers'])) ? array_values($d['answers']) : [];

    if ($username === '' || count($answers) !== 3) {
        error('Username and 3 answers are required.', 422);
    }

    $stmt = db()->prepare(
        "SELECT a.answer_1_hash, a.answer_2_hash, a.answer_3_hash
         FROM users u
         JOIN admin_security_answers a ON a.user_id = u.user_id
         WHERE u.username = :u AND u.role = 'Administrator' AND u.status = 'Active'"
    );
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if (!$row) {
        error("Password recovery is only available for administrator accounts with security questions set up. If you're a staff member, please contact an administrator to reset your password.", 404);
    }

    $ok = password_verify(normalizeAnswer($answers[0]), $row['answer_1_hash'])
       && password_verify(normalizeAnswer($answers[1]), $row['answer_2_hash'])
       && password_verify(normalizeAnswer($answers[2]), $row['answer_3_hash']);
    if (!$ok) {
        error('One or more answers are incorrect.', 403);
    }

    json(['status' => 'ok', 'message' => 'Answers verified.']);
}

// POST /api/auth/forgot-reset  { username, answers:[a1,a2,a3], newPassword }
function authForgotReset()
{
    $d = body();
    $username = isset($d['username']) ? trim($d['username']) : '';
    $answers  = (isset($d['answers']) && is_array($d['answers'])) ? array_values($d['answers']) : [];
    $newPass  = isset($d['newPassword']) ? $d['newPassword'] : '';

    if ($username === '' || count($answers) !== 3) {
        error('Username and 3 answers are required.', 422);
    }
    if (strlen($newPass) < 8) {
        error('New password must be at least 8 characters.', 422);
    }

    $stmt = db()->prepare(
        "SELECT u.user_id, a.answer_1_hash, a.answer_2_hash, a.answer_3_hash
         FROM users u
         JOIN admin_security_answers a ON a.user_id = u.user_id
         WHERE u.username = :u AND u.role = 'Administrator' AND u.status = 'Active'"
    );
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if (!$row) {
        error("Password recovery is only available for administrator accounts with security questions set up. If you're a staff member, please contact an administrator to reset your password.", 404);
    }

    $ok = password_verify(normalizeAnswer($answers[0]), $row['answer_1_hash'])
       && password_verify(normalizeAnswer($answers[1]), $row['answer_2_hash'])
       && password_verify(normalizeAnswer($answers[2]), $row['answer_3_hash']);
    if (!$ok) {
        error('One or more answers are incorrect.', 403);
    }

    db()->prepare("UPDATE users SET password_hash = :h, updated_at = now() WHERE user_id = :id")
        ->execute([':h' => password_hash($newPass, PASSWORD_DEFAULT), ':id' => $row['user_id']]);

    json(['status' => 'ok', 'message' => 'Password reset. You can now log in.']);
}
