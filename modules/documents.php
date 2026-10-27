<?php
// Documents Tab: folder-organized PESO-staff document library, unrelated to any applicant/project.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

// Routes a documents action to its handler function.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'listFolders':    requirePermission('documents', 'Viewer'); return docsListFolders();
        case 'createFolder':   requirePermission('documents', 'Editor'); return docsCreateFolder();
        case 'renameFolder':   requirePermission('documents', 'Editor'); return docsRenameFolder($id);
        case 'deleteFolder':   requirePermission('documents', 'Editor'); return docsDeleteFolder($id);
        case 'listDocuments':  requirePermission('documents', 'Viewer'); return docsListDocuments();
        case 'uploadDocument': requirePermission('documents', 'Editor'); return docsUploadDocument();
        case 'renameDocument': requirePermission('documents', 'Editor'); return docsRenameDocument($id);
        case 'moveDocument':   requirePermission('documents', 'Editor'); return docsMoveDocument($id);
        case 'deleteDocument': requirePermission('documents', 'Editor'); return docsDeleteDocument($id);
        case 'listDeleted':    requirePermission('documents', 'Viewer'); return docsListDeleted();
        case 'restoreRecord':  requirePermission('documents', 'Editor'); return docsRestoreRecord();
        case 'purgeRecord':    requirePermission('documents', 'Editor'); return docsPurgeRecord();
        default: error("Unknown Documents action: {$action}", 404);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

// Returns the base URL for serving uploaded files.
function docsUploadBaseUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

// Formats a byte count as a human-readable size.
function docsFormatBytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Resolves a parent folder sentinel into [parentId, parentLevel].
function docsResolveParent($parentId)
{
    if (!$parentId || $parentId === 'root') return [null, 0];
    if (!ctype_digit((string) $parentId)) error('Invalid parent folder.', 422);
    $s = db()->prepare("SELECT folder_id, folder_level FROM folders WHERE folder_id = :id AND deleted_at IS NULL");
    $s->execute([':id' => (int) $parentId]);
    $row = $s->fetch();
    if (!$row) error('Parent folder not found.', 404);
    return [(int) $row['folder_id'], (int) $row['folder_level']];
}

// Checks whether a folder name is already used under a parent.
function docsFolderNameTaken($name, $parentId, $excludeId = null)
{
    $sql = "SELECT 1 FROM folders WHERE deleted_at IS NULL AND folder_name = :name AND "
        . ($parentId === null ? 'parent_folder_id IS NULL' : 'parent_folder_id = :pid');
    $params = [':name' => $name];
    if ($parentId !== null) $params[':pid'] = $parentId;
    if ($excludeId !== null) {
        $sql .= ' AND folder_id != :exclude';
        $params[':exclude'] = $excludeId;
    }
    $s = db()->prepare($sql);
    $s->execute($params);
    return (bool) $s->fetchColumn();
}

// Checks whether a document name is already used within a folder.
function docsDocumentNameTaken($name, $folderId, $excludeId = null)
{
    $sql = "SELECT 1 FROM document_library WHERE deleted_at IS NULL AND file_name = :name AND "
        . ($folderId === null ? 'folder_id IS NULL' : 'folder_id = :fid');
    $params = [':name' => $name];
    if ($folderId !== null) $params[':fid'] = $folderId;
    if ($excludeId !== null) {
        $sql .= ' AND document_id != :exclude';
        $params[':exclude'] = $excludeId;
    }
    $s = db()->prepare($sql);
    $s->execute($params);
    return (bool) $s->fetchColumn();
}

// ─── Folders ─────────────────────────────────────────────────────────────────

