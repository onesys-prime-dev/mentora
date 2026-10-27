<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * The plain rules of follow-up requests, apart from the database: who may ask whom, what
 * kinds of attention item a request can be about, and when an open one is overdue. Time is an
 * input, so the same call gives the same answer in a test.
 */
final class FollowUpRules
{
    public const DEAN        = 'dean';
    public const CHAIRPERSON = 'chairperson';
    public const INSTRUCTOR  = 'instructor';

    /** What a request can be about, and how it is worded. */
    public const REASONS = [
        'overdue_grading' => 'Overdue grading',
        'inactive_course' => 'Inactive course',
        'at_risk'         => 'At-risk student',
        'blocked_signoff' => 'Blocked sign-off',
        'other'           => 'Something else',
    ];

    /** A request with no due date counts as overdue this many days after it was sent. */
    public const DEFAULT_DUE_DAYS = 7;

    /** A dean asks chairpersons and instructors; a chairperson asks instructors; nobody else asks anybody. */
    public static function mayAsk(string $senderRole, string $recipientRole): bool
    {
        return match ($senderRole) {
            self::DEAN        => in_array($recipientRole, [self::CHAIRPERSON, self::INSTRUCTOR], true),
            self::CHAIRPERSON => $recipientRole === self::INSTRUCTOR,
            default           => false,
        };
    }

    /** The day a request is due: the one asked for, or the default number of days after it was sent. */
    public static function dueOn(?string $dueDate, string $createdAt): string
    {
        if ($dueDate !== null && $dueDate !== '') {
            return substr($dueDate, 0, 10);
        }

        return date('Y-m-d', strtotime(substr($createdAt, 0, 10) . ' +' . self::DEFAULT_DUE_DAYS . ' days'));
    }

    /** Overdue means still open after its due day has passed; a done request never is. */
    public static function isOverdue(string $status, ?string $dueDate, string $createdAt, string $today): bool
    {
        return $status === 'open' && self::dueOn($dueDate, $createdAt) < $today;
    }
}
