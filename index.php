<?php
// Front controller: routes /api/{module}/{action}/{id} to a module

include_once __DIR__ . '/core/cors.php';
include_once __DIR__ . '/core/db.php';
include_once __DIR__ . '/core/response.php';

// --- Parse the path into segments after "/api/" ---
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode($uri);

$pos = strpos($uri, '/api/');
if ($pos === false) {
    // Allow a bare ".../api" as well.
    if (preg_match('#/api/?$#', $uri)) {
        json(['status' => 'ok', 'message' => 'ePESO API is running']);
    }
    error('Not found. Use /api/{module}/{action}/{id}', 404);
}

$path     = trim(substr($uri, $pos + 5), '/'); // everything after "/api/"
$segments = $path === '' ? [] : explode('/', $path);

$module = isset($segments[0]) ? $segments[0] : null;
$action = isset($segments[1]) ? $segments[1] : 'index';
$id     = isset($segments[2]) ? $segments[2] : null;
$method = $_SERVER['REQUEST_METHOD'];

// --- Health check (Sprint 0 test) ---
if ($module === null || $module === 'ping') {
    $dbStatus = 'unknown';
    try {
        db()->query('SELECT 1');
        $dbStatus = 'connected';
    } catch (Throwable $e) {
        $dbStatus = 'error: ' . $e->getMessage();
    }
    json([
        'status'  => 'ok',
        'message' => 'ePESO API is running',
        'db'      => $dbStatus,
        'time'    => date('c'),
    ]);
}

// --- Route to a module file ---
$moduleFile = __DIR__ . '/modules/' . basename($module) . '.php';

if (!is_file($moduleFile)) {
    error("Unknown module: {$module}", 404);
}

// Each module file must define handle($action, $id, $method).
include_once $moduleFile;

if (!function_exists('handle')) {
    error("Module {$module} does not define handle()", 500);
}

handle($action, $id, $method);
