<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * What goes out to the SIS: final grades only, and only what is new or
 * changed since it was last sent. Pure: records in, rows and a batch key out.
 *
 * A student's final percentage is the instructor's override when there is
 * one, otherwise the calculated percentage. Nothing is sent for a student
 * with no final percentage yet (nothing graded).
 */
final class SisGradePayload
{
    /** Why a student's grade is not handed over. */
    public const NOT_FINAL = 'not_final';
    public const WITHDRAWN = 'withdrawn';
    public const INCOMPLETE = 'incomplete';
    public const NO_GRADE = 'no_grade';
    public const NOT_IN_SIS = 'not_in_sis';
    public const UNCHANGED = 'unchanged';

    /** @var array<string, string> the reason in words, for the person reading a preview */
    public const REASONS = [
        self::NOT_FINAL => 'The grades are not finalized yet',
        self::WITHDRAWN => 'The student withdrew, so there is no final grade',
        self::INCOMPLETE => 'Marked Incomplete (INC), not settled yet',
        self::NO_GRADE => 'Nothing has been graded yet',
        self::NOT_IN_SIS => 'The course or student is not known to the SIS',
        self::UNCHANGED => 'Already sent, nothing changed',
    ];

    /**
     * @param array{calculated_percentage: mixed, is_override: mixed, overridden_percentage: mixed, finalized_at?: mixed, final_percentage?: mixed} $record a gradebook record
     */
    public static function finalPercentage(array $record): ?float
    {
        $value = match (true) {
            (int) $record['is_override'] === 1 => $record['overridden_percentage'],
            !empty($record['finalized_at']) => $record['final_percentage'] ?? null,
            default => $record['calculated_percentage'],
        };

        return $value === null || $value === '' ? null : round((float) $value, 2);
    }

    /**
     * @param list<array{offering_id: int, student_id: int, course_external_id: string, student_external_id: string, percentage: ?float, remarks: string}> $candidates
     * @param array<string, array{percentage: float, remarks: string}>                                                                                        $sent       what was last sent, keyed "offering_id:student_id"
     *
     * @return array{
     *     rows: list<array{course_external_id: string, student_external_id: string, percentage: string, remarks: string}>,
     *     items: list<array{offering_id: int, student_id: int, percentage: float, remarks: string, change: string}>,
     *     created: int, updated: int, skipped: int,
     *     left_out: list<array{offering_id: int, student_id: int, reason: string}>
     * }
     */
    public static function plan(array $candidates, array $sent): array
    {
        $rows = [];
        $items = [];
        $leftOut = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($candidates as $c) {

            // Why a grade is not handed over: a candidate may arrive already marked
            // (not final, withdrawn, incomplete, not known to the SIS), else it has no grade yet.
            $reason = $c['skip'] ?? ($c['percentage'] === null ? self::NO_GRADE : null);

            if ($reason === null) {

                $before = $sent["{$c['offering_id']}:{$c['student_id']}"] ?? null;

                if ($before !== null && abs($before['percentage'] - $c['percentage']) < 0.005 && $before['remarks'] === $c['remarks']) {
                    $reason = self::UNCHANGED;
                }

            }

            if ($reason !== null) {
                $skipped++;
                $leftOut[] = ['offering_id' => $c['offering_id'], 'student_id' => $c['student_id'], 'reason' => $reason];

                continue;
            }

            $before = $sent["{$c['offering_id']}:{$c['student_id']}"] ?? null;

            $change = $before === null ? 'created' : 'updated';
            $change === 'created' ? $created++ : $updated++;

            $rows[] = [
                'course_external_id'  => $c['course_external_id'],
                'student_external_id' => $c['student_external_id'],
                'percentage'          => number_format($c['percentage'], 2, '.', ''),
                'remarks'             => $c['remarks'],
            ];
            $items[] = ['offering_id' => $c['offering_id'], 'student_id' => $c['student_id'], 'percentage' => $c['percentage'], 'remarks' => $c['remarks'], 'change' => $change];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['course_external_id'], $a['student_external_id']] <=> [$b['course_external_id'], $b['student_external_id']]);

        return ['rows' => $rows, 'items' => $items, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'left_out' => $leftOut];
    }

    /**
     * The idempotency key of a delivery: the same grades for the same
     * institution always give the same key, whatever order they were found
     * in, so sending them again replaces the delivery instead of adding one.
     *
     * @param list<array<string, string>> $rows
     */
    public static function batchKey(int $institutionId, array $rows): string
    {
        $sorted = $rows;
        usort($sorted, static fn (array $a, array $b): int => [$a['course_external_id'], $a['student_external_id']] <=> [$b['course_external_id'], $b['student_external_id']]);

        return "g{$institutionId}-" . substr(hash('sha256', (string) json_encode($sorted)), 0, 24);
    }
}
