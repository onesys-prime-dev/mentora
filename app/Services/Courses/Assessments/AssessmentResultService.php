<?php

namespace App\Services\Courses\Assessments;

/**
 * Which of a student's attempts counts toward their grade — the single
 * rule every screen (and, later, the gradebook) reads:
 *
 *  - quiz ("highest"): the highest-scoring graded attempt counts; a tie
 *    goes to the more recent one. Every finished attempt that isn't graded
 *    yet is pending, since it could still become the best.
 *  - assignment ("latest"): the latest graded submission counts. A newer
 *    submission that isn't graded yet is pending; while it waits, the
 *    previous grade stands and the result is marked "resubmitted".
 *
 * A quiz that auto-graded only in part (Partially Graded: multiple choice
 * and short answers scored, essays / uploads waiting) is pending too; its
 * auto score is exposed as `partial` for "so far" displays.
 *
 * A grade the instructor reopened (status Grading, is_graded = 0) is simply
 * not graded — it's pending, and the next-best graded attempt counts until
 * it's graded again.
 */
class AssessmentResultService
{
    /** Attempts that were handed in (In Progress and Cancelled are not). */
    public const FINISHED_STATUSES = ['Submitted', 'Partially Graded', 'Grading', 'Graded', 'Returned'];

    /**
     * @param list<array> $attempts one student's attempts at one assessment
     *                              (any order; any status — others are ignored)
     * @return array{
     *     counted: ?array, score: ?float, pending: list<array>, resubmitted: bool,
     *     policy: string, finished: list<array>, latest: ?array, partial: ?array
     * }
     *
     * `partial` is the latest pending attempt whose auto-gradable questions
     * are already scored (Partially Graded) — its score shows "so far".
     */
    public function resultFor(bool $isQuiz, array $attempts): array
    {
        $finished = array_values(array_filter(
            $attempts,
            static fn (array $a) => in_array($a['status'] ?? '', self::FINISHED_STATUSES, true)
        ));

        usort($finished, static fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        $graded = array_values(array_filter($finished, static fn (array $a) => !empty($a['is_graded'])));

        $latest = $finished === [] ? null : $finished[count($finished) - 1];

        if ($isQuiz) {

            $counted = null;

            foreach ($graded as $attempt) {
                if ($counted === null || (float) $attempt['score'] >= (float) $counted['score']) {
                    $counted = $attempt;
                }
            }

            $pending = array_values(array_filter($finished, static fn (array $a) => empty($a['is_graded'])));

            $resubmitted = false;

        } else {

            $counted = $graded === [] ? null : $graded[count($graded) - 1];

            $pending = $latest !== null && empty($latest['is_graded']) ? [$latest] : [];

            $resubmitted = $counted !== null && $pending !== [];

        }

        $partial = null;

        foreach ($pending as $attempt) {
            if ($attempt['status'] === 'Partially Graded') {
                $partial = $attempt;
            }
        }

        return [
            'counted' => $counted,
            'partial' => $partial,
            'score' => $counted !== null ? (float) $counted['score'] : null,
            'pending' => $pending,
            'resubmitted' => $resubmitted,
            'policy' => $isQuiz ? 'highest' : 'latest',
            'finished' => $finished,
            'latest' => $latest,
        ];
    }
}
