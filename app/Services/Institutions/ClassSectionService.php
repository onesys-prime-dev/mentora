<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;

/**
 * Block sections (BSIT 1-A): a cohort of students, for one term, in a program
 * and a year level, that takes a set of courses together. The institutional
 * administrator keeps them.
 *
 * A section owns one course offering per course it takes (the offering's
 * `section_code` is the section's code), and its members are enrolled in every
 * one of them. The enrolments are ordinary `enrollments` rows, so rosters, the
 * gradebook and the courses themselves work exactly as before.
 *
 * Rules:
 *  - The code is unique within the institution's term. That is what lets it be
 *    every offering's section code: two blocks sharing a general-education
 *    course never clash.
 *  - A section is made in an upcoming or active term, in a program of the
 *    institution. Its code, year level and capacity can be changed while it is
 *    active; the capacity cannot go below its members.
 *  - Adding a course makes (or brings back) the section's offering of it and
 *    enrols the members. Taking a course out archives that offering, and only
 *    when nobody is enrolled in it (an offering holds work and grades).
 *  - Adding a student enrols them in each offering, through EnrolmentService,
 *    so its rules hold: the student role, an active account, the conflicts of
 *    teaching or leading the same course, and the SIS lock. A place that cannot
 *    be given is reported, and the rest still go in.
 *  - Taking a student out drops (never deletes) their places in the section's
 *    offerings: their work and grades stay.
 *  - A student outside a block is still just enrolled in one offering, by hand
 *    or by upload; that is how an irregular student takes a course.
 *  - Every id is checked against the institution first (404 for a foreign one)
 *    and every change is audited.
 */
class ClassSectionService
{
    public const PER_PAGE = 25;

    public const STATUSES = ['active', 'archived'];

    public const MAX_CAPACITY = 1000;

