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

    public function collegeFor(User $user): ?array
    {
        return $this->assignments->collegeOfDean((int) $user->id);
    }

    /**
     * Every program under the dean's college, read from the hierarchy on each call.
     *
     * @return list<int>
     */
    public function programIds(User $user): array
    {
        return array_map('intval', array_column(
            $this->baseQuery($user)->select('p.id')->get()->getResultArray(),
            'id'
        ));
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
        $collegeIds = $this->collegeIds($user);
        if ($collegeIds === []) {
            return false;
        }

        $db = db_connect();
        return $db->table('institution_users iu')
            ->join('colleges d', 'd.institution_id = iu.institution_id')
            ->where('iu.user_id', $personId)
            ->whereIn('d.id', $collegeIds)
            ->countAllResults() > 0
            || $db->table('instructor_assignments ia')
                ->join('course_offerings co', 'co.id = ia.offering_id')
                ->join('courses c', 'c.id = co.course_id')
                ->join('programs p', 'p.id = c.program_id')
                ->join('departments sd', 'sd.id = p.department_id')
                ->where('ia.instructor_user_id', $personId)
                ->whereIn('sd.college_id', $collegeIds)
                ->countAllResults() > 0;
    }

    public function canAccess(User $user, int $offeringId): bool
    {
        return $this->canAccessOffering($user, $offeringId);
    }

    /** @return list<int> */
    public function collegeIds(User $user): array
    {
        $row = $this->collegeFor($user);

        return $row === null ? [] : [(int) $row['college_id']];
    }

    private function baseQuery(User $user): \CodeIgniter\Database\BaseBuilder
    {
        $collegeIds = $this->collegeIds($user);

        $builder = db_connect()->table('programs p')
            ->join('departments sd', 'sd.id = p.department_id');

        // No active assignment reaches nothing: an empty IN () is an error, not "none".
        return $collegeIds === []
            ? $builder->where('1 = 0', null, false)
            : $builder->whereIn('sd.college_id', $collegeIds);
    }
}