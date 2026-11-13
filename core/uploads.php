<?php
// Safe handling of uploaded files. Every place that saves an uploaded file under uploads/ must get its
// extension from here: the client's file name is never trusted, so a script (.php etc.) can't be stored
// and run on the server.

// Extensions accepted for attachments on a profile (same list as the frontend's ATTACHMENT_ACCEPT).
const UPLOAD_ATTACHMENT_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx'];
// The Documents library also takes presentations and plain text/CSV.
const UPLOAD_LIBRARY_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'];

// Does the file's real content look like what its extension claims? (Signatures are compared as hex.)
function uploadContentMatches($ext, $binary)
{
    $hex = bin2hex(substr($binary, 0, 12));
    switch ($ext) {
        case 'pdf':  return strpos($hex, '25504446') === 0;                                   // %PDF
        case 'png':  return strpos($hex, '89504e47') === 0;
        case 'jpg':
        case 'jpeg': return strpos($hex, 'ffd8ff') === 0;
        case 'gif':  return strpos($hex, '47494638') === 0;                                   // GIF8
        case 'webp': return strpos($hex, '52494646') === 0 && substr($hex, 16, 8) === '57454250'; // RIFF....WEBP
        case 'doc':
        case 'xls':
        case 'ppt':  return strpos($hex, 'd0cf11e0') === 0;                                   // old Office (OLE) format
        case 'docx':
        case 'xlsx':
        case 'pptx': return strpos($hex, '504b0304') === 0;                                   // new Office files are zip packages
        case 'txt':
        case 'csv':  return strpos(substr($binary, 0, 8192), chr(0)) === false;               // text has no NUL bytes
    }
    return false;
}

// Returns the safe, lower-case extension to store this upload under, or stops with a 422 if the type isn't
// allowed or the content doesn't match it. $library = true also allows the Documents-library-only types.
function uploadExtOrFail($origName, $binary, $library = false)
{
    $ext = strtolower(pathinfo((string) $origName, PATHINFO_EXTENSION));
    $allowed = $library ? UPLOAD_LIBRARY_EXTS : UPLOAD_ATTACHMENT_EXTS;
    if (!in_array($ext, $allowed, true)) {
        error('This file type is not allowed. Please upload ' . strtoupper(implode(', ', $allowed)) . ' files only.', 422);
    }
    if (!uploadContentMatches($ext, (string) $binary)) {
        error('The file "' . basename((string) $origName) . '" is not a valid ' . strtoupper($ext) . ' file.', 422);
    }
    return $ext;
}
