<?php

declare(strict_types=1);

namespace SqliteSync\Yii\Commands;

use SqliteSync\Core\SyncEngine;
use SqliteSync\Yii\SqliteSync;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Yii 2 console command.
 *
 * Register in the console config `controllerMap`:
 *
 * ```php
 * 'controllerMap' => [
 *     'sqlite-sync' => \SqliteSync\Yii\Commands\SqliteSyncController::class,
 * ],
 * ```
 *
 * Usage: `php yii sqlite-sync`, `php yii sqlite-sync/sync`,
 * `php yii sqlite-sync/compact --keep=1`, `php yii sqlite-sync/restore --force`.
 */
final class SqliteSyncController extends Controller
{
    /** Batches kept per device when compacting. */
    public $keep = 1;

    /** Force overwrite on restore. */
    public $force = false;

    public function options($actionID): array
    {
        return ['keep', 'force'];
    }

    public function actionIndex(): int
    {
        try {
            $this->stdout($this->encode(SqliteSync::engine()->status()));

            return ExitCode::OK;
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    public function actionPush(): int
    {
        return $this->dispatch(static fn (SyncEngine $engine) => $engine->push());
    }

    public function actionPull(): int
    {
        return $this->dispatch(static fn (SyncEngine $engine) => $engine->pull());
    }

    public function actionSync(): int
    {
        return $this->dispatch(static fn (SyncEngine $engine) => $engine->sync());
    }

    public function actionCompact(): int
    {
        return $this->dispatch(fn (SyncEngine $engine) => $engine->compact((int) $this->keep));
    }

    public function actionSnapshot(): int
    {
        return $this->dispatch(static fn (SyncEngine $engine) => $engine->snapshot());
    }

    public function actionRestore(): int
    {
        return $this->dispatch(fn (SyncEngine $engine) => $engine->restore((bool) $this->force));
    }

    public function actionVerify(): int
    {
        return $this->dispatch(static fn (SyncEngine $engine) => $engine->verify());
    }

    public function actionRepair(): int
    {
        return $this->dispatch(static fn (SyncEngine $engine) => $engine->repair());
    }

    private function dispatch(callable $operation): int
    {
        try {
            $result = $operation(SqliteSync::engine());

            $this->stdout($this->encode($result->toArray()));

            return ExitCode::OK;
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    private function fail(\Throwable $e): int
    {
        $this->stderr($e->getMessage() . PHP_EOL);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
