<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Groups;

use App\Controllers\BaseController;
use App\Models\Assessments\AssessmentFeedbackModel;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentRubricModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Files\FileModel;
use App\Models\Groups\StudentGroupModel;
use App\Services\Courses\Assessments\AssessmentResultService;
use App\Services\Courses\CourseAbilities;
use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupGradingService;
use App\Services\Profiles\AvatarData;
use App\Services\Institutions\StructureException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Grade Work's group view (screen 2B) and the post-grading decisions (4D). The list of an activity's groups with
 * their grading status, one group's detail (work, criteria, scores of every member, the group's feedback thread),
 * and the writes, which GroupGradingService does. This is the instructor's screen, so every member's adjustment,
 * reason and private note is in the detail; no student route reaches it. A group or student not of this activity
 * is "not found"; a broken rule is a 422 with { message, errors }.
 */
class GroupGradeController extends BaseController
{
    private const NAME = "COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username)";

    public function index(int $offeringId, int $assessmentId): ResponseInterface
    {
        $config  = $this->config($offeringId, $assessmentId);
        $members = $this->membersOfConfig((int) $config['id']);
        $groups  = [];

        foreach ((new StudentGroupModel())->where('config_id', $config['id'])->where('status', 'active')->orderBy('id', 'ASC')->findAll() as $g) {
            $ids      = array_column($members[(int) $g['id']] ?? [], 'student_user_id');
            $groups[] = ['id' => (int) $g['id'], 'name' => (string) $g['name'], 'memberCount' => count($ids), 'status' => $this->status($config, $assessmentId, (int) $g['id'], $ids)];
        }

        $placed    = array_merge([], ...array_map(static fn (array $list) => array_column($list, 'student_user_id'), array_values($members)));
        $free      = array_values(array_diff((new ActivityGroupService())->pool((int) $config['id']), $placed));
        $ungrouped = $free === [] ? [] : db_connect()->table('users u')->select('u.id AS student_user_id, ' . self::NAME . ' AS name', false)
            ->join('user_profiles p', 'p.user_id = u.id', 'left')->whereIn('u.id', $free)->orderBy('name', 'ASC')->get()->getResultArray();
        $access    = $this->access($offeringId);
        $blockers  = $ungrouped !== [] && $config['ungrouped_policy'] === 'block'
            ? [count($ungrouped) === 1 ? '1 student is not in a group, so they cannot hand in work.' : count($ungrouped) . ' students are not in a group, so they cannot hand in work.']
            : [];

        // Under the "may work alone" policy an ungrouped student's own work is graded on the per-student page.
        $handedIn = $free === [] ? [] : array_map('intval', array_column(db_connect()->table('assessment_submissions')->select('student_id')->distinct()
            ->where('assessment_id', $assessmentId)->where('group_id', null)->whereIn('student_id', $free)->whereIn('status', AssessmentResultService::FINISHED_STATUSES)->get()->getResultArray(), 'student_id'));

        // One lookup for the list: each ungrouped student's avatar and name as this viewer may see them (FR2, FR5).
        $avatars = (new AvatarData())->forViewerJson(auth()->user(), array_map('intval', array_column($ungrouped, 'student_user_id')));

        return $this->response->setJSON([
            'success'         => true,
            'groups'          => $groups,
            'ungrouped'       => array_map(static fn (array $s) => ['student_user_id' => (int) $s['student_user_id'], 'name' => $avatars[(int) $s['student_user_id']]['name'] ?? (string) $s['name'], 'avatar' => $avatars[(int) $s['student_user_id']] ?? null, 'hasSubmission' => in_array((int) $s['student_user_id'], $handedIn, true)], $ungrouped),
            'ungroupedPolicy' => (string) $config['ungrouped_policy'],
            'blockers'        => $blockers,
            'flags'      => ['canWrite' => $access['canWrite'], 'finalized' => $access['finalized']],
            'lockReason' => $access['lockReason'],
        ]);
    }

