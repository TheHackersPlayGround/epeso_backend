<?php
// DB credentials + CORS origin (gitignored)

return [
    // --- Database (PostgreSQL) ---
    // The database name contains a hyphen; that is fine inside the DSN string.
    'db' => [
        'host' => 'localhost',
        'port' => '5432',
        'name' => 'e-peso_db',
        'user' => 'postgres',
        'pass' => '12345678', // <-- replace with your real Postgres password
    ],

    // --- CORS ---
    // The Vite dev server origin allowed to call this API.
    //'cors_origin' => 'http://192.168.1.70',
    'cors_origin' => 'http://localhost:5173',
    //'cors_origin' => 'http://epeso.internal',

    // --- App ---
    'upload_dir' => __DIR__ . '/uploads',

    // --- Backup ---
    // Full path to this machine's pg_dump.exe (used by Security > Backup).
    // Differs per PostgreSQL install/version — update to match this device.
    'pg_dump_path' => 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe',
    // --- Employer portal (public, through Cloudflare) ---
    // Limited database account used ONLY by employer requests (see migrations/employer_db_role.sql).
    'employer_db' => ['host' => 'localhost', 'port' => '5432', 'name' => 'e-peso_db', 'user' => 'employer_app', 'pass' => 'gapo+x/@#05Toyang2005.....'],
    // Requests for these host names are treated as public (belt and braces; Cloudflare requests are public anyway).
    'employer_public_hosts' => ['epeso-employers.online'],
];
