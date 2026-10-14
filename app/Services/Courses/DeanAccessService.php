<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Assignments\DeanAssignmentModel;
use CodeIgniter\Shield\Entities\User;

class DeanAccessService
{
    private DeanAssignmentModel $assignments;

    public function __construct(?DeanAssignmentModel $assignments = null)
    {
        $this->assignments = $assignments ?? new DeanAssignmentModel();
    }

    public function departmentFor(User $user): ?array
    {
        return $this->assignments->departmentOfDean((int) $user->id);
    }

    public function canAccessOffering(User $user, int $offeringId): bool
    {
        return $this->baseQuery($user)
            ->join('courses c', 'c.program_id = p.id')
            ->join('course_offerings co', 'co.course_id = c.id')
            ->where('co.id', $offeringId)
            ->countAllResults() > 0;
    }

    public function canAccessProgram(User $user, int $programId): bool
    {
        return $this->baseQuery($user)->where('p.id', $programId)->countAllResults() > 0;
    }

    public function canAccessPerson(User $user, int $personId): bool
    {
        $departmentIds = $this->departmentIds($user);
        if ($departmentIds === []) {
            return false;
        }

        $db = db_connect();
        return $db->table('institution_users iu')
            ->join('departments d', 'd.institution_id = iu.institution_id')
            ->where('iu.user_id', $personId)
            ->whereIn('d.id', $departmentIds)
            ->countAllResults() > 0
            || $db->table('instructor_assignments ia')
                ->join('course_offerings co', 'co.id = ia.offering_id')
                ->join('courses c', 'c.id = co.course_id')
                ->join('programs p', 'p.id = c.program_id')
                ->join('sub_departments sd', 'sd.id = p.sub_department_id')
                ->where('ia.instructor_user_id', $personId)
                ->whereIn('sd.department_id', $departmentIds)
                ->countAllResults() > 0;
    }

    public function canAccess(User $user, int $offeringId): bool
    {
        return $this->canAccessOffering($user, $offeringId);
    }

    /** @return list<int> */
    public function departmentIds(User $user): array
    {
        $row = $this->departmentFor($user);

        return $row === null ? [] : [(int) $row['department_id']];
    }

    private function baseQuery(User $user): \CodeIgniter\Database\BaseBuilder
    {
        return db_connect()->table('programs p')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->whereIn('sd.department_id', $this->departmentIds($user));
    }
}