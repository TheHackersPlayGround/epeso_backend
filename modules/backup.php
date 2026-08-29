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

function handle($action, $id, $method)
{
    switch ($action) {
        case 'listBackups':    requireAdmin(); return backupList();
        case 'createBackup':   requireAdmin(); return backupCreate();
        case 'downloadBackup': requireAdmin(); return backupDownload($id);
        case 'deleteBackup':   requireAdmin(); return backupDelete($id);
        case 'restoreBackup':  requireAdmin(); return backupRestore($id);
        case 'restoreUpload':  requireAdmin(); return backupRestoreUpload();
        case 'restoreProgress': requireAdmin(); return backupRestoreProgress();
        default: error("Unknown Backup action: {$action}", 404);
    }
}

// Never web-accessible directly (backups/.htaccess denies all) — unlike
// uploads/, which is deliberately public for Documents Tab previews.
function backupDir()
{
    return __DIR__ . '/../backups/';
}

// A restore runs as a single blocking request (it may take a while for a
// large database), so there's no in-band way for that same response to also
// stream out progress. Instead it writes its current step to this file as it
// goes, and the frontend polls restoreProgress() (a separate, fast request)
// on an interval while the restore request is in flight to show a real
// progress bar rather than a fake time-based animation. Single-admin tool --
// one fixed filename is fine, no need to key it per-restore.
function backupProgressPath()
{
    return backupDir() . '.restore_progress.json';
}

function backupWriteProgress($step, $total, $label, $done = false, $error = null)
{
    $payload = ['step' => $step, 'total' => $total, 'label' => $label, 'done' => $done, 'error' => $error];
    @file_put_contents(backupProgressPath(), json_encode($payload));
}

// GET /api/backup/restoreProgress -- polled by the frontend during a restore.
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

// Not on PATH, so pg_dump must be invoked by full path — differs per
// machine/PostgreSQL version, so it's a config.php setting rather than
// hardcoded here (see config.php's pg_dump_path).
function backupPgDumpPath()
{
    $config = include __DIR__ . '/../config.php';
    return $config['pg_dump_path'] ?? '';
}

// psql.exe always ships next to pg_dump.exe in the same PostgreSQL bin/
// folder, so it's derived from pg_dump_path rather than needing its own
// config.php entry.
function backupPsqlPath()
{
    return dirname(backupPgDumpPath()) . '/psql.exe';
}

// Runs a pg_dump/psql command with stdout+stderr redirected straight to
// temp files instead of pipes. A DROP SCHEMA CASCADE over a non-trivial
// schema makes Postgres emit a NOTICE per cascaded object on stderr while
// stdout stays silent until the whole statement finishes -- reading one
// pipe to completion before the other (or even draining both with
// stream_select()) can deadlock: PHP's stream_select() does not reliably
// work on proc_open pipes on Windows, so the child blocks writing to a full
// pipe nobody is actually draining, and the parent blocks waiting on the
// other one forever. Files have no such buffer limit, so this sidesteps
// the whole class of bug rather than trying to read pipes correctly.
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
// actions, which take a filename straight from the URL. .sql covers backups
// created before uploads/ was bundled in; new backups are always .zip. The
// optional _N suffix is for backupUniqueName()'s collision disambiguation
// (see its comment for why that's needed at all).
function backupValidName($name)
{
    return is_string($name) && preg_match('/^PESO_DB_Backup_\d{4}-\d{2}-\d{2}_\d{6}(?:_\d+)?\.(sql|zip)$/', $name);
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
    if (preg_match('/^PESO_DB_Backup_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})(?:_\d+)?\.(?:sql|zip)$/', $filename, $m)) {
        return "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}";
    }
    return '';
}

// Two backups created within the same request (e.g. an uploaded file saved
// under a fresh timestamp, immediately followed by that same restore's own
// automatic safety snapshot) can land on the identical second-resolution
// timestamp and therefore the identical filename. backupCreateSnapshot()
// used ZipArchive::OVERWRITE, which silently clobbered a just-uploaded
// backup before it was ever read from -- the "restore" ended up reloading
// the safety snapshot (i.e. current data) instead of the intended backup,
// with no error to show for it. This guarantees a name that doesn't already
// exist on disk, appending _2, _3, ... only in that rare collision case.
function backupUniqueName($ts, $ext)
{
    $name = "PESO_DB_Backup_{$ts}.{$ext}";
    $n = 2;
    while (is_file(backupDir() . $name)) {
        $name = "PESO_DB_Backup_{$ts}_{$n}.{$ext}";
        $n++;
    }
    return $name;
}

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

