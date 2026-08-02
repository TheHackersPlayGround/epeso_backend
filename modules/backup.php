<?php
// Database backups: full pg_dump snapshots of the live database.
// Administrator-only (requireAdmin(), not requirePermission()) — a full
// dump contains every applicant's PII and every user's password hash, so
// this isn't gated by the regular per-module permission system at all.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listBackups':    requireAdmin(); return backupList();
        case 'createBackup':   requireAdmin(); return backupCreate();
        case 'downloadBackup': requireAdmin(); return backupDownload($id);
        case 'deleteBackup':   requireAdmin(); return backupDelete($id);
        default: error("Unknown Backup action: {$action}", 404);
    }
}

// Never web-accessible directly (backups/.htaccess denies all) — unlike
// uploads/, which is deliberately public for Documents Tab previews.
function backupDir()
{
    return __DIR__ . '/../backups/';
}

// Not on PATH, so pg_dump must be invoked by full path — differs per
// machine/PostgreSQL version, so it's a config.php setting rather than
// hardcoded here (see config.php's pg_dump_path).
function backupPgDumpPath()
{
    $config = include __DIR__ . '/../config.php';
    return $config['pg_dump_path'] ?? '';
}

function backupFormatBytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Only accepts names of the exact shape this module itself generates —
// blocks path traversal (e.g. "../../config.php") on the download/delete
// actions, which take a filename straight from the URL.
function backupValidName($name)
{
    return is_string($name) && preg_match('/^PESO_DB_Backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $name);
}

// The created-at shown here is parsed straight out of the filename (which
// encodes it) rather than the file's filesystem mtime — mtime reflects
// whatever timezone the PHP process's clock is in (this server's php.ini
// has date.timezone misconfigured to Europe/Berlin, 6 hours off from the
// app's actual local time), whereas the filename itself is built from
// Postgres's now() at creation time, same source every other timestamp in
// this app already uses.
function backupCreatedAt($filename)
{
    if (preg_match('/^PESO_DB_Backup_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})\.sql$/', $filename, $m)) {
        return "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}";
    }
    return '';
}

function backupList()
{
    $files = glob(backupDir() . 'PESO_DB_Backup_*.sql') ?: [];
    $items = array_map(function ($path) {
        $name = basename($path);
        return [
            'name'      => $name,
            'size'      => backupFormatBytes(filesize($path)),
            'createdAt' => backupCreatedAt($name),
        ];
    }, $files);
    usort($items, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    json(['status' => 'ok', 'data' => $items]);
}

function backupCreate()
{
    $config = include __DIR__ . '/../config.php';
    $db = $config['db'];

    $pgDump = backupPgDumpPath();
    if (!is_file($pgDump)) {
        error('pg_dump was not found on this server. Contact your system administrator.', 500);
    }

    // Sourced from Postgres's own clock, not PHP's date() — see
    // backupCreatedAt()'s comment for why they can disagree on this server.
    $ts = db()->query("SELECT to_char(now(), 'YYYY-MM-DD_HH24MISS')")->fetchColumn();
    $filename = "PESO_DB_Backup_{$ts}.sql";
    $path = backupDir() . $filename;

    $cmd = escapeshellarg($pgDump)
        . ' -h ' . escapeshellarg($db['host'])
        . ' -p ' . escapeshellarg($db['port'])
        . ' -U ' . escapeshellarg($db['user'])
        . ' -d ' . escapeshellarg($db['name'])
        . ' -F p -f ' . escapeshellarg($path);

    // Password goes through the environment, never the command line, so it
    // can't leak via a process listing.
    putenv('PGPASSWORD=' . $db['pass']);
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptorSpec, $pipes);
    putenv('PGPASSWORD');

    if (!is_resource($proc)) {
        error('Failed to start the backup process.', 500);
    }
    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($exitCode !== 0 || !is_file($path)) {
        if (is_file($path)) @unlink($path);
        error('Backup failed: ' . trim($stderr ?: 'unknown error'), 500);
    }

    $size = backupFormatBytes(filesize($path));
    logActivity(currentUserId(), 'Create Backup', 'security', "Created database backup: {$filename} ({$size})");
    json(['status' => 'ok', 'message' => 'Backup created.', 'data' => ['name' => $filename, 'size' => $size]]);
}

function backupDownload($name)
{
    $name = basename((string) $name);
    if (!backupValidName($name)) error('Invalid backup file name.', 422);
    $path = backupDir() . $name;
    if (!is_file($path)) error('Backup file not found.', 404);

    logActivity(currentUserId(), 'Download Backup', 'security', "Downloaded database backup: {$name}");

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function backupDelete($name)
{
    $name = basename((string) $name);
    if (!backupValidName($name)) error('Invalid backup file name.', 422);
    $path = backupDir() . $name;
    if (!is_file($path)) error('Backup file not found.', 404);

    unlink($path);
    logActivity(currentUserId(), 'Delete Backup', 'security', "Deleted database backup: {$name}");
    json(['status' => 'ok', 'message' => 'Backup deleted.']);
}
