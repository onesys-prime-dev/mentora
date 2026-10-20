<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

use App\Models\Assessments\GradebookRecordModel;
use App\Models\Assessments\GradeOverrideRequestModel;
use App\Models\Assignments\DeanAssignmentModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Services\Auth\ActiveRoleService;
use App\Services\Courses\ChairpersonAccessService;
use App\Services\Notifications\NotificationService;
use CodeIgniter\Shield\Entities\User;

/**
 * A request to override a student's final grade, and the people it passes through.
 *
 * In an institution that has deans, an override is a request: the calculated
 * grade stays in force until it is approved. An instructor's request waits for
 * the sub-department's chairperson to endorse it or send it back with a comment
 * (the requester may then edit and resubmit, or withdraw); an endorsed request
 * waits for the dean. Who is next is OverrideRequestChain. An institution with
 * no dean keeps the direct override it always had (GradingEngineService).
 *
 * Deciding (the dean's approval) is a later step; this class takes a request as
 * far as the dean. Every step is audited and tells the person who asked.
 */
class GradeOverrideRequestService
{
    public function __construct(
        private readonly GradeOverrideRequestModel $requests = new GradeOverrideRequestModel(),
        private readonly NotificationService $notifications = new NotificationService(),
    ) {
    }

    /** Do grade overrides in this offering's institution go through a request? Only where there is a dean. */
    public function appliesTo(int $offeringId): bool
    {
        $context = $this->context($offeringId);

        if ($context === null) {
            return false;
        }

        return db_connect()->table('dean_assignments da')
            ->join('departments d', 'd.id = da.department_id')
            ->where('d.institution_id', (int) $context['institution_id'])
            ->where(['da.status' => 'active', 'da.active_marker' => 1])
            ->countAllResults() > 0;
    }

    /**
     * Ask for an override (or, with a null percentage, for the current one to be removed).
     *
     * @return array{success: bool, message: string, request?: array<string, mixed>}
     */
    public function request(User $user, int $offeringId, int $studentId, ?float $percentage, string $reason): array
    {
        $role = $this->requesterRole($user, $offeringId);

        if ($role === null) {
            return $this->refused('You cannot request an override in this course.');
        }

        $problem = $this->problemWith($percentage, $reason);

        if ($problem !== null) {
            return $this->refused($problem);
        }

        $record = $this->recordFor($offeringId, $studentId);

        if ($record === null) {
            return $this->refused('Student is not enrolled in this section.');
        }

        if ($percentage === null && empty($record['is_override'])) {
            return $this->refused('There is no override to remove.');
        }

        if ($this->requests->openFor($offeringId, $studentId) !== null) {
            return $this->refused('This student already has an open request. Withdraw it first, or wait for it to be decided.');
        }

        $context = $this->context($offeringId);
        $status  = OverrideRequestChain::first((int) $user->id, $role, $this->chairIds((int) $context['sub_department_id']), $this->deanId((int) $context['department_id']));

        try {
            $id = (int) $this->requests->insert([
                'offering_id'           => $offeringId,
                'student_id'            => $studentId,
                'requested_percentage'  => $percentage,
                'calculated_percentage' => $record['calculated_percentage'],
                'previous_percentage'   => GradebookRecordModel::effectivePercentage($record),
                'reason'                => trim($reason),
                'requested_by'          => (int) $user->id,
                'requester_role'        => $role,
                'status'                => $status,
                'open_marker'           => 1,
            ], true);
        } catch (\Throwable) {
            // Two requests for the same student at once: the unique key let only one in.
            return $this->refused('This student already has an open request.');
        }

        $this->audit('grade.override_requested', $user, $id, $context, null, ['status' => $status, 'requested_percentage' => $percentage, 'student_id' => $studentId]);

        if ($status === OverrideRequestChain::AWAITING_CHAIR) {
            foreach (array_diff($this->chairIds((int) $context['sub_department_id']), [(int) $user->id]) as $chairId) {
                $this->tell($chairId, "grade-override:{$id}:chair", 'Grade override to endorse', $this->label($context) . ': a final-grade override is waiting for you.', (int) $context['institution_id'], site_url('chairperson/override-requests'));
            }
        }

        return [
            'success' => true,
            'message' => 'Request sent. The grade stays as it is until it is approved.',
            'request' => $this->present($this->requests->find($id)),
        ];
    }

