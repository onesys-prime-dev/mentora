<?php

declare(strict_types=1);

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

/**
 * Who sees a course the way its instructor does.
 *
 * The course pages decide what to show (drafts, class-wide progress, the
 * gradebook, no per-student locks) by asking whether the viewer is the
 * instructor. The institutional administrator has a read-only view of every
 * course of their institution that shows exactly that, without being the
 * instructor and without any of the instructor's write routes: this is the
 * one place that says so, so the rule is not repeated in a dozen services.
 *
 * Reading only: nothing that changes a course asks this. Writes keep their
 * own checks (assigned instructor, chairperson of the program).
 */
final class CourseViewer
{
    /**
     * The instructor, the chairperson overseeing the program, or someone with
     * a read-only view of the course. The chairperson is not enrolled, so the
     * student view (their own submissions and grades) has nothing to show them.
     */
    public static function seesInstructorView(User $user): bool
    {
        return $user->inGroup('instructor')
            || $user->inGroup('chairperson')
            || self::isReadOnlyViewer($user);
    }

    /** The institutional administrator: looks at courses, never changes them. */
    public static function isReadOnlyViewer(User $user): bool
    {
        return $user->inGroup('institutional_admin') && ! $user->inGroup('instructor');
    }

    /**
     * The first segment of the course page and API addresses for this person:
     * `institution` for the read-only viewer, else the given role name.
     */
    public static function urlPrefix(User $user, string $role): string
    {
        return self::isReadOnlyViewer($user) ? 'institution' : $role;
    }
}
