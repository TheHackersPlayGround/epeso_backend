<?php
// Backups: a full pg_dump snapshot of the live database PLUS every file in
// uploads/, bundled into one .zip. A bare SQL dump only carries
// attached_documents' metadata rows (filename, folder, uploader) -- the
// actual uploaded files live on disk under uploads/ and were never part of
// the dump, so a restore-only-from-SQL would leave every "View/Download"
// 404ing. Older backups created before this change are plain .sql files;
// both extensions stay supported below so existing history keeps working.
// Administrator-only (requireAdmin(), not requirePermission()) — a full
// dump contains every applicant's PII and every user's password hash, so
// this isn't gated by the regular per-module permission system at all.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

// Routes a backup action to its handler function.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'listBackups':    requireAdmin(); return backupList();
        case 'createBackup':   requireAdmin(); return backupCreate();
        case 'downloadBackup': requireAdmin(); return backupDownload($id);
        case 'deleteBackup':   requireAdmin(); return backupDelete($id);
        case 'restoreBackup':  requireAdmin(); return backupRestore($id);
        case 'restoreUpload':  requireAdmin(); return backupRestoreUpload();
        case 'restoreProgress': requireAdminSessionOnly(); return backupRestoreProgress();
        default: error("Unknown Backup action: {$action}", 404);
    }
}

// Returns the backups/ directory path.
function backupDir()
{
    return __DIR__ . '/../backups/';
}

// Returns the path to the restore-progress tracking file.
function backupProgressPath()
{
    return backupDir() . '.restore_progress.json';
}

// Writes the current restore progress to disk.
function backupWriteProgress($step, $total, $label, $done = false, $error = null)
{
    $payload = ['step' => $step, 'total' => $total, 'label' => $label, 'done' => $done, 'error' => $error];
    @file_put_contents(backupProgressPath(), json_encode($payload));
}

// Returns the current restore progress.
function backupRestoreProgress()
{
    $path = backupProgressPath();
    if (!is_file($path)) {
        json(['status' => 'ok', 'data' => ['step' => 0, 'total' => 1, 'label' => '', 'done' => true, 'error' => null]]);
        return;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        json(['status' => 'ok', 'data' => ['step' => 0, 'total' => 1, 'label' => '', 'done' => true, 'error' => null]]);
        return;
    }
    json(['status' => 'ok', 'data' => $data]);
}

// Returns the configured pg_dump executable path.
function backupPgDumpPath()
{
    $config = include __DIR__ . '/../config.php';
    return $config['pg_dump_path'] ?? '';
}

// Returns the psql executable path.
function backupPsqlPath()
{
    return dirname(backupPgDumpPath()) . '/psql.exe';
}

// Runs a shell command and returns [exitCode, stderr].
function backupRunCommand($cmd)
{
    $outFile = tempnam(sys_get_temp_dir(), 'peso_out_');
    $errFile = tempnam(sys_get_temp_dir(), 'peso_err_');
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']];
    $proc = proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($proc)) {
        @unlink($outFile); @unlink($errFile);
        return [1, 'Failed to start the process.'];
    }
    fclose($pipes[0]);
    $exitCode = proc_close($proc);
    $stderr = trim((string) file_get_contents($errFile));
    @unlink($outFile);
    @unlink($errFile);
    return [$exitCode, $stderr];
}

// Formats a byte count as a human-readable size.
function backupFormatBytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Validates a backup filename against the expected naming pattern.
function backupValidName($name)
{
    return is_string($name) && preg_match('/^PESO_DB_Backup_(?:(?:before_restore_)?\d{4}-\d{2}-\d{2}_\d{6}(?:_\d+)?|pre_[A-Za-z0-9_]+)\.(sql|zip)$/', $name);
}

