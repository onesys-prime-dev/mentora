<?php

declare(strict_types=1);

namespace App\Services\Sis;

/** What one inbound run did (or, for a dry run, would do). */
final class SisImportResult
{
    /**
     * @param array<string, array{created: int, updated: int, skipped: int, failed: int}> $datasets keyed by dataset
     * @param list<array{dataset: string, item: string, reason: string}>                  $issues   rows that were not imported
     */
    public function __construct(
        public readonly int $runId,
        public readonly bool $ok,
        public readonly bool $dryRun,
        public readonly array $datasets,
        public readonly array $issues,
        public readonly string $message,
    ) {
    }

    /** @return array{created: int, updated: int, skipped: int, failed: int} */
    public function totals(): array
    {
        $sum = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->datasets as $counts) {
            foreach (array_keys($sum) as $key) {
                $sum[$key] += $counts[$key];
            }
        }

        return $sum;
    }
}
