<?php

namespace App\Services\Courses;

use App\Models\Assignments\ChairpersonAssignmentModel;
use CodeIgniter\Shield\Entities\User;

/**
 * A chairperson's reach: every course offering under any sub-department they
 * actively chair. Derived live off chairperson_assignments and the program
 * hierarchy on every call — never a stored snapshot — so a program or course
 * added to their sub-department later is covered automatically, and a
 * revoked assignment takes effect immediately.
 */
class ChairpersonAccessService
{
    protected ChairpersonAssignmentModel $assignments;

    public function __construct()
    {
        $this->assignments = new ChairpersonAssignmentModel();
    }

    /** May this chairperson reach the given course offering? */
    public function canAccess(User $user, int $offeringId): bool
    {
        $subDepartmentIds = $this->assignments->subDepartmentIdsFor((int) $user->id);

        if ($subDepartmentIds === []) {
            return false;
        }

        return db_connect()->table('course_offerings co')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->where('co.id', $offeringId)
            ->whereIn('p.sub_department_id', $subDepartmentIds)
            ->countAllResults() > 0;
    }

    /** Every course offering this chairperson has access to, across every sub-department they chair. */
    public function offeringsFor(User $user): array
    {
        return $this->assignments->retrieveChairedCourseOfferings((int) $user->id);
    }
}
