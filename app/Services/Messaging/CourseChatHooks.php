<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use Throwable;

/**
 * What the writers of enrolments, assignments and offerings call once their own
 * write has succeeded. A failed sync is logged by offering id and swallowed: the
 * enrolment or term close it follows must stand, and `messages:sync-course-chats`
 * repairs the gap.
 */
final class CourseChatHooks
{
    private static ?CourseChatSync $sync = null;

    /** For tests: use this sync instead of a real one; null puts the real one back. */
    public static function useSync(?CourseChatSync $sync): void
    {
        self::$sync = $sync;
    }

    public static function offeringChanged(int $offeringId): void
    {
        try {
            (self::$sync ?? new CourseChatSync())->syncOffering($offeringId);
        } catch (Throwable) {
            log_message('error', 'Course chat sync failed for offering ' . $offeringId . '.');
        }
    }

    /** @param list<int> $offeringIds */
    public static function offeringsChanged(array $offeringIds): void
    {
        foreach (array_unique($offeringIds) as $offeringId) {
            self::offeringChanged((int) $offeringId);
        }
    }
}
