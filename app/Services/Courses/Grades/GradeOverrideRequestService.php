<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

use App\Models\Assessments\GradebookRecordModel;
use App\Models\Assessments\GradeOverrideRequestModel;
use App\Models\Assignments\DeanAssignmentModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Services\Auth\ActiveRoleService;
use App\Services\Courses\ChairpersonAccessService;
use App\Services\Courses\DeanAccessService;
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

        $this->tellDeanIfWaiting($id, $status, $context, 'requested');

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

        $this->tellDeanIfWaiting($requestId, $status, $context, 'resubmitted:' . date('YmdHis'));

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

        $this->tellDeanIfWaiting($requestId, $status, $context, 'endorsed');

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
    | The dean's decision
    |--------------------------------------------------------------------------
    */

    /**
     * The dean approves a request: the override (or its removal) is written through the grading
     * engine, so the remarks and the grade history stay consistent, and the request is closed.
     * The request is claimed first (only one decision can win), and given back if the grade
     * could not be changed.
     *
     * @return array{success: bool, message: string}
     */
    public function approve(User $dean, int $requestId, string $comment = ''): array
    {
        $row = $this->decidable($dean, $requestId);

        if ($row === null) {
            return $this->refused('That request is not waiting for you.');
        }

        if (! $this->claim($requestId, OverrideRequestChain::APPROVED, $dean, $comment)) {
            return $this->refused('That request was just decided or withdrawn.');
        }

        $applied = (new GradingEngineService())->overrideFinalGrade(
            $dean,
            (int) $row['offering_id'],
            (int) $row['student_id'],
            $row['requested_percentage'] === null ? null : (float) $row['requested_percentage'],
            (string) $row['reason']
        );

        if (! $applied['success']) {
            // Nothing changed: the request goes back to waiting for the dean.
            $this->requests->update($requestId, [
                'status' => OverrideRequestChain::AWAITING_DEAN, 'open_marker' => 1, 'decided_by' => null, 'decided_at' => null, 'decision_reason' => null,
            ]);

            return $this->refused('The grade could not be changed: ' . $applied['message']);
        }

        $context = $this->context((int) $row['offering_id']) ?? [];

        $this->audit('grade.override_approved', $dean, $requestId, $context, ['status' => $row['status']], [
            'status' => OverrideRequestChain::APPROVED, 'requested_percentage' => $row['requested_percentage'], 'student_id' => (int) $row['student_id'], 'comment' => trim($comment),
            // A change to grades the dean had already signed off: recorded as such, for what is handed to the SIS.
            'after_signoff' => (new GradeSignoffService())->isSignedOff((int) $row['offering_id']),
        ]);

        $this->tell(
            (int) $row['requested_by'],
            "grade-override:{$requestId}:approved",
            'Override request approved',
            $this->label($context) . ': the dean approved your request' . ($row['requested_percentage'] === null ? ': the override was removed.' : ': the final grade is now ' . rtrim(rtrim(number_format((float) $row['requested_percentage'], 2), '0'), '.') . '%.'),
            (int) ($context['institution_id'] ?? 0),
            $this->gradebookLink($row, $context),
        );

        return ['success' => true, 'message' => $row['requested_percentage'] === null ? 'Approved. The override was removed.' : 'Approved. The final grade was changed.'];
    }

    /**
     * The dean rejects a request, with the reason the requester will read. The grade is not touched.
     *
     * @return array{success: bool, message: string}
     */
    public function reject(User $dean, int $requestId, string $reason): array
    {
        if (trim($reason) === '') {
            return $this->refused('Say why, so the requester knows what happened.');
        }

        $row = $this->decidable($dean, $requestId);

        if ($row === null) {
            return $this->refused('That request is not waiting for you.');
        }

        if (! $this->claim($requestId, OverrideRequestChain::REJECTED, $dean, $reason)) {
            return $this->refused('That request was just decided or withdrawn.');
        }

        $context = $this->context((int) $row['offering_id']) ?? [];

        $this->audit('grade.override_rejected', $dean, $requestId, $context, ['status' => $row['status']], ['status' => OverrideRequestChain::REJECTED, 'reason' => trim($reason)]);

        $this->tell(
            (int) $row['requested_by'],
            "grade-override:{$requestId}:rejected",
            'Override request rejected',
            $this->label($context) . ': the dean rejected your request: ' . trim($reason),
            (int) ($context['institution_id'] ?? 0),
            $this->gradebookLink($row, $context),
        );

        return ['success' => true, 'message' => 'Rejected. The grade is unchanged.'];
    }

    /**
     * Sends the requests of a department that are past the chairperson to whoever is next now: the dean,
     * or the institution's administrator when the department has no dean (or the dean asked themselves).
     * Run when a dean is assigned or a deanship ends, so a request is never left waiting for someone who
     * is gone: it moves up one level, and moves back when a new dean arrives. Idempotent.
     *
     * @return int how many requests moved
     */
    public function retargetDepartment(int $departmentId): int
    {
        $deanId = $this->deanId($departmentId);

        $rows = db_connect()->table('grade_override_requests r')
            ->select(['r.id', 'r.offering_id', 'r.requested_by', 'r.status'])
            ->join('course_offerings co', 'co.id = r.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->where('sd.department_id', $departmentId)
            ->whereIn('r.status', [OverrideRequestChain::AWAITING_DEAN, OverrideRequestChain::AWAITING_INSTITUTION])
            ->get()->getResultArray();

        $moved = 0;

        foreach ($rows as $row) {
            $next = OverrideRequestChain::afterChair((int) $row['requested_by'], $deanId);

            if ($next === $row['status']) {
                continue;
            }

            $this->requests->update((int) $row['id'], ['status' => $next]);
            $moved++;

            $context = $this->context((int) $row['offering_id']);

            service('auditLogger')->log('grade.override_rerouted', null, [
                'actor_label' => 'system', 'acting_role' => 'system', 'target_type' => 'grade_override_request', 'target_id' => (int) $row['id'],
                'institution_id' => isset($context['institution_id']) ? (int) $context['institution_id'] : null,
                'old' => ['status' => $row['status']], 'new' => ['status' => $next],
            ]);

            if ($context !== null) {
                $this->tellDeanIfWaiting((int) $row['id'], $next, $context, 'rerouted:' . date('YmdHis'));
            }
        }

        return $moved;
    }

    /** How many requests wait for this dean. */
    public function pendingCountForDean(User $dean): int
    {
        return count($this->listForDean($dean, new OverrideApprovalFilters()));
    }

    /**
     * The requests of the dean's department: what waits for them (oldest first), or what was decided
     * (newest first). A dean's own request never waits for them (the chain sends it to the
     * administrator).
     *
     * @return list<array<string, mixed>>
     */
    public function listForDean(User $dean, OverrideApprovalFilters $filters): array
    {
        $department = (new DeanAccessService())->departmentFor($dean);

        if ($department === null) {
            return [];
        }

        $builder = db_connect()->table('grade_override_requests r')
            ->select([
                'r.*', 'c.course_code', 'c.course_title', 'co.section_code', 'p.id AS program_id', 'p.name AS program_name',
                "CONCAT(sp.first_name, ' ', sp.last_name) AS student_name",
                "CONCAT(rp.first_name, ' ', rp.last_name) AS requester_name",
                "CONCAT(ep.first_name, ' ', ep.last_name) AS endorser_name",
                'g.calculated_percentage AS current_calculated', 'g.is_override AS current_is_override', 'g.overridden_percentage AS current_overridden',
            ], false)
            ->join('course_offerings co', 'co.id = r.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('user_profiles sp', 'sp.user_id = r.student_id', 'left')
            ->join('user_profiles rp', 'rp.user_id = r.requested_by', 'left')
            ->join('user_profiles ep', 'ep.user_id = r.endorsed_by', 'left')
            ->join('gradebook_records g', 'g.course_offering_id = r.offering_id AND g.student_id = r.student_id', 'left')
            ->where('sd.department_id', (int) $department['department_id']);

        if ($filters->view === 'decided') {
            $builder->whereIn('r.status', [OverrideRequestChain::APPROVED, OverrideRequestChain::REJECTED]);
        } else {
            $builder->where('r.status', OverrideRequestChain::AWAITING_DEAN)->where('r.requested_by !=', (int) $dean->id);
        }

        if ($filters->programId !== null) {
            $builder->where('p.id', $filters->programId);
        }

        if ($filters->requesterId !== null) {
            $builder->where('r.requested_by', $filters->requesterId);
        }

        if ($filters->q !== null) {
            $literal = db_connect()->escapeLikeString($filters->q);

            $builder->groupStart()
                ->where("CONCAT(sp.first_name, ' ', sp.last_name) LIKE " . db_connect()->escape('%' . $literal . '%') . " ESCAPE '!'", null, false)
                ->orLike('c.course_code', $literal, 'both', true)
                ->orLike('c.course_title', $literal, 'both', true)
                ->groupEnd();
        }

        // Sorted by the chosen column (ties by when it was asked, so the order never jumps between reloads).
        $direction = strtoupper($filters->direction());
        $columns   = match ($filters->sort) {
            'student'   => ['sp.last_name', 'sp.first_name'],
            'course'    => ['c.course_code', 'co.section_code'],
            'requested' => ['r.requested_percentage'],
            'requester' => ['rp.last_name', 'rp.first_name'],
            default     => [$filters->view === 'decided' ? 'r.decided_at' : 'r.created_at'],
        };

        foreach ($columns as $column) {
            $builder->orderBy($column, $direction);
        }

        return $builder->orderBy('r.id', $direction)->limit(100)->get()->getResultArray();
    }

    /**
     * The people who asked, for the Approvals filter.
     *
     * @return list<array{id: int, name: string}>
     */
    public function requestersForDean(User $dean): array
    {
        $department = (new DeanAccessService())->departmentFor($dean);

        if ($department === null) {
            return [];
        }

        return db_connect()->table('grade_override_requests r')
            ->select(['r.requested_by AS id', "CONCAT(rp.first_name, ' ', rp.last_name) AS name"], false)
            ->join('course_offerings co', 'co.id = r.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('user_profiles rp', 'rp.user_id = r.requested_by', 'left')
            ->where('sd.department_id', (int) $department['department_id'])
            ->distinct()->orderBy('name', 'ASC')->get()->getResultArray();
    }

    /**
     * How a student's grade is made up, for the dean to judge a request by: each category (or period)
     * with its percentage, as the instructor's gradebook computes it.
     *
     * @return list<array{name: string, percentage: ?float}>
     */
    public function breakdown(int $offeringId, int $studentId): array
    {
        $engine  = new GradingEngineService();
        $context = $engine->context($offeringId, [$studentId]);

        if ($context === null || ! in_array($studentId, $context['studentIds'], true)) {
            return [];
        }

        $result = $engine->compute($context, $studentId);
        $groups = ! empty($context['scheme']['uses_periods']) && ($result['periods'] ?? []) !== [] ? $result['periods'] : ($result['categories'] ?? []);

        return array_map(static fn (array $group): array => ['name' => (string) $group['name'], 'percentage' => $group['percentage'] === null ? null : (float) $group['percentage']], array_values($groups));
    }

    /** The request, if it waits for this dean: in their department, not their own, acting as dean. */
    private function decidable(User $dean, int $requestId): ?array
    {
        $row = $this->requests->find($requestId);

        if ($row === null || $row['status'] !== OverrideRequestChain::AWAITING_DEAN || (int) $row['requested_by'] === (int) $dean->id) {
            return null;
        }

        if ((new ActiveRoleService())->resolve($dean) !== 'dean' || ! (new DeanAccessService())->canAccessOffering($dean, (int) $row['offering_id'])) {
            return null;
        }

        return $row;
    }

    /**
     * Closes a request that is still waiting for the dean, in one step, so two decisions (or a decision
     * and a withdrawal) cannot both win. False when it was no longer waiting.
     */
    private function claim(int $requestId, string $status, User $dean, string $reason): bool
    {
        $db = db_connect();

        $db->table('grade_override_requests')
            ->where(['id' => $requestId, 'status' => OverrideRequestChain::AWAITING_DEAN])
            ->update([
                'status'          => $status,
                'open_marker'     => null,
                'decided_by'      => (int) $dean->id,
                'decided_at'      => date('Y-m-d H:i:s'),
                'decision_reason' => trim($reason) !== '' ? trim($reason) : null,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

        return $db->affectedRows() === 1;
    }

    /** @param array<string, mixed> $context */
    private function tellDeanIfWaiting(int $requestId, string $status, array $context, string $arrival): void
    {
        if ($status !== OverrideRequestChain::AWAITING_DEAN) {
            return;
        }

        $deanId = $this->deanId((int) $context['department_id']);

        if ($deanId === null) {
            return;
        }

        $this->tell($deanId, "grade-override:{$requestId}:dean:{$arrival}", 'Grade override to decide', $this->label($context) . ': a final-grade override is waiting for your decision.', (int) $context['institution_id'], site_url('dean/approvals'));
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
