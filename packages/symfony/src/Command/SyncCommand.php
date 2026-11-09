<?php

declare(strict_types=1);

namespace SqliteSync\Symfony\Command;

use SqliteSync\Core\SyncEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sqlite-sync', description: 'Run SQLite cloud sync manually.')]
final class SyncCommand extends Command
{
    public function __construct(private readonly SyncEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'action',
            InputArgument::OPTIONAL,
            'status, push, pull, sync, compact, snapshot, restore, verify, repair',
            'status',
        );
        $this->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Batches kept per device when compacting', '1');
        $this->addOption('force', null, InputOption::VALUE_REQUIRED, 'Force overwrite on restore', 'false');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');

        try {
            if ($action === 'status') {
                $output->writeln(json_encode(
                    $this->engine->status(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));

                return Command::SUCCESS;
            }

            $result = match ($action) {
                'push' => $this->engine->push(),
                'pull' => $this->engine->pull(),
                'sync' => $this->engine->sync(),
                'compact' => $this->engine->compact((int) $input->getOption('keep')),
                'snapshot' => $this->engine->snapshot(),
                'restore' => $this->engine->restore(filter_var($input->getOption('force'), FILTER_VALIDATE_BOOLEAN)),
                'verify' => $this->engine->verify(),
                'repair' => $this->engine->repair(),
                default => null,
            };

            if ($result === null) {
                $output->writeln('<error>Unknown action. Use status|push|pull|sync|compact|snapshot|restore|verify|repair.</error>');

                return Command::INVALID;
            }

            $output->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
