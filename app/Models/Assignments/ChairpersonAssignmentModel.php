<?php

namespace App\Models\Assignments;

use App\Models\Courses\OfferingDetail;
use CodeIgniter\Model;

class ChairpersonAssignmentModel extends Model
{
    protected $table = 'chairperson_assignments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'assigned_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'department_id',
        'chairperson_user_id',
        'status',
        'assigned_at',
    ];

    /** Every department this user actively chairs — the basis for all of their program-wide access. */
    public function departmentIdsFor(int $userId): array
    {
        return array_map(
            'intval',
            array_column(
                $this->select('department_id')
                    ->where('chairperson_user_id', $userId)
                    ->where('status', 'active')
                    ->findAll(),
                'department_id'
            )
        );
    }

    /** Whether this user actively chairs the given department. */
    public function isChairOf(int $userId, int $departmentId): bool
    {
        return $this->where('chairperson_user_id', $userId)
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    /**
     * Every course offering under any department this user actively
     * chairs — every program in it, including ones added later, since this
     * joins live off chairperson_assignments rather than a stored list of
     * courses. Same column shape as
     * InstructorAssignmentModel::retrieveInstructorCourseOfferings(), so a
     * view built for one can read the other.
     */
    public function retrieveChairedCourseOfferings(int $userId, bool $past = false): array
    {
        return $this->chairedOfferingsBuilder($userId, $past ? 'completed' : 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * One course offering, only if this user actively chairs the
     * department it belongs to. Same column shape and null-when-not-
     * theirs contract as InstructorAssignmentModel::retrieveInstructorCourseOffering().
     */
    public function retrieveChairedCourseOffering(int $userId, int $offeringId): ?array
    {
        return $this->chairedOfferingsBuilder($userId)
            ->where('course_offerings.id', $offeringId)
            ->get()
            ->getRowArray();
    }

    /**
     * The base join behind every chaired-offering query — public so
     * search/filter services can extend it. $status defaults to 'active'
     * to keep every existing caller's behavior unchanged; pass null for
     * offerings of any status (what the chairperson Courses page shows
     * until its own status filter narrows that), or a specific status to
     * match only that one.
     */
    public function chairedOfferingsBuilder(int $userId, ?string $status = 'active'): \CodeIgniter\Database\BaseBuilder
    {
        // The columns are the shared offering shape (OfferingDetail), so the Courses page,
        // Monitor Program and the institution reports read the same row.
        $builder = OfferingDetail::select($this->builder())
            // course_offerings must be joined before anything whose ON
            // clause references course_offerings.id (MySQL resolves a
            // JOIN's ON clause only against tables already named earlier
            // in the FROM/JOIN sequence, not later ones).
            ->join('departments', 'departments.id = chairperson_assignments.department_id')
            ->join('programs', 'programs.department_id = departments.id')
            ->join('courses', 'courses.program_id = programs.id')
            ->join('course_offerings', 'course_offerings.course_id = courses.id')
            ->join(
                'instructor_assignments',
                'instructor_assignments.id = (
                    SELECT MIN(first_assignment.id)
                      FROM instructor_assignments first_assignment
                     WHERE first_assignment.offering_id = course_offerings.id
                )',
                'left',
                false
            )
            ->join('users', 'users.id = instructor_assignments.instructor_user_id', 'left')
            ->join('user_profiles AS instructor_profiles', 'instructor_profiles.user_id = users.id', 'left')
            ->join('colleges', 'colleges.id = departments.college_id')
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = colleges.institution_id'
            )
            ->where('chairperson_assignments.chairperson_user_id', $userId)
            ->where('chairperson_assignments.status', 'active');

        if ($status !== null) {
            $builder->where('course_offerings.status', $status);
        }

        return $builder;
    }
}