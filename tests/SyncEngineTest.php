<?php

declare(strict_types=1);

namespace SqliteSync\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SqliteSync\Core\Exception\SchemaMismatchException;
use SqliteSync\Core\Storage\LocalObjectStore;
use SqliteSync\Core\SyncConfig;
use SqliteSync\Core\SyncEngine;

final class SyncEngineTest extends TestCase
{
    private string $dir;
    private LocalObjectStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sqlite-sync-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $this->store = new LocalObjectStore($this->dir . '/store');
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testFirstPushBootstrapsExistingRowsAndOtherDeviceAppliesThem(): void
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");

        $ea = $this->engine($a, 'dev-a');
        $eb = $this->engine($b, 'dev-b');

        $push = $ea->sync();
        self::assertSame(1, $push->pushedBatches);
        self::assertSame(1, $push->pushedEntries);

        $pull = $eb->sync();
        self::assertSame(1, $pull->pulledBatches);
        self::assertSame(1, $pull->appliedEntries);
        self::assertSame('ada', $this->value($b, 'name', 1));

        // Applying remote rows must not feed the local change-log.
        self::assertSame(0, $eb->status()['pending_entries']);
        self::assertSame(0, $ea->pull()->appliedEntries);
    }

    public function testUpdatePropagates(): void
    {
        [$ea, $eb, $a, $b] = $this->syncedPair();

        $a->exec("UPDATE users SET age = 31 WHERE id = 1");
        $ea->push();
        $eb->pull();

        self::assertSame('31', $this->value($b, 'age', 1));
        self::assertSame(0, $eb->status()['pending_entries']);
    }

    public function testLastWriteWinsPerRowOnBothDevices(): void
    {
        [$ea, $eb, $a, $b] = $this->syncedPair();

        $a->exec("UPDATE users SET name = 'from-a' WHERE id = 1");
        $b->exec("UPDATE users SET name = 'from-b' WHERE id = 1");

        $this->forceTimestamp($a, 'users', '1', 2_000_000_000_000);
        $this->forceTimestamp($b, 'users', '1', 2_000_000_000_500);

        $ea->push();
        $eb->push();
        $ea->pull();
        $eb->pull();

        self::assertSame('from-b', $this->value($a, 'name', 1));
        self::assertSame('from-b', $this->value($b, 'name', 1));
        self::assertSame(0, $ea->status()['pending_entries']);
    }

    public function testRemoteDeleteWinsAndRemovesPendingLocalUpsert(): void
    {
        [$ea, $eb, $a, $b] = $this->syncedPair();

        $a->exec("UPDATE users SET name = 'stale-local' WHERE id = 1");
        $b->exec('DELETE FROM users WHERE id = 1');
        $this->forceTimestamp($a, 'users', '1', 2_000_000_000_000);
        $this->forceTimestamp($b, 'users', '1', 2_000_000_000_500);

        $eb->push();
        $ea->pull();

        self::assertSame(0, (int) $a->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, $ea->status()['pending_entries'], 'Superseded local upsert must be dropped.');
        self::assertSame(0, $ea->push()->pushedBatches);
    }

    public function testTombstoneStopsOlderRemoteUpsertFromResurrectingRow(): void
    {
        [$ea, $eb, $a, $b] = $this->syncedPair();

        $a->exec('DELETE FROM users WHERE id = 1');
        $this->forceTimestamp($a, 'users', '1', 2_000_000_000_500);

        // Re-create a stale upsert batch from device b with an older timestamp.
        $b->exec("UPDATE users SET name = 'old' WHERE id = 1");
        $this->forceTimestamp($b, 'users', '1', 2_000_000_000_000);
        $eb->push();

        $ea->push();
        $ea->pull();

        self::assertSame(0, (int) $a->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testSchemaMismatchFailsLoudly(): void
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec('ALTER TABLE users ADD COLUMN email TEXT');

        $ea = $this->engine($a, 'dev-a');
        $eb = $this->engine($b, 'dev-b');

        $a->exec("INSERT INTO users (id, name, age, email) VALUES (1, 'ada', 30, 'ada@example.com')");
        $ea->push();

        $this->expectException(SchemaMismatchException::class);
        $eb->pull();
    }

    public function testTieBreakIsDeterministic(): void
    {
        [$ea, $eb, $a, $b] = $this->syncedPair();

        $a->exec("UPDATE users SET name = 'from-a' WHERE id = 1");
        $b->exec("UPDATE users SET name = 'from-b' WHERE id = 1");
        $this->forceTimestamp($a, 'users', '1', 2_000_000_000_000);
        $this->forceTimestamp($b, 'users', '1', 2_000_000_000_000);

        $ea->push();
        $eb->push();
        $ea->pull();
        $eb->pull();

        // dev-b > dev-a lexicographically, so dev-b wins on both sides.
        self::assertSame('from-b', $this->value($a, 'name', 1));
        self::assertSame('from-b', $this->value($b, 'name', 1));
    }

    public function testInstallIsIdempotentAndDoesNotDuplicateTriggers(): void
    {
        $a = $this->db('a');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $engine = $this->engine($a, 'dev-a');

        $engine->install();
        $engine->install();

        $triggers = (int) $a->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE '_sync_%_users'",
        )->fetchColumn();
        self::assertSame(3, $triggers);
        self::assertSame(1, $engine->status()['pending_entries']);
    }

    public function testPullIsIdempotent(): void
    {
        [$ea, $eb] = $this->syncedPair();

        $ea->sync();
        $second = $eb->pull();

        self::assertSame(0, $second->pulledBatches);
        self::assertSame(0, $second->appliedEntries);
    }

    public function testDeviceIdCannotChangeForSameDatabase(): void
    {
        $a = $this->db('a');
        $this->engine($a, 'dev-a');

        $this->expectException(\SqliteSync\Core\Exception\SyncException::class);
        $this->engine($a, 'dev-b');
    }

    public function testSchemaChangeReLogsExistingRows(): void
    {
        $a = $this->db('a');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $engine = $this->engine($a, 'dev-a');
        $engine->push();
        self::assertSame(0, $engine->status()['pending_entries']);

        $a->exec('ALTER TABLE users ADD COLUMN email TEXT');
        $engine = $this->engine($a, 'dev-a');

        self::assertSame(1, $engine->status()['pending_entries']);
        $engine->push();

        $b = $this->db('b');
        $b->exec('ALTER TABLE users ADD COLUMN email TEXT');
        $eb = $this->engine($b, 'dev-b');
        $eb->pull();

        self::assertSame('ada', $this->value($b, 'name', 1));
    }

    public function testPushDropsUploadedLogRows(): void
    {
        $a = $this->db('a');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $engine = $this->engine($a, 'dev-a');

        $engine->push();

        self::assertSame(
            0,
            (int) $a->query('SELECT COUNT(*) FROM _sync_log')->fetchColumn(),
        );
    }

    public function testCompactionWaitsUntilOtherDeviceHasAppliedTheBatch(): void
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");

        $ea = $this->engine($a, 'dev-a');
        $eb = $this->engine($b, 'dev-b');

        $ea->push();
        $a->exec('UPDATE users SET age = 31 WHERE id = 1');
        $ea->push();

        self::assertSame(0, $ea->compact(0)->deletedBatches, 'No checkpoint from the other device yet.');
        self::assertCount(2, $this->store->list('sqlite-sync/batches'));

        $eb->pull();

        $result = $ea->compact(0);
        self::assertSame(2, $result->deletedBatches);
        self::assertSame([], $this->store->list('sqlite-sync/batches'));
    }

    public function testCompactionKeepsNewestBatchesPerSource(): void
    {
        [$ea, $eb, $a] = $this->syncedPair();

        $a->exec('UPDATE users SET age = 40 WHERE id = 1');
        $ea->push();
        $a->exec('UPDATE users SET age = 41 WHERE id = 1');
        $ea->push();
        $eb->pull();

        $result = $ea->compact(1);

        self::assertSame(2, $result->deletedBatches);
        self::assertCount(1, $this->store->list('sqlite-sync/batches'));
    }

    public function testCompactionPrunesAppliedRows(): void
    {
        [$ea, $eb, $a, $b] = $this->syncedPair();

        $a->exec('UPDATE users SET age = 40 WHERE id = 1');
        $ea->push();
        $eb->pull();

        $before = (int) $b->query('SELECT COUNT(*) FROM _sync_applied')->fetchColumn();
        $result = $eb->compact(0);

        self::assertGreaterThanOrEqual(1, $before);
        self::assertSame($before, $result->deletedAppliedRows);
        self::assertSame(0, (int) $b->query('SELECT COUNT(*) FROM _sync_applied')->fetchColumn());
    }

    public function testSnapshotCreateAndRestoreFreshDevice(): void
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $a->exec("INSERT INTO users (id, name, age) VALUES (2, 'bob', 25)");

        $ea = $this->engine($a, 'dev-a');
        $eb = $this->engine($b, 'dev-b');

        $snap = $ea->snapshot();
        self::assertSame(2, $snap->rows);
        self::assertSame(0, $snap->appliedEntries ?? 0); // SnapshotResult has no appliedEntries
        self::assertGreaterThan(0, $snap->bytes);

        // Fresh device restores snapshot
        $restore = $eb->restore();
        self::assertSame($snap->key, $restore->key);
        self::assertSame(2, $restore->rows);

        // Both rows present on b
        self::assertSame('ada', $this->value($b, 'name', 1));
        self::assertSame('bob', $this->value($b, 'name', 2));

        // Pull after restore should skip batches <= snapshot sources
        $ea->push(); // batch containing the two inserts
        $eb->pull();
        self::assertSame(0, $eb->status()['pending_entries']);
    }

    public function testRestoreRefusesNonEmptyWithoutForce(): void
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $ea = $this->engine($a, 'dev-a');
        $ea->snapshot();

        $eb = $this->engine($b, 'dev-b');
        $b->exec("INSERT INTO users (id, name, age) VALUES (99, 'existing', 10)");

        $this->expectException(\SqliteSync\Core\Exception\SyncException::class);
        $eb->restore();
    }

    public function testRestoreWithForceOverwrites(): void
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $ea = $this->engine($a, 'dev-a');
        $ea->snapshot();

        $eb = $this->engine($b, 'dev-b');
        $b->exec("INSERT INTO users (id, name, age) VALUES (99, 'existing', 10)");

        $eb->restore(true);
        self::assertSame(0, (int) $b->query('SELECT COUNT(*) FROM users WHERE id = 99')->fetchColumn());
        self::assertSame('ada', $this->value($b, 'name', 1));
    }

    public function testCompactionKeepsBatchesNewerThanSnapshot(): void
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $ea = $this->engine($a, 'dev-a');
        $eb = $this->engine($b, 'dev-b');
        $ea->push();
        $eb->pull();

        // Create snapshot (covers batch 1)
        $ea->snapshot();

        // New change after snapshot
        $a->exec('UPDATE users SET age = 31 WHERE id = 1');
        $ea->push();
        $eb->pull();

        // Compact: batch 1 (<= snapshot) should go, batch 2 (> snapshot) kept
        $result = $ea->compact(0);
        self::assertSame(1, $result->deletedBatches, 'Only the pre-snapshot batch deleted');
        self::assertCount(1, $this->store->list('sqlite-sync/batches'));

        // Fresh device restores snapshot + pulls remaining batch
        $c = $this->db('c');
        $ec = $this->engine($c, 'dev-c');
        $ec->restore();
        $ec->pull();
        self::assertSame('31', $this->value($c, 'age', 1));
    }

    public function testVerifyDetectsMissingRowState(): void
    {
        $a = $this->db('a');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $ea = $this->engine($a, 'dev-a');
        $ea->push(); // row_state exists

        // Manually delete row_state for id=1
        $a->exec('DELETE FROM _sync_row_state WHERE tbl = \'users\' AND pk = \'1\'');

        $verify = $ea->verify();
        self::assertFalse($verify->ok);
        self::assertStringContainsString('no sync version', $verify->issues[0] ?? '');
    }

    public function testRepairFixesMissingRowState(): void
    {
        $a = $this->db('a');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");
        $ea = $this->engine($a, 'dev-a');

        $a->exec('DELETE FROM _sync_row_state WHERE tbl = \'users\' AND pk = \'1\'');
        $a->exec('DELETE FROM _sync_log WHERE tbl = \'users\' AND pk = \'1\' AND batch_id IS NULL');

        $repaired = $ea->repair();
        self::assertSame(1, $repaired);

        // Row state restored
        $state = $ea->rowState('users', '1');
        self::assertNotNull($state);
        // Pending log entry created
        self::assertSame(1, $ea->status()['pending_entries']);
    }

    /** @return array{SyncEngine, SyncEngine, PDO, PDO} */
    private function syncedPair(): array
    {
        $a = $this->db('a');
        $b = $this->db('b');
        $a->exec("INSERT INTO users (id, name, age) VALUES (1, 'ada', 30)");

        $ea = $this->engine($a, 'dev-a');
        $eb = $this->engine($b, 'dev-b');
        $ea->sync();
        $eb->sync();

        return [$ea, $eb, $a, $b];
    }

    private function db(string $name): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dir . '/' . $name . '.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            age INTEGER
        )');

        return $pdo;
    }

    private function engine(PDO $pdo, string $deviceId): SyncEngine
    {
        $engine = new SyncEngine(
            $pdo,
            $this->store,
            new SyncConfig($deviceId, ['users' => ['pk' => 'id']]),
        );
        $engine->install();

        return $engine;
    }

    private function forceTimestamp(PDO $pdo, string $table, string $pk, int $changedAt): void
    {
        $pdo->prepare('UPDATE _sync_log SET changed_at = ? WHERE tbl = ? AND pk = ? AND batch_id IS NULL')
            ->execute([$changedAt, $table, $pk]);
        $pdo->prepare('UPDATE _sync_row_state SET changed_at = ? WHERE tbl = ? AND pk = ?')
            ->execute([$changedAt, $table, $pk]);
    }

    private function value(PDO $pdo, string $column, int $id): ?string
    {
        $stmt = $pdo->prepare(sprintf('SELECT %s FROM users WHERE id = ?', $column));
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
