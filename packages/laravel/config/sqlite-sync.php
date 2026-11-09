<?php

declare(strict_types=1);

use SqliteSync\Core\Storage\LocalObjectStore;

return [
    /*
     * Must be unique per device. Store it in .env, never share between devices.
     * Generate: php -r 'echo bin2hex(random_bytes(8));'
     */
    'device_id' => env('SQLITE_SYNC_DEVICE_ID', gethostname() . '-' . substr(sha1(base_path()), 0, 8)),

    'connection' => env('SQLITE_SYNC_CONNECTION', 'sqlite'),

    /*
     * Tables to sync and their single-column primary keys.
     */
    'tables' => [
        // 'users' => ['pk' => 'id'],
        // 'posts' => ['pk' => 'id'],
    ],

    'prefix' => env('SQLITE_SYNC_PREFIX', 'sqlite-sync'),
    'chunk_size' => (int) env('SQLITE_SYNC_CHUNK_SIZE', 500),

    /*
     * Storage driver:
     *  - ['driver' => 'local', 'path' => storage_path('app/sqlite-sync')]
     *  - ['driver' => 's3', 'disk' => 's3', 'root' => 'sqlite-sync']
     *  - a container binding / class name implementing SqliteSync\Core\Storage\ObjectStore
     *  - an ObjectStore instance
     */
    'store' => [
        'driver' => env('SQLITE_SYNC_DRIVER', 'local'),
        'disk' => env('SQLITE_SYNC_DISK', 's3'),
        'root' => env('SQLITE_SYNC_ROOT', 'sqlite-sync'),
        'path' => storage_path('app/sqlite-sync'),
    ],

    'auto_install' => true,
    'auto_sync' => (bool) env('SQLITE_SYNC_AUTO', false),
];
