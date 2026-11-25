<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Every Messages permission decision lives here (spec section 3). Pure logic:
 * the caller passes in the facts, this class never reads the database.
 */
final class MessagingPolicy
{
    private const NOTHING = 'nothing';

    /**
     * Spec 3.1: sender => recipient => the context fact that unlocks the
     * cell. A pair that is absent is refused (this includes every use of the
     * system `admin` role). Student -> student also needs the institution's
     * studentDmEnabled switch, checked in canStart().
     */
    private const START = [
        'student' => [
            'student'    => 'sharedCourse',
            'instructor' => 'sharedCourse',
        ],
        'instructor' => [
            'student'     => 'sharedCourse',
            'instructor'  => 'sameCollege',
            'chairperson' => 'sameCollege',
            'dean'        => 'sameCollege',
            'parent'      => 'linkedChild',
        ],
        'chairperson' => [
            'student'     => 'inScope',
            'instructor'  => 'inScope',
            'chairperson' => 'sameCollege',
            'dean'        => 'sameCollege',
        ],
        'dean' => [
            'student'     => 'inScope',
            'instructor'  => 'inScope',
            'chairperson' => 'inScope',
            'dean'        => self::NOTHING,
        ],
        'parent' => [
            'instructor'  => 'linkedChild',
            'chairperson' => 'linkedChild',
        ],
        'institutional_admin' => [
            'student'             => self::NOTHING,
            'instructor'          => self::NOTHING,
            'chairperson'         => self::NOTHING,
            'dean'                => self::NOTHING,
            'parent'              => self::NOTHING,
            'institutional_admin' => self::NOTHING,
        ],
    ];

    /** Recipients a block cannot shut out (spec 3.3 rule 6). */
    private const UNBLOCKABLE = ['instructor', 'institutional_admin'];

    /**
     * Transitional: the parties pick a capacity for the thread, then any pair of held roles valid for it
     * that passes the START table with the facts allows the start. The resolver refuses the system `admin`,
     * impersonation and no-institution (no context); the system admin is refused here too.
     */
    public function canStart(Party $sender, Party $recipient, ?int $subjectStudentId, StartContext $c): bool
    {
        if ($sender->isSystemAdmin() || $recipient->isSystemAdmin()) {
            return false;
        }

        if ($subjectStudentId !== null) {
            // Exactly one side speaks as the parent, the other holds a staff role; try both orientations.
            return ($sender->isParent() && $recipient->isStaff() && $this->anyPair(['parent'], array_intersect($recipient->roles, Party::STAFF_ROLES), $c))
                || ($recipient->isParent() && $sender->isStaff() && $this->anyPair(array_intersect($sender->roles, Party::STAFF_ROLES), ['parent'], $c));
        }

        // The institution's administrator may write to a parent outside a child thread; only that one pair.
        if (in_array('institutional_admin', $sender->roles, true) && $recipient->isParent() && $this->roleStart('institutional_admin', 'parent', $c)) {
            return true;
        }

        if ($sender->capacity(false) === null || $recipient->capacity(false) === null) {
            return false;
        }

        return $this->anyPair(array_diff($sender->roles, ['parent']), array_diff($recipient->roles, ['parent']), $c);
    }

