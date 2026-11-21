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

    /**
     * SQL for "graded work" lists read straight from assessment_submissions (feeds): with the `gms` LEFT JOIN of
     * memberJoinSql(), a row's score for this student is their own final on a group row, else the row's score.
     */
    public const MEMBER_SCORE_SQL = 'CASE WHEN gms.id IS NULL THEN assessment_submissions.score ELSE gms.final_score END AS score';

    public static function memberJoinSql(int $studentId): string
    {
        return 'gms.submission_id = assessment_submissions.id AND gms.student_user_id = ' . $studentId;
    }

    /** The rows that are this student's graded work, by the same rule as memberAttempts() (excused rows show no grade). */
    public static function memberRowSql(int $studentId): string
    {
        return "((gms.id IS NULL AND assessment_submissions.student_id = {$studentId} AND assessment_submissions.group_id IS NULL)"
            . ' OR (gms.id IS NOT NULL AND gms.pending = 0 AND gms.excused = 0 AND gms.final_score IS NOT NULL))';
    }

    /** @var array<int, ?array> assessment id => its group config (null: not a group activity) */
    private array $configs = [];

    /**
     * The attempts that stand for one student on a group activity (D7, D8, D10): their own rows plus their group's
     * shared rows, each carrying the student's OWN final from group_member_scores as score/percentage and that row as
     * `member_score`. A shared row with no member row, a pending one or a reopened one is not graded for them; an
     * excused one counts, flagged `excused`. Another member's score row is never read. Non-group work: $attempts as is.
     *
     * @param list<array> $attempts the student's own rows (student_id = $studentId)
     *
     * @return list<array>
     */
    public function memberAttempts(int $assessmentId, int $studentId, array $attempts): array
    {
        if (!array_key_exists($assessmentId, $this->configs)) {
            $this->configs[$assessmentId] = (new \App\Services\Groups\ActivityGroupService())->findByAssessment($assessmentId);
        }

        if ($this->configs[$assessmentId] === null) {
            return $attempts;
        }

        $db     = db_connect();
        $scores = array_column($db->table('group_member_scores gms')->select('gms.*')
            ->join('assessment_submissions s', 's.id = gms.submission_id')
            ->where('s.assessment_id', $assessmentId)->where('gms.student_user_id', $studentId)
            ->get()->getResultArray(), null, 'submission_id');

        $group = (new \App\Services\Groups\GroupSubmissionResolver())->sharedGroup($assessmentId, $studentId);
        $ids   = array_merge(array_keys($scores), $group === null ? [] : array_column(
            $db->table('assessment_submissions')->select('id')->where('assessment_id', $assessmentId)->where('group_id', $group['id'])->get()->getResultArray(),
            'id'
        ));
        $known = array_map('intval', array_column($attempts, 'id'));
        $extra = array_values(array_diff(array_map('intval', $ids), $known));

        if ($extra !== []) {
            $attempts = array_merge($attempts, $db->table('assessment_submissions')->whereIn('id', $extra)->whereIn('status', self::FINISHED_STATUSES)->get()->getResultArray());
        }

        foreach ($attempts as &$attempt) {
            $row = $scores[$attempt['id']] ?? null;

            if ($row === null) {
                if (!empty($attempt['group_id'])) {
                    $attempt['is_graded'] = 0;
                    $attempt['score']     = null;
                    $attempt['percentage'] = null;
                }

                continue;
            }

            $excused = (int) $row['excused'] === 1;

            $attempt['is_graded']    = $excused || (!empty($attempt['is_graded']) && (int) $row['pending'] === 0 && $row['final_score'] !== null) ? 1 : 0;
            $attempt['score']        = $row['final_score'];
            $attempt['percentage']   = $row['final_percentage'];
            $attempt['excused']      = $excused;
            $attempt['member_score'] = array_intersect_key($row, array_flip(['group_points', 'individual_points', 'adjustment', 'adjustment_reason', 'private_note', 'final_score', 'final_percentage', 'excused', 'pending']))
                + ['individual_rubric_scores' => $row['individual_rubric_scores'] === null ? [] : (json_decode((string) $row['individual_rubric_scores'], true) ?? [])];
        }
        unset($attempt);

        return $attempts;
    }
}
