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
     * The resolver must pass each account's *active role* (not every role it
     * holds), must never call this with an `admin` sender or recipient except
     * to have it refused, and sets StartContext::$isSelf.
     */
    public function canStart(string $senderRole, string $recipientRole, StartContext $c): bool
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
            'sharedCourse'   => $c->sharedCourse,
            'sameCollege' => $c->sameCollege,
            'inScope'        => $c->inScope,
            'linkedChild'    => $c->linkedChild,
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
     * For a `course` chat $role is the person's role in that course (from
     * CourseMembership::roleIn), not their account role.
     */
    public function canModerate(string $role, string $conversationType, bool $inScope, bool $sameInstitution = true): bool
    {
        if (! $sameInstitution) {
            return false;
        }

        if (! in_array($conversationType, ['course', 'group'], true)) {
            return false;
        }

        if ($role === 'institutional_admin') {
            return true;
        }

        return match ($conversationType) {
            'course' => $role === 'instructor' && $inScope,
            'group'  => in_array($role, ['chairperson', 'dean'], true) && $inScope,
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
