<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * One dataset read from the SIS: the usable records, and the rows that were
 * left out with the reason (so a few bad rows do not hide the good ones, and
 * nothing is dropped silently).
 */
final class SisBatch
{
    /**
     * @param list<array<string, string>>      $records  keyed by column name
     * @param list<array{row: int, reason: string}> $rejected row 1 is the header
     */
    public function __construct(
        public readonly string $dataset,
        public readonly array $records,
        public readonly array $rejected = [],
    ) {
    }

    public function total(): int
    {
        return count($this->records) + count($this->rejected);
    }
}