// Recursively adds every file under $dir into $zip beneath $zipPrefix,
// preserving the relative folder structure (documents/, gip photos, etc.).
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

// Core snapshot logic shared by backupCreate() (the user-facing button) and
// backupRestore() (which takes one of these automatically right before
// overwriting live data, so a bad restore always has a way back). Returns
// [filename, formattedSize] or throws via error() on failure.
function backupCreateSnapshot()
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
    $zipName = backupUniqueName($ts, 'zip');
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

function backupCreate()
{
    [$zipName, $size] = backupCreateSnapshot();
    logActivity(currentUserId(), 'Create Backup', 'security', "Created database backup: {$zipName} ({$size})");
    json(['status' => 'ok', 'message' => 'Backup created.', 'data' => ['name' => $zipName, 'size' => $size]]);
}

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

// Accepts a .sql/.zip uploaded from outside the system (e.g. the admin only
// has a copy on a USB drive/cloud folder because the on-server copy was
// deleted) and restores from it. The upload is saved into backups/ under a
// freshly generated system name first -- not the browser's original
// filename, to dodge collisions and keep every backup matching
// backupValidName()'s pattern -- then handed to the exact same
// backupRestore() used for on-server backups, so it also reappears in
// Backup History afterward like any other entry.
function backupRestoreUpload()
{
    if (empty($_FILES['backupFile']) || $_FILES['backupFile']['error'] !== UPLOAD_ERR_OK) {
        error('No valid file was uploaded.', 422);
    }

    $origName = $_FILES['backupFile']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['sql', 'zip'], true)) {
        error('Only .sql or .zip backup files are accepted.', 422);
    }

    $ts = db()->query("SELECT to_char(now(), 'YYYY-MM-DD_HH24MISS')")->fetchColumn();
    $name = backupUniqueName($ts, $ext);
    $path = backupDir() . $name;

    if (!move_uploaded_file($_FILES['backupFile']['tmp_name'], $path)) {
        error('Failed to save the uploaded file.', 500);
    }

    logActivity(currentUserId(), 'Upload Backup', 'security', "Uploaded external backup file, saved as: {$name} (originally \"{$origName}\")");

    return backupRestore($name);
}

// Deletes every file/folder inside $dir, keeping $dir itself. Used to wipe
// uploads/ clean before restoring a backup's own uploads/ over it, so stray
// files from after the backup don't linger mixed in with restored ones.
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

// Runs a psql command (either a literal SQL string via -c, or a .sql file
// via -f) against the live database. Returns [exitCode, stderr].
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

// Overwrites the live database + uploads/ with the contents of one backup.
// Always takes a fresh safety snapshot first (see backupCreateSnapshot())
// so a bad restore still has a way back -- if the restore itself fails
// partway, the DB may be left in a broken state; that snapshot is the
// recovery path in that case, not this function retrying anything.
function backupRestore($name)
{
    $name = basename((string) $name);
    if (!backupValidName($name)) error('Invalid backup file name.', 422);
    $path = backupDir() . $name;
    if (!is_file($path)) error('Backup file not found.', 404);

    $psql = backupPsqlPath();
    if (!is_file($psql)) {
        error('psql was not found on this server. Contact your system administrator.', 500);
    }

    $isZip = str_ends_with(strtolower($name), '.zip');
    $totalSteps = $isZip ? 6 : 4;
    $step = 0;

    $uid = currentUserId();
    backupWriteProgress(++$step, $totalSteps, 'Creating safety backup of current data...');
    [$safetyName, $safetySize] = backupCreateSnapshot();
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

    logActivity($uid, 'Restore Backup', 'security', "Restored system from backup: {$name}" . ($uploadsRestored ? ' (database + uploaded files)' : ' (database only -- this backup had no bundled uploads)') . ". Safety backup taken first: {$safetyName}");
    backupWriteProgress($totalSteps, $totalSteps, 'Restore complete.', true);
    json([
        'status'  => 'ok',
        'message' => 'Restore complete.',
        'data'    => ['uploadsRestored' => $uploadsRestored, 'safetyBackup' => $safetyName],
    ]);
}