    /**
     * The requester edits a request that was sent back, and sends it again from the start of the chain.
     *
     * @return array{success: bool, message: string, request?: array<string, mixed>}
     */
    public function resubmit(User $user, int $offeringId, int $requestId, ?float $percentage, string $reason): array
    {
        $row = $this->ownRequest($user, $offeringId, $requestId);

        if ($row === null) {
            return $this->refused('That request was not found.');
        }

        if ($row['status'] !== OverrideRequestChain::RETURNED) {
            return $this->refused('Only a request that was sent back can be edited.');
        }

        $problem = $this->problemWith($percentage, $reason);

        if ($problem !== null) {
            return $this->refused($problem);
        }

        if ($percentage === null) {
            $record = $this->recordFor($offeringId, (int) $row['student_id']);

            if ($record === null || empty($record['is_override'])) {
                return $this->refused('There is no override to remove.');
            }
        }

        $context = $this->context($offeringId);
        $status  = OverrideRequestChain::first((int) $user->id, (string) $row['requester_role'], $this->chairIds((int) $context['sub_department_id']), $this->deanId((int) $context['department_id']));

        $this->requests->update($requestId, [
            'requested_percentage' => $percentage,
            'reason'               => trim($reason),
            'status'               => $status,
            'returned_by'          => null,
            'returned_at'          => null,
            'return_comment'       => null,
        ]);

        $this->audit('grade.override_resubmitted', $user, $requestId, $context, ['requested_percentage' => $row['requested_percentage']], ['status' => $status, 'requested_percentage' => $percentage]);

        if ($status === OverrideRequestChain::AWAITING_CHAIR) {
            foreach (array_diff($this->chairIds((int) $context['sub_department_id']), [(int) $user->id]) as $chairId) {
                $this->tell($chairId, "grade-override:{$requestId}:chair:" . date('YmdHis'), 'Grade override to endorse', $this->label($context) . ': a request you sent back was edited and sent again.', (int) $context['institution_id'], site_url('chairperson/override-requests'));
            }
        }

        return ['success' => true, 'message' => 'Request sent again.', 'request' => $this->present($this->requests->find($requestId))];
    }

    /**
     * The requester takes an open request back.
     *
     * @return array{success: bool, message: string}
     */
    public function withdraw(User $user, int $offeringId, int $requestId): array
    {
        $row = $this->ownRequest($user, $offeringId, $requestId);

        if ($row === null) {
            return $this->refused('That request was not found.');
        }

        if (! OverrideRequestChain::isOpen((string) $row['status'])) {
            return $this->refused('That request is already closed.');
        }

        $this->requests->update($requestId, ['status' => OverrideRequestChain::WITHDRAWN, 'open_marker' => null]);

        $this->audit('grade.override_withdrawn', $user, $requestId, $this->context($offeringId) ?? [], ['status' => $row['status']], ['status' => OverrideRequestChain::WITHDRAWN]);

        return ['success' => true, 'message' => 'Request withdrawn.'];
    }

    /**
     * The chairperson endorses a request: it moves on to the dean (or, with no dean, the institution's administrator).
     *
     * @return array{success: bool, message: string}
     */
    public function endorse(User $chair, int $requestId, string $comment): array
    {
        $row = $this->actionable($chair, $requestId);

        if ($row === null) {
            return $this->refused('That request is not waiting for you.');
        }

        $context = $this->context((int) $row['offering_id']);
        $status  = OverrideRequestChain::afterChair((int) $row['requested_by'], $this->deanId((int) $context['department_id']));

        $this->requests->update($requestId, [
            'status'          => $status,
            'endorsed_by'     => (int) $chair->id,
            'endorsed_at'     => date('Y-m-d H:i:s'),
            'endorse_comment' => trim($comment) !== '' ? trim($comment) : null,
        ]);

        $this->audit('grade.override_endorsed', $chair, $requestId, $context, ['status' => $row['status']], ['status' => $status, 'comment' => trim($comment)]);

        $this->tell(
            (int) $row['requested_by'],
            "grade-override:{$requestId}:endorsed",
            'Override request endorsed',
            $this->label($context) . ': the chairperson endorsed your request. It now waits for ' . OverrideRequestChain::waitingFor($status) . '.',
            (int) $context['institution_id'],
            $this->gradebookLink($row, $context),
        );

        return ['success' => true, 'message' => 'Endorsed. It now waits for ' . OverrideRequestChain::waitingFor($status) . '.'];
    }

