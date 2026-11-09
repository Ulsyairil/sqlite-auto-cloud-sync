<?php

declare(strict_types=1);

namespace SqliteSync\Core;

final class SyncResult
{
    public int $pushedEntries = 0;
    public int $pushedBatches = 0;
    public int $pulledBatches = 0;
    public int $appliedEntries = 0;
    public int $conflicts = 0;
    public int $skippedEntries = 0;

    /** @var list<string> */
    public array $errors = [];

    public function merge(self $other): self
    {
        $this->pushedEntries += $other->pushedEntries;
        $this->pushedBatches += $other->pushedBatches;
        $this->pulledBatches += $other->pulledBatches;
        $this->appliedEntries += $other->appliedEntries;
        $this->conflicts += $other->conflicts;
        $this->skippedEntries += $other->skippedEntries;
        array_push($this->errors, ...$other->errors);

        return $this;
    }

    /** @return array<string, int|list<string>> */
    public function toArray(): array
    {
        return [
            'pushed_entries' => $this->pushedEntries,
            'pushed_batches' => $this->pushedBatches,
            'pulled_batches' => $this->pulledBatches,
            'applied_entries' => $this->appliedEntries,
            'conflicts' => $this->conflicts,
            'skipped_entries' => $this->skippedEntries,
            'errors' => $this->errors,
        ];
    }
}
