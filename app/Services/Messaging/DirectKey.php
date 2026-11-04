<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * The unique key of a direct thread (spec 4, `conversations.direct_key`):
 * SHA-256 of min(user)|max(user)|subject student (0 for none)|institution, so
 * starting a thread that exists finds it, whichever side starts.
 */
final class DirectKey
{
    public static function for(int $institutionId, int $userA, int $userB, ?int $subjectStudentId): string
    {
        return hash('sha256', min($userA, $userB) . '|' . max($userA, $userB) . '|' . ($subjectStudentId ?? 0) . '|' . $institutionId);
    }
}