    /**
     * The chairperson sends a request back to its requester with a comment.
     *
     * @return array{success: bool, message: string}
     */
    public function sendBack(User $chair, int $requestId, string $comment): array
    {
        if (trim($comment) === '') {
            return $this->refused('Say what needs to change, so the instructor can fix it.');
        }

        $row = $this->actionable($chair, $requestId);

        if ($row === null) {
            return $this->refused('That request is not waiting for you.');
        }

        $context = $this->context((int) $row['offering_id']);

        $this->requests->update($requestId, [
            'status'         => OverrideRequestChain::RETURNED,
            'returned_by'    => (int) $chair->id,
            'returned_at'    => date('Y-m-d H:i:s'),
            'return_comment' => trim($comment),
        ]);

        $this->audit('grade.override_returned', $chair, $requestId, $context, ['status' => $row['status']], ['status' => OverrideRequestChain::RETURNED, 'comment' => trim($comment)]);

        $this->tell(
            (int) $row['requested_by'],
            "grade-override:{$requestId}:returned",
            'Override request sent back',
            $this->label($context) . ': the chairperson sent your request back: ' . trim($comment),
            (int) $context['institution_id'],
            $this->gradebookLink($row, $context),
        );

        return ['success' => true, 'message' => 'Sent back to the requester.'];
    }

