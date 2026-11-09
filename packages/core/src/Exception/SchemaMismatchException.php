<?php

declare(strict_types=1);

namespace SqliteSync\Core\Exception;

use RuntimeException;

final class SchemaMismatchException extends RuntimeException
{
    public static function between(string $local, string $remote): self
    {
        return new self(sprintf(
            'Schema mismatch between devices: local hash %s, remote hash %s. '
            . 'Run the same migrations on every device before syncing.',
            $local,
            $remote,
        ));
    }
}
