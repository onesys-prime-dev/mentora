<?php

declare(strict_types=1);

namespace App\Services\Sis;

/** What one inbound run did (or, for a dry run, would do). */
final class SisImportResult
{
    /**
     * @param array<string, array{created: int, updated: int, skipped: int, failed: int, ended?: int}> $datasets keyed by dataset;
     *        `ended` is only there for a dataset that can end things (staff_assignments): an assignment the SIS no longer lists;
     *        `removed` only for class_schedules: a section whose SIS class times were cleared because the SIS no longer lists it
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

    /**
     * How many assignments this run ends (or, for a preview, would end): what the SIS no longer lists. Counted on its
     * own, not as an update, so nobody reads "3 updated" when it is three people losing a job.
     */
    public function ended(): int
    {
        $sum = 0;

        foreach ($this->datasets as $counts) {
            $sum += $counts['ended'] ?? 0;
        }

        return $sum;
    }

    /** How many sections lose the class times the SIS had sent (or, for a preview, would): it no longer lists them. */
    public function removed(): int
    {
        $sum = 0;

        foreach ($this->datasets as $counts) {
            $sum += $counts['removed'] ?? 0;
        }

        return $sum;
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