// Parses the created-at timestamp out of a backup filename.
function backupCreatedAt($filename)
{
    if (preg_match('/^PESO_DB_Backup_(?:before_restore_)?(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})(?:_\d+)?\.(?:sql|zip)$/', $filename, $m)) {
        return "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}";
    }
    // Earlier automatic safety backups used a compact, dash-less date
    // (pre_restore_YYYYMMDD_HHMMSS) to fit the pre_<label> shape; still
    // listed, so they keep a proper created-at.
    if (preg_match('/^PESO_DB_Backup_pre_restore_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})(?:_\d+)?\.(?:sql|zip)$/', $filename, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }
    return '';
}

// Generates a unique backup filename for a given timestamp.
function backupUniqueName($ts, $ext, $safety = false)
{
    // $ts is 'YYYY-MM-DD_HHMMSS'. Automatic safety backups taken before a
    // restore get a "before_restore" marker so they're recognisable even
    // once downloaded, where the UI badge isn't shown.
    $base = $safety
        ? "PESO_DB_Backup_before_restore_{$ts}"
        : "PESO_DB_Backup_{$ts}";
    $name = "{$base}.{$ext}";
    $n = 2;
    while (is_file(backupDir() . $name)) {
        $name = "{$base}_{$n}.{$ext}";
        $n++;
    }
    return $name;
}

// Returns the list of available backup files.
function backupList()
{
    $files = array_merge(
        glob(backupDir() . 'PESO_DB_Backup_*.sql') ?: [],
        glob(backupDir() . 'PESO_DB_Backup_*.zip') ?: []
    );
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

// Adds a directory's files into a zip archive.
function backupAddDirToZip(ZipArchive $zip, $dir, $zipPrefix)
{
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $localPath = $zipPrefix . '/' . ltrim(substr($file->getPathname(), strlen($dir)), '/\\');
        $localPath = str_replace('\\', '/', $localPath);
        if ($file->isDir()) {
            $zip->addEmptyDir($localPath);
        } else {
            $zip->addFile($file->getPathname(), $localPath);
        }
    }
}

// Creates a full database + uploads backup and returns [filename, size].
function backupCreateSnapshot($safety = false)
{
    $config = include __DIR__ . '/../config.php';
    $db = $config['db'];

    $pgDump = backupPgDumpPath();
    if (!is_file($pgDump)) {
        error('pg_dump was not found on this server. Contact your system administrator.', 500);
    }
    if (!class_exists('ZipArchive')) {
        error('The PHP zip extension is not enabled on this server. Contact your system administrator.', 500);
    }

    // Sourced from Postgres's own clock, not PHP's date() — see
    // backupCreatedAt()'s comment for why they can disagree on this server.
    // Names are reserved via backupUniqueName() (against the .zip, the one
    // that actually persists) so this can never collide with -- and
    // overwrite -- another backup created moments earlier in the same
    // request (see backupUniqueName()'s comment for the incident that
    // caused this).
    $ts = db()->query("SELECT to_char(now(), 'YYYY-MM-DD_HH24MISS')")->fetchColumn();
    $zipName = backupUniqueName($ts, 'zip', $safety);
    $sqlName = substr($zipName, 0, -4) . '.sql';
    $sqlPath = backupDir() . $sqlName;

    $cmd = escapeshellarg($pgDump)
        . ' -h ' . escapeshellarg($db['host'])
        . ' -p ' . escapeshellarg($db['port'])
        . ' -U ' . escapeshellarg($db['user'])
        . ' -d ' . escapeshellarg($db['name'])
        . ' -F p -f ' . escapeshellarg($sqlPath);

    // Password goes through the environment, never the command line, so it
    // can't leak via a process listing.
    putenv('PGPASSWORD=' . $db['pass']);
    [$exitCode, $stderr] = backupRunCommand($cmd);
    putenv('PGPASSWORD');

    if ($exitCode !== 0 || !is_file($sqlPath)) {
        if (is_file($sqlPath)) @unlink($sqlPath);
        error('Backup failed: ' . trim($stderr ?: 'unknown error'), 500);
    }

    // Bundle the SQL dump + every uploaded file into one .zip so a single
    // download/restore carries both the data and the documents it refers to.
    $zipPath = backupDir() . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($sqlPath);
        error('Failed to create the backup archive.', 500);
    }
    $zip->addFile($sqlPath, $sqlName);
    backupAddDirToZip($zip, $config['upload_dir'], 'uploads');
    $zip->close();
    @unlink($sqlPath);

    if (!is_file($zipPath)) {
        error('Failed to create the backup archive.', 500);
    }

    return [$zipName, backupFormatBytes(filesize($zipPath))];
}

