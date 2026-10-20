<?php

namespace App\Services\Courses\Grades;

use App\Services\Courses\Assessments\AssessmentResultService;
use CodeIgniter\Shield\Entities\User;

/**
 * Finalizing a section's grades.
 *
 * Until then a student's grade is a running one: over the work graded so far.
 * Finalizing computes each student's final grade with work that was never
 * graded or handed in counted as zero, freezes it on their gradebook record
 * (an Incomplete stays Incomplete; everyone else becomes Passed or Failed),
 * and locks the gradebook: scores, grading setup and imports are refused until
 * the instructor reopens the grades with a reason. Only finalized grades are
 * handed to the student information system.
 */
class GradeFinalizationService
{
    public const LOCKED_MESSAGE = 'The grades are finalized. Reopen them to make changes.';

    /** @return array<string, mixed>|null the section's current finalization, who and when */
    public function current(int $offeringId): ?array
    {
        $row = db_connect()->table('grade_finalizations f')
            ->select("f.*, CONCAT_WS(' ', up.first_name, up.last_name) AS finalized_by_name", false)
            ->join('user_profiles up', 'up.user_id = f.finalized_by', 'left')
            ->where('f.course_offering_id', $offeringId)
            ->where('f.open_marker', 1)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function isFinalized(int $offeringId): bool
    {
        return db_connect()->table('grade_finalizations')
            ->where('course_offering_id', $offeringId)
            ->where('open_marker', 1)
            ->countAllResults() > 0;
    }

    /** A refusal to hand back from any change that would move a final grade, or null while the grades are open. */
    public function lockRefusal(int $offeringId): ?array
    {
        return $this->isFinalized($offeringId)
            ? ['success' => false, 'message' => self::LOCKED_MESSAGE]
            : null;
    }

    /**
     * What stands in the way of finalizing, and what the instructor should
     * know first.
     *
     * @return array{
     *     finalized: bool,
     *     blockers: list<string>,
     *     notes: list<string>,
     *     can_finalize: bool,
     *     counts: array{students: int, missing_scores: int, ungraded_work: int, open_requests: int, incomplete: int}
     * }
     */
    public function readiness(int $offeringId): array
    {
        $schemes = new GradingSchemeService();

        $scheme = $schemes->forOffering($offeringId);

        $context = (new GradingEngineService())->context($offeringId);

        $blockers = [];
        $notes = [];

        if ($this->isFinalized($offeringId)) {
            return [
                'finalized' => true,
                'blockers' => [],
                'notes' => [],
                'can_finalize' => false,
                'counts' => ['students' => count($context['studentIds'] ?? []), 'missing_scores' => 0, 'ungraded_work' => 0, 'open_requests' => 0, 'incomplete' => 0],
            ];
        }

        if ($scheme['status'] !== 'Published') {
            $blockers[] = 'The grading is still a draft. Publish it first, so students can see how they are graded.';
        }

        $problem = $schemes->publishProblem((int) $scheme['id']);

        if ($problem !== null) {
            $blockers[] = $problem;
        }

        $ungraded = $this->ungradedWork($offeringId);

        if ($ungraded > 0) {
            $blockers[] = $ungraded . ($ungraded === 1 ? ' piece of handed-in work is' : ' pieces of handed-in work are') . ' still waiting to be graded.';
        }

        $requests = db_connect()->table('grade_override_requests')
            ->where('offering_id', $offeringId)
            ->where('open_marker', 1)
            ->countAllResults();

        if ($requests > 0) {
            $blockers[] = $requests . ($requests === 1 ? ' grade-override request is' : ' grade-override requests are') . ' still open. Decide or withdraw ' . ($requests === 1 ? 'it' : 'them') . ' first.';
        }

        $students = $context['studentIds'] ?? [];

        $missing = 0;

        foreach ($students as $studentId) {
            foreach (($context['items'] ?? []) as $item) {

                $row = $context['scores'][(int) $item['id']][$studentId] ?? null;

                if (!$row || $row['raw_score'] === null || !empty($row['is_missing'])) {
                    $missing++;
                }

            }
        }

        if ($missing > 0) {
            $notes[] = $missing . ($missing === 1 ? ' score has' : ' scores have') . ' not been entered or handed in. They will count as 0 in the final grades.';
        }

        $incomplete = db_connect()->table('gradebook_records')
            ->where('course_offering_id', $offeringId)
            ->where('remarks', 'INC')
            ->countAllResults();

        if ($incomplete > 0) {
            $notes[] = $incomplete . ($incomplete === 1 ? ' student is' : ' students are') . ' marked Incomplete (INC) and keep that mark.';
        }

        return [
            'finalized' => false,
            'blockers' => $blockers,
            'notes' => $notes,
            'can_finalize' => $blockers === [],
            'counts' => [
                'students' => count($students),
                'missing_scores' => $missing,
                'ungraded_work' => $ungraded,
                'open_requests' => $requests,
                'incomplete' => $incomplete,
            ],
        ];
    }

    public function finalize(User $user, int $offeringId): array
    {
        $readiness = $this->readiness($offeringId);

        if ($readiness['finalized']) {
            return ['success' => false, 'message' => 'The grades are already finalized.'];
        }

        if (!$readiness['can_finalize']) {
            return ['success' => false, 'message' => $readiness['blockers'][0]];
        }

        $engine = new GradingEngineService();

        $context = $engine->context($offeringId);

        $now = date('Y-m-d H:i:s');

        $db = db_connect();

        $db->transStart();

        $frozen = 0;

        foreach ($context['studentIds'] as $studentId) {
            $frozen += $engine->freezeStudent($context, (int) $studentId, (int) $user->id, $now) ? 1 : 0;
        }

        $db->table('grade_finalizations')->insert([
            'course_offering_id' => $offeringId,
            'finalized_by' => $user->id,
            'finalized_at' => $now,
            'students' => $frozen,
            'open_marker' => 1,
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'The grades could not be finalized. Nothing was changed.'];
        }

        GradeAudit::record('grades.finalized', $offeringId, ['new' => ['students' => $frozen]], $user);

        return [
            'success' => true,
            'message' => "Grades finalized for {$frozen} " . ($frozen === 1 ? 'student' : 'students') . '.',
        ];
    }

    public function reopen(User $user, int $offeringId, string $reason): array
    {
        $reason = trim($reason);

        if (!$this->isFinalized($offeringId)) {
            return ['success' => false, 'message' => 'The grades are not finalized.'];
        }

        if ($reason === '') {
            return ['success' => false, 'message' => 'Give a reason for reopening the grades.'];
        }

        $now = date('Y-m-d H:i:s');

        $db = db_connect();

        $db->transStart();

        $db->table('grade_finalizations')
            ->where('course_offering_id', $offeringId)
            ->where('open_marker', 1)
            ->update([
                'reopened_by' => $user->id,
                'reopened_at' => $now,
                'reopen_reason' => $reason,
                'open_marker' => null,
            ]);

        (new GradingEngineService())->unfreezeOffering($offeringId);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'The grades could not be reopened. Nothing was changed.'];
        }

        GradeAudit::record('grades.reopened', $offeringId, ['new' => ['reason' => $reason]], $user);

        return ['success' => true, 'message' => 'The grades are open again.'];
    }

    /** Handed-in work of the section's counted assessments that nobody has finished grading. */
    private function ungradedWork(int $offeringId): int
    {
        $waiting = array_values(array_diff(AssessmentResultService::FINISHED_STATUSES, ['Graded', 'Returned']));

        return db_connect()->table('assessment_submissions s')
            ->join('assessments a', 'a.id = s.assessment_id')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->join('enrollments e', 'e.offering_id = cm.offering_id AND e.student_user_id = s.student_id')
            ->where('cm.offering_id', $offeringId)
            ->whereIn('a.status', GradeItemSyncService::COUNTED_STATUSES)
            ->whereIn('e.status', ['enrolled', 'completed'])
            ->whereIn('s.status', $waiting)
            ->countAllResults();
    }
}
