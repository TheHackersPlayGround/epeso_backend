<?php
// Template for config.php (per-device settings, gitignored — never commit
// the real config.php). Copy this file, rename the copy to config.php, and
// fill in the real values for this machine.

return [
    // --- Database (PostgreSQL) ---
    'db' => [
        'host' => 'localhost',
        'port' => '5432',
        'name' => 'e-peso_db',
        'user' => 'postgres',
        'pass' => 'YOUR_DB_PASSWORD_HERE',
    ],

    // --- CORS ---
    // The Vite dev server origin allowed to call this API.
    'cors_origin' => 'http://localhost:5173',

    // --- App ---
    'upload_dir' => __DIR__ . '/uploads',

    // --- Backup ---
    // Full path to this machine's pg_dump.exe (used by Security > Backup).
    // Find it under your PostgreSQL install's bin/ folder — the version
    // number in the path depends on which PostgreSQL version is installed
    // on this machine (e.g. 16, 17, 18).
    'pg_dump_path' => 'C:\\Program Files\\PostgreSQL\\YOUR_VERSION\\bin\\pg_dump.exe',
];
