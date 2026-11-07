<?php
// Possible-duplicate check used by the Add/Edit forms before saving a person.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/duplicates.php';

// Routes a duplicates action to its handler function.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'check':
            return duplicatesCheck();
        default:
            error("Unknown action: {$action}", 404);
    }
}

// Returns the people already in the program with the same first name, last name and birthdate.
function duplicatesCheck()
{
    $d = body();
    $program = (string) ($d['program'] ?? '');
    $all = dupPrograms();
    if (!isset($all[$program])) error('Unknown program.', 422);
    requirePermission($all[$program]['permission'], 'Editor');

    $matches = dupFindExisting(
        dupServiceIds($program),
        $d['firstName'] ?? '', $d['lastName'] ?? '', $d['birthdate'] ?? '',
        isset($d['excludeId']) && is_numeric($d['excludeId']) ? (int) $d['excludeId'] : null
    );
    json(['status' => 'ok', 'data' => ['matches' => $matches]]);
}
