<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Assignments\ChairpersonAssignmentModel;
use App\Services\Admin\Impersonation;
use App\Services\Auth\ActiveRoleService;
use App\Services\Courses\DeanAccessService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Parents\ParentAccessService;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\AccountControl;
use CodeIgniter\Shield\Entities\User;

/**
 * Builds the facts MessagingPolicy decides on from the real data (spec 3): who
 * shares a course, a department or a scope, which child links a parent to a
 * teacher, who has blocked whom, and the state of a conversation for one
 * person. It decides nothing itself; the services pass these contexts to the
 * policy.
 *
 * A person acts under their active role. For the signed-in user that is the
 * session's role; for anyone else it is their saved choice, or their
 * preferred role, so that looking at a recipient never changes their session.
 */
class MessagingContextResolver
{
    /** Roles whose block never binds the person they blocked (spec 3.3 rule 6). */
    private const UNBLOCKABLE = ['instructor', 'institutional_admin'];

    public function activeRole(User $user): string
    {
        if (auth()->loggedIn() && (int) auth()->id() === (int) $user->id) {
            return (new ActiveRoleService())->resolve($user);
        }

        $groups = (new ActiveRoleService())->usableGroups($user);
        $saved = db_connect()->table('user_profiles')->select('active_role')->where('user_id', $user->id)->get()->getRowArray()['active_role'] ?? null;

        if (is_string($saved) && in_array($saved, $groups, true)) {
            return $saved;
        }

        return $groups === [] ? '' : ActiveRoleService::preferredRole($groups);
    }

    public function institutionOf(User $user): ?int
    {
        return (new PlanLimits())->institutionOf((int) $user->id);
    }

    /**
     * The facts for $sender starting a thread with $recipient, or null when
     * Messages is closed to the pair: the system administrator on either side,
     * someone with no institution, or an impersonation session.
     */
    public function forStart(User $sender, User $recipient, ?int $subjectStudentId): ?StartContext
    {
        if (Impersonation::active()) {
            return null;
        }

        $senderRole    = $this->activeRole($sender);
        $recipientRole = $this->activeRole($recipient);

        if (in_array($senderRole, ['', 'admin'], true) || in_array($recipientRole, ['', 'admin'], true)) {
            return null;
        }

        $senderInstitution    = $this->institutionOf($sender);
        $recipientInstitution = $this->institutionOf($recipient);

        if ($senderInstitution === null || $recipientInstitution === null) {
            return null;
        }

        $sameInstitution = $senderInstitution === $recipientInstitution;
        $senderId        = (int) $sender->id;
        $recipientId     = (int) $recipient->id;
        $linked          = $sameInstitution && $this->linkedChild($sender, $senderRole, $recipient, $recipientRole, $subjectStudentId);

        return new StartContext(
            sameInstitution: $sameInstitution,
            senderActive: $this->accountActive($senderId),
            recipientActive: $this->accountActive($recipientId),
            sharedCourse: $sameInstitution && $this->sharedCourse($senderId, $recipientId),
            sameDepartment: $sameInstitution && array_intersect($this->departmentIds($sender, $senderRole), $this->departmentIds($recipient, $recipientRole)) !== [],
            inScope: $sameInstitution && $this->inScope($sender, $senderRole, $recipient, $recipientRole),
            linkedChild: $linked,
            studentDmEnabled: true,
            blockedByRecipient: $this->blocked($recipientId, $senderId),
            isSelf: $senderId === $recipientId,
        );
    }

    /** @param array<string, mixed> $conversation a `conversations` row */
    public function forPost(User $user, array $conversation): PostContext
    {
        $userId         = (int) $user->id;
        $conversationId = (int) $conversation['id'];
        $type           = (string) $conversation['type'];
        $sameInst       = $this->institutionOf($user) === (int) $conversation['institution_id'];
        $blocked        = false;
        $exempt         = false;

        if ($type === 'direct') {
            $other = db_connect()->table('conversation_participants')->select('user_id')
                ->where('conversation_id', $conversationId)->where('user_id !=', $userId)->get()->getRowArray();

            if ($other !== null && $this->blocked((int) $other['user_id'], $userId)) {
                $blocked   = true;
                $blocker   = model(\CodeIgniter\Shield\Models\UserModel::class)->findById((int) $other['user_id']);
                $exempt    = $blocker !== null && in_array($this->activeRole($blocker), self::UNBLOCKABLE, true);
            }
        }

        $offeringId = $conversation['offering_id'] === null ? null : (int) $conversation['offering_id'];

        return new PostContext(
            type: $type,
            activeParticipant: $sameInst && $this->isActiveParticipant($userId, $conversationId),
            accountActive: $this->accountActive($userId),
            institutionReadOnly: (new InstitutionAccess())->stateFor($userId) !== InstitutionAccess::WRITABLE,
            offeringClosed: $offeringId !== null && $this->offeringClosed($offeringId),
            locked: (bool) $conversation['is_locked'],
            isCourseInstructor: $offeringId !== null && db_connect()->table('instructor_assignments')
                ->where('offering_id', $offeringId)->where('instructor_user_id', $userId)->countAllResults() > 0,
            blocked: $blocked,
            studentDmBlocked: false,
            blockerIsExempt: $exempt,
        );
    }

    public function isActiveParticipant(int $userId, int $conversationId): bool
    {
        return db_connect()->table('conversation_participants')
            ->where('conversation_id', $conversationId)->where('user_id', $userId)->where('left_at IS NULL', null, false)
            ->countAllResults() > 0;
    }

    private function accountActive(int $userId): bool
    {
        $row = db_connect()->table('users')->select('active')->where('id', $userId)->where('deleted_at IS NULL', null, false)->get()->getRowArray();

        return $row !== null && (int) $row['active'] === 1 && ! (new AccountControl())->isDeactivated($userId);
    }

