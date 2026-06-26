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
    'cors_origin' => 'http://localhost:5173',

    // --- App ---
    'upload_dir' => __DIR__ . '/uploads',
];
