<?php

declare(strict_types=1);

namespace SqliteSync\Laravel;

use Illuminate\Console\Command;
use SqliteSync\Core\SyncEngine;

final class SyncCommand extends Command
{
    protected $signature = 'sqlite-sync {action=status : status|push|pull|sync|compact|snapshot|restore|verify|repair} {--keep=1 : Batches kept per device when compacting} {--force : Force overwrite on restore}';

    protected $description = 'Run SQLite cloud sync manually.';

    public function handle(SyncEngine $engine): int
    {
        $action = (string) $this->argument('action');

        try {
            $result = match ($action) {
                'push' => $engine->push(),
                'pull' => $engine->pull(),
                'sync' => $engine->sync(),
                'compact' => $engine->compact((int) $this->option('keep')),
                'snapshot' => $engine->snapshot(),
                'restore' => $engine->restore($this->option('force')),
                'verify' => $engine->verify(),
                'repair' => $engine->repair(),
                'status' => null,
                default => null,
            };

            if ($action === 'status') {
                $this->table(
                    ['key', 'value'],
                    collect($engine->status())->map(
                        static fn ($value, $key) => [$key, is_scalar($value) ? $value : json_encode($value)],
                    )->values()->all(),
                );

                return self::SUCCESS;
            }

            if ($result === null) {
                $this->error(sprintf('Unknown action "%s". Use status|push|pull|sync|compact|snapshot|restore|verify|repair.', $action));

                return self::INVALID;
            }

            $this->info(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
