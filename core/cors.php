<?php
// CORS headers + preflight for Vite

// Allow the Vite dev server to call this API and carry the session cookie.
$config = include __DIR__ . '/../config.php';
$origin = $config['cors_origin'];

header("Access-Control-Allow-Origin: {$origin}");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Answer the browser's preflight request and stop.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
