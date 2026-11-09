<?php

declare(strict_types=1);

namespace SqliteSync\Core;

use PDO;
use SqliteSync\Core\Storage\ObjectStore;

/**
 * Deletes uploaded batches that every registered device has already applied.
 *
 * Safety model:
 *  - Each device writes `state/<device>.json` after a successful pull, holding
 *    the highest applied batch key per source device.
 *  - A batch is deleted only when every OTHER registered device covers it.
 *  - A source device never needs its own batches, so its own checkpoint is
 *    excluded from that check.
 *  - The newest $keepPerDevice batches per source are always kept.
 *  - If no other device checkpoint exists, nothing is deleted.
 *
 * Devices that never pulled before compaction lose that history. Run compaction
 * only after every device has synced at least once.
 */
final class Compactor
{
    private readonly string $ownSegment;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ObjectStore $store,
        private readonly SyncConfig $config,
    ) {
        $this->ownSegment = SyncConfig::safeSegment($config->deviceId);
    }

    public function writeCheckpoint(): void
    {
        $this->store->put($this->checkpointKey($this->ownSegment), json_encode([
            'device_id' => $this->config->deviceId,
            'segment' => $this->ownSegment,
            'updated_at' => $this->nowMs(),
            'sources' => $this->watermarks(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Highest batch key known to be reflected in this database, per source.
     * Merges applied batches, snapshot baselines and the previous checkpoint.
     *
     * @return array<string, string>
     */
    public function watermarks(): array
    {
        $sources = $this->readCheckpoint($this->ownSegment)['sources'] ?? [];

        $snapshotSources = json_decode($this->getState('snapshot_sources') ?? '{}', true);
        if (is_array($snapshotSources)) {
            foreach ($snapshotSources as $source => $key) {
                if (!is_string($source) || !is_string($key)) {
                    continue;
                }
                if (!isset($sources[$source]) || strcmp($key, $sources[$source]) > 0) {
                    $sources[$source] = $key;
                }
            }
        }

        $stmt = $this->pdo->query('SELECT batch_key FROM _sync_applied');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $source = $this->config->segmentOfBatchKey((string) $key);
            if ($source === null) {
                continue;
            }
            if (!isset($sources[$source]) || strcmp($key, $sources[$source]) > 0) {
                $sources[$source] = (string) $key;
            }
        }

        return $sources;
    }

    public function compact(int $keepPerDevice = 1): CompactionResult
    {
        $keepPerDevice = max(0, $keepPerDevice);
        $result = new CompactionResult();

        $checkpoints = $this->readAllCheckpoints();
        if ($checkpoints === []) {
            $result->keptBecause[] = 'no device checkpoints yet';

            return $result;
        }

        $snapshotSources = $this->latestSnapshotSources();

        $groups = [];
        foreach ($this->store->list($this->config->batchPrefix()) as $key) {
            if (!str_ends_with($key, '.json')) {
                continue;
            }
            $source = $this->config->segmentOfBatchKey($key);
            if ($source === null) {
                continue;
            }
            $groups[$source][] = $key;
        }

        $toDelete = [];
        foreach ($groups as $source => $keys) {
            sort($keys);

            $others = $checkpoints;
            unset($others[$source]);

            if ($others === []) {
                $result->keptBecause[] = sprintf('no other device registered for source "%s"', $source);

                continue;
            }

            $candidates = $keepPerDevice === 0
                ? $keys
                : array_slice($keys, 0, max(0, count($keys) - $keepPerDevice));

            foreach ($candidates as $key) {
                // New devices restore the latest snapshot, then need every batch
                // created after it.
                if (isset($snapshotSources[$source]) && strcmp($key, $snapshotSources[$source]) > 0) {
                    $result->keptBecause[] = sprintf('newer than latest snapshot: %s', $key);

                    continue;
                }

                $coveredByAll = true;
                foreach ($others as $checkpoint) {
                    $watermark = $checkpoint['sources'][$source] ?? null;
                    if (!is_string($watermark) || strcmp($watermark, $key) < 0) {
                        $coveredByAll = false;
                        break;
                    }
                }

                if ($coveredByAll) {
                    $toDelete[] = $key;
                } else {
                    $result->keptBecause[] = sprintf('device behind on "%s"', $key);
                }
            }
        }

        if ($toDelete === []) {
            return $result;
        }

        foreach ($toDelete as $key) {
            $this->store->delete($key);
            $result->deletedBatches++;
        }

        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM _sync_applied WHERE batch_key IN (%s)', $placeholders),
        );
        $stmt->execute($toDelete);
        $result->deletedAppliedRows = $stmt->rowCount();

        return $result;
    }

    /** @return array<string, array{sources: array<string, string>}> */
    private function readAllCheckpoints(): array
    {
        $checkpoints = [];
        foreach ($this->store->list($this->config->statePrefix()) as $key) {
            if (!str_ends_with($key, '.json')) {
                continue;
            }
            $segment = basename($key, '.json');
            $checkpoint = $this->readCheckpoint($segment);
            if ($checkpoint !== null) {
                $checkpoints[$segment] = $checkpoint;
            }
        }

        return $checkpoints;
    }

    /** @return array{sources: array<string, string>}|null */
    private function readCheckpoint(string $segment): ?array
    {
        $raw = $this->store->get($this->checkpointKey($segment));
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $sources = [];
        foreach ($decoded['sources'] ?? [] as $source => $watermark) {
            if (is_string($source) && is_string($watermark)) {
                $sources[$source] = $watermark;
            }
        }

        return ['sources' => $sources];
    }

    private function checkpointKey(string $segment): string
    {
        return $this->config->statePrefix() . '/' . $segment . '.json';
    }

    /**
     * Watermarks of the newest snapshot. Batches after these keys are still
     * needed to bring a restored device up to date.
     *
     * @return array<string, string>
     */
    private function latestSnapshotSources(): array
    {
        $bestAt = -1;
        $bestKey = '';
        $bestSources = [];

        foreach ($this->store->list($this->config->snapshotPrefix()) as $key) {
            if (!str_ends_with($key, '.json')) {
                continue;
            }
            $raw = $this->store->get($key);
            if ($raw === null) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $createdAt = (int) ($decoded['created_at'] ?? 0);
            if ($createdAt > $bestAt || ($createdAt === $bestAt && strcmp($key, $bestKey) > 0)) {
                $bestAt = $createdAt;
                $bestKey = $key;
                $bestSources = is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
            }
        }

        $sources = [];
        foreach ($bestSources as $source => $watermark) {
            if (is_string($source) && is_string($watermark)) {
                $sources[$source] = $watermark;
            }
        }

        return $sources;
    }

    private function getState(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM _sync_state WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function nowMs(): int
    {
        return (int) $this->pdo->query(
            "SELECT CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)",
        )->fetchColumn();
    }
}
