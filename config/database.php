<?php

declare(strict_types=1);

return [
    'driver'   => 'mysql',
    'host'     => env('DB_HOST', '127.0.0.1'),
    'port'     => (int) env('DB_PORT', 3306),
    'database' => env('DB_NAME', 'gitphp'),
    'username' => env('DB_USER', 'gitphp'),
    'password' => env('DB_PASS', ''),
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    // Persistent connections act as a tiny connection pool across requests
    'persistent' => (bool) env('DB_PERSISTENT', true),
];
