<?php
// Input validation, enum-cast, pagination

// Shape a DB users row into the form the frontend expects (camelCase, no password).
// Note: the DB has no email column; the frontend derives it from username.
function publicUser($u)
{
    return [
        'id'        => (int) $u['user_id'],
        'firstName' => $u['first_name'],
        'lastName'  => $u['last_name'],
        'username'  => $u['username'],
        'email'     => $u['username'] . '@peso.gov.ph',
        'role'      => $u['role'],
        'status'    => $u['status'],
        'lastLogin' => isset($u['last_login']) ? $u['last_login'] : null,
    ];
}

// Allowed enum values (exact casing the DB requires).
function validRoles()
{
    return ['Administrator', 'Staff'];
}

function validStatuses()
{
    return ['Active', 'Inactive'];
}
