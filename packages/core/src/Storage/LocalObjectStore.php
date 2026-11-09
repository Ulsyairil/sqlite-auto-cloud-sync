<?php

declare(strict_types=1);

namespace SqliteSync\Core\Storage;

use RuntimeException;

/**
 * Filesystem object store. Useful for tests, local dev and NFS/rsync mirrors.
 */
final class LocalObjectStore implements ObjectStore
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new RuntimeException(sprintf('Cannot create object store root "%s".', $this->root));
        }
    }

    public function put(string $key, string $contents): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $dir));
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $tmp));
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Cannot move "%s" to "%s".', $tmp, $path));
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read "%s".', $path));
        }

        return $contents;
    }

    public function list(string $prefix): array
    {
        $base = $this->path($prefix);
        if (!is_dir($base)) {
            return [];
        }

        $keys = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($this->root) + 1);
            $keys[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }
        sort($keys);

        return $keys;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $key): string
    {
        $key = ltrim($key, '/');
        foreach (explode('/', $key) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException(sprintf('Unsafe object key "%s".', $key));
            }
        }

        return $this->root . DIRECTORY_SEPARATOR . $key;
    }
}
