<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Models\Assessments\AssessmentRubricModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Groups\GroupMemberScoreModel;
use App\Models\Groups\StudentGroupModel;
use App\Services\Courses\Assessments\AssessmentFeedbackService;
use App\Services\Courses\Assessments\AssessmentResultService;
use App\Services\Courses\Grades\GradebookSyncService;
use App\Services\Institutions\StructureException;

/**
 * Grades a group once and fans the result out to one group_member_scores row per member (D7, D8, D10, D24).
 * A member's final = group part + own individual part + adjustment, clamped to 0..total. Rubric activities split by
 * criterion scope; single-score activities follow the config's grading_mode (or the average, for per-member work).
 * The fan-out is one transaction; the gradebook sync runs after commit and never undoes a grade.
 */
class GroupGradingService
{
    /**
     * @param array{group_scores?: array<int, float>, group_score?: ?float, feedback?: ?string,
     *     members?: array<int, array{individual?: array<int, float>, score?: ?float, adjustment?: float, reason?: ?string, note?: ?string}>} $input
     */
    public function gradeGroup(int $assessmentId, int $groupId, array $input, int $graderId): void
    {
        [$assessment, $config] = $this->context($assessmentId, $groupId);
        $total   = (float) $assessment['total_points'];
        $members = $this->members($groupId);
        $given   = $input['members'] ?? [];

        if (array_diff(array_map('intval', array_keys($given)), $members) !== []) {
            throw new StructureException('Only members of this group can be graded here.');
        }

        $scopes = ['group' => [], 'individual' => []];

        foreach ($this->criteria($assessmentId) as $criterion) {
            $scopes[$criterion['scope'] === 'group' ? 'group' : 'individual'][(int) $criterion['id']] = (float) $criterion['max_points'];
        }

        $rubric    = $scopes['group'] !== [] || $scopes['individual'] !== [];
        $perMember = $config['submission_style'] === 'per_member';
        $average   = !$rubric && $perMember && (int) $config['use_average'] === 1;
        $mode      = $rubric ? 'rubric' : ($average ? 'average' : (string) $config['grading_mode']);
        $canAdjust = $rubric ? $scopes['individual'] !== [] : $config['grading_mode'] === 'collective_adjust';

        $groupScores = $rubric ? $this->points($input['group_scores'] ?? [], $scopes['group']) : [];
        $groupPoints = match (true) {
            $rubric                 => $scopes['group'] === [] ? null : array_sum($groupScores),
            $mode === 'individual', $average => null,
            default                 => $this->clamp((float) ($input['group_score'] ?? 0), $total),
        };

        $shared = $perMember ? null : $this->latestHandIn($assessmentId, $groupId, null);

        if (!$perMember && $shared === null) {
            throw new StructureException('This group has not submitted yet.');
        }

        $rows    = [];
        $skipped = [];

        foreach ($members as $studentId) {
            $m          = $given[$studentId] ?? [];
            $submission = $perMember ? $this->latestHandIn($assessmentId, null, $studentId) : $shared;

            if ($submission === null) {
                $skipped[] = $studentId;

                continue;
            }

            $adjustment = (float) ($m['adjustment'] ?? 0);
            $reason     = trim((string) ($m['reason'] ?? ''));

            if ($adjustment != 0.0 && !$canAdjust) {
                throw StructureException::field('adjustment', 'This activity gives every member the same grade, so there is no adjustment.');
            }

            if ($adjustment != 0.0 && ($reason === '' || mb_strlen($reason) > 500)) {
                throw StructureException::field('reason', 'Give a reason of up to 500 characters for each adjustment.');
            }

            $individual       = $rubric ? $this->points($m['individual'] ?? [], $scopes['individual']) : [];
            $individualPoints = match (true) {
                $rubric                          => $scopes['individual'] === [] ? null : array_sum($individual),
                $mode === 'individual', $average => $this->clamp((float) ($m['score'] ?? 0), $total),
                default                          => null,
            };

            $rows[$studentId] = [
                'submission'  => $submission,
                'individual'  => $individual,
                'points'      => $individualPoints,
                'adjustment'  => $adjustment,
                'reason'      => $adjustment != 0.0 ? $reason : null,
                'input'       => $m,
            ];
        }

        if ($rows === []) {
            throw new StructureException('No member of this group has submitted yet.');
        }

        if ($average) {
            $groupPoints = round(array_sum(array_column($rows, 'points')) / count($rows), 2);
        }

        $now      = date('Y-m-d H:i:s');
        $grader   = auth()->getProvider()->findById($graderId);
        $feedback = (string) ($input['feedback'] ?? '');
        $old      = [];
        $db       = db_connect();
        $db->transStart();

        try {
            if ($shared !== null) {
                $this->gradeSubmission($shared, (float) ($groupPoints ?? 0), $groupScores, $rubric, $total, $graderId, $now);
                $grader === null || (new AssessmentFeedbackService())->appendInstructorMessage($grader, (int) $shared['id'], $feedback);
            }

            foreach ($rows as $studentId => $row) {
                $base  = $average ? (float) $groupPoints : (float) ($groupPoints ?? 0) + (float) ($row['points'] ?? 0);
                $final = $this->clamp($base + $row['adjustment'], $total);

                if ($perMember) {
                    $this->gradeSubmission($row['submission'], $final, $groupScores + $row['individual'], $rubric, $total, $graderId, $now);
                    $grader === null || (new AssessmentFeedbackService())->appendInstructorMessage($grader, (int) $row['submission']['id'], $feedback);
                }

                $old[$studentId] = $this->memberScoreFor((int) $row['submission']['id'], $studentId);

                $this->saveMemberScore([
                    'submission_id'            => (int) $row['submission']['id'],
                    'student_user_id'          => $studentId,
                    'group_id'                 => $groupId,
                    'group_points'             => $groupPoints,
                    'individual_points'        => $row['points'],
                    'individual_rubric_scores' => $row['individual'] === [] ? null : json_encode($row['individual']),
                    'adjustment'               => $row['adjustment'],
                    'adjustment_reason'        => $row['reason'],
                    'final_score'              => $final,
                    'final_percentage'         => $this->percentage($final, $total),
                    'pending'                  => 0,
                    'graded_by'                => $graderId,
                    'graded_at'                => $now,
                ] + (array_key_exists('note', $row['input']) ? ['private_note' => $row['input']['note']] : []));
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Group grading was rolled back: ' . $e->getMessage());

            throw new StructureException('The grades could not be saved. Nothing was changed.');
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException('The grades could not be saved. Nothing was changed.');
        }

        GroupSetService::audit('groups.graded', $graderId, $groupId, 'student_group', ['new' => ['assessment_id' => $assessmentId, 'mode' => $mode, 'graded' => count($rows), 'skipped' => $skipped]]);

        foreach ($rows as $studentId => $row) {
            if ($row['adjustment'] != 0.0) {
                GroupSetService::audit('groups.adjusted', $graderId, $groupId, 'student_group', [
                    'old' => ['adjustment' => (float) ($old[$studentId]['adjustment'] ?? 0), 'reason' => $old[$studentId]['adjustment_reason'] ?? null],
                    'new' => ['student_user_id' => $studentId, 'adjustment' => $row['adjustment'], 'reason' => $row['reason']],
                ]);
            }

            $this->sync($assessmentId, $studentId);
        }
    }

    /** A member added after the group was graded starts pending, with no final score (D10). Per-member work has no shared row to hang it on. */
    public function onMemberAdded(int $groupId, int $studentId): void
    {
        $group = db_connect()->table('student_groups g')->select('c.assessment_id, c.submission_style')->join('activity_group_configs c', 'c.id = g.config_id')->where('g.id', $groupId)->get()->getRowArray();

        if ($group === null || $group['assessment_id'] === null || $group['submission_style'] === 'per_member') {
            return;
        }

        $graded = (new AssessmentSubmissionModel())->where('assessment_id', $group['assessment_id'])->where('group_id', $groupId)->where('is_graded', 1)
            ->orderBy('attempt_number', 'DESC')->orderBy('id', 'DESC')->first();

        if ($graded === null || $this->memberScoreFor((int) $graded['id'], $studentId) !== null) {
            return;
        }

        (new GroupMemberScoreModel())->insert(['submission_id' => (int) $graded['id'], 'student_user_id' => $studentId, 'group_id' => $groupId, 'pending' => 1]);
    }

    public function resolveLateAdd(int $groupId, int $assessmentId, int $studentId, string $decision, string $reason, int $userId): void
    {
        $this->context($assessmentId, $groupId);
        $reason = $this->decision($decision, ['separate', 'inherit'], $reason);
        $row    = $this->memberRow($groupId, $assessmentId, $studentId);

        if ($row === null || (int) $row['pending'] !== 1) {
            throw new StructureException('That student has no pending grade in this group.');
        }

        $groupPartOnly = false;

        if ($decision === 'inherit') {
            $total         = (float) db_connect()->table('assessments')->select('total_points')->where('id', $assessmentId)->get()->getRowArray()['total_points'];
            $points        = (float) (new AssessmentSubmissionModel())->find((int) $row['submission_id'])['score'];
            $final         = $this->clamp($points, $total);
            $groupPartOnly = array_filter($this->criteria($assessmentId), static fn (array $c) => $c['scope'] !== 'group') !== [];

            (new GroupMemberScoreModel())->update((int) $row['id'], ['pending' => 0, 'group_points' => $points, 'individual_points' => null, 'individual_rubric_scores' => null,
                'final_score' => $final, 'final_percentage' => $this->percentage($final, $total), 'graded_by' => $userId, 'graded_at' => date('Y-m-d H:i:s')]);
        }

        GroupSetService::audit('groups.late_add_resolved', $userId, $groupId, 'student_group', ['old' => ['pending' => true],
            'new' => ['student_user_id' => $studentId, 'decision' => $decision, 'reason' => $reason, 'group_part_only' => $groupPartOnly]]);

        if ($decision === 'inherit') {
            $this->sync($assessmentId, $studentId);
        }
    }

    /** Keep (the default when a drop removes the member) or excuse a member's grade; works whether or not they are still in the group. */
    public function resolveDropped(int $groupId, int $assessmentId, int $studentId, string $decision, string $reason, int $userId): void
    {
        $this->context($assessmentId, $groupId);
        $reason = $this->decision($decision, ['keep', 'excuse'], $reason);
        $row    = $this->memberRow($groupId, $assessmentId, $studentId);

        if ($row === null) {
            throw new StructureException('That student has no grade in this group.');
        }

        (new GroupMemberScoreModel())->update((int) $row['id'], ['excused' => $decision === 'excuse' ? 1 : 0]);
        GroupSetService::audit('groups.dropped_resolved', $userId, $groupId, 'student_group', ['old' => ['excused' => (bool) $row['excused']],
            'new' => ['student_user_id' => $studentId, 'decision' => $decision, 'reason' => $reason]]);
        $this->sync($assessmentId, $studentId);
    }

    /** Mirrors GradeWorkService::reopenGrading for the group's graded submission(s); notes and adjustments stay for the regrade. */
    public function reopenGroup(int $assessmentId, int $groupId, int $userId): void
    {
        $this->context($assessmentId, $groupId);

        $rows = db_connect()->table('group_member_scores gms')->select('gms.submission_id, gms.student_user_id')
            ->join('assessment_submissions s', 's.id = gms.submission_id')
            ->where('gms.group_id', $groupId)->where('s.assessment_id', $assessmentId)->where('s.status', 'Graded')
            ->get()->getResultArray();

        if ($rows === []) {
            throw new StructureException('Only graded work can be reopened.');
        }

        $submissions = array_values(array_unique(array_map('intval', array_column($rows, 'submission_id'))));
        $db          = db_connect();
        $db->transStart();
        $db->table('assessment_submissions')->whereIn('id', $submissions)->update(['status' => 'Submitted', 'is_graded' => 0]);
        $db->table('group_member_scores')->whereIn('submission_id', $submissions)->update(['final_score' => null, 'final_percentage' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException('Grading could not be reopened. Try again.');
        }

        GroupSetService::audit('groups.grading_reopened', $userId, $groupId, 'student_group', ['new' => ['assessment_id' => $assessmentId, 'submission_ids' => $submissions]]);

        foreach (array_unique(array_map('intval', array_column($rows, 'student_user_id'))) as $studentId) {
            $this->sync($assessmentId, $studentId);
        }
    }

    /** @return array<string, mixed>|null */
    public function memberScoreFor(int $submissionId, int $studentId): ?array
    {
        $row = (new GroupMemberScoreModel())->where('submission_id', $submissionId)->where('student_user_id', $studentId)->first();

        if ($row !== null) {
            $row['individual_rubric_scores'] = $row['individual_rubric_scores'] === null ? [] : (json_decode((string) $row['individual_rubric_scores'], true) ?? []);
        }

        return $row;
    }

    /** One member's row, inserted or updated. Overridable so a test can fail the fan-out halfway. */
    protected function saveMemberScore(array $row): void
    {
        $model    = new GroupMemberScoreModel();
        $existing = $model->where('submission_id', $row['submission_id'])->where('student_user_id', $row['student_user_id'])->first();

        $existing === null ? $model->insert($row) : $model->update((int) $existing['id'], $row);
    }

    /** Refuses on a finalized offering, a non-group assignment, or a group that is not an active group of this activity. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function context(int $assessmentId, int $groupId): array
    {
        $config = (new ActivityGroupService())->findByAssessment($assessmentId) ?? throw new StructureException('This assignment is not a group activity.');
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);

        $group = (new StudentGroupModel())->find($groupId);

        if ($group === null || (int) $group['config_id'] !== (int) $config['id'] || $group['status'] !== 'active') {
            throw new StructureException('That group is not part of this activity.');
        }

        $assessment = db_connect()->table('assessments')->where('id', $assessmentId)->get()->getRowArray();

        return [$assessment, $config];
    }

    /** @return list<int> */
    private function members(int $groupId): array
    {
        $rows = db_connect()->table('student_group_members')->select('student_user_id')->where('group_id', $groupId)->orderBy('id')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'student_user_id'));
    }

