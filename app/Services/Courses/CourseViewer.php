<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Auth\ActiveRoleService;
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
     *
     * What counts is the person's role in this course (9.1): the offering named,
     * else the course of the request (CourseContext). Only outside any course
     * does the group answer.
     */
    public static function seesInstructorView(User $user, ?int $offeringId = null): bool
    {
        if (self::isReadOnlyViewer($user)) {
            return true;
        }

        $role = self::roleHere($user, $offeringId);

        if ($role !== false) {
            return in_array($role, ['instructor', 'chairperson'], true);
        }

        return $user->inGroup('instructor') || $user->inGroup('chairperson');
    }

    /** A student of this course (the one named, else the request's): the student's own progress and work apply. */
    public static function isStudentHere(User $user, ?int $offeringId = null): bool
    {
        // Looking at a course (a dean as dean, the institution's administrator) is never studying it, even for
        // someone who studies elsewhere; the dean and institution routes set no course to ask about.
        if (self::isReadOnlyViewer($user)) {
            return false;
        }

        $role = self::roleHere($user, $offeringId);

        return $role === false ? $user->inGroup('student') : $role === 'student';
    }

    /** The role in the named offering, else in the request's course; false when there is no course to ask about. */
    private static function roleHere(User $user, ?int $offeringId): string|false|null
    {
        if ($offeringId !== null) {
            return (new CourseMembership())->roleIn($user, $offeringId);
        }

        $context = service('courseContext');

        return $context->offeringId() === null ? false : $context->role();
    }

    /**
     * Someone looking at courses, never changing them: the institutional
     * administrator, or the dean while acting as dean.
     */
    public static function isReadOnlyViewer(?User $user): bool
    {
        // Nobody signed in (a page drawn outside a request, such as a test) is nobody's read-only view.
        return $user !== null && (self::isInstitutionViewer($user) || self::isDeanViewer($user));
    }

    /** The institutional administrator (who does not also teach). */
    public static function isInstitutionViewer(User $user): bool
    {
        return $user->inGroup('institutional_admin') && ! $user->inGroup('instructor');
    }

    /**
     * A dean acting as dean. A dean who also teaches and is acting as an
     * instructor is not a viewer: they see their own courses as their
     * instructor does.
     */
    public static function isDeanViewer(User $user): bool
    {
        return $user->inGroup('dean') && (new ActiveRoleService())->resolve($user) === 'dean';
    }

    /**
     * The first segment of the course page and API addresses for this person:
     * `institution` for the institution's administrator, `dean` for a dean
     * acting as dean, else the given role name.
     */
    public static function urlPrefix(User $user, string $role): string
    {
        return match (true) {
            self::isInstitutionViewer($user) => 'institution',
            self::isDeanViewer($user)        => 'dean',
            default                          => $role,
        };
    }
}