// Returns the list of folders.
function docsListFolders()
{
    $s = db()->query(
        "SELECT folder_id, parent_folder_id, folder_name
         FROM folders
         WHERE deleted_at IS NULL
         ORDER BY folder_name"
    );
    $data = array_map(function ($r) {
        return [
            'id'       => (string) $r['folder_id'],
            'name'     => $r['folder_name'],
            'parentId' => $r['parent_folder_id'] !== null ? (string) $r['parent_folder_id'] : 'root',
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $data]);
}

// Creates a new folder.
function docsCreateFolder()
{
    $d    = body();
    $name = trim((string) ($d['name'] ?? ''));
    if ($name === '') error('Folder name is required.', 422);

    [$parentId, $parentLevel] = docsResolveParent($d['parentId'] ?? 'root');
    // Matches the frontend's existing 2-level nesting limit (top-level +
    // one level of subfolders) — a folder at level 2 can't take children.
    if ($parentLevel >= 2) {
        error('Cannot create folder: maximum nesting depth reached.', 422);
    }
    if (docsFolderNameTaken($name, $parentId)) {
        error("A folder named \"{$name}\" already exists here.", 409);
    }

    $uid = currentUserId();
    $s = db()->prepare(
        "INSERT INTO folders (parent_folder_id, folder_name, folder_level, created_by_user_id)
         VALUES (:pid, :name, :level, :uid) RETURNING folder_id"
    );
    $s->execute([':pid' => $parentId, ':name' => $name, ':level' => $parentLevel + 1, ':uid' => $uid]);
    $newId = $s->fetchColumn();

    logActivity($uid, 'Create Folder', 'documents', "Created folder: {$name}");
    json(['status' => 'ok', 'message' => 'Folder created.', 'data' => [
        'id' => (string) $newId, 'name' => $name, 'parentId' => $parentId !== null ? (string) $parentId : 'root',
    ]]);
}

// Renames a folder.
function docsRenameFolder($id)
{
    $id = (int) $id;
    if (!$id) error('Invalid folder ID.', 422);
    $d    = body();
    $name = trim((string) ($d['name'] ?? ''));
    if ($name === '') error('Folder name is required.', 422);

    $chk = db()->prepare("SELECT folder_name, parent_folder_id FROM folders WHERE folder_id = :id AND deleted_at IS NULL");
    $chk->execute([':id' => $id]);
    $row = $chk->fetch();
    if (!$row) error('Folder not found.', 404);
    $oldName = $row['folder_name'];
    $parentId = $row['parent_folder_id'] !== null ? (int) $row['parent_folder_id'] : null;

    if ($name !== $oldName && docsFolderNameTaken($name, $parentId, $id)) {
        error("A folder named \"{$name}\" already exists here.", 409);
    }

    db()->prepare("UPDATE folders SET folder_name = :name, updated_at = now() WHERE folder_id = :id")
        ->execute([':name' => $name, ':id' => $id]);

    logActivity(currentUserId(), 'Rename Folder', 'documents', "Renamed folder \"{$oldName}\" to \"{$name}\"");
    json(['status' => 'ok', 'message' => 'Folder renamed.']);
}

// Deletes a folder, if it has no active files or subfolders.
function docsDeleteFolder($id)
{
    $id = (int) $id;
    if (!$id) error('Invalid folder ID.', 422);

    $chk = db()->prepare("SELECT folder_name FROM folders WHERE folder_id = :id AND deleted_at IS NULL");
    $chk->execute([':id' => $id]);
    $name = $chk->fetchColumn();
    if ($name === false) error('Folder not found.', 404);

    $fileCntS = db()->prepare("SELECT COUNT(*) FROM document_library WHERE folder_id = :id AND deleted_at IS NULL");
    $fileCntS->execute([':id' => $id]);
    $fileCnt = (int) $fileCntS->fetchColumn();

    $subCntS = db()->prepare("SELECT COUNT(*) FROM folders WHERE parent_folder_id = :id AND deleted_at IS NULL");
    $subCntS->execute([':id' => $id]);
    $subCnt = (int) $subCntS->fetchColumn();

    if ($fileCnt > 0 || $subCnt > 0) {
        $parts = [];
        if ($fileCnt > 0) $parts[] = "{$fileCnt} file" . ($fileCnt === 1 ? '' : 's');
        if ($subCnt > 0) $parts[] = "{$subCnt} subfolder" . ($subCnt === 1 ? '' : 's');
        error('Cannot delete this folder — it still contains ' . implode(' and ', $parts) . '. Move or delete them first.', 409);
    }

    db()->prepare("UPDATE folders SET deleted_at = now(), deleted_by = :uid WHERE folder_id = :id")
        ->execute([':uid' => currentUserId(), ':id' => $id]);

    logActivity(currentUserId(), 'Delete Folder', 'documents', "Deleted folder: {$name}");
    json(['status' => 'ok', 'message' => 'Folder deleted.']);
}

// ─── Documents ────────────────────────────────────────────────────────────────

// Returns the list of documents.
function docsListDocuments()
{
    $s = db()->query(
        "SELECT dl.document_id, dl.folder_id, dl.file_name, dl.file_path,
                dl.file_size, dl.uploaded_at, u.username AS uploaded_by
         FROM document_library dl
         LEFT JOIN users u ON u.user_id = dl.uploaded_by
         WHERE dl.deleted_at IS NULL
         ORDER BY dl.uploaded_at DESC"
    );
    $data = array_map(function ($r) {
        return [
            'id'           => (string) $r['document_id'],
            'folderId'     => $r['folder_id'] !== null ? (string) $r['folder_id'] : 'root',
            'name'         => $r['file_name'],
            'size'         => docsFormatBytes($r['file_size'] ?? 0),
            'uploadedBy'   => $r['uploaded_by'] ?? '(deleted user)',
            'uploadedDate' => substr($r['uploaded_at'], 0, 10),
            'category'     => 'Uncategorized',
            'fileUrl'      => docsUploadBaseUrl() . $r['file_path'],
        ];
    }, $s->fetchAll());
    json(['status' => 'ok', 'data' => $data]);
}

// Uploads a new document.
function docsUploadDocument()
{
    $d       = body();
    $dataUrl = $d['dataUrl'] ?? '';
    if (!is_string($dataUrl) || !preg_match('#^data:([^;]+);base64,(.+)$#s', $dataUrl, $m)) {
        error('Invalid file data.', 422);
    }
    $binary = base64_decode($m[2], true);
    if ($binary === false) error('Invalid file data.', 422);

    [$folderId, ] = docsResolveParent($d['folderId'] ?? 'root');

    $origName = trim((string) ($d['fileName'] ?? 'file'));
    if ($origName === '') $origName = 'file';
    if (docsDocumentNameTaken($origName, $folderId)) {
        error("A file named \"{$origName}\" already exists here.", 409);
    }
    $ext    = pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin';
    $stored = 'doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (file_put_contents(__DIR__ . '/../uploads/' . $stored, $binary) === false) {
        error('Failed to save the uploaded file.', 500);
    }

    $uid = currentUserId();
    $s = db()->prepare(
        "INSERT INTO document_library (folder_id, title, file_name, file_path, file_size, mime_type, uploaded_by)
         VALUES (:fid, :title, :fname, :fpath, :size, :mime, :uid) RETURNING document_id"
    );
    $s->execute([
        ':fid'   => $folderId,
        ':title' => $origName,
        ':fname' => $origName,
        ':fpath' => 'uploads/' . $stored,
        ':size'  => strlen($binary),
        ':mime'  => $m[1],
        ':uid'   => $uid,
    ]);
    $newId = $s->fetchColumn();

    logActivity($uid, 'Upload Document', 'documents', "Uploaded document: {$origName}");
    json(['status' => 'ok', 'message' => 'Document uploaded.', 'data' => ['id' => (string) $newId]]);
}

