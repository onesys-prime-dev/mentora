<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * The facts MessagingPolicy needs to decide whether someone may post in an
 * existing conversation. studentDmBlocked means a student-to-student direct
 * thread while the institution's switch is off.
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
    ) {
    }
}
