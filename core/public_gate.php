<?php
// Who is asking: someone on the office network, or someone coming in through the public employer address?
//
// Employers reach the office server through a Cloudflare tunnel (see docs/employer-portal.md). Every request that comes
// through Cloudflare carries Cloudflare's own headers (CF-Ray / CF-Connecting-IP), which the visitor cannot remove or
// forge. Such a request is PUBLIC and may only reach the employer modules; everything else (staff, kiosk, backups,
// documents ...) answers "not found" no matter what a web server in front of it allows. Requests from the office
// network carry no such header and behave exactly as before.
//
// config.php may also list 'employer_public_hosts' => ['employers.yourdomain'] to treat requests for those host
// names as public too (belt and braces).

const EMPLOYER_MODULES = ['employer_auth', 'employer_portal'];

function requestIsPublic()
{
    if (!empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return true;

    $config = include __DIR__ . '/../config.php';
    $hosts = $config['employer_public_hosts'] ?? [];
    if (!$hosts) return false;
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    foreach ($hosts as $h) if ($host === strtolower($h)) return true;
    return false;
}

// True when config.php defines the limited database account for employers.
function employerDbConfigured()
{
    $config = include __DIR__ . '/../config.php';
    return !empty($config['employer_db']['user']);
}

// Called by the router once the module is known. Stops public requests from reaching anything but the employer modules,
// and refuses to serve employers from the internet until the limited database account is configured.
function applyPublicGate($module)
{
    $isEmployerModule = in_array($module, EMPLOYER_MODULES, true);
    if (requestIsPublic()) {
        if (!$isEmployerModule) error('Not found.', 404);
        if (!employerDbConfigured()) error('The employer portal is not set up yet.', 503);
    }
    if ($isEmployerModule) {
        define('EMPLOYER_REQUEST', true);   // db() then uses the limited account (core/db.php)
        // An unexpected error must never show the visitor file paths or SQL: log it, answer with a plain message.
        ini_set('display_errors', '0');
        set_exception_handler(function ($e) {
            error_log('Employer portal error: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['error' => 'Something went wrong. Please try again.']);
        });
    }
}
