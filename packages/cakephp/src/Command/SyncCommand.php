<?php

declare(strict_types=1);

namespace SqliteSync\CakePHP\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use SqliteSync\CakePHP\SqliteSync;

/**
 * Register in `src/Application.php`:
 *
 * ```php
 * public function console(CommandCollection $commands): CommandCollection
 * {
 *     return $commands->add('sqlite-sync', \SqliteSync\CakePHP\Command\SyncCommand::class);
 * }
 * ```
 *
 * Usage: `bin/cake sqlite-sync`, `bin/cake sqlite-sync sync`,
 * `bin/cake sqlite-sync compact --keep=1`, `bin/cake sqlite-sync restore --force`.
 */
final class SyncCommand extends Command
{
    public static function defaultName(): string
    {
        return 'sqlite-sync';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->addArgument('action', [
            'help' => 'status, push, pull, sync, compact, snapshot, restore, verify, repair',
            'required' => false,
            'default' => 'status',
        ]);
        $parser->addOption('keep', [
            'help' => 'Batches kept per device when compacting',
            'default' => '1',
        ]);
        $parser->addOption('force', [
            'help' => 'Force overwrite on restore',
            'boolean' => true,
            'default' => false,
        ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $action = (string) ($args->getArgument('action') ?? 'status');

        try {
            $engine = SqliteSync::engine();

            if ($action === 'status') {
                $io->out($this->encode($engine->status()));

                return static::CODE_SUCCESS;
            }

            $result = match ($action) {
                'push' => $engine->push(),
                'pull' => $engine->pull(),
                'sync' => $engine->sync(),
                'compact' => $engine->compact((int) $args->getOption('keep')),
                'snapshot' => $engine->snapshot(),
                'restore' => $engine->restore((bool) $args->getOption('force')),
                'verify' => $engine->verify(),
                'repair' => $engine->repair(),
                default => null,
            };

            if ($result === null) {
                $io->err('Unknown action. Use status|push|pull|sync|compact|snapshot|restore|verify|repair.');

                return static::CODE_ERROR;
            }

            $io->out($this->encode($result->toArray()));

            return static::CODE_SUCCESS;
        } catch (\Throwable $e) {
            $io->err($e->getMessage());

            return static::CODE_ERROR;
        }
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
