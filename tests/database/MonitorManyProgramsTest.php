<?php

declare(strict_types=1);

use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Monitor Program over a set of programs, not one chairperson: the same
 * metrics give the same numbers for one program and for many (the union of
 * the single ones), an empty set is nothing, the chairperson's page is
 * exactly the set of programs under what they chair, and the audit viewer
 * works for a scope with no single actor.
 *
 * @internal
 */
final class MonitorManyProgramsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private MonitorProgramService $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitor = new MonitorProgramService();
    }

    /** @return array{sub: int, program: int, institution: int, department: int} */
    private function unit(string $tag, ?int $institution = null, ?int $department = null): array
    {
        $db = db_connect();

        if ($institution === null) {
            $db->table('institutions')->insert(['name' => "Inst {$tag}", 'code' => "I{$tag}"]);
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

    private function term(int $institution, string $name = 'First', string $start = '2026-08-01'): int
    {
        db_connect()->table('academic_terms')->insert([
            'institution_id' => $institution, 'name' => $name, 'academic_year' => '2026-2027', 'start_date' => $start, 'end_date' => '2026-12-20',
        ]);

        return (int) db_connect()->insertID();
    }

    private function offering(array $unit, int $term, string $code, string $section = 'A'): int
    {
        $db = db_connect();

        $db->table('courses')->insert(['program_id' => $unit['program'], 'course_code' => $code, 'course_title' => "Course {$code}", 'units' => 3]);
        $course = (int) $db->insertID();

        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => $section, 'status' => 'active']);

        return (int) $db->insertID();
    }

    private function student(int $offeringId): User
    {
        $student = CourseFactory::user('student');

        db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace' . $student->id]);
        CourseFactory::enroll($offeringId, $student);

        return $student;
    }

    /** @return array<int, int> offering => its enrolled count, built with some progress and a published grade */
    private function populate(int $offering, int $students, int $itemsDone): void
    {
        $module = CourseFactory::module($offering);
        $unit   = CourseFactory::unit($module);
        $items  = CourseFactory::items($unit, [['title' => 'One'], ['title' => 'Two']]);

        $scheme = GradeFactory::scheme($offering);
        $grade  = GradeFactory::item($scheme, null, 'Quiz', 10);

        for ($i = 0; $i < $students; $i++) {
            $student = $this->student($offering);

            for ($k = 0; $k < $itemsDone; $k++) {
                CourseFactory::progress($items[$k], $student);
            }

            GradeFactory::score($offering, $student, $grade, 8.0 + $i);
        }
    }

    /** @param list<array<string, mixed>> $rows @return list<int> */
    private function ids(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['offering_id'], $rows);
        sort($ids);

        return $ids;
    }

    public function testOfferingsOfOneProgramAndOfManyAreTheUnion(): void
    {
        $a = $this->unit('A');
        $b = $this->unit('B', $a['institution']);
        $c = $this->unit('C');

        $term = $this->term($a['institution']);
        $one  = $this->offering($a, $term, 'IT101');
        $two  = $this->offering($b, $term, 'CS101');
        $this->offering($c, $this->term($c['institution']), 'ZZ999');

        $filters = new MonitorProgramFilters();

        $this->assertSame([$one], $this->ids($this->monitor->offeringRowsForPrograms([$a['program']], $filters)));
        $this->assertSame([$two], $this->ids($this->monitor->offeringRowsForPrograms([$b['program']], $filters)));
        $this->assertSame([$one, $two], $this->ids($this->monitor->offeringRowsForPrograms([$a['program'], $b['program']], $filters)));
    }

    public function testAnEmptySetOfProgramsIsNothingNotEverything(): void
    {
        $a = $this->unit('A');
        $this->offering($a, $this->term($a['institution']), 'IT101');

        $this->assertSame([], $this->monitor->offeringRowsForPrograms([], new MonitorProgramFilters()));
        $this->assertSame([], $this->monitor->termsForPrograms([]));
        $this->assertSame([], $this->monitor->offeringRowsForPrograms([0, -1], new MonitorProgramFilters()));
    }

    public function testTheRowShapeIsTheOneTheCoursesPageReads(): void
    {
        $a  = $this->unit('A');
        $id = $this->offering($a, $this->term($a['institution']), 'IT101', 'B');

        $row = $this->monitor->offeringRowsForPrograms([$a['program']], new MonitorProgramFilters())[0];

        foreach (['offering_id', 'section_code', 'capacity', 'offering_status', 'course_id', 'course_code', 'course_title', 'program_id', 'program_name', 'term_id', 'term_name', 'academic_year', 'sub_department_id', 'department_id', 'institution_id', 'enrolled', 'instructor_count', 'instructor_name', 'instructor_id'] as $column) {
            $this->assertArrayHasKey($column, $row, $column);
        }

        $this->assertSame($id, (int) $row['offering_id']);
        $this->assertSame('B', $row['section_code']);
        $this->assertSame($a['institution'], (int) $row['institution_id']);
    }

    public function testATermNarrowsTheSetAndTheTermListHoldsOnlyTheirs(): void
    {
        $a = $this->unit('A');
        $first  = $this->term($a['institution'], 'First', '2026-08-01');
        $second = $this->term($a['institution'], 'Second', '2027-01-10');
        $x = $this->offering($a, $first, 'IT101');
        $y = $this->offering($a, $second, 'IT102');

        $this->assertSame([$y], $this->ids($this->monitor->offeringRowsForPrograms([$a['program']], new MonitorProgramFilters(termId: $second))));
        $this->assertSame([$x, $y], $this->ids($this->monitor->offeringRowsForPrograms([$a['program']], new MonitorProgramFilters())));

        $terms = $this->monitor->termsForPrograms([$a['program']]);
        $this->assertSame(['Second', 'First'], array_column($terms, 'name'), 'newest first');
    }

    public function testEveryMetricForManyProgramsIsWhatTheSingleProgramsGiveTogether(): void
    {
        $a = $this->unit('A');
        $b = $this->unit('B', $a['institution']);

        $term = $this->term($a['institution']);
        $one  = $this->offering($a, $term, 'IT101');
        $two  = $this->offering($b, $term, 'CS101');

        $this->populate($one, 2, 2);
        $this->populate($two, 3, 1);

        $filters = new MonitorProgramFilters();

        $rowsA = $this->monitor->offeringRowsForPrograms([$a['program']], $filters);
        $rowsB = $this->monitor->offeringRowsForPrograms([$b['program']], $filters);
        $rowsAll = $this->monitor->offeringRowsForPrograms([$a['program'], $b['program']], $filters);

        // Snapshot.
        $this->assertSame(
            ['courses' => 2, 'instructors' => 0, 'students' => 5],
            $this->monitor->snapshot($rowsAll),
        );
        $this->assertSame(
            $this->monitor->snapshot($rowsAll)['students'],
            $this->monitor->snapshot($rowsA)['students'] + $this->monitor->snapshot($rowsB)['students'],
        );

        // Enrolment and completion, grades and the comparison table.
        $idsAll = $this->ids($rowsAll);

        $this->assertSame(
            $this->monitor->enrollmentAndCompletion($this->ids($rowsA)) + $this->monitor->enrollmentAndCompletion($this->ids($rowsB)),
            $this->monitor->enrollmentAndCompletion($idsAll),
        );
        $this->assertSame(
            $this->monitor->grades($this->ids($rowsA)) + $this->monitor->grades($this->ids($rowsB)),
            $this->monitor->grades($idsAll),
        );

        $comparison = $this->monitor->courseComparison($rowsAll, $filters);
        $this->assertCount(2, $comparison);
        $this->assertSame(['CS101', 'IT101'], array_column($comparison, 'course_code'));
        $this->assertSame(100.0, $comparison[1]['completion_percentage']);
        $this->assertSame(50.0, $comparison[0]['completion_percentage']);

        // At risk: everyone is active today, and "behind" is below the platform's 50%.
        $this->assertSame(
            array_merge($this->monitor->atRisk($this->ids($rowsA)), $this->monitor->atRisk($this->ids($rowsB))),
            $this->monitor->atRisk($idsAll),
        );
    }

    public function testTheChairpersonsPageIsExactlyTheProgramsUnderWhatTheyChair(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home  = $this->unit('H');
        $same  = $this->unit('S', $home['institution'], $home['department']);
        $other = $this->unit('O', $home['institution']);

        db_connect()->table('chairperson_assignments')->insert(['sub_department_id' => $home['sub'], 'chairperson_user_id' => $chair->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);

        $term = $this->term($home['institution']);
        $mine = $this->offering($home, $term, 'IT101');
        $this->offering($same, $term, 'IT201');     // same department, another sub-department: not chaired
        $this->offering($other, $term, 'CS101');

        $this->assertSame([$home['program']], $this->monitor->chairedProgramIds($chair));

        $filters = new MonitorProgramFilters();

        $this->assertSame(
            $this->monitor->offeringRows($chair, $filters),
            $this->monitor->offeringRowsForPrograms($this->monitor->chairedProgramIds($chair), $filters),
        );
        $this->assertSame([$mine], $this->ids($this->monitor->offeringRows($chair, $filters)));
    }

    public function testAProgramAddedLaterAndAnEndedAssignmentAreReadLive(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home  = $this->unit('H');

        db_connect()->table('chairperson_assignments')->insert(['sub_department_id' => $home['sub'], 'chairperson_user_id' => $chair->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);

        $this->assertCount(1, $this->monitor->chairedProgramIds($chair));

        db_connect()->table('programs')->insert(['sub_department_id' => $home['sub'], 'code' => 'PNEW', 'name' => 'Program New']);
        $this->assertCount(2, $this->monitor->chairedProgramIds($chair), 'covered at once');

        db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $chair->id)->update(['status' => 'revoked']);
        $this->assertSame([], $this->monitor->chairedProgramIds($chair));
        $this->assertSame([], $this->monitor->offeringRows($chair, new MonitorProgramFilters()));
    }

    public function testTheAuditViewerWorksForAScopeWithNoSingleActor(): void
    {
        $a    = $this->unit('A');
        $term = $this->term($a['institution']);
        $mine = $this->offering($a, $term, 'IT101');
        $out  = $this->offering($this->unit('B'), $this->term($this->unit('B')['institution']), 'XX101');

        $chair = CourseFactory::user('chairperson');
        $rows  = $this->monitor->offeringRowsForPrograms([$a['program']], new MonitorProgramFilters());

        foreach ([$mine, $out] as $target) {
            db_connect()->table('audit_logs')->insert([
                'actor_user_id' => $chair->id, 'acting_role' => 'chairperson', 'action' => 'course.chairperson_action',
                'target_type' => 'offering', 'target_id' => (string) $target, 'new_values' => '{"method":"POST"}', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $entries = $this->monitor->auditEntries(null, $rows, new MonitorProgramFilters());

        $this->assertSame(1, $entries['total'], 'only what happened inside the scope');
        $this->assertSame($mine, $entries['rows'][0]['offering_id']);
        $this->assertSame(['rows' => [], 'total' => 0], $this->monitor->auditEntries(null, [], new MonitorProgramFilters()));

        // The chairperson's own view is unchanged: their actions, whatever the scope.
        $own = $this->monitor->auditLog($chair, $rows, new MonitorProgramFilters());
        $this->assertSame(2, $own['total']);
    }
}
