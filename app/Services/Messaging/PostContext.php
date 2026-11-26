<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * The facts MessagingPolicy needs to decide whether someone may post in an
 * existing conversation. studentDmBlocked means a student-to-student direct
 * thread while the institution's switch is off. blocked means a block that
 * binds (not paused by a protected link, not made by an institutional admin).
 * subjectLinkLost means a parent thread about a child
 * whose parent link has ended: the thread stays readable but nobody posts.
 * closed is a group its owner or an administrator closed (or that fell to one member): read-only for everyone.
 * restriction is the poster's own: 'no_send' stops posting and reacting except in a course chat they teach.
 */
final class PostContext
{
    public function __construct(
        public readonly string $type,
        public readonly bool $activeParticipant,
        public readonly bool $accountActive,
        public readonly bool $institutionReadOnly = false,
        public readonly bool $offeringClosed = false,
        public readonly bool $locked = false,
        public readonly bool $isCourseInstructor = false,
        public readonly bool $blocked = false,
        public readonly bool $studentDmBlocked = false,
        public readonly bool $subjectLinkLost = false,
        public readonly string $restriction = 'none',
        public readonly bool $closed = false,
    ) {
    }
}
