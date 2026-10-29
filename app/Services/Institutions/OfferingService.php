<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;
use App\Services\Sis\SisOwnership;

/**
 * An institution's course offerings (a course taught in a term, in a
 * section) and who teaches them, as the institutional administrator keeps
 * them.
 *
 * Rules:
 *  - An offering is a course (active) in a term (upcoming or active) with a
 *    section code; the three together are unique. Neither the course nor the
 *    term changes afterwards: that would be a different offering.
 *  - Capacity is a whole number from 1 to 1000 and cannot be set below the
 *    number of students already enrolled.
 *  - An offering with students enrolled cannot be archived. Offerings are
 *    never deleted: they hold content, work and grades. (Completing one is
 *    part of closing its term.)
 *  - Instructors are people of the institution who hold the instructor role
 *    (a chairperson who also teaches has it). Unassigning removes only the
 *    assignment: the work and grades they did, and the audit trail, stay.
 *  - Every id is checked against the institution first (404 for a foreign
 *    one) and every change is audited.
 */
class OfferingService
{
    public const PER_PAGE = 25;

    public const STATUSES = ['active', 'completed', 'archived'];

    public const MAX_CAPACITY = 1000;

    private const SORTS = [
        'course'   => 'c.course_code',
        'section'  => 'o.section_code',
        'term'     => 't.start_date',
        'capacity' => 'o.capacity',
        'enrolled' => 'enrolled_count',
        'status'   => 'o.status',
    ];

    private const SECTION_PATTERN = '/^[A-Z0-9][A-Z0-9 ._-]{0,49}$/';

