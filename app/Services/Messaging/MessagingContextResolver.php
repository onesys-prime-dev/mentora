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
        return $this->parties([$user])[(int) $user->id];
    }

    /**
     * party() for many people in a few queries whatever their number (a search page, an inbox): roles, institution,
     * account state and minor flag are each read once for the lot.
     *
     * @param list<User> $users
     *
     * @return array<int, Party> user id => party
     */
    public function parties(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $db  = db_connect();
        $ids = array_values(array_unique(array_map(static fn (User $u): int => (int) $u->id, $users)));
        $this->primeRoles($users);
        $institutions = (new PlanLimits())->institutionsOf($ids);

        $active = array_flip(array_map('intval', array_column($db->table('users u')->select('u.id')
            ->join('user_account_controls c', 'c.user_id = u.id', 'left')
            ->whereIn('u.id', $ids)->where('u.active', 1)->where('u.deleted_at IS NULL', null, false)->where('c.deactivated_at IS NULL', null, false)
            ->get()->getResultArray(), 'id')));

        // Only a student can be marked a minor (MinorFlag::set), so nobody else costs the lookup.
        $students = array_values(array_filter($ids, fn (int $id): bool => in_array('student', $this->roles[$id], true)));
        $minors   = [];

        foreach ($students === [] ? [] : $db->table('institution_users')->select('user_id, institution_id')->whereIn('user_id', $students)->where('is_minor', 1)->get()->getResultArray() as $row) {
            $minors[(int) $row['user_id']][] = (int) $row['institution_id'];
        }

        $out = [];

        foreach ($ids as $id) {
            $institution = $institutions[$id] ?? null;
            $out[$id]    = new Party($id, $this->roles[$id], isset($active[$id]), $institution, $institution !== null && in_array($institution, $minors[$id] ?? [], true));
        }

        return $out;
    }

    /** @var array<int, list<string>> roles per user id, for this resolver's lifetime (one request) */
    private array $roles = [];

    /**
     * roles() for many people in one query instead of one each.
     *
     * @param list<User> $users
     */
    private function primeRoles(array $users): void
    {
        $missing = array_values(array_filter($users, fn (User $u): bool => ! isset($this->roles[(int) $u->id])));

        if ($missing === []) {
            return;
        }

        $groups = [];

        foreach (db_connect()->table('auth_groups_users')->select('user_id, group')->whereIn('user_id', array_map(static fn (User $u): int => (int) $u->id, $missing))->get()->getResultArray() as $row) {
            $groups[(int) $row['user_id']][] = (string) $row['group'];
        }

        foreach ($missing as $user) {
            $this->roles[(int) $user->id] = $this->usableRoles((int) $user->id, $groups[(int) $user->id] ?? []);
        }
    }

    /**
     * The Shield groups the account may use: all of them, except that a dean with
     * no active assignment loses `dean` while they hold another role. Cheap (no
     * account or institution lookups): use it where only the roles matter.
     *
     * @return list<string>
     */
    public function roles(User $user): array
    {
        // One source for roles (auth_groups_users, as parties() reads them), so the answer never depends on which ran first.
        $this->primeRoles([$user]);

        return $this->roles[(int) $user->id];
    }

    /**
     * Same rule as the auth module's usable-groups check (app/Services/Auth); keep the two in step.
     *
     * @param list<string> $groups
     *
     * @return list<string>
     */
    private function usableRoles(int $userId, array $groups): array
    {
        if (! in_array('dean', $groups, true)) {
            return $groups;
        }

        $others = array_values(array_diff($groups, ['dean']));

        if ($others === [] || (new DeanAssignmentModel())->collegeOfDean($userId) !== null) {
            return $groups;
        }

        return $others;
    }

    public function institutionOf(User $user): ?int
    {
        return (new PlanLimits())->institutionOf((int) $user->id);
    }

    /**
     * The facts for $sender starting a thread with $recipient (FR1), or null when Messages is closed to the pair:
     * the bare system administrator on either side, or someone with no institution. Pass the parties when already
     * built (a search page) so their account and institution lookups are not repeated.
     */
    public function forStart(User $sender, User $recipient, ?int $subjectStudentId, ?Party $senderParty = null, ?Party $recipientParty = null): ?StartContext
    {
        // Beside institution roles `admin` is ignored (ruling R10); only the bare system admin is refused.
        $senderRoles    = array_values(array_diff($this->roles($sender), ['admin']));
        $recipientRoles = array_values(array_diff($this->roles($recipient), ['admin']));

        if ($senderRoles === [] || $recipientRoles === []) {
            return null;
        }

        $senderParty    ??= $this->party($sender);
        $recipientParty ??= $this->party($recipient);
        $senderInstitution    = $senderParty->institutionId;
        $recipientInstitution = $recipientParty->institutionId;

        if ($senderInstitution === null || $recipientInstitution === null) {
            return null;
        }

        $sameInstitution = $senderInstitution === $recipientInstitution;
        $senderId        = (int) $sender->id;
        $recipientId     = (int) $recipient->id;
        $minor           = $sameInstitution && $recipientParty->minor;

        return new StartContext(
            sameInstitution: $sameInstitution,
            senderActive: $senderParty->active,
            recipientActive: $recipientParty->active,
            senderInstitutionWritable: (new InstitutionAccess())->canWrite($senderId),
            impersonating: Impersonation::active(),
            isSelf: $senderId === $recipientId,
            // Read only for a student pair (D3 scope) or a classmate of a minor, so other pairs skip the lookup.
            sharedCourse: $sameInstitution && in_array('student', $senderRoles, true) && in_array('student', $recipientRoles, true) && $this->classmates($senderId, $recipientId),
            // Read only by the minor rule, so only a minor recipient costs the lookups.
            senderTeachesRecipient: $minor && $this->teaches($senderId, $recipientId),
            senderLeadsRecipient: $minor && $this->leads($sender, $senderRoles, $recipient),
            linkedChildAsSenderParent: $sameInstitution && $this->linkedChild($sender, $senderRoles, $subjectStudentId),
            linkedChildAsRecipientParent: $sameInstitution && $this->linkedChild($recipient, $recipientRoles, $subjectStudentId),
            studentScope: $this->studentSwitch()->scope($senderInstitution),
            blockedByRecipient: $this->blockBinds($recipientId, $senderId),
            senderRestriction: $this->restrictionKind($senderId),
            recipientMinor: $minor,
        );
    }

    /**
     * @param array<string, mixed>       $conversation a `conversations` row
     * @param array{User, Party}|null    $other        a direct thread's other person and party, when the caller already
     *                                                 has them (an inbox page), so they are not looked up per row
     */
    public function forPost(User $user, array $conversation, ?Party $userParty = null, ?array $other = null): PostContext
    {
        $userId         = (int) $user->id;
        $conversationId = (int) $conversation['id'];
        $type           = (string) $conversation['type'];
        $sameInst       = $this->institutionOf($user) === (int) $conversation['institution_id'];
        $blocked        = false;
        $lost           = ['scope' => false, 'minor' => false];
        $deactivated    = false;

        if ($type === 'direct') {
            $otherId = $other === null ? null : (int) $other[0]->id;

            if ($other === null) {
                $row     = db_connect()->table('conversation_participants')->select('user_id')
                    ->where('conversation_id', $conversationId)->where('user_id !=', $userId)->get()->getRowArray();
                $otherId = $row === null ? null : (int) $row['user_id'];
                $found   = $otherId === null ? null : model(\CodeIgniter\Shield\Models\UserModel::class)->findById($otherId);
                $other   = $found === null ? null : [$found, $this->party($found)];
            }

            if ($otherId !== null) {
                $blocked     = $this->blockBinds($otherId, $userId);
                $deactivated = $other === null || ! $other[1]->active;

                // A parent thread names a child and follows its own link rule (subjectLinkLost).
                if ($other !== null && ($conversation['subject_student_id'] ?? null) === null) {
                    $lost = $this->pairLost($user, $userParty ?? $this->party($user), $other[0], $other[1], $conversation);
                }
            }
        }

        $offeringId = $conversation['offering_id'] === null ? null : (int) $conversation['offering_id'];

        return new PostContext(
            type: $type,
            activeParticipant: $sameInst && $this->isActiveParticipant($userId, $conversationId),
            accountActive: $userParty !== null ? $userParty->active : $this->accountActive($userId),
            institutionReadOnly: (new InstitutionAccess())->stateFor($userId) !== InstitutionAccess::WRITABLE,
            offeringClosed: $offeringId !== null && $this->offeringClosed($offeringId),
            locked: (bool) $conversation['is_locked'],
            isCourseInstructor: $offeringId !== null && db_connect()->table('instructor_assignments')
                ->where('offering_id', $offeringId)->where('instructor_user_id', $userId)->countAllResults() > 0,
            blocked: $blocked,
            scopeLost: $lost['scope'],
            minorLost: $lost['minor'],
            deactivatedOther: $deactivated,
            subjectLinkLost: $type === 'direct' && $this->linkLost($conversation),
            restriction: $this->restrictionKind($userId),
            closed: ($conversation['closed_at'] ?? null) !== null,
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
     * Whether a direct thread without a child still qualifies (FR2): a student pair must stay inside the
     * institution's scope (D3), and a minor recipient must stay reachable by whoever started the thread (D9;
     * a thread the minor started is theirs). With no known starter both directions are checked.
     * The course and minor lookups run only when the scope or a minor flag asks for them.
     *
     * @param array<string, mixed> $conversation a `conversations` row
     *
     * @return array{scope: bool, minor: bool}
     */
    private function pairLost(User $user, Party $userParty, User $other, Party $otherParty, array $conversation): array
    {
        $scopeLost = false;

        if ($userParty->capacity(false) === 'student' && $otherParty->capacity(false) === 'student') {
            $scope     = $this->studentSwitch()->scope((int) $conversation['institution_id']);
            $scopeLost = $scope === 'off' || ($scope === 'course' && ! $this->classmates((int) $user->id, (int) $other->id));
        }

        $minorLost = false;
        $starter   = (int) ($conversation['created_by'] ?? 0);

        foreach ([[$user, $userParty, $other, $otherParty], [$other, $otherParty, $user, $userParty]] as [$sender, $senderParty, $minor, $minorParty]) {
            if ($minorParty->minor && $starter !== (int) $minor->id && ! (new MessagingPolicy())->reachesMinor($senderParty, new StartContext(
                sharedCourse: in_array('student', $senderParty->roles, true) && $this->classmates((int) $sender->id, (int) $minor->id),
                senderTeachesRecipient: $this->teaches((int) $sender->id, (int) $minor->id),
                senderLeadsRecipient: $this->leads($sender, $senderParty->roles, $minor),
            ))) {
                $minorLost = true;
            }
        }

        return ['scope' => $scopeLost, 'minor' => $minorLost];
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
     * A thread between a parent and a staff member is about one of the parent's linked children (D2). Any other
     * pair is about nobody in particular. Null when the pair is not about a child, else the children it could be
     * about (each parent side's linked children; both directions for two staff who are also parents).
     *
     * @return list<int>|null
     */
    public function childSubjects(User $a, Party $pa, User $b, Party $pb): ?array
    {
        $about = false;
        $ids   = [];

        foreach ([[$a, $pa, $pb], [$b, $pb, $pa]] as [$parent, $parentParty, $staffParty]) {
            if ($parentParty->isParent() && $staffParty->isStaff()) {
                $about = true;
                $ids   = [...$ids, ...$this->childIds($parent)];
            }
        }

        return $about ? array_values(array_unique($ids)) : null;
    }

    /** @return list<int> the parent's linked children in their institution */
    private function childIds(User $parent): array
    {
        return array_map('intval', array_column((new ParentAccessService())->children($parent), 'student_id'));
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

        if ($thread > 0 || $this->shareActiveOffering($a, $b)) {
            return true;
        }

        // Only a parent can have the child link, so a pair with no parent stops here without loading either account.
        if ($db->table('auth_groups_users')->whereIn('user_id', [$a, $b])->where('group', 'parent')->countAllResults() === 0) {
            return false;
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

    /** activeOfferingIds() of the two intersect, in one query. */
    private function shareActiveOffering(int $a, int $b): bool
    {
        $of = static fn (int $id): string => "SELECT e.offering_id FROM enrollments e JOIN course_offerings co ON co.id = e.offering_id WHERE e.student_user_id = {$id} AND e.status = 'enrolled' AND co.status = 'active'"
            . " UNION SELECT ia.offering_id FROM instructor_assignments ia JOIN course_offerings co ON co.id = ia.offering_id WHERE ia.instructor_user_id = {$id} AND co.status = 'active'";

        return db_connect()->query("SELECT 1 FROM ({$of($a)}) x JOIN ({$of($b)}) y ON y.offering_id = x.offering_id LIMIT 1")->getRowArray() !== null;
    }

    /**
     * SQL true when $person (a column) has a block on $sender that blockBinds() would honour. Restates blockBinds
     * for the recipient search, so a page does not test every blocker one by one; keep the two in step.
     */
    public function bindingBlockSql(int $sender, string $person): string
    {
        return "EXISTS (SELECT 1 FROM user_blocks ub WHERE ub.blocker_id = {$person} AND ub.blocked_id = {$sender}"
            . " AND NOT EXISTS (SELECT 1 FROM auth_groups_users bg WHERE bg.user_id = {$person} AND bg.`group` = 'institutional_admin')"
            . " AND NOT {$this->teachesSql((string) $sender, $person)} AND NOT {$this->teachesSql($person, (string) $sender)})";
    }

    /**
     * SQL true when $sender may reach $person (a column) were $person a minor (D9, MessagingPolicy::reachesMinor):
     * teaches them, is a classmate, or chairs or deans their home program's department or college. Null when nothing
     * narrows (the institution's admin). Restates teaches(), classmates() and leads() for the recipient search.
     */
    public function minorReachSql(User $sender, Party $senderParty, string $person): ?string
    {
        $roles = $senderParty->roles;

        if (in_array('institutional_admin', $roles, true)) {
            return null;
        }

        $id    = (int) $sender->id;
        $reach = [$this->teachesSql((string) $id, $person)];

        if (in_array('student', $roles, true)) {
            $reach[] = "EXISTS (SELECT 1 FROM enrollments ma JOIN enrollments mb ON mb.offering_id = ma.offering_id JOIN course_offerings mco ON mco.id = ma.offering_id"
                . " WHERE mco.status = 'active' AND ma.status = 'enrolled' AND mb.status = 'enrolled' AND ma.student_user_id = {$id} AND mb.student_user_id = {$person})";
        }

        // As homeDepartmentIds(): the department of their home program.
        $studies = static fn (string $where): string => "EXISTS (SELECT 1 FROM user_profiles mup JOIN programs mp ON mp.id = mup.program_id"
            . " JOIN departments md ON md.id = mp.department_id WHERE mup.user_id = {$person} AND {$where})";
        $departments = in_array('chairperson', $roles, true) ? $this->departmentIds($sender, 'chairperson') : [];
        $colleges    = in_array('dean', $roles, true) ? $this->collegeIds($sender, 'dean') : [];

        if ($departments !== []) {
            $reach[] = $studies('md.id IN (' . implode(', ', array_map('intval', $departments)) . ')');
        }

        if ($colleges !== []) {
            $reach[] = $studies('md.college_id IN (' . implode(', ', array_map('intval', $colleges)) . ')');
        }

        return '(' . implode(' OR ', $reach) . ')';
    }

    /** teaches() as SQL over two columns or ids. */
    private function teachesSql(string $instructor, string $student): string
    {
        return "EXISTS (SELECT 1 FROM instructor_assignments tia JOIN enrollments te ON te.offering_id = tia.offering_id JOIN course_offerings tco ON tco.id = tia.offering_id"
            . " WHERE tco.status = 'active' AND te.status = 'enrolled' AND tia.instructor_user_id = {$instructor} AND te.student_user_id = {$student})";
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

    /** Both are enrolled as students in one active offering. */
    private function classmates(int $a, int $b): bool
    {
        return db_connect()->table('enrollments ea')
            ->join('enrollments eb', 'eb.offering_id = ea.offering_id')->join('course_offerings co', 'co.id = ea.offering_id')
            ->where('co.status', 'active')->where('ea.status', 'enrolled')->where('eb.status', 'enrolled')
            ->where('ea.student_user_id', $a)->where('eb.student_user_id', $b)
            ->countAllResults() > 0;
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

    /**
     * The sender chairs the department of the recipient's home program, or is dean of its college. The home program
     * (user_profiles.program_id), not the courses they take, is "their department" (M5 ruling); none, nobody leads them.
     *
     * @param list<string> $senderRoles
     */
    private function leads(User $sender, array $senderRoles, User $recipient): bool
    {
        $chairs = in_array('chairperson', $senderRoles, true);
        $deans  = in_array('dean', $senderRoles, true);

        if (! $chairs && ! $deans) {
            return false;
        }

        $home = db_connect()->table('user_profiles up')->select('d.id, d.college_id')
            ->join('programs p', 'p.id = up.program_id')->join('departments d', 'd.id = p.department_id')
            ->where('up.user_id', (int) $recipient->id)->get()->getRowArray();

        if ($home === null) {
            return false;
        }

        return ($chairs && in_array((int) $home['id'], $this->departmentIds($sender, 'chairperson'), true))
            || ($deans && in_array((int) $home['college_id'], $this->collegeIds($sender, 'dean'), true));
    }

    /**
     * $parent holds the parent role and is linked to the child the thread names. False when no child is named.
     *
     * @param list<string> $parentRoles
     */
    private function linkedChild(User $parent, array $parentRoles, ?int $subjectStudentId): bool
    {
        return $subjectStudentId !== null && in_array('parent', $parentRoles, true) && in_array($subjectStudentId, $this->childIds($parent), true);
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

        $childIds = $this->childIds($parent);

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
