<?php

declare(strict_types=1);

namespace SqliteSync\Core;

final class VerifyResult
{
    /** @param list<string> $issues */
    public function __construct(
        public readonly bool $ok,
        public readonly array $issues,
    ) {
    }

    /** @return array{ok: bool, issues: list<string>} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'issues' => $this->issues];
    }
}
