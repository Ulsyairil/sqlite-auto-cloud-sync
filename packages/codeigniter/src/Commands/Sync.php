<?php

declare(strict_types=1);

namespace SqliteSync\CodeIgniter\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use SqliteSync\CodeIgniter\SqliteSync;

final class Sync extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'sqlite-sync';
    protected $description = 'Run SQLite cloud sync manually.';
    protected $usage = 'sqlite-sync [status|push|pull|sync|compact|snapshot|restore|verify|repair] [keep] [force]';
    protected $arguments = [
        'action' => 'status, push, pull, sync, compact, snapshot, restore, verify, repair',
        'keep' => 'Batches kept per device when compacting (default 1)',
        'force' => 'Force overwrite on restore (true/false)',
    ];

    public function run(array $params): void
    {
        $action = $params[0] ?? 'status';

        try {
            $engine = SqliteSync::engine();

            if ($action === 'status') {
                foreach ($engine->status() as $key => $value) {
                    CLI::write($key . ': ' . (is_scalar($value) ? $value : json_encode($value)));
                }

                return;
            }

            $result = match ($action) {
                'push' => $engine->push(),
                'pull' => $engine->pull(),
                'sync' => $engine->sync(),
                'compact' => $engine->compact((int) ($params[1] ?? 1)),
                'snapshot' => $engine->snapshot(),
                'restore' => $engine->restore(
                    isset($params[2]) ? filter_var($params[2], FILTER_VALIDATE_BOOLEAN) : false
                ),
                'verify' => $engine->verify(),
                'repair' => $engine->repair(),
                default => null,
            };

            if ($result === null) {
                CLI::error('Unknown action. Use status|push|pull|sync|compact|snapshot|restore|verify|repair.');

                return;
            }

            CLI::write(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
