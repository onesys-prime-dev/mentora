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
            'instructor'  => 'sameDepartment',
            'chairperson' => 'sameDepartment',
            'dean'        => 'sameDepartment',
            'parent'      => 'linkedChild',
        ],
        'chairperson' => [
            'student'     => 'inScope',
            'instructor'  => 'inScope',
            'chairperson' => 'sameDepartment',
            'dean'        => 'sameDepartment',
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

    public function canStart(string $senderRole, string $recipientRole, StartContext $c): bool
    {
        $requirement = self::START[$senderRole][$recipientRole] ?? null;

        if ($requirement === null) {
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

        return match ($requirement) {
            self::NOTHING    => true,
            'sharedCourse'   => $c->sharedCourse,
            'sameDepartment' => $c->sameDepartment,
            'inScope'        => $c->inScope,
            'linkedChild'    => $c->linkedChild,
            default          => false,
        };
    }

    public function canPost(PostContext $c): bool
    {
        if (! $c->activeParticipant || ! $c->accountActive || $c->institutionReadOnly || $c->offeringClosed) {
            return false;
        }

        if ($c->type === 'course' && $c->locked && ! $c->isCourseInstructor) {
            return false;
        }

        if ($c->type === 'direct' && ($c->blocked || $c->studentDmBlocked)) {
            return false;
        }

        return true;
    }

    public function canView(bool $activeParticipant, bool $sameInstitution): bool
    {
        return $activeParticipant && $sameInstitution;
    }

    /**
     * $inScope is "instructor of that course" for a course chat, "created or
     * leads that staff group" for a group. Direct threads are never moderated.
     */
    public function canModerate(string $role, string $conversationType, bool $inScope): bool
    {
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

    public function canSeeParentFeed(bool $linked, bool $childEnrolled, string $senderRole): bool
    {
        return $linked && $childEnrolled && $senderRole === 'instructor';
    }
}