// Renames a document.
function docsRenameDocument($id)
{
    $id = (int) $id;
    if (!$id) error('Invalid document ID.', 422);
    $d    = body();
    $name = trim((string) ($d['name'] ?? ''));
    if ($name === '') error('File name is required.', 422);

    $chk = db()->prepare("SELECT file_name, folder_id FROM document_library WHERE document_id = :id AND deleted_at IS NULL");
    $chk->execute([':id' => $id]);
    $row = $chk->fetch();
    if (!$row) error('Document not found.', 404);
    $oldName = $row['file_name'];
    $folderId = $row['folder_id'] !== null ? (int) $row['folder_id'] : null;

    // Extension is not user-editable; client input is treated as base name only and the
    // original extension is always reappended.
    $oldExt = pathinfo($oldName, PATHINFO_EXTENSION);
    $newBase = pathinfo($name, PATHINFO_FILENAME);
    if ($newBase === '') error('File name is required.', 422);
    $name = $oldExt !== '' ? "{$newBase}.{$oldExt}" : $newBase;

    if ($name !== $oldName && docsDocumentNameTaken($name, $folderId, $id)) {
        error("A file named \"{$name}\" already exists here.", 409);
    }

    db()->prepare("UPDATE document_library SET title = :name, file_name = :name, updated_at = now() WHERE document_id = :id")
        ->execute([':name' => $name, ':id' => $id]);

    logActivity(currentUserId(), 'Rename Document', 'documents', "Renamed document \"{$oldName}\" to \"{$name}\"");
    json(['status' => 'ok', 'message' => 'Document renamed.']);
}