    /** @return list<array<string, mixed>> */
    private function criteria(int $assessmentId): array
    {
        $rubrics = new AssessmentRubricModel();
        $rubric  = $rubrics->findByAssessment($assessmentId);

        return $rubric === null ? [] : ($rubrics->getWithCriteria((int) $rubric['id'])['criteria'] ?? []);
    }

    /**
     * Clamped like GradeWorkService (a missing criterion scores 0); a criterion of another rubric or scope is refused.
     *
     * @param array<int|string, mixed> $given
     * @param array<int, float>        $max
     *
     * @return array<int, float>
     */
    private function points(array $given, array $max): array
    {
        foreach (array_keys($given) as $id) {
            if (!isset($max[(int) $id])) {
                throw StructureException::field('rubric_scores', 'That rubric criterion is not part of this grade.');
            }
        }

        $out = [];

        foreach ($max as $id => $cap) {
            $out[$id] = max(0.0, min((float) ($given[$id] ?? 0), $cap));
        }

        return $out;
    }

    /** @return array<string, mixed>|null the latest handed-in attempt: the group's (shared styles) or the student's own */
    private function latestHandIn(int $assessmentId, ?int $groupId, ?int $studentId): ?array
    {
        $model = (new AssessmentSubmissionModel())->where('assessment_id', $assessmentId)->whereIn('status', AssessmentResultService::FINISHED_STATUSES);
        $model = $groupId !== null ? $model->where('group_id', $groupId) : $model->where('student_id', $studentId)->where('group_id', null);

        return $model->orderBy('attempt_number', 'DESC')->orderBy('id', 'DESC')->first();
    }

