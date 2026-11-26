<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Assignments\ChairpersonAssignmentModel;
use App\Models\Assignments\DeanAssignmentModel;
use App\Services\Admin\Impersonation;
use App\Services\Courses\DeanAccessService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Parents\ParentAccessService;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\AccountControl;
use CodeIgniter\Shield\Entities\User;

/**
 * Builds the facts MessagingPolicy decides on from the real data (spec 3): who
 * shares a course, a college or a scope, which child links a parent to a
 * teacher, who has blocked whom, and the state of a conversation for one
 * person. It decides nothing itself; the services pass these contexts to the
 * policy.
 */
class MessagingContextResolver
{
    /** Every role the account may use, as Messaging decides on it (no active role involved). */
    public function party(User $user): Party
    {
        return new Party((int) $user->id, $this->roles($user), $this->accountActive((int) $user->id), $this->institutionOf($user));
    }

    /** @var array<int, list<string>> roles per user id, for this resolver's lifetime (one request) */
    private array $roles = [];

    /**
     * The Shield groups the account may use: all of them, except that a dean with
     * no active assignment loses `dean` while they hold another role. Cheap (no
     * account or institution lookups): use it where only the roles matter.
     *
     * @return list<string>
     */
    public function roles(User $user): array
    {
        return $this->roles[(int) $user->id] ??= $this->heldRoles($user);
    }

