<?php

declare(strict_types=1);

namespace SqliteSync\S3;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use SqliteSync\Core\Exception\SyncException;
use SqliteSync\Core\Storage\ObjectStore;

/**
 * ObjectStore backed by any Flysystem adapter. Works with S3, R2, MinIO,
 * Supabase Storage, GCS and local disks.
 */
final class S3ObjectStore implements ObjectStore
{
    private readonly string $root;

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        string $root = 'sqlite-sync',
    ) {
        $this->root = trim($root, '/');
    }

    public function put(string $key, string $contents): void
    {
        try {
            $this->filesystem->write($this->path($key), $contents);
        } catch (FilesystemException $e) {
            throw new SyncException(sprintf('Cannot write object "%s".', $key), 0, $e);
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        try {
            if (!$this->filesystem->fileExists($path)) {
                return null;
            }

            return $this->filesystem->read($path);
        } catch (FilesystemException $e) {
            throw new SyncException(sprintf('Cannot read object "%s".', $key), 0, $e);
        }
    }

    public function list(string $prefix): array
    {
        $location = $this->path($prefix);
        $keys = [];
        try {
            foreach ($this->filesystem->listContents($location, true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $path = $item->path();
                $keys[] = $this->root === '' ? $path : substr($path, strlen($this->root) + 1);
            }
        } catch (FilesystemException $e) {
            throw new SyncException(sprintf('Cannot list objects under "%s".', $prefix), 0, $e);
        }
        sort($keys);

        return $keys;
    }

    public function delete(string $key): void
    {
        try {
            $this->filesystem->delete($this->path($key));
        } catch (FilesystemException $e) {
            throw new SyncException(sprintf('Cannot delete object "%s".', $key), 0, $e);
        }
    }

    private function path(string $key): string
    {
        $key = ltrim($key, '/');

        return $this->root === '' ? $key : $this->root . '/' . $key;
    }
}
