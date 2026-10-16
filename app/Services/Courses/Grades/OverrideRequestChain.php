<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

/**
 * Who a grade-override request waits for next. A pure rule, no database:
 *
 *   instructor's request  -> the sub-department's chairperson (if there is one
 *                            who is not the requester), then the dean
 *   chairperson's request -> the dean
 *   the dean              -> the institution's administrator when the department
 *                            has no dean, or the dean is the requester
 *
 * Nobody endorses or decides their own request, and a missing level is skipped
 * upward, so a request always has exactly one next person and never loops back.
 */
final class OverrideRequestChain
{
    public const AWAITING_CHAIR = 'awaiting_chair';
    public const AWAITING_DEAN = 'awaiting_dean';
    public const AWAITING_INSTITUTION = 'awaiting_institution';
    public const RETURNED = 'returned';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    /** A request in one of these is still open: waiting for someone, or sent back to its requester. */
    public const OPEN = [self::AWAITING_CHAIR, self::AWAITING_DEAN, self::AWAITING_INSTITUTION, self::RETURNED];

    /**
     * Where a new (or resubmitted) request starts.
     *
     * @param list<int> $chairIds active chairpersons of the offering's sub-department
     */
    public static function first(int $requesterId, string $requesterRole, array $chairIds, ?int $deanId): string
    {
        $others = array_diff($chairIds, [$requesterId]);

        if ($requesterRole === 'instructor' && $others !== []) {
            return self::AWAITING_CHAIR;
        }

        return self::afterChair($requesterId, $deanId);
    }

    /** Where a request goes once the chairperson endorsed it (or the chairperson step was skipped). */
    public static function afterChair(int $requesterId, ?int $deanId): string
    {
        return $deanId !== null && $deanId !== $requesterId ? self::AWAITING_DEAN : self::AWAITING_INSTITUTION;
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    /** The words for a status, for the person who asked. */
    public static function waitingFor(string $status): string
    {
        return match ($status) {
            self::AWAITING_CHAIR       => 'the chairperson',
            self::AWAITING_DEAN        => 'the dean',
            self::AWAITING_INSTITUTION => "the institution's administrator",
            self::RETURNED             => 'you: it was sent back',
            default                    => '',
        };
    }
}