    /**
     * Same rule as the auth module's usable-groups check (app/Services/Auth); keep the two in step.
     *
     * @return list<string>
     */
    private function heldRoles(User $user): array
    {
        $groups = array_values(array_filter($user->getGroups() ?? [], 'is_string'));

        if (! in_array('dean', $groups, true)) {
            return $groups;
        }

        $others = array_values(array_diff($groups, ['dean']));

        if ($others === [] || (new DeanAssignmentModel())->collegeOfDean((int) $user->id) !== null) {
            return $groups;
        }

        return $others;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<int>
     */
    private function unionColleges(User $user, array $roles): array
    {
        $ids = [];

        foreach ($roles as $role) {
            if ($role !== 'parent') {
                $ids = [...$ids, ...$this->collegeIds($user, $role)];
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $senderRoles
     * @param list<string> $recipientRoles
     */
    private function anyInScope(User $sender, array $senderRoles, User $recipient, array $recipientRoles): bool
    {
        foreach ($senderRoles as $senderRole) {
            foreach ($recipientRoles as $recipientRole) {
                if ($this->inScope($sender, $senderRole, $recipient, $recipientRole)) {
                    return true;
                }
            }
        }

        return false;
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

        // Beside institution roles `admin` is ignored (ruling R10); only the bare system admin is refused.
        $senderRoles    = array_values(array_diff($this->roles($sender), ['admin']));
        $recipientRoles = array_values(array_diff($this->roles($recipient), ['admin']));

        if ($senderRoles === [] || $recipientRoles === []) {
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

        return new StartContext(
            sameInstitution: $sameInstitution,
            senderActive: $this->accountActive($senderId),
            recipientActive: $this->accountActive($recipientId),
            sharedCourse: $sameInstitution && $this->sharedCourse($senderId, $recipientId),
            sameCollege: $sameInstitution && array_intersect($this->unionColleges($sender, $senderRoles), $this->unionColleges($recipient, $recipientRoles)) !== [],
            inScope: $sameInstitution && $this->anyInScope($sender, $senderRoles, $recipient, $recipientRoles),
            linkedChildAsSenderParent: $sameInstitution && $this->linkedChild($sender, $senderRoles, $recipient, $recipientRoles, $subjectStudentId),
            linkedChildAsRecipientParent: $sameInstitution && $this->linkedChild($recipient, $recipientRoles, $sender, $senderRoles, $subjectStudentId),
            studentDmEnabled: ! $sameInstitution || $this->studentSwitch()->enabled($senderInstitution),
            blockedByRecipient: $this->blockBinds($recipientId, $senderId),
            isSelf: $senderId === $recipientId,
            senderRestriction: $this->restrictionKind($senderId),
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

        if ($type === 'direct') {
            $other = db_connect()->table('conversation_participants')->select('user_id')
                ->where('conversation_id', $conversationId)->where('user_id !=', $userId)->get()->getRowArray();

            $blocked = $other !== null && $this->blockBinds((int) $other['user_id'], $userId);
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
            studentDmBlocked: $type === 'direct' && $this->studentDmBlocked($conversation),
            subjectLinkLost: $type === 'direct' && $this->linkLost($conversation),
            restriction: $this->restrictionKind($userId),
        );
    }

    /** @var array<int, string> each person's restriction kind, read once per resolver (a list or a search asks per row) */
    private array $restrictionKinds = [];

    public function restrictionKind(int $userId): string
    {
        return $this->restrictionKinds[$userId] ??= (new RestrictionService())->kindFor($userId);
    }

    private ?StudentMessaging $studentSwitch = null;

    private function studentSwitch(): StudentMessaging
    {
        return $this->studentSwitch ??= new StudentMessaging();
    }

    /**
     * A student-to-student thread while the institution has that switched off: still readable, nobody posts.
     * The switch is read first, so the usual case (on) costs one settings read and no participant lookups.
     *
     * @param array<string, mixed> $conversation a `conversations` row
     */
    private function studentDmBlocked(array $conversation): bool
    {
        if ($this->studentSwitch()->enabled((int) $conversation['institution_id'])) {
            return false;
        }

        $ids = array_map('intval', array_column(
            db_connect()->table('conversation_participants')->select('user_id')->where('conversation_id', (int) $conversation['id'])->get()->getResultArray(),
            'user_id',
        ));

        if (count($ids) !== 2) {
            return false;
        }

        foreach ($ids as $id) {
            $person = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($id);

            if ($person === null || ! $this->party($person)->isStudent()) {
                return false;
            }
        }

        return true;
    }

    /**
     * A parent thread is about one child. Once no participant is linked to that child any more, nobody
     * should be posting into it: the other side would be writing about a child to someone who may not see them.
     *
     * @param array<string, mixed> $conversation a `conversations` row
     */
    private function linkLost(array $conversation): bool
    {
        if (($conversation['subject_student_id'] ?? null) === null) {
            return false;
        }

        $db = db_connect();
        $participants = array_map('intval', array_column(
            $db->table('conversation_participants')->select('user_id')->where('conversation_id', (int) $conversation['id'])->get()->getResultArray(),
            'user_id',
        ));

        return $participants === [] || $db->table('parent_student_links')
            ->where('institution_id', (int) $conversation['institution_id'])->where('student_user_id', (int) $conversation['subject_student_id'])
            ->whereIn('parent_user_id', $participants)->countAllResults() === 0;
    }

    /**
     * A thread between a parent and an instructor or chairperson is about one of the parent's children. Any
     * other pair that includes a parent (an institutional administrator writing to them, say) is about nobody
     * in particular. Null when the pair is not about a child, else the children they share (any role held).
     *
     * @return list<int>|null
     */
    public function childSubjects(User $a, Party $pa, User $b, Party $pb): ?array
    {
        $about = false;
        $ids   = [];

        foreach ([[$a, $pa, $b, $pb], [$b, $pb, $a, $pa]] as [$parent, $parentParty, $staff, $staffParty]) {
            if (! $parentParty->isParent()) {
                continue;
            }

            foreach (['instructor', 'chairperson'] as $role) {
                if (in_array($role, $staffParty->roles, true)) {
                    $about = true;
                    $ids   = [...$ids, ...$this->sharedLinkedChildIds($parent, $staff, $role)];
                }
            }
        }

        return $about ? array_values(array_unique($ids)) : null;
    }

    public function isCourseInstructor(int $userId, int $offeringId): bool
    {
        return db_connect()->table('instructor_assignments')
            ->where('offering_id', $offeringId)->where('instructor_user_id', $userId)->countAllResults() > 0;
    }

    public function isActiveParticipant(int $userId, int $conversationId): bool
    {
        return db_connect()->table('conversation_participants')
            ->where('conversation_id', $conversationId)->where('user_id', $userId)->where('left_at IS NULL', null, false)
            ->countAllResults() > 0;
    }

    public function accountActive(int $userId): bool
    {
        $row = db_connect()->table('users')->select('active')->where('id', $userId)->where('deleted_at IS NULL', null, false)->get()->getRowArray();

        return $row !== null && (int) $row['active'] === 1 && ! (new AccountControl())->isDeactivated($userId);
    }

    /**
     * Whether a block row shuts $blockedId out of $blockerId. Never binds when the blocker holds
     * institutional_admin, or while the pair is in a protected link (computed live; the row stays).
     */
    public function blockBinds(int $blockerId, int $blockedId): bool
    {
        if (db_connect()->table('user_blocks')->where('blocker_id', $blockerId)->where('blocked_id', $blockedId)->countAllResults() === 0) {
            return false;
        }

        $blocker = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($blockerId);

        return $blocker !== null && ! in_array('institutional_admin', $this->roles($blocker), true) && ! $this->protectedLink($blockerId, $blockedId);
    }

    /**
     * Whether two people already have a relationship, so a first message is not a cold contact: an earlier direct
     * thread with a message in it, an active offering they share (teaching or enrolled), or a parent and the
     * staff of that parent's linked child.
     */
    public function talkedBefore(int $a, int $b): bool
    {
        $db = db_connect();

        $thread = $db->table('conversations c')
            ->join('conversation_participants pa', 'pa.conversation_id = c.id AND pa.user_id = ' . $a, 'inner', false)
            ->join('conversation_participants pb', 'pb.conversation_id = c.id AND pb.user_id = ' . $b, 'inner', false)
            ->where('c.type', 'direct')->where('c.last_message_id IS NOT NULL', null, false)->countAllResults();

        if ($thread > 0 || array_intersect($this->activeOfferingIds($a), $this->activeOfferingIds($b)) !== []) {
            return true;
        }

        $users = model(\CodeIgniter\Shield\Models\UserModel::class);
        $ua    = $users->findById($a);
        $ub    = $users->findById($b);

        if ($ua === null || $ub === null) {
            return false;
        }

        foreach ([[$ua, $ub], [$ub, $ua]] as [$parent, $staff]) {
            if (in_array('parent', $this->roles($parent), true)) {
                foreach ($this->roles($staff) as $role) {
                    if ($this->sharedLinkedChildIds($parent, $staff, $role) !== []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** One of the two is an assigned instructor of an active offering the other is enrolled in. */
    public function protectedLink(int $a, int $b): bool
    {
        return $this->teaches($a, $b) || $this->teaches($b, $a);
    }

    /** $instructor is assigned to an active offering $student is enrolled in. */
    public function teaches(int $instructor, int $student): bool
    {
        return db_connect()->table('instructor_assignments ia')
            ->join('enrollments e', 'e.offering_id = ia.offering_id')->join('course_offerings co', 'co.id = ia.offering_id')
            ->where('co.status', 'active')->where('e.status', 'enrolled')
            ->where('ia.instructor_user_id', $instructor)->where('e.student_user_id', $student)
            ->countAllResults() > 0;
    }

    private function offeringClosed(int $offeringId): bool
    {
        $row = db_connect()->table('course_offerings')->select('status')->where('id', $offeringId)->get()->getRowArray();

        return $row === null || $row['status'] !== 'active';
    }

    /** @return list<int> active offerings this person is enrolled in or assigned to teach */
    public function activeOfferingIds(int $userId): array
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
     * Departments a person belongs to: the ones they chair, or the ones
     * their offerings (taught or enrolled, any status) hang under.
     *
     * @return list<int>
     */
    private function departmentIds(User $user, string $role): array
    {
        $id = (int) $user->id;

        if ($role === 'chairperson') {
            return (new ChairpersonAssignmentModel())->departmentIdsFor($id);
        }

        if (! in_array($role, ['student', 'instructor'], true)) {
            return [];
        }

        $builder = db_connect()->table('course_offerings co')->distinct()->select('p.department_id')
            ->join('courses c', 'c.id = co.course_id')->join('programs p', 'p.id = c.program_id');

        if ($role === 'student') {
            $builder->join('enrollments e', 'e.offering_id = co.id')->where('e.student_user_id', $id)->where('e.status', 'enrolled');
        } else {
            $builder->join('instructor_assignments ia', 'ia.offering_id = co.id')->where('ia.instructor_user_id', $id);
        }

        return array_map('intval', array_column($builder->get()->getResultArray(), 'department_id'));
    }

    /** @return list<int> */
    private function collegeIds(User $user, string $role): array
    {
        if ($role === 'dean') {
            return (new DeanAccessService())->collegeIds($user);
        }

        $departments = $this->departmentIds($user, $role);

        if ($departments === []) {
            return [];
        }

        return array_map('intval', array_column(
            db_connect()->table('departments')->select('college_id')->whereIn('id', $departments)->get()->getResultArray(),
            'college_id',
        ));
    }

    private function inScope(User $sender, string $senderRole, User $recipient, string $recipientRole): bool
    {
        if ($senderRole === 'chairperson' && in_array($recipientRole, ['student', 'instructor'], true)) {
            return array_intersect($this->departmentIds($sender, 'chairperson'), $this->departmentIds($recipient, $recipientRole)) !== [];
        }

        if ($senderRole === 'dean' && in_array($recipientRole, ['student', 'instructor', 'chairperson'], true)) {
            return array_intersect($this->collegeIds($sender, 'dean'), $this->collegeIds($recipient, $recipientRole)) !== [];
        }

        return false;
    }

    /**
     * $parent, as a parent, and $staff as the teacher or chairperson of one of
     * $parent's linked children. One direction only. With $subjectStudentId the
     * child is that one (it must be linked); without it any linked child counts.
     *
     * @param list<string> $parentRoles
     * @param list<string> $staffRoles
     */
    private function linkedChild(User $parent, array $parentRoles, User $staff, array $staffRoles, ?int $subjectStudentId): bool
    {
        if (! in_array('parent', $parentRoles, true)) {
            return false;
        }

        foreach ($staffRoles as $staffRole) {
            if ($this->sharedLinkedChildIds($parent, $staff, $staffRole, $subjectStudentId) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parent's linked children that this instructor teaches (same active
     * offering) or whose department this chairperson chairs; narrowed to
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

        $staffDepartments = $this->departmentIds($staff, $staffRole);
        $shared    = [];

        foreach ($childIds as $childId) {
            $child = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($childId);

            if ($child === null) {
                continue;
            }

            $match = $staffRole === 'instructor'
                ? array_intersect($this->activeOfferingIds($childId), $this->activeOfferingIds((int) $staff->id)) !== []
                : array_intersect($this->departmentIds($child, 'student'), $staffDepartments) !== [];

            if ($match) {
                $shared[] = $childId;
            }
        }

        return $shared;
    }
}
