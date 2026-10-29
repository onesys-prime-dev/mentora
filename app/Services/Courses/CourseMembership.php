<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Courses\EnrollmentModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Services\Auth\ActiveRoleService;
use CodeIgniter\Shield\Entities\User;

/**
 * Does this person belong to this offering, in the role they are acting as?
 *
 * One answer for every course route of the student, instructor and
 * chairperson areas, so a route that names an offering cannot be pointed at
 * someone else's section by putting that section's number in the address:
 *
 *  - an instructor belongs to the offerings they are assigned to teach;
 *  - a student to the ones they are enrolled in (or have completed);
 *  - a chairperson to every offering in the sub-departments they chair.
 *
 * The institution's administrator has their own read-only access
 * (InstitutionCourseAccess) and is not answered here.
 */
class CourseMembership
{
    public const ROLES = ['student', 'instructor', 'chairperson'];

    /** In the role they are currently acting as. */
    public function canAccess(User $user, int $offeringId): bool
    {
        $role = (new ActiveRoleService())->resolve($user);

        return $this->canAccessAs($user, $role, $offeringId);
    }

    /** @var array<string, ?string> roleIn's answers, by "user:offering" */
    private array $roles = [];

    /**
     * What this person is in this offering, whatever groups they hold (9.1): its instructor, the chairperson of
     * its sub-department, or a student enrolled in it (or who completed it); null for none. Teaching comes first,
     * then chairing: once a student can't also be staff of a course, only a chair who teaches can be two.
     */
    public function roleIn(User $user, int $offeringId): ?string
    {
        $key = $user->id . ':' . $offeringId;

        if (! array_key_exists($key, $this->roles)) {
            $this->roles[$key] = null;

            foreach (['instructor', 'chairperson', 'student'] as $role) {
                if ($this->canAccessAs($user, $role, $offeringId)) {
                    $this->roles[$key] = $role;

                    break;
                }
            }
        }

        return $this->roles[$key];
    }

    /** As the named role, whatever else the person may also be. */
    public function canAccessAs(User $user, string $role, int $offeringId): bool
    {
        if ($offeringId < 1 || ! in_array($role, self::ROLES, true) || ! $user->inGroup($role)) {
            return false;
        }

        return match ($role) {
            'instructor'  => (new InstructorAssignmentModel())->retrieveInstructorCourseOffering((int) $user->id, $offeringId) !== null,
            'chairperson' => (new ChairpersonAccessService())->canAccess($user, $offeringId),
            default       => (new EnrollmentModel())->retrieveStudentCourseOffering((int) $user->id, $offeringId) !== null,
        };
    }
}
