<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;

$dir = sys_get_temp_dir() . '/sqlite-sync-demo-' . bin2hex(random_bytes(3));
mkdir($dir, 0775, true);

$open = static function (string $file) use ($dir): PDO {
    $pdo = new PDO('sqlite:' . $dir . '/' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        body TEXT
    )');

    return $pdo;
};

$store = new LocalObjectStore($dir . '/store');
$laptop = $open('laptop.sqlite');
$phone = $open('phone.sqlite');

$laptopEngine = new SyncEngine($laptop, $store, new SyncConfig('laptop', ['notes' => ['pk' => 'id']]));
$phoneEngine = new SyncEngine($phone, $store, new SyncConfig('phone', ['notes' => ['pk' => 'id']]));
$laptopEngine->install();
$phoneEngine->install();

$laptop->exec("INSERT INTO notes (id, title, body) VALUES (1, 'hello', 'written on laptop')");

echo "laptop -> cloud: " . json_encode($laptopEngine->push()->toArray()) . PHP_EOL;
echo "cloud -> phone:  " . json_encode($phoneEngine->pull()->toArray()) . PHP_EOL;

$row = $phone->query('SELECT title, body FROM notes WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
echo "phone sees:      " . json_encode($row) . PHP_EOL;

$phone->exec("UPDATE notes SET body = 'edited on phone' WHERE id = 1");

echo "phone -> cloud:  " . json_encode($phoneEngine->push()->toArray()) . PHP_EOL;
echo "cloud -> laptop: " . json_encode($laptopEngine->pull()->toArray()) . PHP_EOL;

$row = $laptop->query('SELECT title, body FROM notes WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
echo "laptop sees:     " . json_encode($row) . PHP_EOL;

echo "laptop status:   " . json_encode($laptopEngine->status(), JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo "compact:         " . json_encode($laptopEngine->compact(0)->toArray()) . PHP_EOL;
echo "batches left:    " . count($store->list('sqlite-sync/batches')) . PHP_EOL;