    /** @param array<int, float> $rubricScores */
    private function gradeSubmission(array $submission, float $score, array $rubricScores, bool $rubric, float $total, int $graderId, string $now): void
    {
        (new AssessmentSubmissionModel())->update((int) $submission['id'], [
            'score'         => $score,
            'percentage'    => $this->percentage($score, $total),
            'grading_mode'  => $rubric ? 'Rubric' : 'Default',
            'rubric_scores' => $rubric ? json_encode($rubricScores) : null,
            'is_graded'     => 1,
            'graded_by'     => $graderId,
            'graded_at'     => $now,
            'status'        => 'Graded',
        ]);
    }

    /** @return array<string, mixed>|null the member's newest score row in this group for this assessment */
    private function memberRow(int $groupId, int $assessmentId, int $studentId): ?array
    {
        return db_connect()->table('group_member_scores gms')->select('gms.*')->join('assessment_submissions s', 's.id = gms.submission_id')
            ->where('gms.group_id', $groupId)->where('gms.student_user_id', $studentId)->where('s.assessment_id', $assessmentId)
            ->orderBy('gms.id', 'DESC')->get()->getRowArray();
    }

    /** @param list<string> $allowed */
    private function decision(string $decision, array $allowed, string $reason): string
    {
        if (!in_array($decision, $allowed, true)) {
            throw StructureException::field('decision', 'Choose one of the options.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw StructureException::field('reason', 'Give a reason of up to 500 characters.');
        }

        return $reason;
    }

    private function clamp(float $points, float $total): float
    {
        return max(0.0, min($points, $total));
    }

    private function percentage(float $score, float $total): float
    {
        return $total > 0 ? round(($score / $total) * 100, 2) : 0.0;
    }

    /** The gradebook is derived: a failure here is logged and never undoes the grade. */
    private function sync(int $assessmentId, int $studentId): void
    {
        try {
            (new GradebookSyncService())->syncItemScore($assessmentId, $studentId);
        } catch (\Throwable $e) {
            log_message('error', "Gradebook sync failed for student {$studentId} on assessment {$assessmentId}: " . $e->getMessage());
        }
    }
}