// Creates a backup and logs the action.
function backupCreate()
{
    [$zipName, $size] = backupCreateSnapshot();
    logActivity(currentUserId(), 'Create Backup', 'security', "Created database backup: {$zipName} ({$size})");
    json(['status' => 'ok', 'message' => 'Backup created.', 'data' => ['name' => $zipName, 'size' => $size]]);
}

// Streams a backup file to the browser for download.
function backupDownload($name)
{
    $name = basename((string) $name);
    if (!backupValidName($name)) error('Invalid backup file name.', 422);
    $path = backupDir() . $name;
    if (!is_file($path)) error('Backup file not found.', 404);

    logActivity(currentUserId(), 'Download Backup', 'security', "Downloaded database backup: {$name}");

    $isZip = str_ends_with(strtolower($name), '.zip');
    header('Content-Type: ' . ($isZip ? 'application/zip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Deletes a backup file.
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

// Restores the database from an uploaded backup file.
function backupRestoreUpload()
{
    if (empty($_FILES['backupFile']) || $_FILES['backupFile']['error'] !== UPLOAD_ERR_OK) {
        error('No valid file was uploaded.', 422);
    }

    $origName = $_FILES['backupFile']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    // New backups are always .zip (database + uploaded documents). Old .sql
    // backups already in backups/ can still be restored from Backup History,
    // but are no longer accepted as an upload, since they'd restore the
    // database without the documents it refers to.
    if ($ext !== 'zip') {
        error('Only .zip backup files are accepted.', 422);
    }

    $tmpPath = $_FILES['backupFile']['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        error('No valid file was uploaded.', 422);
    }

    // Shown in the activity log as the source of the restore. basename() +
    // control-character strip because this string is user-supplied.
    $label = preg_replace('/[\x00-\x1F\x7F]/', '', basename($origName));
    return backupRunRestore($tmpPath, true, "uploaded backup file: \"{$label}\"");
}

// Deletes everything inside a directory, keeping the directory itself.
function backupClearDirContents($dir)
{
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
}

// Recursively copies every file from $src into $dst, creating subfolders as needed.
function backupCopyDirRecursive($src, $dst)
{
    if (!is_dir($src)) return;
    if (!is_dir($dst)) mkdir($dst, 0777, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0777, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

// Runs a psql command against the live database and returns [exitCode, stderr].
function backupRunPsql($args)
{
    $config = include __DIR__ . '/../config.php';
    $db = $config['db'];
    $psql = backupPsqlPath();

    $cmd = escapeshellarg($psql)
        . ' -h ' . escapeshellarg($db['host'])
        . ' -p ' . escapeshellarg($db['port'])
        . ' -U ' . escapeshellarg($db['user'])
        . ' -d ' . escapeshellarg($db['name'])
        . ' -v ON_ERROR_STOP=1 '
        . $args;

    putenv('PGPASSWORD=' . $db['pass']);
    $result = backupRunCommand($cmd);
    putenv('PGPASSWORD');
    return $result;
}

// Restores the database and uploads from a backup file.
function backupRestore($name)
{
    $name = basename((string) $name);
    if (!backupValidName($name)) error('Invalid backup file name.', 422);
    $path = backupDir() . $name;
    if (!is_file($path)) error('Backup file not found.', 404);

    return backupRunRestore($path, str_ends_with(strtolower($name), '.zip'), "backup: {$name}");
}

// Restores the live database and uploads from a backup path.
function backupRunRestore($path, $isZip, $sourceLabel)
{
    $psql = backupPsqlPath();
    if (!is_file($psql)) {
        error('psql was not found on this server. Contact your system administrator.', 500);
    }

    $totalSteps = $isZip ? 6 : 4;
    $step = 0;

    $uid = currentUserId();
    // Release the session lock now that we have the user id. PHP's default
    // session handler holds an exclusive file lock for the whole request --
    // without closing it here, the frontend's restoreProgress polls (which
    // also start a session) would queue behind this multi-step restore and
    // only resolve once it's already finished, making the progress bar look
    // stuck at 0% and then jump straight to done.
    session_write_close();
    backupWriteProgress(++$step, $totalSteps, 'Creating safety backup of current data...');
    [$safetyName, $safetySize] = backupCreateSnapshot(true);
    logActivity($uid, 'Create Backup', 'security', "Automatic safety backup before restore: {$safetyName} ({$safetySize})");

    $tmpDir = null;
    $sqlPath = $path;
    $uploadsExtractDir = null;

    if ($isZip) {
        backupWriteProgress(++$step, $totalSteps, 'Extracting backup archive...');
        $tmpDir = sys_get_temp_dir() . '/peso_restore_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            backupClearDirContents($tmpDir); @rmdir($tmpDir);
            backupWriteProgress($step, $totalSteps, 'Failed', true, 'Failed to open the backup archive.');
            error('Failed to open the backup archive.', 500);
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        $sqlMatches = glob($tmpDir . '/PESO_DB_Backup_*.sql');
        if (empty($sqlMatches)) {
            backupClearDirContents($tmpDir); @rmdir($tmpDir);
            backupWriteProgress($step, $totalSteps, 'Failed', true, 'This backup archive does not contain a database dump.');
            error('This backup archive does not contain a database dump.', 422);
        }
        $sqlPath = $sqlMatches[0];
        if (is_dir($tmpDir . '/uploads')) {
            $uploadsExtractDir = $tmpDir . '/uploads';
        }
    }

    // Drop and recreate the schema so the dump restores to exactly the
    // backup's state -- anything created/changed since (tables, columns,
    // enum values) is wiped, matching "use that backup file instead".
    backupWriteProgress(++$step, $totalSteps, 'Resetting database schema...');
    [$dropCode, $dropErr] = backupRunPsql('-c ' . escapeshellarg('DROP SCHEMA public CASCADE; CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO public;'));
    if ($dropCode !== 0) {
        if ($tmpDir) { backupClearDirContents($tmpDir); @rmdir($tmpDir); }
        backupWriteProgress($step, $totalSteps, 'Failed', true, "Restore failed while resetting the database: {$dropErr}");
        error("Restore failed while resetting the database: {$dropErr}\n\nA safety backup was taken first: {$safetyName}. Restore that to recover.", 500);
    }

    backupWriteProgress(++$step, $totalSteps, 'Restoring database...');
    [$restoreCode, $restoreErr] = backupRunPsql('-f ' . escapeshellarg($sqlPath));
    if ($restoreCode !== 0) {
        if ($tmpDir) { backupClearDirContents($tmpDir); @rmdir($tmpDir); }
        backupWriteProgress($step, $totalSteps, 'Failed', true, "Restore failed while loading the backup: {$restoreErr}");
        error("Restore failed while loading the backup: {$restoreErr}\n\nA safety backup was taken first: {$safetyName}. Restore that to recover.", 500);
    }

    $uploadsRestored = false;
    if ($uploadsExtractDir) {
        backupWriteProgress(++$step, $totalSteps, 'Restoring uploaded files...');
        $config = include __DIR__ . '/../config.php';
        backupClearDirContents($config['upload_dir']);
        backupCopyDirRecursive($uploadsExtractDir, $config['upload_dir']);
        $uploadsRestored = true;
    }

    if ($tmpDir) { backupClearDirContents($tmpDir); @rmdir($tmpDir); }

    logActivity($uid, 'Restore Backup', 'security', "Restored system from {$sourceLabel}" . ($uploadsRestored ? '' : ' (database only -- this backup had no bundled uploads)') . ". Safety backup taken first: {$safetyName}");
    backupWriteProgress($totalSteps, $totalSteps, 'Restore complete.', true);
    json([
        'status'  => 'ok',
        'message' => 'Restore complete.',
        'data'    => ['uploadsRestored' => $uploadsRestored, 'safetyBackup' => $safetyName],
    ]);
}