    public function show(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $config    = $this->config($offeringId, $assessmentId);
        $group     = $this->group($config, $groupId);
        $perMember = $config['submission_style'] === 'per_member';
        $current   = $this->membersOfConfig((int) $config['id'])[$groupId] ?? [];
        $ids       = array_column($current, 'student_user_id');
        $handIns   = $this->handIns($config, $assessmentId, $groupId, $ids);
        $shared    = $perMember ? null : ($handIns[0] ?? null);
        $criteria  = $this->criteria($assessmentId);
        $isRubric  = $criteria !== [];
        $groupIds  = array_column(array_filter($criteria, static fn (array $c) => $c['scope'] === 'group'), 'id');
        $scores    = $this->scoreRows($groupId, $assessmentId);

        // Removed: a score row in this group for someone who is no longer in it (dropped or moved after grading).
        $removed = array_diff(array_keys($scores), $ids);
        $names   = $removed === [] ? [] : array_column(db_connect()->table('users u')->select('u.id, ' . self::NAME . ' AS name', false)
            ->join('user_profiles p', 'p.user_id = u.id', 'left')->whereIn('u.id', $removed)->get()->getResultArray(), 'name', 'id');

        // One lookup for the group: every member's avatar and name as this viewer may see them (FR2, FR5).
        $avatars = (new AvatarData())->forViewerJson(auth()->user(), array_map('intval', [...$ids, ...$removed]));

        $entry = function (int $studentId, string $name, bool $isRemoved) use ($scores, $handIns, $perMember, $shared, $group, $avatars): array {
            $row = $scores[$studentId] ?? null;

            return [
                'student_user_id'  => $studentId,
                'name'             => $avatars[$studentId]['name'] ?? $name,
                'avatar'           => $avatars[$studentId] ?? null,
                'isLeader'         => $group['leader_user_id'] !== null && (int) $group['leader_user_id'] === $studentId,
                'hasSubmission'    => $perMember ? isset($handIns[$studentId]) : $shared !== null,
                'submission'       => $perMember && isset($handIns[$studentId]) ? $this->summary($handIns[$studentId]) : null,
                'individualScores' => (object) ($row === null || $row['individual_rubric_scores'] === null ? [] : (json_decode((string) $row['individual_rubric_scores'], true) ?? [])),
                'individualScore'  => $this->number($row['individual_points'] ?? null),
                'adjustment'       => (float) ($row['adjustment'] ?? 0),
                'reason'           => $row['adjustment_reason'] ?? null,
                'note'             => $row['private_note'] ?? null,
                'final'            => $this->number($row['final_score'] ?? null),
                'finalPercentage'  => $this->number($row['final_percentage'] ?? null),
                'pending'          => (int) ($row['pending'] ?? 0) === 1,
                'excused'          => (int) ($row['excused'] ?? 0) === 1,
                'removed'          => $isRemoved,
            ];
        };

        $members = array_map(static fn (array $m) => $entry((int) $m['student_user_id'], (string) $m['name'], false), $current);

        foreach ($removed as $studentId) {
            $members[] = $entry((int) $studentId, (string) ($names[$studentId] ?? 'Former member'), true);
        }

        // The group criteria and feedback: the shared row, or (per member) the first member who handed in.
        $source      = $shared ?? (array_values($handIns)[0] ?? null);
        $rubricSaved = $source === null || $source['rubric_scores'] === null ? [] : (json_decode((string) $source['rubric_scores'], true) ?? []);
        $groupPoints = array_values(array_filter(array_map(static fn (array $r) => $r['group_points'], array_intersect_key($scores, array_flip($ids))), static fn ($v) => $v !== null));
        $access      = $this->access($offeringId);
        $total       = (float) db_connect()->table('assessments')->select('total_points')->where('id', $assessmentId)->get()->getRow()->total_points;

        return $this->response->setJSON([
            'success'     => true,
            'group'       => ['id' => $groupId, 'name' => (string) $group['name'], 'leader_user_id' => $group['leader_user_id'] === null ? null : (int) $group['leader_user_id']],
            'status'      => $this->status($config, $assessmentId, $groupId, $ids),
            'totalPoints' => $total,
            'config'      => [
                'gradingMode'      => (string) $config['grading_mode'],
                'allowsAdjustment' => $isRubric ? count($groupIds) < count($criteria) : $config['grading_mode'] === 'collective_adjust',
                'usesAverage'      => !$isRubric && $perMember && (int) $config['use_average'] === 1,
                'submissionStyle'  => (string) $config['submission_style'],
                'isRubric'         => $isRubric,
            ],
            'submission'  => $shared === null ? null : $this->summary($shared),
            'criteria'    => array_map(fn (array $c) => ['id' => (int) $c['id'], 'title' => (string) $c['criteria_title'], 'description' => $c['description'] ?? null, 'max_points' => (float) $c['max_points'], 'scope' => $c['scope'] === 'group' ? 'group' : 'individual'], $criteria),
            'groupScores' => (object) array_intersect_key($rubricSaved, array_flip($groupIds)),
            'groupScore'  => $groupPoints === [] ? null : (float) $groupPoints[0],
            'feedback'    => $source === null ? [] : (new AssessmentFeedbackModel())->listForSubmission((int) $source['id']),
            'members'     => $members,
            'flags'       => ['canWrite' => $access['canWrite'], 'finalized' => $access['finalized'], 'graded' => in_array(1, array_map(static fn (array $s) => (int) $s['is_graded'], $handIns), true)],
            'lockReason'  => $access['lockReason'],
        ]);
    }