    public const MAX_YEAR_LEVEL = 12;

    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9 ._-]{0,49}$/';

    private readonly EnrolmentService $enrolments;

    public function __construct(?EnrolmentService $enrolments = null)
    {
        $this->enrolments = $enrolments ?? new EnrolmentService();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{q?: string, term?: int, program?: int, status?: string, page?: int} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function list(int $institutionId, array $filters = []): array
    {
        $builder = db_connect()->table('class_sections cs')
            ->join('programs p', 'p.id = cs.program_id')
            ->join('academic_terms t', 't.id = cs.term_id')
            ->where('cs.institution_id', $institutionId);

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $builder->groupStart()->like('cs.code', $q)->orLike('p.code', $q)->orLike('p.name', $q)->groupEnd();
        }

        if (((int) ($filters['term'] ?? 0)) > 0) {
            $builder->where('cs.term_id', (int) $filters['term']);
        }

        if (((int) ($filters['program'] ?? 0)) > 0) {
            $builder->where('cs.program_id', (int) $filters['program']);
        }

        if (in_array((string) ($filters['status'] ?? ''), self::STATUSES, true)) {
            $builder->where('cs.status', (string) $filters['status']);
        }

        $total = (clone $builder)->countAllResults();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        $rows = $builder
            ->select('cs.*, p.code AS program_code, p.name AS program_name, t.name AS term_name, t.academic_year, t.status AS term_status')
            ->select('(SELECT COUNT(*) FROM class_section_students m WHERE m.class_section_id = cs.id) AS student_count', false)
            ->select("(SELECT COUNT(*) FROM course_offerings o WHERE o.class_section_id = cs.id AND o.status <> 'archived') AS course_count", false)
            ->orderBy('t.start_date', 'DESC')
            ->orderBy('cs.year_level', 'ASC')
            ->orderBy('cs.code', 'ASC')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * What the add form and the filters can choose from.
     *
     * @return array{terms: list<array<string, mixed>>, openTerms: list<array<string, mixed>>, programs: list<array<string, mixed>>}
     */
    public function options(int $institutionId): array
    {
        $db    = db_connect();
        $terms = $db->table('academic_terms')->select('id, name, academic_year, status')->where('institution_id', $institutionId)->orderBy('start_date', 'DESC')->get()->getResultArray();

        return [
            'terms'     => $terms,
            'openTerms' => array_values(array_filter($terms, static fn (array $t): bool => in_array($t['status'], ['upcoming', 'active'], true))),
            'programs'  => $db->table('programs p')->select('p.id, p.code, p.name')
                ->join('departments sd', 'sd.id = p.department_id')
                ->join('colleges d', 'd.id = sd.college_id')
                ->where('d.institution_id', $institutionId)->orderBy('p.code')->get()->getResultArray(),
        ];
    }

    /**
     * One section with its courses, its students and the courses it could still take.
     *
     * @return array{section: array<string, mixed>, courses: list<array<string, mixed>>, students: list<array<string, mixed>>, available: list<array<string, mixed>>}
     */
    public function detail(int $institutionId, int $id): array
    {
        $this->scope($institutionId)->require('classSection', $id);

        $db      = db_connect();
        $section = $db->table('class_sections cs')
            ->select('cs.*, p.code AS program_code, p.name AS program_name, t.name AS term_name, t.academic_year, t.status AS term_status')
            ->join('programs p', 'p.id = cs.program_id')
            ->join('academic_terms t', 't.id = cs.term_id')
            ->where('cs.id', $id)
            ->get()->getRowArray();

        $courses = $db->table('course_offerings o')
            ->select('o.id, o.status, o.capacity, c.id AS course_id, c.course_code, c.course_title, c.units')
            ->select("(SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = 'enrolled') AS enrolled_count", false)
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.class_section_id', $id)
            ->where('o.status <>', 'archived')
            ->orderBy('c.course_code')
            ->get()->getResultArray();

        $instructors = [];

        if ($courses !== []) {
            $rows = $db->table('instructor_assignments ia')
                ->select('ia.offering_id, up.first_name, up.last_name, ai.secret AS email')
                ->join('user_profiles up', 'up.user_id = ia.instructor_user_id', 'left')
                ->join('auth_identities ai', "ai.user_id = ia.instructor_user_id AND ai.type = 'email_password'", 'left', false)
                ->whereIn('ia.offering_id', array_map(static fn (array $c): int => (int) $c['id'], $courses))
                ->orderBy('up.last_name')
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $instructors[(int) $r['offering_id']][] = trim($r['first_name'] . ' ' . $r['last_name']) ?: (string) $r['email'];
            }
        }

        foreach ($courses as &$course) {
            $course['instructors'] = $instructors[(int) $course['id']] ?? [];
        }

        unset($course);

        $offeringIds = array_map(static fn (array $c): int => (int) $c['id'], $courses);

        $students = $db->table('class_section_students m')
            ->select('m.student_user_id, m.created_at, up.first_name, up.last_name, up.student_number, ai.secret AS email')
            ->join('user_profiles up', 'up.user_id = m.student_user_id', 'left')
            ->join('auth_identities ai', "ai.user_id = m.student_user_id AND ai.type = 'email_password'", 'left', false)
            ->where('m.class_section_id', $id)
            ->orderBy('up.last_name')->orderBy('up.first_name')
            ->get()->getResultArray();

        $places = [];

        if ($offeringIds !== [] && $students !== []) {
            $counts = $db->table('enrollments')->select('student_user_id, COUNT(*) AS total', false)
                ->whereIn('offering_id', $offeringIds)->where('status', 'enrolled')->groupBy('student_user_id')
                ->get()->getResultArray();

            foreach ($counts as $c) {
                $places[(int) $c['student_user_id']] = (int) $c['total'];
            }
        }

        $out = [];

        foreach ($students as $s) {
            $out[] = [
                'id'             => (int) $s['student_user_id'],
                'name'           => trim($s['first_name'] . ' ' . $s['last_name']) ?: (string) $s['email'],
                'email'          => (string) $s['email'],
                'student_number' => (string) ($s['student_number'] ?? ''),
                'places'         => $places[(int) $s['student_user_id']] ?? 0,
            ];
        }

        $taken = array_map(static fn (array $c): int => (int) $c['course_id'], $courses);

        $available = $db->table('courses c')
            ->select('c.id, c.course_code, c.course_title')
            ->join('programs p', 'p.id = c.program_id')
            ->join('departments sd', 'sd.id = p.department_id')
            ->join('colleges d', 'd.id = sd.college_id')
            ->where('d.institution_id', $institutionId)
            ->where('c.status', 'active')
            ->orderBy('c.course_code')
            ->get()->getResultArray();

        $available = array_values(array_filter($available, static fn (array $c): bool => ! in_array((int) $c['id'], $taken, true)));

        return ['section' => $section, 'courses' => $courses, 'students' => $out, 'available' => $available];
    }

    /**
     * The active block section of a term that has this code, if there is one. Used by the CSV upload and the
     * SIS import, whose "section" can name a block.
     *
     * @return array<string, mixed>|null
     */
    public function activeByCode(int $institutionId, int $termId, string $code): ?array
    {
        return db_connect()->table('class_sections')
            ->where(['institution_id' => $institutionId, 'term_id' => $termId, 'code' => $code, 'status' => 'active'])
            ->get()->getRowArray();
    }

    /*
    |--------------------------------------------------------------------------
    | The section itself
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $input term_id, program_id, year_level, code, capacity
     *
     * @return int the new section's id
     *
     * @throws StructureException
     */
    public function create(int $institutionId, array $input, ?User $actor = null): int
    {
        $scope     = $this->scope($institutionId);
        $termId    = (int) ($input['term_id'] ?? 0);
        $programId = (int) ($input['program_id'] ?? 0);

        $scope->require('term', $termId);
        $scope->require('program', $programId);

        $db   = db_connect();
        $term = $db->table('academic_terms')->where('id', $termId)->get()->getRowArray();

        if (! in_array($term['status'], ['upcoming', 'active'], true)) {
            throw StructureException::field('term_id', "{$term['name']} is closed, so no section can be made in it.");
        }

        $values = $this->values($input);

        $this->codeIsFree($institutionId, $termId, $values['code'], null);

        $now = date('Y-m-d H:i:s');
        $db->table('class_sections')->insert($values + ['institution_id' => $institutionId, 'term_id' => $termId, 'program_id' => $programId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();

        $this->audit('structure.class_section_created', $actor, $institutionId, $id, null, $values + ['term_id' => $termId, 'program_id' => $programId]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input code, year_level, capacity
     *
     * @throws StructureException
     */
    public function update(int $institutionId, int $id, array $input, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('classSection', $id);

        $db     = db_connect();
        $before = $db->table('class_sections')->where('id', $id)->get()->getRowArray();

        $this->mustBeOpen($before);

        $values  = $this->values($input);
        $members = $this->memberCount($id);

        if ($values['capacity'] < $members) {
            throw StructureException::field('capacity', "{$members} student" . ($members === 1 ? ' is' : 's are') . ' in this section, so the capacity cannot be lower than that.');
        }

        if ($values['code'] !== $before['code']) {
            $this->codeIsFree($institutionId, (int) $before['term_id'], $values['code'], $id);

            $clash = $db->table('course_offerings o')
                ->select('c.course_code')
                ->join('courses c', 'c.id = o.course_id')
                ->where('o.class_section_id', $id)
                ->where("EXISTS (SELECT 1 FROM course_offerings x WHERE x.course_id = o.course_id AND x.term_id = o.term_id AND x.section_code = " . $db->escape($values['code']) . ' AND x.id <> o.id)', null, false)
                ->get()->getRowArray();

            if ($clash !== null) {
                throw StructureException::field('code', "{$clash['course_code']} already has a section {$values['code']} in this term, so the section cannot take that code.");
            }
        }

        $old = [];
        $new = [];

        foreach ($values as $field => $value) {
            if ((string) $before[$field] !== (string) $value) {
                $old[$field] = $before[$field];
                $new[$field] = $value;
            }
        }

        if ($new === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $db->transStart();
        $db->table('class_sections')->where('id', $id)->update($new + ['updated_at' => $now]);

        // Its offerings carry the code and take the capacity (never below who is already enrolled).
        if (isset($new['code'])) {
            $db->table('course_offerings')->where('class_section_id', $id)->update(['section_code' => $new['code'], 'updated_at' => $now]);
        }

        if (isset($new['capacity'])) {
            $db->table('course_offerings')->where('class_section_id', $id)
                ->where("(SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = course_offerings.id AND e.status = 'enrolled') <= " . (int) $new['capacity'], null, false)
                ->update(['capacity' => $new['capacity'], 'updated_at' => $now]);
        }

        $db->transComplete();

        $this->audit('structure.class_section_updated', $actor, $institutionId, $id, $old, $new);
    }

    /**
     * Archives a section with nobody in it, or brings an archived one back.
     *
     * @throws StructureException
     */
    public function setArchived(int $institutionId, int $id, bool $archived, ?User $actor = null): void
    {
        $this->scope($institutionId)->require('classSection', $id);

        $db  = db_connect();
        $row = $db->table('class_sections')->where('id', $id)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');

        if ($archived) {
            if ($row['status'] === 'archived') {
                return;
            }

            $members = $this->memberCount($id);

            if ($members > 0) {
                throw new StructureException("This section has {$members} student" . ($members === 1 ? '' : 's') . ' in it, so it cannot be archived. Take them out of it first.');
            }

            $enrolled = (int) $db->table('enrollments e')->join('course_offerings o', 'o.id = e.offering_id')->where('o.class_section_id', $id)->where('e.status', 'enrolled')->countAllResults();

            if ($enrolled > 0) {
                throw new StructureException("{$enrolled} student" . ($enrolled === 1 ? ' is' : 's are') . ' still enrolled in its courses, so it cannot be archived. Drop their enrolments first.');
            }

            $db->transStart();
            $db->table('course_offerings')->where('class_section_id', $id)->where('status', 'active')->update(['status' => 'archived', 'updated_at' => $now]);
            $db->table('class_sections')->where('id', $id)->update(['status' => 'archived', 'updated_at' => $now]);
            $db->transComplete();
        } else {
            if ($row['status'] !== 'archived') {
                return;
            }

            $term = $db->table('academic_terms')->where('id', $row['term_id'])->get()->getRowArray();

            if (! in_array($term['status'], ['upcoming', 'active'], true)) {
                throw new StructureException("{$term['name']} is closed, so this section cannot be brought back.");
            }

            $db->table('class_sections')->where('id', $id)->update(['status' => 'active', 'updated_at' => $now]);
        }

        $this->audit($archived ? 'structure.class_section_archived' : 'structure.class_section_reactivated', $actor, $institutionId, $id, ['status' => $row['status']], ['status' => $archived ? 'archived' : 'active']);
    }

    /*
    |--------------------------------------------------------------------------
    | Its courses
    |--------------------------------------------------------------------------
    */

    /**
     * Gives the section a course: its offering is made (or brought back) and the members are enrolled in it.
     *
     * @return array{offering_id: int, enrolled: int, warnings: list<string>}
     *
     * @throws StructureException
     */
    public function addCourse(int $institutionId, int $sectionId, int $courseId, ?User $actor = null): array
    {
        $scope = $this->scope($institutionId);
        $scope->require('classSection', $sectionId);
        $scope->require('course', $courseId);

        return $this->attachCourse($institutionId, $sectionId, $courseId, $actor);
    }

    /**
     * addCourse for a caller that has already checked the ids belong to the institution (the CSV upload and the
     * SIS import, which run with no signed-in administrator).
     *
     * @return array{offering_id: int, enrolled: int, warnings: list<string>}
     *
     * @throws StructureException
     */
    public function attachCourse(int $institutionId, int $sectionId, int $courseId, ?User $actor = null): array
    {
        $db      = db_connect();
        $section = $db->table('class_sections')->where('id', $sectionId)->get()->getRowArray();
        $course  = $db->table('courses')->where('id', $courseId)->get()->getRowArray();

        if ($section['status'] !== 'active') {
            throw new StructureException('This section is archived, so it cannot take a course.');
        }

        if ($course['status'] !== 'active') {
            throw StructureException::field('course_id', "{$course['course_code']} is not active, so it cannot be offered.");
        }

        $term = $db->table('academic_terms')->where('id', $section['term_id'])->get()->getRowArray();

        if (! in_array($term['status'], ['upcoming', 'active'], true)) {
            throw new StructureException("{$term['name']} is closed, so nothing can be added to this section.");
        }

        $existing = $db->table('course_offerings')->where(['course_id' => $courseId, 'term_id' => $section['term_id'], 'section_code' => $section['code']])->get()->getRowArray();
        $now      = date('Y-m-d H:i:s');

        if ($existing !== null) {
            if ((int) $existing['class_section_id'] !== $sectionId) {
                throw StructureException::field('course_id', "{$course['course_code']} already has a section {$section['code']} in this term that is not part of this one.");
            }

            if ($existing['status'] !== 'archived') {
                throw StructureException::field('course_id', "{$course['course_code']} is already in this section.");
            }

            $db->table('course_offerings')->where('id', $existing['id'])->update(['status' => 'active', 'capacity' => $section['capacity'], 'updated_at' => $now]);
            $offeringId = (int) $existing['id'];
            $action     = 'structure.class_section_course_restored';
        } else {
            $db->table('course_offerings')->insert([
                'course_id'        => $courseId,
                'term_id'          => $section['term_id'],
                'section_code'     => $section['code'],
                'capacity'         => $section['capacity'],
                'class_section_id' => $sectionId,
                'status'           => 'active',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $offeringId = (int) $db->insertID();
            $action     = 'structure.class_section_course_added';
        }

        $this->audit($action, $actor, $institutionId, $sectionId, null, ['offering_id' => $offeringId, 'course_id' => $courseId]);

        \App\Services\Messaging\CourseChatHooks::offeringChanged($offeringId);

        $members = $this->memberEmails($sectionId);
        $result  = ['enrolled' => 0, 'warnings' => []];

        if ($members !== []) {
            $result = $this->enrolInto($institutionId, $offeringId, $course['course_code'], $members, $actor);
        }

        return ['offering_id' => $offeringId, 'enrolled' => $result['enrolled'], 'warnings' => $result['warnings']];
    }

    /**
     * Takes a course out of the section by archiving its offering, which only an offering with nobody
     * enrolled can be.
     *
     * @throws StructureException
     */
    public function removeCourse(int $institutionId, int $sectionId, int $offeringId, ?User $actor = null): void
    {
        $scope = $this->scope($institutionId);
        $scope->require('classSection', $sectionId);
        $scope->require('offering', $offeringId);

        $db       = db_connect();
        $offering = $db->table('course_offerings o')->select('o.*, c.course_code')->join('courses c', 'c.id = o.course_id')->where('o.id', $offeringId)->get()->getRowArray();

        if ((int) $offering['class_section_id'] !== $sectionId) {
            throw new StructureException('That course is not part of this section.');
        }

        $this->mustBeOpen($db->table('class_sections')->where('id', $sectionId)->get()->getRowArray());

        if ($offering['status'] === 'archived') {
            return;
        }

        $enrolled = (int) $db->table('enrollments')->where(['offering_id' => $offeringId, 'status' => 'enrolled'])->countAllResults();

        if ($enrolled > 0) {
            throw new StructureException("{$offering['course_code']} has {$enrolled} student" . ($enrolled === 1 ? '' : 's') . ' enrolled, so it cannot be taken out. Drop their enrolments first, on the roster.');
        }

        $db->table('course_offerings')->where('id', $offeringId)->update(['status' => 'archived', 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('structure.class_section_course_removed', $actor, $institutionId, $sectionId, ['offering_id' => $offeringId], null);

        \App\Services\Messaging\CourseChatHooks::offeringChanged($offeringId);
    }

    /*
    |--------------------------------------------------------------------------
    | Its students
    |--------------------------------------------------------------------------
    */

    /**
     * Puts the students named in the pasted text into the section and enrols them in each of its courses.
     * Names that cannot be added are skipped with the reason; the rest go in, or none do when the capacity
     * would be exceeded without the override.
     *
     * @return array{added: int, skipped: list<array{identifier: string, reason: string}>, warnings: list<string>}
     *
     * @throws StructureException
     */
    public function addStudents(int $institutionId, int $sectionId, string $text, bool $overCapacity, ?User $actor = null): array
    {
        $this->scope($institutionId)->require('classSection', $sectionId);

        $db      = db_connect();
        $section = $db->table('class_sections')->where('id', $sectionId)->get()->getRowArray();

        $this->mustBeOpen($section);

        $names = EnrolmentList::parse($text);

        if ($names === []) {
            throw StructureException::field('students', 'Enter at least one student number or email address.');
        }

        if (count($names) > EnrolmentList::MAX) {
            throw StructureException::field('students', 'That is more than ' . EnrolmentList::MAX . ' at once. Add them in smaller groups, or upload a CSV file.');
        }

        $skipped = [];
        $new     = [];

        foreach ($names as $name) {
            $student = $this->enrolments->findStudent($institutionId, $name);

            if (is_string($student)) {
                $skipped[] = ['identifier' => $name, 'reason' => $student];

                continue;
            }

            $studentId = (int) $student['id'];

            if ($db->table('class_section_students')->where(['class_section_id' => $sectionId, 'student_user_id' => $studentId])->countAllResults() > 0) {
                $skipped[] = ['identifier' => $name, 'reason' => 'is already in this section'];

                continue;
            }

            $new[$studentId] = $name;
        }

        if ($new === []) {
            throw StructureException::field('students', $this->skippedText($skipped, 'Nobody was added.'));
        }

        $members = $this->memberCount($sectionId);
        $over    = $members + count($new) - (int) $section['capacity'];

        if ($over > 0 && ! $overCapacity) {
            throw StructureException::field('over_capacity', sprintf('This would put the section over capacity by %d (%d of %d places are taken). Tick the box to add them anyway.', $over, $members, (int) $section['capacity']));
        }

        $now = date('Y-m-d H:i:s');

        $db->transStart();

        foreach (array_keys($new) as $studentId) {
            $db->table('class_section_students')->insert(['class_section_id' => $sectionId, 'student_user_id' => $studentId, 'created_at' => $now]);
            $this->audit('structure.class_section_student_added', $actor, $institutionId, $sectionId, null, ['student_user_id' => $studentId]);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new StructureException('The students could not be added. Nothing was changed.');
        }

        // Each of the section's courses enrols them, through the rules of an enrolment.
        $emails   = $this->emailsOf(array_keys($new));
        $warnings = [];

        $offerings = $db->table('course_offerings o')->select('o.id, c.course_code')->join('courses c', 'c.id = o.course_id')
            ->where('o.class_section_id', $sectionId)->where('o.status', 'active')->orderBy('c.course_code')->get()->getResultArray();

        foreach ($offerings as $offering) {
            $already = array_column($db->table('enrollments')->select('student_user_id')->where(['offering_id' => $offering['id'], 'status' => 'enrolled'])->whereIn('student_user_id', array_keys($new))->get()->getResultArray(), 'student_user_id');
            $todo    = [];

            foreach ($new as $studentId => $name) {
                if (! in_array((string) $studentId, array_map('strval', $already), true)) {
                    $todo[] = $emails[$studentId] ?? $name;
                }
            }

            if ($todo !== []) {
                $warnings = array_merge($warnings, $this->enrolInto($institutionId, (int) $offering['id'], (string) $offering['course_code'], $todo, $actor)['warnings']);
            }
        }

        if ($skipped !== []) {
            $warnings[] = $this->skippedText($skipped, count($skipped) === 1 ? '1 was skipped.' : count($skipped) . ' were skipped.');
        }

        return ['added' => count($new), 'skipped' => $skipped, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Takes a student out of the section and drops their places in its courses (their work and grades stay).
     *
     * @return array{dropped: int, warnings: list<string>}
     *
     * @throws StructureException
     */
    public function removeStudent(int $institutionId, int $sectionId, int $studentId, ?User $actor = null): array
    {
        $this->scope($institutionId)->require('classSection', $sectionId);

        $db = db_connect();

        $this->mustBeOpen($db->table('class_sections')->where('id', $sectionId)->get()->getRowArray());

        if ($db->table('class_section_students')->where(['class_section_id' => $sectionId, 'student_user_id' => $studentId])->countAllResults() === 0) {
            throw new StructureException('That student is not in this section.');
        }

        $db->table('class_section_students')->where(['class_section_id' => $sectionId, 'student_user_id' => $studentId])->delete();

        $this->audit('structure.class_section_student_removed', $actor, $institutionId, $sectionId, ['student_user_id' => $studentId], null);

        $places = $db->table('enrollments e')->select('e.id, c.course_code')
            ->join('course_offerings o', 'o.id = e.offering_id')
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.class_section_id', $sectionId)->where('e.student_user_id', $studentId)->where('e.status', 'enrolled')
            ->get()->getResultArray();

        $dropped  = 0;
        $warnings = [];

        foreach ($places as $place) {
            try {
                $this->enrolments->setStatus($institutionId, (int) $place['id'], 'dropped', true, $actor);
                $dropped++;
            } catch (StructureException $e) {
                $warnings[] = "{$place['course_code']}: " . $e->getMessage();
            }
        }

        return ['dropped' => $dropped, 'warnings' => $warnings];
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

    /**
     * A section takes changes only while it is active and its term is upcoming or active (a closed term's
     * enrolments are history).
     *
     * @param array<string, mixed> $section
     *
     * @throws StructureException
     */
    private function mustBeOpen(array $section): void
    {
        if ($section['status'] !== 'active') {
            throw new StructureException('This section is archived, so it cannot be changed.');
        }

        $term = db_connect()->table('academic_terms')->where('id', $section['term_id'])->get()->getRowArray();

        if (! in_array($term['status'], ['upcoming', 'active'], true)) {
            throw new StructureException("{$term['name']} is closed, so this section cannot be changed.");
        }
    }

    private function memberCount(int $sectionId): int
    {
        return (int) db_connect()->table('class_section_students')->where('class_section_id', $sectionId)->countAllResults();
    }

    /** @return list<string> */
    private function memberEmails(int $sectionId): array
    {
        $ids = array_map('intval', array_column(db_connect()->table('class_section_students')->select('student_user_id')->where('class_section_id', $sectionId)->get()->getResultArray(), 'student_user_id'));

        return array_values($this->emailsOf($ids));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> user id => email
     */
    private function emailsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (db_connect()->table('auth_identities')->select('user_id, secret')->where('type', 'email_password')->whereIn('user_id', $ids)->get()->getResultArray() as $r) {
            $out[(int) $r['user_id']] = (string) $r['secret'];
        }

        return $out;
    }

    /**
     * Enrols people (emails) in one offering through the enrolment rules. A refusal that leaves nobody enrolled
     * (every one of them conflicts, say) comes back as a warning, not as a failure: the section change stands.
     *
     * @param list<string> $identifiers
     *
     * @return array{enrolled: int, warnings: list<string>}
     */
    private function enrolInto(int $institutionId, int $offeringId, string $courseCode, array $identifiers, ?User $actor): array
    {
        try {
            $result = $this->enrolments->add($institutionId, $offeringId, implode("\n", $identifiers), true, $actor);
        } catch (StructureException $e) {
            return ['enrolled' => 0, 'warnings' => ["{$courseCode}: " . $e->getMessage()]];
        }

        return [
            'enrolled' => $result['added'] + $result['reinstated'],
            'warnings' => array_map(static fn (string $w): string => "{$courseCode}: {$w}", $result['warnings']),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{code: string, year_level: int, capacity: int}
     *
     * @throws StructureException
     */
    private function values(array $input): array
    {
        $code     = strtoupper(trim((string) ($input['code'] ?? '')));
        $year     = trim((string) ($input['year_level'] ?? ''));
        $capacity = trim((string) ($input['capacity'] ?? ''));

        if ($code === '' || preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw StructureException::field('code', 'The section name is required: letters, numbers, spaces, dots, dashes or underscores, up to 50 characters. For example BSIT 1-A.');
        }

        if ($year === '' || preg_match('/^\d{1,2}$/', $year) !== 1 || (int) $year < 1 || (int) $year > self::MAX_YEAR_LEVEL) {
            throw StructureException::field('year_level', 'The year level is a whole number from 1 to ' . self::MAX_YEAR_LEVEL . '.');
        }

        if ($capacity === '' || preg_match('/^\d{1,4}$/', $capacity) !== 1 || (int) $capacity < 1 || (int) $capacity > self::MAX_CAPACITY) {
            throw StructureException::field('capacity', 'Capacity is a whole number from 1 to ' . self::MAX_CAPACITY . '.');
        }

        return ['code' => $code, 'year_level' => (int) $year, 'capacity' => (int) $capacity];
    }

    /**
     * @throws StructureException
     */
    private function codeIsFree(int $institutionId, int $termId, string $code, ?int $exceptId): void
    {
        $builder = db_connect()->table('class_sections')->where(['institution_id' => $institutionId, 'term_id' => $termId, 'code' => $code]);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw StructureException::field('code', "There is already a section called {$code} in that term.");
        }
    }

    /**
     * @param list<array{identifier: string, reason: string}> $skipped
     */
    private function skippedText(array $skipped, string $lead): string
    {
        $parts = array_map(static fn (array $s): string => $s['identifier'] . ' ' . $s['reason'], array_slice($skipped, 0, 5));
        $more  = count($skipped) > 5 ? ' and ' . (count($skipped) - 5) . ' more' : '';

        return $skipped === [] ? $lead : $lead . ' Skipped: ' . implode('; ', $parts) . $more . '.';
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'class_section',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
