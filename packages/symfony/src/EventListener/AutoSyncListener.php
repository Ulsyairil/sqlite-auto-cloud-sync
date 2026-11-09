<?php

declare(strict_types=1);

namespace SqliteSync\Symfony\EventListener;

use SqliteSync\Core\SyncEngine;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AutoSyncListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SyncEngine $engine,
        private readonly bool $autoInstall,
        private readonly bool $autoSync,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onBoot', 1024],
            ConsoleEvents::COMMAND => ['onBoot', 1024],
            KernelEvents::TERMINATE => ['onTerminate', -1024],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -1024],
        ];
    }

    public function onBoot(): void
    {
        if ($this->autoInstall) {
            $this->engine->install();
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->run();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->run();
    }

    private function run(): void
    {
        if (!$this->autoSync) {
            return;
        }

        try {
            $this->engine->sync();
        } catch (\Throwable) {
            // Sync must never break the request. Wire a logger if you need visibility.
        }
    }
}
