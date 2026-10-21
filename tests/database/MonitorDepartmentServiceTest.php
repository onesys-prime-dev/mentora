<?php

declare(strict_types=1);

use App\Services\Courses\MonitorDepartmentFilters;
use App\Services\Courses\MonitorDepartmentService;
use App\Services\Courses\MonitorProgramFilters;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * Monitor Department: the department's numbers grouped per sub-department and
 * per program. The fixture is one department with known numbers:
 *
 *   Sub A: program A1 (IT101: 2 students, all items done, grades 80 and 90;
 *          3 submissions, 2 graded after 24 h and 48 h), program A2 (CS101: nobody yet)
 *   Sub B: program B1 (LW100: 3 students, one never active; grades 80, 90, 100;
 *          1 submission graded after 12 h)
 *   Sub C: no programs
 *
 * and a second department of the same institution, which must never be counted.
 *
 * @internal
 */
final class MonitorDepartmentServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private MonitorDepartmentService $service;
    private int $institution;
    private int $department;
    private int $subA;
    private int $subB;
    private int $programA1;
    private int $programA2;
    private int $programB1;
    private int $term;
    private int $laterTerm;
    private int $it101;
    private int $lw100;
    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('H');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'A');
        $this->subB        = DeanFactory::subDepartment($this->department, 'B');
        DeanFactory::subDepartment($this->department, 'C'); // holds no program
        $this->programA1   = DeanFactory::program($this->subA, 'A1');
        $this->programA2   = DeanFactory::program($this->subA, 'A2');
        $this->programB1   = DeanFactory::program($this->subB, 'B1');
        $this->term        = DeanFactory::term($this->institution);
        $this->laterTerm   = DeanFactory::term($this->institution, 'Second', '2027-01-10');
        $this->dean        = DeanFactory::dean($this->institution, $this->department);
        $this->service     = new MonitorDepartmentService();

        $this->it101 = DeanFactory::offering($this->programA1, $this->term, 'IT101');
        DeanFactory::offering($this->programA2, $this->term, 'CS101'); // nobody enrolled yet
        $this->lw100 = DeanFactory::offering($this->programB1, $this->term, 'LW100');

        CourseFactory::assignInstructor($this->it101, PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution));
        CourseFactory::assignInstructor($this->lw100, PeopleFactory::person('instructor', 'Leo', 'Law', null, $this->institution));

        $teacher = PeopleFactory::person('instructor', 'Tom', 'Teacher', null, $this->institution);

        // IT101: two students, everything done, grades 80 and 90.
        $this->populate($this->it101, [2, 2], [8.0, 9.0], $teacher, [
            ['2026-09-02 08:00:00', '2026-09-03 08:00:00'],   // graded after 24 h
            ['2026-09-01 08:00:00', '2026-09-03 08:00:00'],   // graded after 48 h
            ['2026-09-04 08:00:00', null],                    // submitted, not graded yet
        ], '2026-09-01 10:00:00');

        // LW100: three students (the first never active), grades 80, 90, 100, one submission graded after 12 h.
        $this->populate($this->lw100, [0, 1, 1], [8.0, 9.0, 10.0], $teacher, [
            ['2026-10-05 00:00:00', '2026-10-05 12:00:00'],
        ], '2026-10-05 10:00:00');

        // Another department of the same institution: counted nowhere here.
        $other = DeanFactory::department('N', $this->institution);
        $next  = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($other['department'], 'N'), 'N'), $this->term, 'LAW900');
        $this->populate($next, [2, 2, 2, 2], [8.0, 8.0, 8.0, 8.0], $teacher, [], '2026-12-01 10:00:00');
    }

    /**
     * @param list<int>          $itemsDone items done per student (of 2)
     * @param list<float>        $scores    score per student out of 10
     * @param list<array{0: string, 1: ?string}> $submissions [submitted_at, graded_at]
     */
    private function populate(int $offering, array $itemsDone, array $scores, User $teacher, array $submissions, string $moduleUpdated): void
    {
        $module = CourseFactory::module($offering);
        $items  = CourseFactory::items(CourseFactory::unit($module), [['title' => 'One'], ['title' => 'Two']]);
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        $first = null;

        foreach ($itemsDone as $i => $done) {
            $student = CourseFactory::user('student');
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'Stu', 'last_name' => 'Dent' . $student->id]);
            CourseFactory::enroll($offering, $student);
            $first ??= $student;

            for ($k = 0; $k < $done; $k++) {
                CourseFactory::progress($items[$k], $student);
            }

            db_connect()->table('gradebook_records')->insert([
                'course_offering_id'    => $offering,
                'student_id'            => $student->id,
                'grading_scheme_id'     => $scheme,
                'calculated_percentage' => $scores[$i] * 10,
                'is_override'           => 0,
            ]);
        }

        db_connect()->table('course_modules')->where('id', $module)->update(['updated_at' => $moduleUpdated]);

        if ($submissions === [] || $first === null) {
            return;
        }

        $assessment = CourseFactory::assessment($offering, $teacher, $module);

        foreach ($submissions as [$submittedAt, $gradedAt]) {
            AssessmentFactory::attempt($assessment, $first, $gradedAt === null ? 'Submitted' : 'Graded', 0.0, [
                'submitted_at' => $submittedAt,
                'graded_at'    => $gradedAt,
                'attempt_number' => (int) db_connect()->table('assessment_submissions')->where('assessment_id', $assessment)->countAllResults() + 1,
            ]);
        }
    }

    private function filters(array $input = []): MonitorDepartmentFilters
    {
        return MonitorDepartmentFilters::fromInput($input);
    }

    /** @param list<array<string, mixed>> $groups @return array<string, array<string, mixed>> keyed by name */
    private function byName(array $groups): array
    {
        return array_column($groups, null, 'name');
    }

    public function testTheDepartmentTotalsAreWhatItsSubDepartmentsGiveTogether(): void
    {
        $overview = $this->service->overview($this->dean, $this->filters());
        $totals   = $overview['totals'];

        $this->assertSame(3, $totals['courses'], 'the other department is not counted');
        $this->assertSame(5, $totals['students']);
        $this->assertSame(2, $totals['instructors']);

        foreach (['courses', 'students', 'instructors', 'at_risk'] as $sum) {
            $this->assertSame($totals[$sum], array_sum(array_column($overview['groups'], $sum)), $sum);
        }
    }

    public function testAveragesAreWeightedByWhatTheyAverageNotAveragedAgain(): void
    {
        $totals = $this->service->overview($this->dean, $this->filters())['totals'];

        // Grades 80, 90 (IT101) and 80, 90, 100 (LW100): one mean over five students, not the mean of 85 and 90.
        $this->assertSame(88.0, $totals['average_grade']);
        // 24 h and 48 h (IT101) and 12 h (LW100): over three graded submissions, not the mean of 36 and 12.
        $this->assertSame(28.0, $totals['turnaround']);
        // IT101 is 100% complete for 2 students and LW100 33.3% for 3: weighted by students, (200 + 100) / 5.
        $this->assertSame(60.0, $totals['completion']);
        // 3 graded of 4 submitted.
        $this->assertSame(75.0, $totals['grading_progress']);
        $this->assertSame('2026-10-05 12:00:00', $totals['last_activity']);
    }

    public function testEverySubDepartmentIsARowIncludingOneWithNothingInIt(): void
    {
        $groups = $this->byName($this->service->overview($this->dean, $this->filters())['groups']);

        $this->assertSame(['Sub A', 'Sub B', 'Sub C'], array_keys($groups));
        $this->assertSame(2, $groups['Sub A']['students']);
        $this->assertSame(2, $groups['Sub A']['courses']);
        $this->assertSame(3, $groups['Sub B']['students']);
        $this->assertSame(100.0, $groups['Sub A']['completion']);
        $this->assertSame(85.0, $groups['Sub A']['average_grade']);
        $this->assertSame(66.7, $groups['Sub A']['grading_progress']);
        $this->assertSame(36.0, $groups['Sub A']['turnaround']);
        $this->assertSame('2026-09-03 08:00:00', $groups['Sub A']['last_activity'], 'the latest grading; a submission not graded yet is not activity');

        $empty = $groups['Sub C'];
        $this->assertSame([0, 0, 0, 0], [$empty['courses'], $empty['students'], $empty['instructors'], $empty['at_risk']]);
        $this->assertNull($empty['completion']);
        $this->assertNull($empty['average_grade']);
        $this->assertNull($empty['grading_progress']);
        $this->assertNull($empty['turnaround']);
        $this->assertNull($empty['last_activity']);
    }

    public function testComparingByProgramListsEveryProgramWithItsSubDepartment(): void
    {
        $groups = $this->byName($this->service->overview($this->dean, $this->filters(['compare' => 'program']))['groups']);

        $this->assertSame(['Program A1', 'Program A2', 'Program B1'], array_keys($groups));
        $this->assertSame('Sub A', $groups['Program A1']['context']);
        $this->assertSame(2, $groups['Program A1']['students']);
        $this->assertSame(0, $groups['Program A2']['students'], 'a program whose course has nobody yet');
        $this->assertSame(1, $groups['Program A2']['courses']);
        $this->assertNull($groups['Program A2']['completion']);
        $this->assertSame(1, $groups['Program B1']['at_risk'], 'the student who was never active');
    }

    public function testSortingPutsMissingValuesLastAndTiesInNameOrder(): void
    {
        $names = fn (array $input) => array_column($this->service->overview($this->dean, $this->filters($input))['groups'], 'name');

        $this->assertSame(['Sub A', 'Sub B', 'Sub C'], $names([]));
        $this->assertSame(['Sub C', 'Sub B', 'Sub A'], $names(['dir' => 'desc']));
        $this->assertSame(['Sub A', 'Sub B', 'Sub C'], $names(['sort' => 'completion', 'dir' => 'desc']), 'Sub C has no completion yet and stays last');
        $this->assertSame(['Sub B', 'Sub A', 'Sub C'], $names(['sort' => 'completion']), 'still last when ascending');
        $this->assertSame(['Sub A', 'Sub C', 'Sub B'], $names(['sort' => 'at_risk']), 'A and C tie at none and keep name order');
        $this->assertSame(['Sub B', 'Sub A', 'Sub C'], $names(['sort' => 'at_risk', 'dir' => 'desc']));
        $this->assertSame(['Sub B', 'Sub A', 'Sub C'], $names(['sort' => 'last_activity', 'dir' => 'desc']));
    }

    public function testEachFilterNarrowsWhatIsCounted(): void
    {
        DeanFactory::offering($this->programB1, $this->laterTerm, 'LW200');

        $totals = static fn (MonitorDepartmentService $s, User $d, array $input) => $s->overview($d, MonitorDepartmentFilters::fromInput($input))['totals'];

        $this->assertSame(4, $totals($this->service, $this->dean, [])['courses']);
        $this->assertSame(1, $totals($this->service, $this->dean, ['term' => (string) $this->laterTerm])['courses']);
        $this->assertSame(1, $totals($this->service, $this->dean, ['program' => (string) $this->programA1])['courses']);
        $this->assertSame(2, $totals($this->service, $this->dean, ['sub_department' => (string) $this->subA])['courses']);
        $this->assertSame(1, $totals($this->service, $this->dean, ['sub_department' => (string) $this->subA, 'program' => (string) $this->programA1])['courses']);
        $this->assertSame(0, $totals($this->service, $this->dean, ['sub_department' => (string) $this->subB, 'program' => (string) $this->programA1])['courses'], 'a program not in that sub-department');
    }

    public function testTheDateRangeNarrowsWhatWasSubmittedAndGraded(): void
    {
        $totals = $this->service->overview($this->dean, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-30']))['totals'];

        // Only IT101's three submissions fall in September: 2 graded of 3, the two graded after 24 h and 48 h.
        $this->assertEqualsWithDelta(66.7, $totals['grading_progress'], 0.05);
        $this->assertSame(36.0, $totals['turnaround']);
    }

    public function testASubDepartmentOrProgramFilterAlsoNarrowsTheComparisonRows(): void
    {
        $subs     = array_column($this->service->overview($this->dean, $this->filters(['sub_department' => (string) $this->subB]))['groups'], 'name');
        $programs = array_column($this->service->overview($this->dean, $this->filters(['compare' => 'program', 'sub_department' => (string) $this->subA]))['groups'], 'name');
        $byProgram = array_column($this->service->overview($this->dean, $this->filters(['program' => (string) $this->programB1]))['groups'], 'name');

        $this->assertSame(['Sub B'], $subs);
        $this->assertSame(['Program A1', 'Program A2'], $programs);
        $this->assertSame(['Sub B'], $byProgram);
    }

    public function testAnotherDepartmentsProgramOrSubDepartmentFindsNothing(): void
    {
        $elsewhere = DeanFactory::department('Z', $this->institution);
        $sub       = DeanFactory::subDepartment($elsewhere['department'], 'Z');
        $program   = DeanFactory::program($sub, 'Z');
        DeanFactory::offering($program, $this->term, 'ZZ999');

        foreach ([['program' => (string) $program], ['sub_department' => (string) $sub]] as $input) {
            $overview = $this->service->overview($this->dean, $this->filters($input));

            $this->assertSame(0, $overview['totals']['courses'], json_encode($input));
            $this->assertSame([], $overview['atRisk']);
            $this->assertSame([], $this->service->programIds($this->dean, $this->filters($input)));
        }
    }

    public function testTheGradeDistributionAndTheAtRiskListAreDepartmentWide(): void
    {
        $overview = $this->service->overview($this->dean, $this->filters());

        $this->assertSame(5, array_sum($overview['gradeDistribution']));
        $this->assertCount(1, $overview['atRisk']);
        $this->assertSame('LW100', $overview['atRisk'][0]['course_code']);
        $this->assertSame('inactive', $overview['atRisk'][0]['reason']);
        $this->assertNotNull($overview['atRisk'][0]['slug']);
    }

    public function testInstructorActivityIsPerInstructorOfTheDepartmentOnly(): void
    {
        $rows = array_column($this->service->instructorActivity($this->dean, $this->filters()), null, 'name');

        $names = array_keys($rows);
        sort($names);

        $this->assertSame(['Leo Law', 'Tess Tan'], $names, 'the two instructors of the department, nobody from the other department');
        $this->assertSame(1, $rows['Tess Tan']['sections']);
        $this->assertSame(2, $rows['Tess Tan']['graded_count']);
        $this->assertSame(36.0, $rows['Tess Tan']['avg_grading_turnaround_hours']);
        $this->assertSame(12.0, $rows['Leo Law']['avg_grading_turnaround_hours']);
        $this->assertGreaterThanOrEqual(1, $rows['Tess Tan']['content_updates']);
    }

    public function testInstructorActivityFollowsTheFiltersAndAnEndedDeanshipSeesNothing(): void
    {
        $narrow = $this->service->instructorActivity($this->dean, $this->filters(['program' => (string) $this->programB1]));

        $this->assertSame(['Leo Law'], array_column($narrow, 'name'));
        $this->assertSame([], $this->service->instructorActivity($this->dean, $this->filters(['term' => (string) $this->laterTerm])));

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertSame([], $this->service->instructorActivity($this->dean, $this->filters()));
    }

    public function testADeanWithoutAnActiveAssignmentSeesNothing(): void
    {
        $overview = $this->service->overview(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution), $this->filters());

        $this->assertSame(0, $overview['totals']['courses']);
        $this->assertSame([], $overview['groups']);
        $this->assertSame([], $overview['atRisk']);

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertSame(0, $this->service->overview($this->dean, $this->filters())['totals']['courses'], 'an ended deanship takes effect at once');
        $this->assertSame([], $this->service->terms($this->dean));
    }

    /** A chairperson's action on an offering, as ChairpersonCourseAudit records it. @return int audit id */
    private function chairAction(User $chair, int $offering, string $method, string $at): int
    {
        \CodeIgniter\Config\Services::resetSingle('auditLogger');

        $id = service('auditLogger')->log('course.chairperson_action', $chair, [
            'target_type' => 'offering', 'target_id' => $offering, 'acting_role' => 'chairperson', 'new' => ['method' => $method],
        ]);

        db_connect()->table('audit_logs')->where('id', $id)->update(['created_at' => $at]);

        return $id;
    }

    public function testTheAuditLogListsChairpersonActionsInTheDepartmentsCoursesOnly(): void
    {
        $chair = PeopleFactory::person('chairperson', 'Maria', 'Chair', 'maria@dept.example', $this->institution);
        $this->chairAction($chair, $this->it101, 'POST', '2026-09-01 09:00:00');
        $this->chairAction($chair, $this->lw100, 'PATCH', '2026-09-02 09:00:00');

        $elsewhere = DeanFactory::department('N', $this->institution);
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'N'), 'N'), $this->term, 'LAW900');
        $this->chairAction($chair, $foreign, 'DELETE', '2026-09-03 09:00:00');

        $log = $this->service->auditLog($this->dean, $this->filters());

        $this->assertSame(2, $log['total'], "another department's course is not listed");
        $this->assertSame(['LW100', 'IT101'], array_column($log['rows'], 'course_code'), 'newest first');
        $this->assertSame('maria@dept.example', $log['rows'][0]['actor']);
        $this->assertSame('chairperson', $log['rows'][0]['acting_role']);
        $this->assertSame('Updated something', $log['rows'][0]['description']);
        $this->assertSame('Created something', $log['rows'][1]['description']);
        $this->assertSame('2026-09-02 09:00:00', $log['rows'][0]['created_at']);
    }

    public function testTheAuditLogFollowsTheFiltersAndPages(): void
    {
        $chair = PeopleFactory::person('chairperson', 'Maria', 'Chair', null, $this->institution);

        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $day) {
            $this->chairAction($chair, $this->it101, 'POST', "{$day} 09:00:00");
        }
        $this->chairAction($chair, $this->lw100, 'POST', '2026-10-05 09:00:00');

        $this->assertSame(4, $this->service->auditLog($this->dean, $this->filters())['total']);
        $this->assertSame(1, $this->service->auditLog($this->dean, $this->filters(['program' => (string) $this->programB1]))['total']);
        $this->assertSame(3, $this->service->auditLog($this->dean, $this->filters(['sub_department' => (string) $this->subA]))['total']);
        $this->assertSame(2, $this->service->auditLog($this->dean, $this->filters(['from' => '2026-09-02', 'to' => '2026-09-03']))['total'], 'both end days count');

        $second = $this->service->auditLog($this->dean, $this->filters(), 2, 3);

        $this->assertSame(4, $second['total']);
        $this->assertCount(1, $second['rows'], 'the fourth entry is alone on page two of three-a-page');
    }

    public function testTheAuditLogIsEmptyForADeanWithoutADepartmentAndAfterTheDeanshipEnds(): void
    {
        $chair = PeopleFactory::person('chairperson', 'Maria', 'Chair', null, $this->institution);
        $this->chairAction($chair, $this->it101, 'POST', '2026-09-01 09:00:00');

        $this->assertSame(0, $this->service->auditLog(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution), $this->filters())['total']);

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertSame(0, $this->service->auditLog($this->dean, $this->filters())['total']);
    }

    public function testTheTermFilterListsOnlyTheDepartmentsTerms(): void
    {
        $terms = array_column($this->service->terms($this->dean), 'name');

        $this->assertSame(['First'], $terms, 'only terms with courses in this department');
    }

    public function testTheMonitorProgramFiltersAreTheOnesThePageUses(): void
    {
        $filters = $this->filters(['term' => '7', 'from' => '2026-09-01']);

        $this->assertInstanceOf(MonitorProgramFilters::class, $filters->base);
        $this->assertSame(7, $filters->base->termId);
    }
}
