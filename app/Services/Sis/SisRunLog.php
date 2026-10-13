<?php

declare(strict_types=1);

namespace App\Services\Sis;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The history of SIS runs for an institution: every connection test, and (in
 * the sync branches) every inbound and outbound run, with its counts.
 */
class SisRunLog
{
    public const INBOUND  = 'inbound';
    public const OUTBOUND = 'outbound';

    public const MANUAL    = 'manual';
    public const SCHEDULED = 'scheduled';
    public const TEST      = 'test';

    /** @return int the run's id */
    public function start(int $institutionId, string $direction, string $trigger, ?int $userId = null, bool $dryRun = false, ?DateTimeImmutable $now = null): int
    {
        if (! in_array($direction, [self::INBOUND, self::OUTBOUND], true) || ! in_array($trigger, [self::MANUAL, self::SCHEDULED, self::TEST], true)) {
            throw new InvalidArgumentException('Unknown run direction or trigger.');
        }

        $db = db_connect();
        $db->table('sis_runs')->insert([
            'institution_id'     => $institutionId,
            'direction'          => $direction,
            'trigger'            => $trigger,
            'status'             => 'running',
            'dry_run'            => $dryRun ? 1 : 0,
            'started_by_user_id' => $userId,
            'started_at'         => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /**
     * @param array{created?: int, updated?: int, skipped?: int, failed?: int} $counts
     * @param array<string, mixed>|null                                        $details per-dataset counts and the rows that were not imported
     */
    public function finish(int $runId, bool $succeeded, array $counts = [], ?string $message = null, ?DateTimeImmutable $now = null, ?array $details = null): void
    {
        db_connect()->table('sis_runs')->where('id', $runId)->update([
            'status'        => $succeeded ? 'succeeded' : 'failed',
            'created_count' => $counts['created'] ?? 0,
            'updated_count' => $counts['updated'] ?? 0,
            'skipped_count' => $counts['skipped'] ?? 0,
            'failed_count'  => $counts['failed'] ?? 0,
            'message'       => $message === null ? null : mb_substr($message, 0, 1000),
            'details'       => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'finished_at'   => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $institutionId, int $limit = 20): array
    {
        return db_connect()->table('sis_runs')->where('institution_id', $institutionId)->orderBy('id', 'desc')->limit($limit)->get()->getResultArray();
    }
}
