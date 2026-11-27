<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * The facts MessagingPolicy needs to decide whether someone may post in an
 * existing conversation (FR2). scopeLost: a student-to-student direct thread the
 * institution's scope (D3) no longer covers. minorLost: a direct thread whose
 * minor recipient its starter can no longer reach (D9); groups never (D26).
 * deactivatedOther: the other person of a direct thread is deactivated (D11). blocked means a block that
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
        public readonly bool $scopeLost = false,
        public readonly bool $minorLost = false,
        public readonly bool $deactivatedOther = false,
        public readonly bool $subjectLinkLost = false,
        public readonly string $restriction = 'none',
        public readonly bool $closed = false,
    ) {
    }
}