    /**
     * @param list<string> $senderRoles
     * @param list<string> $recipientRoles
     */
    private function anyPair(array $senderRoles, array $recipientRoles, StartContext $c): bool
    {
        foreach ($senderRoles as $senderRole) {
            foreach ($recipientRoles as $recipientRole) {
                if ($this->roleStart($senderRole, $recipientRole, $c)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function roleStart(string $senderRole, string $recipientRole, StartContext $c): bool
    {
        $requirement = self::START[$senderRole][$recipientRole] ?? null;

        if ($requirement === null) {
            return false;
        }

        if ($c->isSelf) {
            return false;
        }

        if (! $c->sameInstitution || ! $c->senderActive || ! $c->recipientActive) {
            return false;
        }

        if ($c->blockedByRecipient && ! in_array($recipientRole, self::UNBLOCKABLE, true)) {
            return false;
        }

        if ($senderRole === 'student' && $recipientRole === 'student' && ! $c->studentDmEnabled) {
            return false;
        }

        $facts = [
            'sharedCourse' => $c->sharedCourse,
            'sameCollege'  => $c->sameCollege,
            'inScope'      => $c->inScope,
            // Each orientation reads the fact for its own parent side.
            'linkedChild'  => $senderRole === 'parent' ? $c->linkedChildAsSenderParent : $c->linkedChildAsRecipientParent,
        ];

        return $requirement === self::NOTHING || $facts[$requirement];
    }

    /**
     * Takes no role: the resolver must refuse the system `admin` and
     * impersonation sessions before calling this.
     */
    public function canPost(PostContext $c): bool
    {
        if (! $c->activeParticipant || ! $c->accountActive || $c->institutionReadOnly || $c->offeringClosed || $c->subjectLinkLost) {
            return false;
        }

        if ($c->type === 'course' && $c->locked && ! $c->isCourseInstructor) {
            return false;
        }

        if ($c->type === 'direct' && (($c->blocked && ! $c->blockerIsExempt) || $c->studentDmBlocked)) {
            return false;
        }

        return true;
    }

    /**
     * Takes no role: the resolver must refuse the system `admin` and
     * impersonation sessions before calling this.
     */
    public function canView(bool $activeParticipant, bool $sameInstitution): bool
    {
        return $activeParticipant && $sameInstitution;
    }

    /**
     * $inScope is "instructor of that course" for a course chat, "created or
     * leads that staff group" for a group. Direct threads are never moderated.
     * Any held role counts (the caller adds `instructor` for someone who teaches the course); the
     * system admin never moderates.
     */
    public function canModerate(Party $actor, string $conversationType, bool $inScope, bool $sameInstitution = true): bool
    {
        if (! $sameInstitution || $actor->isSystemAdmin()) {
            return false;
        }

        if (! in_array($conversationType, ['course', 'group'], true)) {
            return false;
        }

        if (in_array('institutional_admin', $actor->roles, true)) {
            return true;
        }

        return match ($conversationType) {
            'course' => in_array('instructor', $actor->roles, true) && $inScope,
            'group'  => array_intersect(['chairperson', 'dean'], $actor->roles) !== [] && $inScope,
        };
    }

    /**
     * Who may change a chat's controls (reactions, replies, forwarding, bump, delete for everyone, the lock).
     * Only the institution's administrator, for a course chat or staff group of their own institution; direct
     * threads have no controls. The other parameters stay so a caller states every fact; none of them matters.
     */
    public function canManageControls(
        string $role,
        string $type,
        bool $teachesCourse,
        bool $inScope,
        bool $createdGroup,
        bool $sameInstitution,
    ): bool {
        if (! $sameInstitution || ! in_array($type, ['course', 'group'], true)) {
            return false;
        }

        return $role === 'institutional_admin';
    }

    /** A course chat pins by moderators only; direct and group chats by anyone in them. */
    public function canPinMessage(string $type, bool $isModerator): bool
    {
        return $type === 'course' ? $isModerator : true;
    }

    /** Like canPost, but a lock does not stop a reaction (it is not a post). */
    public function canReact(PostContext $c): bool
    {
        if (! $c->activeParticipant || ! $c->accountActive || $c->institutionReadOnly || $c->offeringClosed || $c->subjectLinkLost) {
            return false;
        }

        if ($c->type === 'direct' && (($c->blocked && ! $c->blockerIsExempt) || $c->studentDmBlocked)) {
            return false;
        }

        return true;
    }

    public function canSeeParentFeed(bool $linked, bool $childEnrolled, string $senderRole): bool
    {
        return $linked && $childEnrolled && $senderRole === 'instructor';
    }
}
