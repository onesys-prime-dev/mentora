<?php

declare(strict_types=1);

use App\Services\Grades\ChairpersonProgramOverview;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * The sections behind the chairperson's Program tab: the sections of the sub-departments they chair, with
 * average, at risk, waiting and status, a finalization tracker, search, filter, sort and pages.
 *
 * @internal
 */
final class ChairpersonProgramOverviewTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $chair;

    private ChairpersonProgramOverview $overview;

    /** @var array{sub: int, program: int, institution: int, department: int} */
    private array $home;

    private int $term;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overview = new ChairpersonProgramOverview();
        $this->chair    = PeopleFactory::person('chairperson', 'Cara', 'Chair');
        $this->home     = $this->unit('H');
        $this->term     = $this->term($this->home['institution']);

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id' => $this->home['sub'], 'chairperson_user_id' => $this->chair->id,
            'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{sub: int, program: int, institution: int, department: int} */
    private function unit(string $tag, ?int $institution = null, ?int $department = null): array
    {
        $db = db_connect();

        if ($institution === null) {
            $db->table('institutions')->insert(['name' => "Inst {$tag}", 'code' => "I{$tag}" . uniqid()]);
            $institution = (int) $db->insertID();
        }

        if ($department === null) {
            $db->table('departments')->insert(['institution_id' => $institution, 'code' => "D{$tag}", 'name' => "College {$tag}"]);
            $department = (int) $db->insertID();
        }

        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => "S{$tag}", 'name' => "Department {$tag}"]);
        $sub = (int) $db->insertID();

        $db->table('programs')->insert(['sub_department_id' => $sub, 'code' => "P{$tag}", 'name' => "Program {$tag}"]);

        return ['sub' => $sub, 'program' => (int) $db->insertID(), 'institution' => $institution, 'department' => $department];
    }

    private function term(int $institution, string $start = '2026-08-01'): int
    {
        db_connect()->table('academic_terms')->insert([
            'institution_id' => $institution, 'name' => 'Term ' . (++$this->seq), 'academic_year' => '2026-2027',
            'start_date' => $start, 'end_date' => date('Y-m-d', strtotime($start . ' +141 days')),
        ]);

        return (int) db_connect()->insertID();
    }

    private function section(?string $code = null, string $section = 'A', string $status = 'active', ?array $unit = null, ?int $term = null, ?string $title = null): int
    {
        $db   = db_connect();
        $code ??= 'IT' . (100 + (++$this->seq));

        $db->table('courses')->insert(['program_id' => ($unit ?? $this->home)['program'], 'course_code' => $code, 'course_title' => $title ?? "Course {$code}", 'units' => 3]);
        $db->table('course_offerings')->insert(['course_id' => (int) $db->insertID(), 'term_id' => $term ?? $this->term, 'section_code' => $section, 'status' => $status]);

        return (int) $db->insertID();
    }

    private function instructor(int $offering, string $first = 'Ian', string $last = 'Structor'): User
    {
        $user = PeopleFactory::person('instructor', $first, $last);
        CourseFactory::assignInstructor($offering, $user);

        return $user;
    }

    private function publish(int $offering): int
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        return $scheme;
    }

    private function schemeOf(int $offering): ?int
    {
        $row = db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->get()->getRow();

        return $row === null ? null : (int) $row->id;
    }

    /** An enrolled (or other status) student with a gradebook record. */
    private function student(int $offering, ?float $percentage, array $record = [], string $status = 'enrolled'): User
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($offering, $student, $status);

        db_connect()->table('gradebook_records')->insert($record + [
            'course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => $this->schemeOf($offering),
            'calculated_percentage' => $percentage, 'remarks' => 'Passed',
        ]);

        return $student;
    }

    /** $count students with one ungraded quiz attempt each. */
    private function waiting(int $offering, int $count): void
    {
        $quiz = CourseFactory::assessment($offering, $this->chair, CourseFactory::module($offering), null, 'Quiz');
        db_connect()->table('assessments')->where('id', $quiz)->update(['status' => 'Published']);

        for ($i = 0; $i < $count; $i++) {
            $student = CourseFactory::user('student');
            CourseFactory::enroll($offering, $student);
            AssessmentFactory::attempt($quiz, $student, 'Submitted');
        }
    }

    private function finalize(int $offering): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $offering, 'finalized_by' => $this->chair->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    private function overrideRequest(int $offering): void
    {
        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $offering, 'student_id' => CourseFactory::user('student')->id, 'requested_percentage' => 80,
            'reason' => 'Appeal', 'requested_by' => $this->chair->id, 'requester_role' => 'instructor',
            'status' => 'awaiting_dean', 'open_marker' => 1,
        ]);
    }

    /** @return list<int> */
    private function ids(array $opts = []): array
    {
        return array_column($this->overview->sections($this->chair, $this->term, $opts)['rows'], 'offering_id');
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(array $opts = []): array
    {
        $out = [];

        foreach ($this->overview->sections($this->chair, $this->term, $opts)['rows'] as $row) {
            $out[$row['offering_id']] = $row;
        }

        return $out;
    }

    public function testOnlySectionsOfChairedSubDepartmentsAreListed(): void
    {
        $active    = $this->section('IT101');
        $completed = $this->section('IT102', 'A', 'completed');
        $this->section('IT103', 'A', 'archived');

        $sibling = $this->unit('S', $this->home['institution'], $this->home['department']);
        $this->section('IT201', 'A', 'active', $sibling); // same department, another sub-department

        $elsewhere = $this->unit('O');
        $this->section('CS101', 'A', 'active', $elsewhere, $this->term($elsewhere['institution']));

        // A term whose only chaired section is archived, and an older term.
        $this->section('IT104', 'A', 'archived', null, $this->term($this->home['institution'], '2027-01-10'));
        $older = $this->term($this->home['institution'], '2025-08-01');
        $this->section('IT105', 'A', 'active', null, $older);

        $this->assertSame([$active, $completed], $this->ids());
        $this->assertSame(2, $this->overview->sections($this->chair, $this->term)['total']);

        $terms = $this->overview->terms($this->chair);
        $this->assertSame([$this->term, $older], array_column($terms, 'id'), 'terms of chaired sections, newest first');
        $this->assertSame(['id', 'name', 'academic_year', 'status'], array_keys($terms[0]));

        $this->assertSame($older, $this->overview->defaultTermId($terms, $older));
        $this->assertSame($this->term, $this->overview->defaultTermId($terms, 9999));

        // A revoked assignment reaches nothing.
        db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $this->chair->id)->update(['status' => 'revoked']);
        $this->assertSame([], $this->ids());
        $this->assertSame([], $this->overview->terms($this->chair));
    }

    public function testColumnsAverageAtRiskWaitingAndStatus(): void
    {
        $graded = $this->section('IT101', 'B', 'active', null, null, 'Intro to Computing');
        $this->publish($graded);
        $this->instructor($graded, 'Zed', 'Young');
        $alan = $this->instructor($graded, 'Alan', 'Turing');

        $this->student($graded, 80.0);
        $this->student($graded, 60.0, ['remarks' => 'Failed', 'inc_set_at' => date('Y-m-d H:i:s')]); // one student, two reasons
        $this->student($graded, 50.0, ['is_override' => 1, 'overridden_percentage' => 90.0]);         // the override counts
        $this->student($graded, null, ['inc_set_at' => date('Y-m-d H:i:s'), 'remarks' => 'INC']);    // at risk, no percentage
        $this->student($graded, 10.0, ['remarks' => 'Failed'], 'dropped');                          // not counted at all
        $this->waiting($graded, 1);

        // A draft scheme: its failing record is not "at risk", since grades are not published.
        $draft = $this->section('IT102');
        GradeFactory::scheme($draft);
        $this->student($draft, 40.0, ['remarks' => 'Failed']);

        $bare = $this->section('IT103');

        $rows = $this->rowsById();
        $row  = $rows[$graded];

        $this->assertSame(
            ['offering_id', 'course_code', 'course_title', 'section_code', 'slug', 'instructors', 'average', 'at_risk', 'waiting', 'state', 'note', 'has_scheme', 'offering_status'],
            array_keys($row)
        );
        $this->assertSame('IT101', $row['course_code']);
        $this->assertSame('Intro to Computing', $row['course_title']);
        $this->assertSame('B', $row['section_code']);
        $this->assertSame('intro-to-computing', $row['slug']);
        $this->assertSame('Alan Turing', $row['instructors'][0]['name'], 'by last name');
        $this->assertSame((int) $alan->id, $row['instructors'][0]['id']);
        $this->assertSame('Zed Young', $row['instructors'][1]['name']);
        $this->assertEqualsWithDelta((80 + 60 + 90) / 3, $row['average'], 0.001);
        $this->assertSame(2, $row['at_risk']);
        $this->assertSame(1, $row['waiting']);
        $this->assertSame('waiting', $row['state']);
        $this->assertSame('1 waiting to be graded', $row['note']);
        $this->assertTrue($row['has_scheme']);
        $this->assertSame('active', $row['offering_status']);

        $this->assertSame(0, $rows[$draft]['at_risk']);
        $this->assertSame('not_published', $rows[$draft]['state']);
        $this->assertTrue($rows[$draft]['has_scheme']);

        $this->assertNull($rows[$bare]['average']);
        $this->assertSame([], $rows[$bare]['instructors']);
        $this->assertSame(0, $rows[$bare]['at_risk']);
    }

    public function testTheTrackerCountsTheWholeTermWhateverTheFilter(): void
    {
        $finalized = $this->section('IT101');
        $this->publish($finalized);
        $this->finalize($finalized);

        $ready = $this->section('IT102');
        $this->publish($ready);

        $blocked = $this->section('IT103');
        $this->publish($blocked);
        $this->overrideRequest($blocked);

        $this->section('IT104'); // not published: not finalizable either

        $expected = ['finalized' => 1, 'ready' => 1, 'blocked' => 2, 'total' => 4];

        $all = $this->overview->sections($this->chair, $this->term);
        $this->assertSame($expected, $all['tracker']);
        $this->assertSame(4, $all['total']);

        $narrowed = $this->overview->sections($this->chair, $this->term, ['q' => 'IT102', 'notFinalized' => true, 'page' => 1]);
        $this->assertSame($expected, $narrowed['tracker']);
        $this->assertSame(1, $narrowed['total']);
        $this->assertSame([$ready], array_column($narrowed['rows'], 'offering_id'));

        $this->assertSame(['finalized' => 0, 'ready' => 0, 'blocked' => 0, 'total' => 0], $this->overview->sections($this->chair, $this->term($this->home['institution'], '2030-01-01'))['tracker']);
    }

    public function testSearchMatchesCodeTitleOrInstructor(): void
    {
        $intro = $this->section('IT101', 'A', 'active', null, null, 'Intro to Computing');
        $data  = $this->section('CS202', 'B', 'active', null, null, 'Data Structures');
        $calc  = $this->section('MA303', 'A', 'active', null, null, 'Calculus');
        $this->instructor($calc, 'Grace', 'Hopper');

        $this->assertSame([$intro], $this->ids(['q' => 'it10']));
        $this->assertSame([$data], $this->ids(['q' => 'STRUCTURES']));
        $this->assertSame([$calc], $this->ids(['q' => 'hopper']));
        $this->assertSame([$data], $this->ids(['q' => 'CS202-B']), 'the section label');
        $this->assertSame([$data, $intro, $calc], $this->ids(['q' => '  ']));
        $this->assertSame([], $this->ids(['q' => 'nothing like it']));
    }

    public function testNotFinalizedOnly(): void
    {
        $done = $this->section('IT101');
        $this->publish($done);
        $this->finalize($done);

        $open = $this->section('IT102');
        $this->publish($open);

        $this->assertSame([$done, $open], $this->ids());
        $this->assertSame([$open], $this->ids(['notFinalized' => true]));
        $this->assertSame(1, $this->overview->sections($this->chair, $this->term, ['notFinalized' => true])['total']);
    }

    public function testSortByEachColumnBothWays(): void
    {
        $a = $this->section('A101');
        $b = $this->section('B101');
        $c = $this->section('C101');

        foreach ([$a, $b, $c] as $offering) {
            $this->publish($offering);
        }

        $this->instructor($a, 'Carol', 'Xu');
        $this->instructor($b, 'Alice', 'Zu');
        $this->instructor($c, 'Bob', 'Yu');

        // Averages A 70, B 90, C 80.
        $this->student($a, 70.0);
        $this->student($b, 90.0);
        $this->student($c, 80.0);

        // At risk A 2, B 0, C 1 (INC without a percentage, so the averages stay).
        foreach ([$a, $a, $c] as $offering) {
            $this->student($offering, null, ['inc_set_at' => date('Y-m-d H:i:s')]);
        }

        // Waiting A 0, B 2, C 1: A is ready, B and C are waiting.
        $this->waiting($b, 2);
        $this->waiting($c, 1);

        $cases = [
            'section' => [[$a, $b, $c], [$c, $b, $a]],
            'instructor' => [[$b, $c, $a], [$a, $c, $b]],
            'average' => [[$a, $c, $b], [$b, $c, $a]],
            'at_risk' => [[$b, $c, $a], [$a, $c, $b]],
            'waiting' => [[$a, $c, $b], [$b, $c, $a]],
            // Not finalizable first; ties keep the section order.
            'status' => [[$b, $c, $a], [$a, $b, $c]],
        ];

        foreach ($cases as $sort => [$asc, $desc]) {
            $this->assertSame($asc, $this->ids(['sort' => $sort, 'dir' => 'asc']), "{$sort} asc");
            $this->assertSame($desc, $this->ids(['sort' => $sort, 'dir' => 'desc']), "{$sort} desc");
        }

        $this->assertSame([$a, $b, $c], $this->ids(['sort' => 'nonsense', 'dir' => 'sideways']), 'unknown values fall back to section, ascending');
    }

    public function testASectionWithoutAnAverageSortsLastEitherWay(): void
    {
        $none = $this->section('A101');
        $low  = $this->section('B101');
        $high = $this->section('C101');
        $this->publish($low);
        $this->publish($high);
        $this->student($low, 60.0);
        $this->student($high, 95.0);

        $this->assertSame([$low, $high, $none], $this->ids(['sort' => 'average', 'dir' => 'asc']));
        $this->assertSame([$high, $low, $none], $this->ids(['sort' => 'average', 'dir' => 'desc']));
    }

    public function testANotPublishedSectionSortsLastByAverageEitherWay(): void
    {
        // Its average is hidden on the page, so it must not sort as if it were shown.
        $draft = $this->section('A101');
        GradeFactory::scheme($draft);
        $this->student($draft, 99.0);

        $low  = $this->section('B101');
        $high = $this->section('C101');
        $this->publish($low);
        $this->publish($high);
        $this->student($low, 60.0);
        $this->student($high, 95.0);

        $this->assertSame('not_published', $this->rowsById()[$draft]['state']);
        $this->assertSame([$low, $high, $draft], $this->ids(['sort' => 'average', 'dir' => 'asc']));
        $this->assertSame([$high, $low, $draft], $this->ids(['sort' => 'average', 'dir' => 'desc']));
    }

    public function testASectionWithoutAnInstructorSortsLastByInstructorEitherWay(): void
    {
        $none  = $this->section('A101');
        $alice = $this->section('B101');
        $bob   = $this->section('C101');
        $this->instructor($alice, 'Alice', 'Zu');
        $this->instructor($bob, 'Bob', 'Yu');

        $this->assertSame([$alice, $bob, $none], $this->ids(['sort' => 'instructor', 'dir' => 'asc']));
        $this->assertSame([$bob, $alice, $none], $this->ids(['sort' => 'instructor', 'dir' => 'desc']));
    }

    public function testTermEndIsTheTermsLastDay(): void
    {
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['end_date' => '2026-12-18']);

        $this->assertSame('2026-12-18', $this->overview->termEnd($this->term));
        $this->assertNull($this->overview->termEnd(999999));
    }

    public function testPagesOf25(): void
    {
        $ids = [];

        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->section(sprintf('IT%03d', $i + 1));
        }

        $first = $this->overview->sections($this->chair, $this->term);
        $this->assertCount(25, $first['rows']);
        $this->assertSame(30, $first['total']);
        $this->assertSame(array_slice($ids, 0, 25), array_column($first['rows'], 'offering_id'));

        $this->assertSame(array_slice($ids, 25), $this->ids(['page' => 2]));
        $this->assertSame(array_slice($ids, 25), $this->ids(['page' => 9]), 'past the end is the last page');
        $this->assertSame(array_slice($ids, 0, 25), $this->ids(['page' => 0]));
    }

    public function testMySectionsAreTaughtAndChaired(): void
    {
        $taught = $this->section('IT101');
        CourseFactory::assignInstructor($taught, $this->chair);

        $this->section('IT102'); // chaired, not taught

        $sibling = $this->unit('S', $this->home['institution'], $this->home['department']);
        $outside = $this->section('IT201', 'A', 'active', $sibling); // taught, not chaired
        CourseFactory::assignInstructor($outside, $this->chair);

        $mine = $this->overview->mySections($this->chair, $this->term);

        $this->assertSame([$taught], array_column($mine, 'offering_id'));
        $this->assertSame(
            ['offering_id', 'course_code', 'course_title', 'section_code', 'slug', 'students', 'waiting', 'state', 'note', 'offering_status', 'has_scheme'],
            array_keys($mine[0]),
            'the instructor workbench rows'
        );

        $this->assertSame([], (new ChairpersonProgramOverview())->mySections(PeopleFactory::person('chairperson'), $this->term));
    }

    public function testNoSchemeIsNotCreated(): void
    {
        $offering = $this->section('IT101');
        CourseFactory::assignInstructor($offering, $this->chair);

        $row = $this->rowsById()[$offering];
        $this->overview->mySections($this->chair, $this->term);

        $this->assertSame('not_published', $row['state']);
        $this->assertSame('Grades not published yet', $row['note']);
        $this->assertFalse($row['has_scheme']);
        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->countAllResults());
    }

    public function testTheQueryCountDoesNotGrowWithSections(): void
    {
        $few  = $this->busyTerm(3);
        $many = $this->busyTerm(15);

        $this->overview->sections($this->chair, $few); // warm up

        $three   = $this->queriesOf(fn () => $this->overview->sections($this->chair, $few, ['sort' => 'average']));
        $fifteen = $this->queriesOf(fn () => $this->overview->sections($this->chair, $many, ['sort' => 'average']));

        $this->assertGreaterThan(0, $three);
        $this->assertSame($three, $fifteen);

        $this->assertSame(
            $this->queriesOf(fn () => $this->overview->mySections($this->chair, $few)),
            $this->queriesOf(fn () => $this->overview->mySections($this->chair, $many))
        );
    }

    private function busyTerm(int $sections): int
    {
        $term = $this->term($this->home['institution'], '2027-0' . (1 + $this->seq % 8) . '-01');
        $db   = db_connect();
        $now  = date('Y-m-d H:i:s');

        for ($i = 0; $i < $sections; $i++) {
            $offering = $this->section(null, 'A', 'active', null, $term);
            CourseFactory::assignInstructor($offering, $this->chair);
            $this->instructor($offering);
            $scheme = $this->publish($offering);

            $quiz = CourseFactory::assessment($offering, $this->chair, CourseFactory::module($offering), null, 'Quiz');
            $db->table('assessments')->where('id', $quiz)->update(['status' => 'Published']);

            for ($s = 0; $s < 50; $s++) {
                $student = CourseFactory::user('student');
                CourseFactory::enroll($offering, $student);
                $db->table('assessment_submissions')->insert([
                    'assessment_id' => $quiz, 'student_id' => $student->id, 'attempt_number' => 1, 'started_at' => $now,
                    'submitted_at' => $now, 'score' => 0, 'is_graded' => 0, 'status' => 'Submitted',
                ]);
                $db->table('gradebook_records')->insert([
                    'course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => $scheme,
                    'calculated_percentage' => 50 + $s, 'remarks' => $s < 5 ? 'Failed' : 'Passed',
                ]);
            }
        }

        return $term;
    }

    private function queriesOf(callable $work): int
    {
        $queries  = 0;
        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        $work();
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }
}
