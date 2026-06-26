<?php
// PDO connection to e-peso_db

// Returns a single shared PDO instance for the whole request.
function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = include __DIR__ . '/../config.php';
    $c = $config['db'];

    // The hyphen in "e-peso_db" is fine inside the DSN string.
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}";

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never leak the DSN/credentials to the client.
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Database connection failed', 'detail' => $e->getMessage()]);
        exit;
    }

    return $pdo;
}