    public function grade(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $this->group($this->config($offeringId, $assessmentId), $groupId);
        $input = $this->body();

        return $this->run(fn () => (new GroupGradingService())->gradeGroup($assessmentId, $groupId, $input, $this->actor()));
    }

    public function reopen(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $this->group($this->config($offeringId, $assessmentId), $groupId);

        return $this->run(fn () => (new GroupGradingService())->reopenGroup($assessmentId, $groupId, $this->actor()));
    }

    public function lateDecision(int $offeringId, int $assessmentId, int $groupId, int $studentId): ResponseInterface
    {
        $this->student($this->group($this->config($offeringId, $assessmentId), $groupId), $assessmentId, $studentId);
        $body = $this->body();

        return $this->run(fn () => (new GroupGradingService())->resolveLateAdd($groupId, $assessmentId, $studentId, (string) ($body['decision'] ?? ''), (string) ($body['reason'] ?? ''), $this->actor()));
    }

    public function dropDecision(int $offeringId, int $assessmentId, int $groupId, int $studentId): ResponseInterface
    {
        $this->student($this->group($this->config($offeringId, $assessmentId), $groupId), $assessmentId, $studentId);
        $body = $this->body();

        return $this->run(fn () => (new GroupGradingService())->resolveDropped($groupId, $assessmentId, $studentId, (string) ($body['decision'] ?? ''), (string) ($body['reason'] ?? ''), $this->actor()));
    }

    /** needs_grading | graded | pending_members (a late add waits for a decision) | not_submitted */
    private function status(array $config, int $assessmentId, int $groupId, array $memberIds): string
    {
        $handIns = $this->handIns($config, $assessmentId, $groupId, $memberIds);

        if ($handIns === []) {
            return 'not_submitted';
        }

        $pending = db_connect()->table('group_member_scores')->whereIn('submission_id', array_map(static fn (array $s) => (int) $s['id'], $handIns))->where('group_id', $groupId)->where('pending', 1)->countAllResults();

        return match (true) {
            $pending > 0 => 'pending_members',
            array_filter($handIns, static fn (array $s) => (int) $s['is_graded'] !== 1) === [] => 'graded',
            default => 'needs_grading',
        };
    }

    /**
     * The latest handed-in attempt: the group's one (key 0) for shared styles, else each member's own (by student id),
     * as GroupGradingService grades them.
     *
     * @param list<int> $memberIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function handIns(array $config, int $assessmentId, int $groupId, array $memberIds): array
    {
        $latest = fn (): AssessmentSubmissionModel => (new AssessmentSubmissionModel())->where('assessment_id', $assessmentId)->whereIn('status', AssessmentResultService::FINISHED_STATUSES)->orderBy('attempt_number', 'DESC')->orderBy('id', 'DESC');

        if ($config['submission_style'] !== 'per_member') {
            $row = $latest()->where('group_id', $groupId)->first();

            return $row === null ? [] : [0 => $row];
        }

        $out = [];

        foreach ($memberIds as $studentId) {
            $row = $latest()->where('student_id', $studentId)->where('group_id', null)->first();

            if ($row !== null) {
                $out[(int) $studentId] = $row;
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> each student's newest score row in this group, by student id */
    private function scoreRows(int $groupId, int $assessmentId): array
    {
        $out = [];

        foreach (db_connect()->table('group_member_scores gms')->select('gms.*')->join('assessment_submissions s', 's.id = gms.submission_id')
            ->where('gms.group_id', $groupId)->where('s.assessment_id', $assessmentId)->orderBy('gms.id', 'DESC')->get()->getResultArray() as $row) {
            $out[(int) $row['student_user_id']] ??= $row;
        }

        return $out;
    }