// Moves a document to a different folder.
function docsMoveDocument($id)
{
    $id = (int) $id;
    if (!$id) error('Invalid document ID.', 422);
    $d = body();
    [$folderId, ] = docsResolveParent($d['folderId'] ?? 'root');

    $chk = db()->prepare("SELECT file_name FROM document_library WHERE document_id = :id AND deleted_at IS NULL");
    $chk->execute([':id' => $id]);
    $docName = $chk->fetchColumn();
    if ($docName === false) error('Document not found.', 404);

    if (docsDocumentNameTaken($docName, $folderId, $id)) {
        error("A file named \"{$docName}\" already exists in the destination folder.", 409);
    }

    $destName = 'Documents';
    if ($folderId !== null) {
        $fS = db()->prepare("SELECT folder_name FROM folders WHERE folder_id = :id");
        $fS->execute([':id' => $folderId]);
        $destName = $fS->fetchColumn() ?: 'Documents';
    }

    db()->prepare("UPDATE document_library SET folder_id = :fid, updated_at = now() WHERE document_id = :id")
        ->execute([':fid' => $folderId, ':id' => $id]);

    logActivity(currentUserId(), 'Move Document', 'documents', "Moved document \"{$docName}\" to \"{$destName}\"");
    json(['status' => 'ok', 'message' => 'Document moved.']);
}

// Soft-deletes a document.
function docsDeleteDocument($id)
{
    $id = (int) $id;
    if (!$id) error('Invalid document ID.', 422);

    $chk = db()->prepare("SELECT file_name FROM document_library WHERE document_id = :id AND deleted_at IS NULL");
    $chk->execute([':id' => $id]);
    $name = $chk->fetchColumn();
    if ($name === false) error('Document not found.', 404);

    db()->prepare("UPDATE document_library SET deleted_at = now(), deleted_by = :uid WHERE document_id = :id")
        ->execute([':uid' => currentUserId(), ':id' => $id]);

    logActivity(currentUserId(), 'Delete Document', 'documents', "Deleted document: {$name}");
    json(['status' => 'ok', 'message' => 'Document moved to recycle bin.']);
}

// ─── Recycle bin ──────────────────────────────────────────────────────────────

// Maps each recycle-bin record type to its table and primary key.
function docsRecycleMap()
{
    return [
        'documentsFolder'   => ['folders', 'folder_id'],
        'documentsDocument' => ['document_library', 'document_id'],
    ];
}

// Reads and validates {recordType, id} from the request body.
function docsRecycleTarget()
{
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(docsRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id, $d];
}

// Counts document_library rows referencing a folder, active or deleted.
function docsFolderFileCount($folderId)
{
    $s = db()->prepare("SELECT COUNT(*) FROM document_library WHERE folder_id = :id");
    $s->execute([':id' => $folderId]);
    return (int) $s->fetchColumn();
}

