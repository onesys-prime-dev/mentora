<?php

declare(strict_types=1);

use App\Services\Courses\CollegeReportService;
use App\Services\Courses\MonitorCollegeFilters;
use App\Services\Courses\MonitorCollegeService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The dean's college report: what each CSV holds, that it is what the pages show (and nothing else),
 * that a spreadsheet cannot read a typed name as a formula, the filters, and that nothing of another
 * college is in it. The fixture, in college H (the dean's):
 *
 *   Department A: IT101 (Tess Tan; 2 students; grades final, nothing open: ready)
 *   Department B: LW100 ("=Evil Law", whose name a spreadsheet would run; 1 student; signed off by the dean)
 *   and college N, where Zed teaches NX100: in no export of H.
 *
 * @internal
 */
final class CollegeReportServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private CollegeReportService $service;
    private int $institution;
    private int $college;
    private int $departmentA;
    private int $departmentB;
    private int $term;
    private int $it101;
    private int $lw100;
    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::college('H');
        $this->institution = $home['institution'];
        $this->college  = $home['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'A');
        $this->departmentB        = DeanFactory::department($this->college, 'B');
        $this->term        = DeanFactory::term($this->institution);
        $this->dean        = DeanFactory::dean($this->institution, $this->college);
        $this->service     = new CollegeReportService();

        $this->it101 = DeanFactory::offering(DeanFactory::program($this->departmentA, 'A'), $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        $this->lw100 = DeanFactory::offering(DeanFactory::program($this->departmentB, 'B'), $this->term, 'LW100', 'A', 'active', 'Intro to Law');

        $tess = PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution);
        $evil = PeopleFactory::person('instructor', '=Evil', 'Law', null, $this->institution);
        CourseFactory::assignInstructor($this->it101, $tess);
        CourseFactory::assignInstructor($this->lw100, $evil);

        foreach ([[$this->it101, 2], [$this->lw100, 1]] as [$offering, $students]) {
            for ($i = 0; $i < $students; $i++) {
                CourseFactory::enroll($offering, PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution));
            }
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->it101, 'finalized_by' => $tess->id, 'finalized_at' => $now, 'open_marker' => 1]);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->lw100, 'finalized_by' => $evil->id, 'finalized_at' => $now, 'open_marker' => 1]);
        db_connect()->table('grade_signoffs')->insert(['offering_id' => $this->lw100, 'signed_by' => $this->dean->id, 'signed_at' => '2026-12-12 09:00:00', 'open_marker' => 1]);

        $other = DeanFactory::college('N', $this->institution);
        $nx    = DeanFactory::offering(DeanFactory::program(DeanFactory::department($other['college'], 'N'), 'N'), $this->term, 'NX100', 'A', 'active', 'Foreign Course');
        CourseFactory::assignInstructor($nx, PeopleFactory::person('instructor', 'Zed', 'Zee', null, $this->institution));
        CourseFactory::enroll($nx, PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution));
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        return array_map('str_getcsv', array_values(array_filter(explode("\n", $csv), static fn (string $l): bool => trim($l) !== '')));
    }

    /** @return array<string, mixed> */
    private function report(string $section, array $get = []): array
    {
        $report = $this->service->build($this->dean, $get, null, [$section]);

        $this->assertNotNull($report);

        return $report;
    }

    public function testTheMonitorCsvHasTheCollegeTotalThenOneRowPerDepartment(): void
    {
        $export = $this->service->csv('monitor', $this->report('monitor'));
        $rows   = $this->rows($export['csv']);

        $this->assertSame(['Department', 'Department', 'Courses', 'Instructors', 'Students', 'Completion %', 'Average grade %', 'Graded %', 'Grading turnaround (hours)', 'At risk', 'Last activity'], $rows[0]);
        $this->assertSame(['College total', 'Department A', 'Department B'], [$rows[1][0], $rows[2][0], $rows[3][0]]);
        $this->assertSame('3', $rows[1][4], 'the college has 3 students');
        $this->assertSame(['1', '2'], [$rows[2][2], $rows[2][4]], 'Department A: 1 course, 2 students');
        $this->assertSame(['1', '1'], [$rows[3][2], $rows[3][4]], 'Department B: 1 course, 1 student');
        $this->assertSame(3, $export['rows']);
    }

    public function testTheMonitorCsvIsExactlyWhatTheMonitorServiceGives(): void
    {
        $overview = (new MonitorCollegeService())->overview($this->dean, MonitorCollegeFilters::fromInput([]));
        $rows     = $this->rows($this->service->csv('monitor', $this->report('monitor'))['csv']);

        $this->assertSame((string) $overview['totals']['students'], $rows[1][4]);
        $this->assertSame(count($overview['groups']), count($rows) - 2, 'one row per group, plus the header and the total');

        foreach ($overview['groups'] as $i => $group) {
            $this->assertSame((string) $group['name'], $rows[$i + 2][0]);
            $this->assertSame((string) $group['students'], $rows[$i + 2][4]);
            $this->assertSame((string) ($group['completion'] ?? ''), $rows[$i + 2][5]);
        }
    }

    public function testComparingByProgramNamesTheProgramAndItsDepartment(): void
    {
        $rows = $this->rows($this->service->csv('monitor', $this->report('monitor', ['compare' => 'program']))['csv']);

        $this->assertSame('Program', $rows[0][0]);
        $this->assertSame(['Program A', 'Department A'], [$rows[2][0], $rows[2][1]]);
        $this->assertSame(['Program B', 'Department B'], [$rows[3][0], $rows[3][1]]);
    }

    public function testTheTeachingLoadCsvListsEachInstructorOfTheCollege(): void
    {
        $rows = $this->rows($this->service->csv('teaching-load', $this->report('teaching-load'))['csv']);

        $this->assertSame(['Instructor', 'Sections', 'Students', 'Units', 'Waiting to be graded', 'Grading turnaround (hours)', 'High load (30+ units)'], $rows[0]);

        $names = array_column(array_slice($rows, 1), 0);

        $this->assertEqualsCanonicalizing(["'=Evil Law", 'Tess Tan'], $names);
        $this->assertNotContains('Zed Zee', $names, 'another college\'s instructor');

        $tess = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'Tess Tan'))[0];

        $this->assertSame(['1', '2', '3', '0', '', 'No'], array_slice($tess, 1));
    }

    public function testAHighLoadIsFlaggedAtTheInstitutionsNumber(): void
    {
        db_connect()->table('courses')->where('course_code', 'IT101')->update(['units' => 33]);

        $rows = $this->rows($this->service->csv('teaching-load', $this->report('teaching-load'))['csv']);
        $tess = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'Tess Tan'))[0];

        $this->assertSame(['33', '0', '', 'Yes'], [$tess[3], $tess[4], $tess[5], $tess[6]]);
    }

    public function testTheSignOffCsvSaysWhereEachSectionStands(): void
    {
        $rows = $this->rows($this->service->csv('signoff', $this->report('signoff'))['csv']);

        $this->assertSame(['Program', 'Course', 'Section', 'Title', 'Instructor', 'Students', 'Status', 'Why blocked', 'Signed off by', 'Signed off on'], $rows[0]);

        $by = array_column(array_slice($rows, 1), null, 1);

        $this->assertSame('Ready', $by['IT101'][6]);
        $this->assertSame('', $by['IT101'][8]);
        $this->assertSame('Signed off', $by['LW100'][6]);
        $this->assertStringContainsString('Dana', $by['LW100'][8]);
        $this->assertSame('2026-12-12', $by['LW100'][9]);
        $this->assertArrayNotHasKey('NX100', $by);
    }

    public function testABlockedSectionSaysWhy(): void
    {
        db_connect()->table('grade_finalizations')->where('course_offering_id', $this->it101)->update(['open_marker' => null]);

        $rows = $this->rows($this->service->csv('signoff', $this->report('signoff'))['csv']);
        $it   = array_values(array_filter($rows, static fn (array $r): bool => $r[1] === 'IT101'))[0];

        $this->assertSame('Blocked', $it[6]);
        $this->assertStringContainsString('has not finalized', $it[7]);
    }

    public function testAnythingATypedNameCouldTurnIntoAFormulaIsMadePlainText(): void
    {
        foreach (['monitor', 'teaching-load', 'signoff'] as $section) {
            $csv = $this->service->csv($section, $this->report($section))['csv'];

            $this->assertStringNotContainsString('"=Evil', $csv, $section);
            $this->assertStringNotContainsString(",=Evil", $csv, $section);
        }

        $this->assertStringContainsString("'=Evil Law", $this->service->csv('teaching-load', $this->report('teaching-load'))['csv']);
        $this->assertStringContainsString("'=Evil Law", $this->service->csv('signoff', $this->report('signoff'))['csv']);
    }

    public function testNothingOfAnotherCollegeIsInAnyExport(): void
    {
        $full = $this->service->build($this->dean, []);

        foreach (['monitor', 'teaching-load', 'signoff'] as $section) {
            $csv = $this->service->csv($section, $full)['csv'];

            foreach (['NX100', 'Zed', 'Foreign Course', 'Sider'] as $foreign) {
                $this->assertStringNotContainsString($foreign, $csv, "{$section} must not hold {$foreign}");
            }
        }
    }

    public function testTheFiltersInTheAddressNarrowTheExport(): void
    {
        $monitor = $this->rows($this->service->csv('monitor', $this->report('monitor', ['department' => (string) $this->departmentB]))['csv']);
        $load    = $this->rows($this->service->csv('teaching-load', $this->report('teaching-load', ['department' => (string) $this->departmentB]))['csv']);

        $this->assertSame(['College total', 'Department B'], [$monitor[1][0], $monitor[2][0]]);
        $this->assertCount(3, $monitor);
        $this->assertSame(["'=Evil Law"], array_column(array_slice($load, 1), 0));

        $foreign = $this->service->build($this->dean, ['department' => '999999']);

        $this->assertSame(1, $this->service->csv('monitor', $foreign)['rows'], 'a department of another college finds only the empty total');
    }

    public function testAReportNarrowedToADepartmentOrAProgramIsNarrowedInEveryPartIncludingSignOff(): void
    {
        $byDepartment = $this->rows($this->service->csv('signoff', $this->report('signoff', ['department' => (string) $this->departmentB]))['csv']);

        $this->assertSame(['LW100'], array_column(array_slice($byDepartment, 1), 1), 'only the section in Department B');

        $programA = (int) db_connect()->table('programs')->where('department_id', $this->departmentA)->get()->getRow('id');
        $byProgram = $this->rows($this->service->csv('signoff', $this->report('signoff', ['program' => (string) $programA]))['csv']);

        $this->assertSame(['IT101'], array_column(array_slice($byProgram, 1), 1));
        $this->assertSame(2, $this->service->csv('signoff', $this->report('signoff'))['rows'], 'unfiltered, both');
        $this->assertSame(0, $this->service->csv('signoff', $this->report('signoff', ['department' => '999999']))['rows'], 'a department of another college finds nothing');
    }

    public function testOnlyTheAskedForPartIsWorkedOut(): void
    {
        $report = $this->service->build($this->dean, [], null, ['teaching-load']);

        $this->assertNotEmpty($report['teaching_load']['rows']);
        $this->assertSame([], $report['monitor']['groups']);
        $this->assertSame([], $report['signoff']['programs']);
    }

    public function testTheReportSaysWhatItIsFilteredBy(): void
    {
        $report = $this->service->build($this->dean, ['term' => (string) $this->term, 'department' => (string) $this->departmentA, 'from' => '2026-09-01']);

        $this->assertSame(['Term: First (2026-2027)', 'Dates: 2026-09-01 to today', 'Department: Department A'], $report['filters']);
        $this->assertSame('College H', $report['college']);
        $this->assertSame([], $this->service->build($this->dean, [])['filters']);
    }

    public function testADeanWithoutACollegeHasNothingToExport(): void
    {
        $this->assertNull($this->service->build(PeopleFactory::person('dean', 'No', 'College', null, $this->institution), []));

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertNull($this->service->build($this->dean, []), 'an ended deanship takes effect at once');
    }

    public function testAnEmptyCollegeExportsOnlyHeadings(): void
    {
        $quiet = DeanFactory::college('Q', $this->institution);
        $dean  = DeanFactory::dean($this->institution, $quiet['college'], 'Quin');
        $full  = $this->service->build($dean, []);

        $this->assertSame(0, $this->service->csv('teaching-load', $full)['rows']);
        $this->assertSame(0, $this->service->csv('signoff', $full)['rows']);
        $this->assertSame(1, $this->service->csv('monitor', $full)['rows'], 'just the empty college total');
    }

    public function testOnlyTheKnownPartsExist(): void
    {
        $this->assertTrue(CollegeReportService::isSection('monitor'));
        $this->assertTrue(CollegeReportService::isSection('teaching-load'));
        $this->assertTrue(CollegeReportService::isSection('signoff'));
        $this->assertFalse(CollegeReportService::isSection('people'));
        $this->assertFalse(CollegeReportService::isSection('../etc/passwd'));

        $this->expectException(InvalidArgumentException::class);
        $this->service->csv('people', $this->service->build($this->dean, []));
    }

    public function testTheFileNameNamesTheCollegeThePartAndTheDay(): void
    {
        $at = new DateTimeImmutable('2026-12-24 10:00:00');

        $this->assertSame('college-of-computing-signoff-2026-12-24', $this->service->fileStem('signoff', 'College of Computing', $at));
        $this->assertSame('college-monitor-2026-12-24', $this->service->fileStem('monitor', '???', $at));
        $this->assertSame('a-b-rm-rf-teaching-load-2026-12-24', $this->service->fileStem('teaching-load', '"A/B"; rm -rf', $at), 'nothing but letters, digits and dashes reaches a file name');
    }
}
