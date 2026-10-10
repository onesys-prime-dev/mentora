<?php

namespace App\Models\Assignments;

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
        'sub_department_id',
        'chairperson_user_id',
        'status',
        'assigned_at',
    ];

    /** Every sub-department this user actively chairs — the basis for all of their program-wide access. */
    public function subDepartmentIdsFor(int $userId): array
    {
        return array_map(
            'intval',
            array_column(
                $this->select('sub_department_id')
                    ->where('chairperson_user_id', $userId)
                    ->where('status', 'active')
                    ->findAll(),
                'sub_department_id'
            )
        );
    }

    /** Whether this user actively chairs the given sub-department. */
    public function isChairOf(int $userId, int $subDepartmentId): bool
    {
        return $this->where('chairperson_user_id', $userId)
            ->where('sub_department_id', $subDepartmentId)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    /**
     * Every course offering under any sub-department this user actively
     * chairs — every program in it, including ones added later, since this
     * joins live off chairperson_assignments rather than a stored list of
     * courses. Same column shape as
     * InstructorAssignmentModel::retrieveInstructorCourseOfferings(), so a
     * view built for one can read the other.
     */
    public function retrieveChairedCourseOfferings(int $userId): array
    {
        return $this->chairedOfferingsBuilder($userId)
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * One course offering, only if this user actively chairs the
     * sub-department it belongs to. Same column shape and null-when-not-
     * theirs contract as InstructorAssignmentModel::retrieveInstructorCourseOffering().
     */
    public function retrieveChairedCourseOffering(int $userId, int $offeringId): ?array
    {
        return $this->chairedOfferingsBuilder($userId)
            ->where('course_offerings.id', $offeringId)
            ->get()
            ->getRowArray();
    }

    private function chairedOfferingsBuilder(int $userId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->builder()
            ->select([
                'course_offerings.id AS offering_id',
                'course_offerings.section_code',
                'course_offerings.capacity',
                'course_offerings.status AS offering_status',

                'courses.id AS course_id',
                'courses.course_code',
                'courses.course_title',
                'courses.description',
                'courses.units',

                'programs.id AS program_id',
                'programs.name AS program_name',

                'academic_terms.id AS term_id',
                'academic_terms.name AS term_name',
                'academic_terms.academic_year',

                'sub_departments.id AS sub_department_id',
                'sub_departments.name AS sub_department_name',
                'departments.id AS department_id',
                'departments.name AS department_name',

                'institutions.id AS institution_id',
                'institutions.name AS institution_name',
            ])
            ->select(
                "(SELECT COUNT(*)
                    FROM enrollments
                   WHERE enrollments.offering_id = course_offerings.id
                     AND enrollments.status = 'enrolled') AS enrolled",
                false
            )
            ->join('sub_departments', 'sub_departments.id = chairperson_assignments.sub_department_id')
            ->join('programs', 'programs.sub_department_id = sub_departments.id')
            ->join('courses', 'courses.program_id = programs.id')
            ->join('course_offerings', 'course_offerings.course_id = courses.id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = departments.institution_id'
            )
            ->where('chairperson_assignments.chairperson_user_id', $userId)
            ->where('chairperson_assignments.status', 'active')
            ->where('course_offerings.status', 'active');
    }
}