// Returns the recycle bin contents for folders and documents.
function docsListDeleted()
{
    docsPurgeExpired();
    $items = [];

    // Shows origin context (parent folder it'll restore into) rather than the module badge.
    $fS = db()->query(
        "SELECT f.folder_id AS id, f.folder_name AS name, f.deleted_at, u.username AS deleted_by,
                pf.folder_name AS parent_name
         FROM folders f
         LEFT JOIN users u ON u.user_id = f.deleted_by
         LEFT JOIN folders pf ON pf.folder_id = f.parent_folder_id
         WHERE f.deleted_at IS NOT NULL"
    );
    foreach ($fS->fetchAll() as $r) {
        $items[] = [
            'recordType'  => 'documentsFolder',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'Document Folders',
            'description' => $r['parent_name'] !== null ? "Subfolder of {$r['parent_name']}" : 'Top-level folder',
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }

    $dS = db()->query(
        "SELECT dl.document_id AS id, dl.file_name AS name, dl.file_size, dl.deleted_at, u.username AS deleted_by,
                f.folder_name AS folder_name, pf.folder_name AS parent_name
         FROM document_library dl
         LEFT JOIN users u ON u.user_id = dl.deleted_by
         LEFT JOIN folders f ON f.folder_id = dl.folder_id
         LEFT JOIN folders pf ON pf.folder_id = f.parent_folder_id
         WHERE dl.deleted_at IS NOT NULL"
    );
    foreach ($dS->fetchAll() as $r) {
        $location = $r['folder_name'] === null
            ? 'Documents (root)'
            : ($r['parent_name'] !== null ? "{$r['parent_name']} › {$r['folder_name']}" : $r['folder_name']);
        $items[] = [
            'recordType'  => 'documentsDocument',
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'module'      => 'Document Files',
            'description' => "From: {$location} · " . docsFormatBytes($r['file_size'] ?? 0),
            'deletedBy'   => $r['deleted_by'] ?? '',
            'deletedAt'   => $r['deleted_at'],
        ];
    }

    json(['status' => 'ok', 'data' => $items]);
}

// Returns a recycle-bin record's display name.
function docsRecordName($type, $id)
{
    $nameCol = $type === 'documentsFolder' ? 'folder_name' : 'file_name';
    [$table, $pk] = docsRecycleMap()[$type];
    $s = db()->prepare("SELECT {$nameCol} FROM {$table} WHERE {$pk} = :id");
    $s->execute([':id' => $id]);
    $name = $s->fetchColumn();
    return $name !== false ? $name : "#{$id}";
}

// Renames a restored record if its name is already taken in the destination.
function docsRenameIfTakenAfterRestore($type, $id, $name)
{
    if ($type === 'documentsFolder') {
        $s = db()->prepare("SELECT parent_folder_id FROM folders WHERE folder_id = :id");
        $s->execute([':id' => $id]);
        $parent = $s->fetchColumn();
        $parent = $parent === null || $parent === false ? null : (int) $parent;

        if (!docsFolderNameTaken($name, $parent, $id)) return null;
        $n = 1;
        while (docsFolderNameTaken("{$name} ({$n})", $parent, $id)) $n++;
        $newName = "{$name} ({$n})";
        db()->prepare("UPDATE folders SET folder_name = :name WHERE folder_id = :id")
            ->execute([':name' => $newName, ':id' => $id]);
        return $newName;
    }

    $s = db()->prepare("SELECT folder_id FROM document_library WHERE document_id = :id");
    $s->execute([':id' => $id]);
    $folder = $s->fetchColumn();
    $folder = $folder === null || $folder === false ? null : (int) $folder;

    if (!docsDocumentNameTaken($name, $folder, $id)) return null;
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $n = 1;
    do {
        $newName = $ext !== '' ? "{$base} ({$n}).{$ext}" : "{$base} ({$n})";
        $n++;
    } while (docsDocumentNameTaken($newName, $folder, $id));
    db()->prepare("UPDATE document_library SET title = :name, file_name = :name, updated_at = now() WHERE document_id = :id")
        ->execute([':name' => $newName, ':id' => $id]);
    return $newName;
}

// Restores a folder or document from the recycle bin.
function docsRestoreRecord()
{
    [$type, $id] = docsRecycleTarget();
    [$table, $pk] = docsRecycleMap()[$type];
    $name = docsRecordName($type, $id);

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    // If the parent folder is still in the bin, restoring here would orphan this record; fall back to root.
    if ($type === 'documentsDocument') {
        db()->prepare(
            "UPDATE document_library SET folder_id = NULL
             WHERE document_id = :id AND folder_id IS NOT NULL
               AND folder_id IN (SELECT folder_id FROM folders WHERE deleted_at IS NOT NULL)"
        )->execute([':id' => $id]);
    } elseif ($type === 'documentsFolder') {
        db()->prepare(
            "UPDATE folders SET parent_folder_id = NULL, folder_level = 1
             WHERE folder_id = :id AND parent_folder_id IS NOT NULL
               AND parent_folder_id IN (SELECT folder_id FROM folders WHERE deleted_at IS NOT NULL)"
        )->execute([':id' => $id]);
    }

    // The name may have been reused while this record sat in the bin. Instead of
    // leaving two same-named siblings, restore under "name (1)", "name (2)", ...
    $renamedTo = docsRenameIfTakenAfterRestore($type, $id, $name);

    $label = $type === 'documentsFolder' ? 'folder' : 'document';
    $logName = $renamedTo !== null ? "{$name}\" as \"{$renamedTo}" : $name;
    logActivity(currentUserId(), 'Restore Record', 'documents', "Restored {$label} \"{$logName}\" from recycle bin");
    json(['status' => 'ok', 'message' => 'Record restored.', 'data' => ['renamedTo' => $renamedTo]]);
}

// Permanently deletes a folder row.
function docsHardDeleteFolder($id)
{
    db()->prepare("DELETE FROM folders WHERE folder_id = :id")->execute([':id' => $id]);
}

// Permanently deletes a recycle-bin record.
function docsPurgeRecord()
{
    [$type, $id, $body] = docsRecycleTarget();
    [$table, $pk] = docsRecycleMap()[$type];
    $name = docsRecordName($type, $id);

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    if ($type === 'documentsDocument') {
        docsHardDeleteDocument($id);
    } else {
        if (empty($body['force'])) {
            $fileCount = docsFolderFileCount($id);
            if ($fileCount > 0) {
                error(
                    'This folder still has files sitting in the Recycle Bin that will also be permanently deleted.',
                    409,
                    ['code' => 'has_files', 'count' => $fileCount]
                );
            }
        } else {
            $docs = db()->prepare("SELECT document_id FROM document_library WHERE folder_id = :id");
            $docs->execute([':id' => $id]);
            foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $docId) {
                docsHardDeleteDocument((int) $docId);
            }
        }
        docsHardDeleteFolder($id);
    }
    $label = $type === 'documentsFolder' ? 'folder' : 'document';
    logActivity(currentUserId(), 'Purge Record', 'documents', "Permanently deleted {$label} \"{$name}\"");
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

// Purges recycle bin rows past RECYCLE_BIN_RETENTION_DAYS, skipping folders with leftover files.
function docsPurgeExpired()
{
    $uid = currentUserId();
    foreach (docsRecycleMap() as $type => [$table, $pk]) {
        $s = db()->prepare(
            "SELECT {$pk} AS id FROM {$table}
             WHERE deleted_at IS NOT NULL AND deleted_at < now() - make_interval(days => :days)"
        );
        $s->execute([':days' => RECYCLE_BIN_RETENTION_DAYS]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if ($type === 'documentsFolder' && docsFolderFileCount($id) > 0) continue;
            $name = docsRecordName($type, $id);
            if ($type === 'documentsDocument') {
                docsHardDeleteDocument($id);
            } else {
                docsHardDeleteFolder($id);
            }
            $label = $type === 'documentsFolder' ? 'folder' : 'document';
            logActivity($uid, 'Auto-Purge Record', 'documents', "Automatically purged {$label} \"{$name}\" after " . RECYCLE_BIN_RETENTION_DAYS . "-day recycle bin retention");
        }
    }
}

// Permanently deletes a document and its uploaded file.
function docsHardDeleteDocument($id)
{
    $s = db()->prepare("SELECT file_path FROM document_library WHERE document_id = :id");
    $s->execute([':id' => $id]);
    $path = $s->fetchColumn();
    if ($path) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    db()->prepare("DELETE FROM document_library WHERE document_id = :id")->execute([':id' => $id]);
}