    /** @return array<string, mixed> what was handed in, drawn with the per-student Grade Work's partial */
    private function summary(array $s): array
    {
        $by   = (int) ($s['submitted_by_user_id'] ?? $s['student_id']);
        $name = db_connect()->table('users u')->select(self::NAME . ' AS name', false)->join('user_profiles p', 'p.user_id = u.id', 'left')->where('u.id', $by)->get()->getRow()?->name;
        $file = empty($s['submission_file_id']) ? null : (new FileModel())->retrieveFile((int) $s['submission_file_id']);

        return [
            'id'            => (int) $s['id'],
            'attemptNumber' => (int) $s['attempt_number'],
            'status'        => (string) $s['status'],
            'isGraded'      => (int) $s['is_graded'] === 1,
            'submittedAt'   => $s['submitted_at'],
            'isLate'        => (int) ($s['is_late_submission'] ?? 0) === 1,
            'submittedBy'   => ['id' => $by, 'name' => (string) $name],
            'workHtml'      => view('pages/courses/features/assessments/partials/submitted-work', ['attempt' => ['file' => $file] + $s], ['saveData' => false]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function criteria(int $assessmentId): array
    {
        $rubrics = new AssessmentRubricModel();
        $rubric  = $rubrics->findByAssessment($assessmentId);

        return $rubric === null ? [] : ($rubrics->getWithCriteria((int) $rubric['id'])['criteria'] ?? []);
    }

    /** @return array<int, list<array{student_user_id: int, name: string}>> the members of every group of the activity, by group */
    private function membersOfConfig(int $configId): array
    {
        $out = [];

        foreach (db_connect()->table('student_group_members m')->select('m.group_id, m.student_user_id, ' . self::NAME . ' AS name', false)
            ->join('users u', 'u.id = m.student_user_id')->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->where('m.config_id', $configId)->orderBy('name', 'ASC')->get()->getResultArray() as $m) {
            $out[(int) $m['group_id']][] = ['student_user_id' => (int) $m['student_user_id'], 'name' => (string) $m['name']];
        }

        return $out;
    }

    /** @return array{canWrite: bool, finalized: bool, lockReason: string} */
    private function access(int $offeringId): array
    {
        $finalized = (new GradeFinalizationService())->isFinalized($offeringId);
        $closed    = db_connect()->table('course_offerings')->where('id', $offeringId)->where('status !=', 'active')->countAllResults() > 0;
        $viewOnly  = CourseAbilities::viewOnly(auth()->user(), 'grading');

        return [
            'canWrite'   => !$viewOnly && !$finalized && !$closed,
            'finalized'  => $finalized,
            'lockReason' => match (true) {
                $finalized => 'The grades are finalized, so these grades cannot be changed. Reopen the grades first.',
                $viewOnly  => 'View only. You can see the groups and their grades but not change them.',
                $closed    => 'This course is closed, so its grades cannot be changed.',
                default    => '',
            },
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function run(callable $change): ResponseInterface
    {
        try {
            $change();
        } catch (StructureException $e) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()]);
        }

        return $this->response->setJSON(['success' => true]);
    }

    /** @return array<string, mixed> the activity's config; an assessment of another offering, or one without groups, is not found */
    private function config(int $offeringId, int $assessmentId): array
    {
        if ((new AssessmentModel())->offeringIdOf($assessmentId) !== $offeringId) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

        return (new ActivityGroupService())->findByAssessment($assessmentId) ?? throw PageNotFoundException::forPageNotFound('This assignment does not use groups.');
    }

    /** @return array<string, mixed> */
    private function group(array $config, int $groupId): array
    {
        $group = (new StudentGroupModel())->where('id', $groupId)->where('config_id', $config['id'])->where('status', 'active')->first();

        return $group ?? throw PageNotFoundException::forPageNotFound('Group not found.');
    }

    /** A student who is in the group or holds a score row from it; anyone else is not found. */
    private function student(array $group, int $assessmentId, int $studentId): void
    {
        $member = db_connect()->table('student_group_members')->where('group_id', $group['id'])->where('student_user_id', $studentId)->countAllResults() > 0;

        if (!$member && !isset($this->scoreRows((int) $group['id'], $assessmentId)[$studentId])) {
            throw PageNotFoundException::forPageNotFound('Student not found in this group.');
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return $this->request->getJSON(true) ?? [];
    }

    private function actor(): int
    {
        return (int) auth()->id();
    }
}