    private function blocked(int $blockerId, int $blockedId): bool
    {
        return db_connect()->table('user_blocks')->where('blocker_id', $blockerId)->where('blocked_id', $blockedId)->countAllResults() > 0;
    }

    private function offeringClosed(int $offeringId): bool
    {
        $row = db_connect()->table('course_offerings')->select('status')->where('id', $offeringId)->get()->getRowArray();

        return $row === null || $row['status'] !== 'active';
    }

    /** @return list<int> active offerings this person is enrolled in or assigned to teach */
    private function activeOfferingIds(int $userId): array
    {
        $db = db_connect();

        $enrolled = $db->table('enrollments e')->select('e.offering_id')->join('course_offerings co', 'co.id = e.offering_id')
            ->where('e.student_user_id', $userId)->where('e.status', 'enrolled')->where('co.status', 'active')->get()->getResultArray();
        $teaching = $db->table('instructor_assignments ia')->select('ia.offering_id')->join('course_offerings co', 'co.id = ia.offering_id')
            ->where('ia.instructor_user_id', $userId)->where('co.status', 'active')->get()->getResultArray();

        return array_values(array_unique(array_map('intval', array_column([...$enrolled, ...$teaching], 'offering_id'))));
    }

    private function sharedCourse(int $a, int $b): bool
    {
        return array_intersect($this->activeOfferingIds($a), $this->activeOfferingIds($b)) !== [];
    }

    /**
     * Sub-departments a person belongs to: the ones they chair, or the ones
     * their offerings (taught or enrolled, any status) hang under.
     *
     * @return list<int>
     */
    private function subDepartmentIds(User $user, string $role): array
    {
        $id = (int) $user->id;

        if ($role === 'chairperson') {
            return (new ChairpersonAssignmentModel())->subDepartmentIdsFor($id);
        }

        if (! in_array($role, ['student', 'instructor'], true)) {
            return [];
        }

        $builder = db_connect()->table('course_offerings co')->distinct()->select('p.sub_department_id')
            ->join('courses c', 'c.id = co.course_id')->join('programs p', 'p.id = c.program_id');

        if ($role === 'student') {
            $builder->join('enrollments e', 'e.offering_id = co.id')->where('e.student_user_id', $id)->where('e.status', 'enrolled');
        } else {
            $builder->join('instructor_assignments ia', 'ia.offering_id = co.id')->where('ia.instructor_user_id', $id);
        }

        return array_map('intval', array_column($builder->get()->getResultArray(), 'sub_department_id'));
    }

    /** @return list<int> */
    private function departmentIds(User $user, string $role): array
    {
        if ($role === 'dean') {
            return (new DeanAccessService())->departmentIds($user);
        }

        $subs = $this->subDepartmentIds($user, $role);

        if ($subs === []) {
            return [];
        }

        return array_map('intval', array_column(
            db_connect()->table('sub_departments')->select('department_id')->whereIn('id', $subs)->get()->getResultArray(),
            'department_id',
        ));
    }

    private function inScope(User $sender, string $senderRole, User $recipient, string $recipientRole): bool
    {
        if ($senderRole === 'chairperson' && in_array($recipientRole, ['student', 'instructor'], true)) {
            return array_intersect($this->subDepartmentIds($sender, 'chairperson'), $this->subDepartmentIds($recipient, $recipientRole)) !== [];
        }

        if ($senderRole === 'dean' && in_array($recipientRole, ['student', 'instructor', 'chairperson'], true)) {
            return array_intersect($this->departmentIds($sender, 'dean'), $this->departmentIds($recipient, $recipientRole)) !== [];
        }

        return false;
    }

    /**
     * A parent and a teacher of one linked child. With $subjectStudentId the
     * child is that one (it must be linked); without it any linked child counts.
     */
    private function linkedChild(User $sender, string $senderRole, User $recipient, string $recipientRole, ?int $subjectStudentId): bool
    {
        if ($senderRole === 'parent') {
            [$parent, $staff, $staffRole] = [$sender, $recipient, $recipientRole];
        } elseif ($recipientRole === 'parent') {
            [$parent, $staff, $staffRole] = [$recipient, $sender, $senderRole];
        } else {
            return false;
        }

        return $this->sharedLinkedChildIds($parent, $staff, $staffRole, $subjectStudentId) !== [];
    }

    /**
     * The parent's linked children that this instructor teaches (same active
     * offering) or whose sub-department this chairperson chairs; narrowed to
     * $subjectStudentId when given. A thread between them is about one of these.
     *
     * @return list<int>
     */
    public function sharedLinkedChildIds(User $parent, User $staff, string $staffRole, ?int $subjectStudentId = null): array
    {
        if (! in_array($staffRole, ['instructor', 'chairperson'], true)) {
            return [];
        }

        $childIds = array_map('intval', array_column((new ParentAccessService())->children($parent), 'student_id'));

        if ($subjectStudentId !== null) {
            $childIds = in_array($subjectStudentId, $childIds, true) ? [$subjectStudentId] : [];
        }

        $staffSubs = $this->subDepartmentIds($staff, $staffRole);
        $shared    = [];

        foreach ($childIds as $childId) {
            $child = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($childId);

            if ($child === null) {
                continue;
            }

            $match = $staffRole === 'instructor'
                ? array_intersect($this->activeOfferingIds($childId), $this->activeOfferingIds((int) $staff->id)) !== []
                : array_intersect($this->subDepartmentIds($child, 'student'), $staffSubs) !== [];

            if ($match) {
                $shared[] = $childId;
            }
        }

        return $shared;
    }
}
