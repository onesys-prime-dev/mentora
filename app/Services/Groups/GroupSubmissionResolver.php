<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Models\Assessments\AssessmentSubmissionModel;

/**
 * Which group a student submits for and whether they may. A shared submission (shared / leader_only) is one row keyed
 * by the group; a per-member one stays one row per student with no group on it.
 */
class GroupSubmissionResolver
{
    /** @return array<string, mixed>|null the student's ACTIVE group in this assignment's activity */
    public function groupFor(int $assessmentId, int $studentId): ?array
    {
        $group = $this->membership($assessmentId, $studentId);

        return $group !== null && $group['status'] === 'active' ? $group : null;
    }

    /** @return array<string, mixed>|null the latest attempt this student is entitled to see */
    public function submissionFor(int $assessmentId, int $studentId): ?array
    {
        $model = (new AssessmentSubmissionModel())->where('assessment_id', $assessmentId);
        $group = $this->sharedGroup($assessmentId, $studentId);
        $model = $group !== null ? $model->where('group_id', (int) $group['id']) : $model->where('student_id', $studentId);

        return $model->orderBy('attempt_number', 'DESC')->orderBy('id', 'DESC')->first();
    }

    /** @return array{allowed: bool, reason: ?string, code: string} */
    public function canSubmit(int $assessmentId, int $studentId): array
    {
        $config = (new ActivityGroupService())->findByAssessment($assessmentId);

        if ($config === null) {
            return $this->verdict(true, 'none');
        }

        if (!in_array($studentId, (new ActivityGroupService())->pool((int) $config['id']), true)) {
            return $this->verdict(false, 'not_in_pool', 'You are not part of this assignment.');
        }

        $group = $this->membership($assessmentId, $studentId);

        if ($group === null && $config['ungrouped_policy'] === 'solo') {
            return $this->verdict(true, 'solo');
        }

        if ($group === null || $group['status'] !== 'active') {
            return $this->verdict(false, 'needs_group', 'Join a group before you submit.');
        }

        if ($config['submission_style'] === 'leader_only' && $group['leader_finalized_at'] !== null && $group['leader_user_id'] !== null && (int) $group['leader_user_id'] !== $studentId) {
            $row  = db_connect()->table('users')->select('username')->where('id', $group['leader_user_id'])->get()->getRowArray();
            $name = $row['username'] ?? 'your group leader';

            return $this->verdict(false, 'leader_only', "Only your group leader ({$name}) can submit for this group.");
        }

        return $this->verdict(true, 'group');
    }

    /** The assessment due date, or the later group extension for the student's group (shared styles only, D11). */
    public function effectiveDueDate(int $assessmentId, int $studentId): ?string
    {
        $row   = db_connect()->table('assessments')->select('due_date')->where('id', $assessmentId)->get()->getRowArray();
        $due   = $row['due_date'] ?? null;
        $group = $this->sharedGroup($assessmentId, $studentId);
        $ext   = $group === null ? null : (new GroupExtensionService())->extensionFor((int) $group['id'], $assessmentId);
        $until = $ext['until_at'] ?? null;

        return $until !== null && ($due === null || strtotime($until) > strtotime($due)) ? $until : $due;
    }

    /** Next attempt number: the group's rows for a shared style, else the student's own. */
    public function attemptNumberFor(int $assessmentId, int $studentId): int
    {
        $group = $this->sharedGroup($assessmentId, $studentId);
        $model = (new AssessmentSubmissionModel())->selectMax('attempt_number')->where('assessment_id', $assessmentId);
        $model = $group !== null ? $model->where('group_id', (int) $group['id']) : $model->where('student_id', $studentId);

        return (int) ($model->first()['attempt_number'] ?? 0) + 1;
    }

    /** The active group, when this activity submits as one (shared or leader_only). */
    public function sharedGroup(int $assessmentId, int $studentId): ?array
    {
        $config = (new ActivityGroupService())->findByAssessment($assessmentId);

        return $config !== null && $config['submission_style'] !== 'per_member' ? $this->groupFor($assessmentId, $studentId) : null;
    }

    /** @return array<string, mixed>|null the student's group of any status (active preferred) */
    private function membership(int $assessmentId, int $studentId): ?array
    {
        return db_connect()->table('student_groups')->select('student_groups.*')
            ->join('activity_group_configs', 'activity_group_configs.id = student_groups.config_id')
            ->join('student_group_members', 'student_group_members.group_id = student_groups.id')
            ->where('activity_group_configs.assessment_id', $assessmentId)
            ->where('student_group_members.student_user_id', $studentId)
            ->orderBy("student_groups.status = 'active'", 'DESC', false)
            ->get()->getRowArray();
    }

    /** @return array{allowed: bool, reason: ?string, code: string} */
    private function verdict(bool $allowed, string $code, ?string $reason = null): array
    {
        return ['allowed' => $allowed, 'reason' => $reason, 'code' => $code];
    }
}
