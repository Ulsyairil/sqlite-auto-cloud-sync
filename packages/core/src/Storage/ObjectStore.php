<?php

declare(strict_types=1);

namespace SqliteSync\Core\Storage;

/**
 * Minimal object storage contract. Implementations must be cheap to list by prefix.
 */
interface ObjectStore
{
    public function put(string $key, string $contents): void;

    public function get(string $key): ?string;

    /**
     * @return list<string> full keys under the prefix, recursively
     */
    public function list(string $prefix): array;

    public function delete(string $key): void;
}
