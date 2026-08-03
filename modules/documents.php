<?php
// Documents Tab: folder-organized, PESO-staff document library. Unrelated to
// any specific applicant/batch/project — that's attached_documents' job
// (see migration 044's split). folders/document_library back this module.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/activity_log.php';

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

function docsUploadBaseUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'http://' . $host . '/epeso_backend/';
}

function docsFormatBytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Resolves the frontend's 'root' | '<folder_id>' sentinel into a real
// [parent_folder_id or null, parent's folder_level or 0], validating that a
// non-root parent actually exists and isn't itself in the recycle bin.
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

// ─── Folders ─────────────────────────────────────────────────────────────────

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

function docsRenameFolder($id)
{
    $id = (int) $id;
    if (!$id) error('Invalid folder ID.', 422);
    $d    = body();
    $name = trim((string) ($d['name'] ?? ''));
    if ($name === '') error('Folder name is required.', 422);

    $chk = db()->prepare("SELECT folder_name FROM folders WHERE folder_id = :id AND deleted_at IS NULL");
    $chk->execute([':id' => $id]);
    $oldName = $chk->fetchColumn();
    if ($oldName === false) error('Folder not found.', 404);

    db()->prepare("UPDATE folders SET folder_name = :name, updated_at = now() WHERE folder_id = :id")
        ->execute([':name' => $name, ':id' => $id]);

    logActivity(currentUserId(), 'Rename Folder', 'documents', "Renamed folder \"{$oldName}\" to \"{$name}\"");
    json(['status' => 'ok', 'message' => 'Folder renamed.']);
}

// Blocks deleting a folder that still has active (non-deleted) files or
// subfolders in it, rather than warning-and-cascading — a folder can hold
// several levels of real documents, and a "delete anyway" option risks
// wiping out files the user forgot were even in there.
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

// Body: { folderId, fileName, dataUrl }. dataUrl is a base64 data URL, same
// convention as employmentSavePhoto/employmentSyncDocuments.
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

function docsRenameDocument($id)
{
    $id = (int) $id;
    if (!$id) error('Invalid document ID.', 422);
    $d    = body();
    $name = trim((string) ($d['name'] ?? ''));
    if ($name === '') error('File name is required.', 422);

    $chk = db()->prepare("SELECT file_name FROM document_library WHERE document_id = :id AND deleted_at IS NULL");
    $chk->execute([':id' => $id]);
    $oldName = $chk->fetchColumn();
    if ($oldName === false) error('Document not found.', 404);

    // The extension is not user-editable: whatever the client submits is treated as the
    // base name only, and the original file's extension is always reappended. This stops
    // a client-side caret-position quirk (backspacing into the extension) from silently
    // corrupting the stored file extension.
    $oldExt = pathinfo($oldName, PATHINFO_EXTENSION);
    $newBase = pathinfo($name, PATHINFO_FILENAME);
    if ($newBase === '') error('File name is required.', 422);
    $name = $oldExt !== '' ? "{$newBase}.{$oldExt}" : $newBase;

    db()->prepare("UPDATE document_library SET title = :name, file_name = :name, updated_at = now() WHERE document_id = :id")
        ->execute([':name' => $name, ':id' => $id]);

    logActivity(currentUserId(), 'Rename Document', 'documents', "Renamed document \"{$oldName}\" to \"{$name}\"");
    json(['status' => 'ok', 'message' => 'Document renamed.']);
}

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

function docsRecycleMap()
{
    return [
        'documentsFolder'   => ['folders', 'folder_id'],
        'documentsDocument' => ['document_library', 'document_id'],
    ];
}

// Read + validate { recordType, id } from the request body. Returns [type, id].
function docsRecycleTarget()
{
    $d    = body();
    $type = $d['recordType'] ?? '';
    $id   = isset($d['id']) && is_numeric($d['id']) ? (int) $d['id'] : null;
    if (!isset(docsRecycleMap()[$type])) error('Invalid record type.', 422);
    if (!$id) error('Invalid record id.', 422);
    return [$type, $id];
}

function docsListDeleted()
{
    $items = [];

    // Origin context (which folder it'll reappear in on restore) is more
    // useful here than restating the module badge — pf is the folder's own
    // parent, so a nested folder shows "Subfolder of <parent>".
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

function docsRecordName($type, $id)
{
    $nameCol = $type === 'documentsFolder' ? 'folder_name' : 'file_name';
    [$table, $pk] = docsRecycleMap()[$type];
    $s = db()->prepare("SELECT {$nameCol} FROM {$table} WHERE {$pk} = :id");
    $s->execute([':id' => $id]);
    $name = $s->fetchColumn();
    return $name !== false ? $name : "#{$id}";
}

function docsRestoreRecord()
{
    [$type, $id] = docsRecycleTarget();
    [$table, $pk] = docsRecycleMap()[$type];
    $name = docsRecordName($type, $id);

    $stmt = db()->prepare("UPDATE {$table} SET deleted_at = NULL, deleted_by = NULL WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) error('Record not found in recycle bin.', 404);

    // If this record's parent folder is itself still in the bin (e.g. the
    // last file in a folder was deleted, then the now-empty folder was
    // deleted too), restoring here would leave it orphaned — visible in
    // listDocuments/listFolders but unreachable in the tree. Fall back to
    // root instead of leaving it invisibly stranded.
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

    $label = $type === 'documentsFolder' ? 'folder' : 'document';
    logActivity(currentUserId(), 'Restore Record', 'documents', "Restored {$label} \"{$name}\" from recycle bin");
    json(['status' => 'ok', 'message' => 'Record restored.']);
}

// Only acts on records already in the recycle bin (deleted_at IS NOT NULL).
function docsPurgeRecord()
{
    [$type, $id] = docsRecycleTarget();
    [$table, $pk] = docsRecycleMap()[$type];
    $name = docsRecordName($type, $id);

    $chk = db()->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id AND deleted_at IS NOT NULL");
    $chk->execute([':id' => $id]);
    if (!$chk->fetchColumn()) error('Record not found in recycle bin.', 404);

    if ($type === 'documentsDocument') {
        docsHardDeleteDocument($id);
    } else {
        // Folders were already blocked from being soft-deleted while still
        // containing files/subfolders (see docsDeleteFolder's in-use guard),
        // so a plain row delete here is safe — no cascade cleanup needed.
        db()->prepare("DELETE FROM folders WHERE folder_id = :id")->execute([':id' => $id]);
    }
    $label = $type === 'documentsFolder' ? 'folder' : 'document';
    logActivity(currentUserId(), 'Purge Record', 'documents', "Permanently deleted {$label} \"{$name}\"");
    json(['status' => 'ok', 'message' => 'Record permanently deleted.']);
}

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