    /**
     * The requests a chairperson sees for the sub-departments they chair, newest first.
     * "pending" is only what waits for them; "all" is every request in their sub-departments.
     *
     * @return list<array<string, mixed>>
     */
    public function listForChair(User $chair, string $view = 'pending'): array
    {
        $subs = array_map('intval', array_column(
            db_connect()->table('chairperson_assignments')->select('sub_department_id')->where(['chairperson_user_id' => (int) $chair->id, 'status' => 'active'])->get()->getResultArray(),
            'sub_department_id'
        ));

        if ($subs === []) {
            return [];
        }

        $builder = db_connect()->table('grade_override_requests r')
            ->select([
                'r.*', 'c.course_code', 'c.course_title', 'co.section_code', 'sd.id AS sub_department_id',
                "CONCAT(sp.first_name, ' ', sp.last_name) AS student_name",
                "CONCAT(rp.first_name, ' ', rp.last_name) AS requester_name",
            ], false)
            ->join('course_offerings co', 'co.id = r.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('user_profiles sp', 'sp.user_id = r.student_id', 'left')
            ->join('user_profiles rp', 'rp.user_id = r.requested_by', 'left')
            ->whereIn('sd.id', $subs);

        if ($view !== 'all') {
            $builder->where('r.status', OverrideRequestChain::AWAITING_CHAIR)->where('r.requested_by !=', (int) $chair->id);
        }

        return $builder->orderBy('r.created_at', 'DESC')->orderBy('r.id', 'DESC')->limit(200)->get()->getResultArray();
    }

    /**
     * The open requests of an offering for the gradebook, by student id, ready to show.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openByStudent(int $offeringId): array
    {
        return array_map(fn (array $row): array => $this->present($row), $this->requests->openByStudent($offeringId));
    }

    /**
     * Who a new request from this person would go to, in words, for the dialog that sends it.
     */
    public function routeText(User $user, int $offeringId): string
    {
        $role    = $this->requesterRole($user, $offeringId) ?? 'instructor';
        $context = $this->context($offeringId);

        if ($context === null) {
            return '';
        }

        $chairs = $this->chairIds((int) $context['sub_department_id']);
        $first  = OverrideRequestChain::first((int) $user->id, $role, $chairs, $this->deanId((int) $context['department_id']));
        $steps  = [];

        if ($first === OverrideRequestChain::AWAITING_CHAIR) {
            $names = array_column(db_connect()->table('user_profiles')->select("CONCAT(first_name, ' ', last_name) AS name", false)->whereIn('user_id', array_diff($chairs, [(int) $user->id]))->get()->getResultArray(), 'name');
            $steps[] = 'the chairperson' . ($names === [] ? '' : ' (' . implode(', ', $names) . ')') . ' to endorse';
            $first   = OverrideRequestChain::afterChair((int) $user->id, $this->deanId((int) $context['department_id']));
        }

        $steps[] = OverrideRequestChain::waitingFor($first) . ' to decide';

        return 'It goes to ' . implode(', then to ', $steps) . '.';
    }

    /**
     * One request in the words the screens use.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $status = (string) $row['status'];

        return [
            'id'                   => (int) $row['id'],
            'student_id'           => (int) $row['student_id'],
            'status'               => $status,
            'requested_percentage' => $row['requested_percentage'] === null ? null : (float) $row['requested_percentage'],
            'reason'               => (string) $row['reason'],
            'return_comment'       => $row['return_comment'],
            'waiting_for'          => OverrideRequestChain::waitingFor($status),
            'returned'             => $status === OverrideRequestChain::RETURNED,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Who and what
    |--------------------------------------------------------------------------
    */

    /** 'instructor' or 'chairperson': what this person is acting as in this course, or null when neither. */
    private function requesterRole(User $user, int $offeringId): ?string
    {
        $role = (new ActiveRoleService())->resolve($user);

        if ($role === 'instructor' && (new InstructorAssignmentModel())->retrieveInstructorCourseOffering((int) $user->id, $offeringId) !== null) {
            return 'instructor';
        }

        if ($role === 'chairperson' && (new ChairpersonAccessService())->canAccess($user, $offeringId)) {
            return 'chairperson';
        }

        return null;
    }

    /** The request, if it is this person's own and belongs to this offering. */
    private function ownRequest(User $user, int $offeringId, int $requestId): ?array
    {
        $row = $this->requests->find($requestId);

        return $row !== null && (int) $row['offering_id'] === $offeringId && (int) $row['requested_by'] === (int) $user->id ? $row : null;
    }

    /** The request, if it waits for this chairperson: in a sub-department they chair, not their own. */
    private function actionable(User $chair, int $requestId): ?array
    {
        $row = $this->requests->find($requestId);

        if ($row === null || $row['status'] !== OverrideRequestChain::AWAITING_CHAIR || (int) $row['requested_by'] === (int) $chair->id) {
            return null;
        }

        if ((new ActiveRoleService())->resolve($chair) !== 'chairperson' || ! (new ChairpersonAccessService())->canAccess($chair, (int) $row['offering_id'])) {
            return null;
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function context(int $offeringId): ?array
    {
        return db_connect()->table('course_offerings co')
            ->select(['d.institution_id', 'd.id AS department_id', 'sd.id AS sub_department_id', 'c.course_code', 'c.course_title', 'co.section_code'])
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray();
    }

    /** @return list<int> */
    private function chairIds(int $subDepartmentId): array
    {
        return array_map('intval', array_column(
            db_connect()->table('chairperson_assignments')->select('chairperson_user_id')->where(['sub_department_id' => $subDepartmentId, 'status' => 'active'])->get()->getResultArray(),
            'chairperson_user_id'
        ));
    }

    private function deanId(int $departmentId): ?int
    {
        $dean = (new DeanAssignmentModel())->currentDeanOfDepartment($departmentId);

        return $dean === null ? null : (int) $dean['dean_user_id'];
    }

    /** @return array<string, mixed>|null the student's gradebook record, calculated first if there is none yet */
    private function recordFor(int $offeringId, int $studentId): ?array
    {
        $model  = new GradebookRecordModel();
        $record = $model->findForStudent($offeringId, $studentId);

        if ($record === null) {
            (new GradingEngineService())->recalculateStudent($offeringId, $studentId);
            $record = $model->findForStudent($offeringId, $studentId);
        }

        return $record;
    }

    private function problemWith(?float $percentage, string $reason): ?string
    {
        if ($percentage !== null && ($percentage < 0 || $percentage > 100)) {
            return 'The grade must be between 0 and 100.';
        }

        if (trim($reason) === '') {
            return $percentage === null ? 'Say why the override should be removed.' : 'Give a reason for the override.';
        }

        return null;
    }

    /** @return array{success: false, message: string} */
    private function refused(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }

    /** @param array<string, mixed> $context */
    private function label(array $context): string
    {
        return trim(($context['course_code'] ?? '') . ' ' . ($context['section_code'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $context
     */
    private function gradebookLink(array $row, array $context): string
    {
        $prefix = $row['requester_role'] === 'chairperson' ? 'chairperson' : 'instructor';

        return site_url("{$prefix}/courses/{$row['offering_id']}/" . url_title((string) ($context['course_title'] ?? ''), '-', true) . '/grades');
    }

    private function tell(int $userId, string $key, string $title, string $body, int $institutionId, string $link): void
    {
        $this->notifications->send($userId, 'grade_override', $title, $body, false, $key, $institutionId, $link);
    }

    /**
     * @param array<string, mixed>      $context
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>      $new
     */
    private function audit(string $action, User $actor, int $requestId, array $context, ?array $old, array $new): void
    {
        service('auditLogger')->log($action, $actor, [
            'target_type'    => 'grade_override_request',
            'target_id'      => $requestId,
            'institution_id' => isset($context['institution_id']) ? (int) $context['institution_id'] : null,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