    /**
     * @param array{q?: string, term?: int, program?: int, instructor?: int|string, status?: string, sort?: string, dir?: string, page?: int} $filters
     *        instructor: a user id, or "none" for offerings nobody teaches
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function list(int $institutionId, array $filters = []): array
    {
        $builder = $this->base($institutionId);

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $builder->groupStart()->like('c.course_code', $q)->orLike('c.course_title', $q)->orLike('o.section_code', $q)->groupEnd();
        }

        if (((int) ($filters['term'] ?? 0)) > 0) {
            $builder->where('o.term_id', (int) $filters['term']);
        }

        if (((int) ($filters['program'] ?? 0)) > 0) {
            $builder->where('c.program_id', (int) $filters['program']);
        }

        $instructor = $filters['instructor'] ?? '';

        if ($instructor === 'none') {
            $builder->where('NOT EXISTS (SELECT 1 FROM instructor_assignments ia WHERE ia.offering_id = o.id)', null, false);
        } elseif ((int) $instructor > 0) {
            $builder->where('EXISTS (SELECT 1 FROM instructor_assignments ia WHERE ia.offering_id = o.id AND ia.instructor_user_id = ' . (int) $instructor . ')', null, false);
        }

        $status = (string) ($filters['status'] ?? '');

        if (in_array($status, self::STATUSES, true)) {
            $builder->where('o.status', $status);
        }

        $total = (clone $builder)->countAllResults();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        $sort = self::SORTS[$filters['sort'] ?? 'course'] ?? self::SORTS['course'];
        $dir  = ($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $rows = $builder
            ->select('o.*, c.course_code, c.course_title, c.status AS course_status, t.name AS term_name, t.academic_year, t.status AS term_status, p.code AS program_code')
            ->select('(SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = \'enrolled\') AS enrolled_count', false)
            ->orderBy($sort, $dir)
            ->orderBy('o.id', 'ASC')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ->get()->getResultArray();

        $instructors = $this->instructorsOf(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        foreach ($rows as &$row) {
            $row['instructors'] = $instructors[(int) $row['id']] ?? [];
        }

        unset($row);

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * What the add form can choose from.
     *
     * @return array{courses: list<array{id: int, code: string, title: string}>, terms: list<array{id: int, name: string, year: string, status: string}>}
     */
    public function options(int $institutionId): array
    {
        $db = db_connect();

        $courses = $db->table('courses c')
            ->select('c.id, c.course_code, c.course_title')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->where('c.status', 'active')
            ->orderBy('c.course_code')
            ->get()->getResultArray();

        $terms = $db->table('academic_terms')
            ->select('id, name, academic_year, status')
            ->where('institution_id', $institutionId)
            ->whereIn('status', ['upcoming', 'active'])
            ->orderBy('start_date', 'DESC')
            ->get()->getResultArray();

        return [
            'courses' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['course_code'], 'title' => (string) $r['course_title']], $courses),
            'terms'   => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'year' => (string) $r['academic_year'], 'status' => (string) $r['status']], $terms),
        ];
    }

    /**
     * Every term and program of the institution, for the list's filters.
     *
     * @return array{terms: list<array<string, mixed>>, programs: list<array<string, mixed>>}
     */
    public function filterChoices(int $institutionId): array
    {
        $db = db_connect();

        return [
            'terms'    => $db->table('academic_terms')->select('id, name, academic_year, status')->where('institution_id', $institutionId)->orderBy('start_date', 'DESC')->get()->getResultArray(),
            'programs' => $db->table('programs p')->select('p.id, p.code, p.name')
                ->join('sub_departments sd', 'sd.id = p.sub_department_id')
                ->join('departments d', 'd.id = sd.department_id')
                ->where('d.institution_id', $institutionId)->orderBy('p.code')->get()->getResultArray(),
        ];
    }

    /**
     * @param array<string, mixed> $input course_id, term_id, section_code, capacity, description
     *
     * @return int the new offering's id
     *
     * @throws StructureException
     */
    public function create(int $institutionId, array $input, ?User $actor = null): int
    {
        $scope    = $this->scope($institutionId);
        $courseId = (int) ($input['course_id'] ?? 0);
        $termId   = (int) ($input['term_id'] ?? 0);

        $scope->require('course', $courseId);
        $scope->require('term', $termId);

        $db     = db_connect();
        $course = $db->table('courses')->where('id', $courseId)->get()->getRowArray();
        $term   = $db->table('academic_terms')->where('id', $termId)->get()->getRowArray();

        if ($course['status'] !== 'active') {
            throw StructureException::field('course_id', "{$course['course_code']} is not active, so it cannot be offered.");
        }

        if (! in_array($term['status'], ['upcoming', 'active'], true)) {
            throw StructureException::field('term_id', "{$term['name']} is closed, so nothing can be offered in it.");
        }

        $values = $this->values($input);

        $this->sectionIsFree($courseId, $termId, $values['section_code'], null);

        $now = date('Y-m-d H:i:s');
        $db->table('course_offerings')->insert($values + ['course_id' => $courseId, 'term_id' => $termId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();

        $this->audit('structure.offering_created', $actor, $institutionId, $id, null, $values + ['course_id' => $courseId, 'term_id' => $termId]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input section_code, capacity, description
     *
     * @throws StructureException
     */
    public function update(int $institutionId, int $id, array $input, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('offering', $id);

        $before = $this->row($id);

        if ($before['status'] !== 'active') {
            throw new StructureException('This offering is closed, so it cannot be edited.');
        }

        $values = $this->values($input);

        $this->sectionIsFree((int) $before['course_id'], (int) $before['term_id'], $values['section_code'], $id);

        $enrolled = $this->enrolled($id);

        if ($values['capacity'] < $enrolled) {
            throw StructureException::field('capacity', "{$enrolled} student" . ($enrolled === 1 ? ' is' : 's are') . ' already enrolled, so the capacity cannot be lower than that.');
        }

        $old = [];
        $new = [];

        foreach ($values as $field => $value) {
            if ((string) ($before[$field] ?? '') !== (string) ($value ?? '')) {
                $old[$field] = $before[$field];
                $new[$field] = $value;
            }
        }

        if ($new === []) {
            return;
        }

        db_connect()->table('course_offerings')->where('id', $id)->update($new + ['updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('structure.offering_updated', $actor, $institutionId, $id, $old, $new);
    }

    /**
     * Archives an offering, or brings an archived one back.
     *
     * @throws StructureException
     */
    public function setArchived(int $institutionId, int $id, bool $archived, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('offering', $id);

        $row = $this->row($id);

        if ($archived) {
            if ($row['status'] === 'archived') {
                return;
            }

            $enrolled = $this->enrolled($id);

            if ($enrolled > 0) {
                throw new StructureException("This offering has {$enrolled} student" . ($enrolled === 1 ? '' : 's') . ' enrolled, so it cannot be archived. Drop or complete their enrolments first.');
            }

            $status = 'archived';
        } else {
            if ($row['status'] !== 'archived') {
                return;
            }

            $term = db_connect()->table('academic_terms')->where('id', $row['term_id'])->get()->getRowArray();

            if (! in_array($term['status'], ['upcoming', 'active'], true)) {
                throw new StructureException("{$term['name']} is closed, so this offering cannot be brought back.");
            }

            $status = 'active';
        }

        db_connect()->table('course_offerings')->where('id', $id)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit($archived ? 'structure.offering_archived' : 'structure.offering_reactivated', $actor, $institutionId, $id, ['status' => $row['status']], ['status' => $status]);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructors
    |--------------------------------------------------------------------------
    */

    /**
     * People of the institution who can teach: the instructor role, account active.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function instructorCandidates(int $institutionId): array
    {
        $rows = db_connect()->table('institution_users iu')
            ->select('u.id, up.first_name, up.last_name, ai.secret AS email')
            ->join('users u', 'u.id = iu.user_id AND u.deleted_at IS NULL AND u.active = 1', 'inner', false)
            ->join('auth_groups_users g', "g.user_id = u.id AND g.`group` = 'instructor'", 'inner', false)
            ->join('user_profiles up', 'up.user_id = u.id', 'left')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left', false)
            ->where('iu.institution_id', $institutionId)
            ->orderBy('up.last_name')
            ->orderBy('up.first_name')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'    => (int) $r['id'],
            'name'  => trim($r['first_name'] . ' ' . $r['last_name']) ?: (string) $r['email'],
            'email' => (string) $r['email'],
        ], $rows);
    }

    public const SIS_LOCK = 'This assignment is managed by your student information system. Change it there; the next sync brings it here.';

    /**
     * @return int the new assignment row
     *
     * @throws StructureException
     */
    public function assignInstructor(int $institutionId, int $offeringId, int $userId, ?User $actor = null): int
    {
        $this->scope($institutionId)->require('offering', $offeringId);

        $offering = $this->row($offeringId);

        if ($offering['status'] !== 'active') {
            throw new StructureException('This offering is closed, so its instructors cannot be changed.');
        }

        $candidate = null;

        foreach ($this->instructorCandidates($institutionId) as $person) {
            if ($person['id'] === $userId) {
                $candidate = $person;
            }
        }

        if ($candidate === null) {
            throw StructureException::field('user_id', 'Choose one of the people who hold the instructor role in your institution.');
        }

        $db = db_connect();

        if ($db->table('instructor_assignments')->where(['offering_id' => $offeringId, 'instructor_user_id' => $userId])->countAllResults() > 0) {
            throw StructureException::field('user_id', "{$candidate['name']} already teaches this offering.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('instructor_assignments')->insert(['offering_id' => $offeringId, 'instructor_user_id' => $userId, 'assigned_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();

        $this->audit('structure.offering_instructor_assigned', $actor, $institutionId, $offeringId, null, ['instructor_user_id' => $userId]);

        return $id;
    }

    /**
     * @param bool $ignoreSisLock true for the SIS sync itself, which is the owner the lock protects
     *
     * @throws \CodeIgniter\Exceptions\PageNotFoundException when the assignment is not this institution's
     * @throws StructureException when the SIS owns this assignment and the connection is enabled
     */
    public function unassignInstructor(int $institutionId, int $assignmentId, ?User $actor = null, bool $ignoreSisLock = false): void
    {
        $db  = db_connect();
        $row = $db->table('instructor_assignments ia')
            ->select('ia.*')
            ->join('course_offerings o', 'o.id = ia.offering_id')
            ->where('ia.id', $assignmentId)
            ->get()->getRowArray();

        if ($row === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // The offering decides whose it is.
        $this->scope($institutionId)->require('offering', (int) $row['offering_id']);

        if ($this->row((int) $row['offering_id'])['status'] !== 'active') {
            throw new StructureException('This offering is closed, so its instructors cannot be changed.');
        }

        if (! $ignoreSisLock && (new SisOwnership())->managesAssignment('instructor', $assignmentId)) {
            throw new StructureException(self::SIS_LOCK);
        }

        $db->table('instructor_assignments')->where('id', $assignmentId)->delete();

        $this->audit('structure.offering_instructor_unassigned', $actor, $institutionId, (int) $row['offering_id'], ['instructor_user_id' => (int) $row['instructor_user_id']], null);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function scope(int $institutionId): InstitutionScope
    {
        return InstitutionScope::forInstitution($institutionId);
    }

    private function base(int $institutionId): \CodeIgniter\Database\BaseBuilder
    {
        return db_connect()->table('course_offerings o')
            ->join('courses c', 'c.id = o.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->join('academic_terms t', 't.id = o.term_id')
            ->where('d.institution_id', $institutionId);
    }

    /**
     * @param list<int> $offeringIds
     *
     * @return array<int, list<array{assignment_id: int, user_id: int, name: string}>>
     */
    private function instructorsOf(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $rows = db_connect()->table('instructor_assignments ia')
            ->select('ia.id, ia.offering_id, ia.instructor_user_id, up.first_name, up.last_name, ai.secret AS email')
            ->join('user_profiles up', 'up.user_id = ia.instructor_user_id', 'left')
            ->join('auth_identities ai', "ai.user_id = ia.instructor_user_id AND ai.type = 'email_password'", 'left', false)
            ->whereIn('ia.offering_id', $offeringIds)
            ->orderBy('up.last_name')
            ->get()->getResultArray();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['offering_id']][] = [
                'assignment_id' => (int) $row['id'],
                'user_id'       => (int) $row['instructor_user_id'],
                'name'          => trim($row['first_name'] . ' ' . $row['last_name']) ?: (string) $row['email'],
            ];
        }

        return $grouped;
    }

    private function enrolled(int $offeringId): int
    {
        return (int) db_connect()->table('enrollments')->where(['offering_id' => $offeringId, 'status' => 'enrolled'])->countAllResults();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray() ?? [];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{section_code: string, capacity: int, description: ?string}
     *
     * @throws StructureException
     */
    private function values(array $input): array
    {
        $section  = strtoupper(trim((string) ($input['section_code'] ?? '')));
        $capacity = trim((string) ($input['capacity'] ?? ''));
        $text     = trim((string) ($input['description'] ?? ''));

        if ($section === '' || preg_match(self::SECTION_PATTERN, $section) !== 1) {
            throw StructureException::field('section_code', 'The section is required: letters, numbers, spaces, dots, dashes or underscores, up to 50 characters. For example A, or 1A.');
        }

        if ($capacity === '' || preg_match('/^\d{1,4}$/', $capacity) !== 1 || (int) $capacity < 1 || (int) $capacity > self::MAX_CAPACITY) {
            throw StructureException::field('capacity', 'Capacity is a whole number from 1 to ' . self::MAX_CAPACITY . '.');
        }

        if (mb_strlen($text) > 5000) {
            throw StructureException::field('description', 'The description can be at most 5000 characters.');
        }

        return ['section_code' => $section, 'capacity' => (int) $capacity, 'description' => $text === '' ? null : $text];
    }

    /**
     * @throws StructureException
     */
    private function sectionIsFree(int $courseId, int $termId, string $section, ?int $exceptId): void
    {
        $builder = db_connect()->table('course_offerings')->where(['course_id' => $courseId, 'term_id' => $termId, 'section_code' => $section]);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw StructureException::field('section_code', "This course already has a section {$section} in that term.");
        }
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'offering',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
