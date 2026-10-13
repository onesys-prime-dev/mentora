<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * The numbers on the institutional administrator's home page: who is in the
 * institution, what it teaches, and the term it is in. Read-only counts,
 * all scoped by the institution id given.
 */
class InstitutionSnapshot
{
    /** Roles shown on the home page, in this order. */
    public const ROLES = ['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'];

    /**
     * @return array{
     *     people: array<string, int>, peopleTotal: int, courses: int, activeTerm: ?array{id: int, name: string, academic_year: string},
     *     offerings: int, enrolments: int
     * }
     */
    public function for(int $institutionId): array
    {
        $db = db_connect();

        $people = array_fill_keys(self::ROLES, 0);

        $rows = $db->table('institution_users iu')
            ->select('g.`group` AS role, COUNT(DISTINCT iu.user_id) AS n', false)
            ->join('users u', 'u.id = iu.user_id AND u.deleted_at IS NULL', 'inner', false)
            ->join('auth_groups_users g', 'g.user_id = iu.user_id')
            ->where('iu.institution_id', $institutionId)
            ->groupBy('g.group')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            if (isset($people[$row['role']])) {
                $people[$row['role']] = (int) $row['n'];
            }
        }

        $peopleTotal = (int) $db->table('institution_users iu')
            ->join('users u', 'u.id = iu.user_id')
            ->where('iu.institution_id', $institutionId)
            ->where('u.deleted_at IS NULL', null, false)
            ->countAllResults();

        $courses = (int) $db->table('courses c')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->where('c.status !=', 'archived')
            ->countAllResults();

        $term = $db->table('academic_terms')
            ->select('id, name, academic_year')
            ->where(['institution_id' => $institutionId, 'status' => 'active'])
            ->orderBy('start_date', 'desc')
            ->get()->getRowArray();

        $offerings  = 0;
        $enrolments = 0;

        if ($term !== null) {
            $offerings = (int) $db->table('course_offerings')->where('term_id', $term['id'])->where('status !=', 'archived')->countAllResults();

            $enrolments = (int) $db->table('enrollments e')
                ->join('course_offerings o', 'o.id = e.offering_id')
                ->where('o.term_id', $term['id'])
                ->where('e.status', 'enrolled')
                ->countAllResults();
        }

        return [
            'people'      => $people,
            'peopleTotal' => $peopleTotal,
            'courses'     => $courses,
            'activeTerm'  => $term === null ? null : ['id' => (int) $term['id'], 'name' => (string) $term['name'], 'academic_year' => (string) $term['academic_year']],
            'offerings'   => $offerings,
            'enrolments'  => $enrolments,
        ];
    }
}
