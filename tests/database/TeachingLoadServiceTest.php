<?php

declare(strict_types=1);

use App\Services\Courses\TeachingLoadFilters;
use App\Services\Courses\TeachingLoadService;
use App\Services\Institutions\AcademicDefaults;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The dean's teaching-load figures, from a fixture with known numbers:
 *
 *   Tess teaches IT101 (3 units, students s1 s2; 2 pieces of work waiting, 2 graded after 24 h and 48 h),
 *   IT102 (3 units, students s2 s3) in Sub A and LW100 (3 units, student s4) in Sub B: 3 sections, 9 units,
 *   4 students (s2 is in two of hers), turnaround 36 h. She also teaches a section of another department.
 *   Leo teaches CS200 (21 units, no students): a high load at the platform's 21. Mia co-teaches IT101.
 *   Zed teaches only in the other department, and Tess has a section in an earlier term.
 *
 * @internal
 */
final class TeachingLoadServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private TeachingLoadService $service;
    private int $institution;
    private int $department;
    private int $subA;
    private int $subB;
    private int $term;
    private int $earlier;
    private int $otherDepartment;
    private int $it101;
    private User $dean;
    private User $tess;
    private User $leo;
    private User $mia;
    private User $zed;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('L');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'A');
        $this->subB        = DeanFactory::subDepartment($this->department, 'B');
        $programA          = DeanFactory::program($this->subA, 'A');
        $programB          = DeanFactory::program($this->subB, 'B');
        $this->term        = DeanFactory::term($this->institution, 'Second', '2027-01-10');
        $this->earlier     = DeanFactory::term($this->institution, 'First', '2026-08-01');
        $this->dean        = DeanFactory::dean($this->institution, $this->department);
        $this->service     = new TeachingLoadService();

        $this->tess = PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution);
        $this->leo  = PeopleFactory::person('instructor', 'Leo', 'Law', null, $this->institution);
        $this->mia  = PeopleFactory::person('instructor', 'Mia', 'Mo', null, $this->institution);
        $this->zed  = PeopleFactory::person('instructor', 'Zed', 'Zee', null, $this->institution);

        [$s1, $s2, $s3, $s4] = array_map(function (int $n): User {
            $student = CourseFactory::user('student');
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'Stu', 'last_name' => 'Dent' . $student->id]);

            return $student;
        }, [1, 2, 3, 4]);

        $this->it101 = DeanFactory::offering($programA, $this->term, 'IT101');
        $it102       = DeanFactory::offering($programA, $this->term, 'IT102');
        $lw100       = DeanFactory::offering($programB, $this->term, 'LW100');
        $cs200       = DeanFactory::offering($programA, $this->term, 'CS200');
        db_connect()->table('courses')->where('course_code', 'CS200')->update(['units' => 21]);

        CourseFactory::assignInstructor($this->it101, $this->tess);
        CourseFactory::assignInstructor($this->it101, $this->mia);
        CourseFactory::assignInstructor($it102, $this->tess);
        CourseFactory::assignInstructor($lw100, $this->tess);
        CourseFactory::assignInstructor($cs200, $this->leo);

        CourseFactory::enroll($this->it101, $s1);
        CourseFactory::enroll($this->it101, $s2);
        CourseFactory::enroll($it102, $s2);
        CourseFactory::enroll($it102, $s3);
        CourseFactory::enroll($lw100, $s4);
        CourseFactory::enroll($lw100, CourseFactory::user('student'), 'dropped');

        // IT101: two graded after 24 h and 48 h, two handed in and waiting.
        $assessment = CourseFactory::assessment($this->it101, $this->tess, CourseFactory::module($this->it101));

        foreach ([['2026-09-02 08:00:00', '2026-09-03 08:00:00', 'Graded'], ['2026-09-01 08:00:00', '2026-09-03 08:00:00', 'Graded'], ['2026-09-04 08:00:00', null, 'Submitted'], ['2026-09-05 08:00:00', null, 'Submitted']] as $n => [$at, $gradedAt, $status]) {
            AssessmentFactory::attempt($assessment, $n % 2 === 0 ? $s1 : $s2, $status, 0.0, ['submitted_at' => $at, 'graded_at' => $gradedAt, 'attempt_number' => $n + 1]);
        }

        // An earlier term, and another department of the same institution.
        CourseFactory::assignInstructor(DeanFactory::offering($programA, $this->earlier, 'OLD100'), $this->tess);

        $other = DeanFactory::department('N', $this->institution);
        $this->otherDepartment = $other['department'];
        $next  = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($other['department'], 'N'), 'N'), $this->term, 'LAW900');
        CourseFactory::assignInstructor($next, $this->tess);
        CourseFactory::assignInstructor($next, $this->zed);
    }

    /** @return array<string, array<string, mixed>> keyed by name */
    private function byName(array $result): array
    {
        return array_column($result['rows'], null, 'name');
    }

    private function filters(array $input = []): TeachingLoadFilters
    {
        return TeachingLoadFilters::fromInput($input);
    }

    public function testEachInstructorOfTheDepartmentHasTheirOwnNumbers(): void
    {
        $rows = $this->byName($this->service->load($this->dean, $this->filters()));

        $this->assertEqualsCanonicalizing(['Tess Tan', 'Leo Law', 'Mia Mo'], array_keys($rows), 'Zed teaches only elsewhere');

        $tess = $rows['Tess Tan'];

        $this->assertSame(3, $tess['sections'], 'LAW900 of the other department and OLD100 of the earlier term are not counted');
        $this->assertSame(9.0, $tess['units']);
        $this->assertSame(4, $tess['students'], 'a student in two of her sections is one student, and a dropped one is not counted');
        $this->assertSame(2, $tess['ungraded']);
        $this->assertSame(36.0, $tess['turnaround']);
        $this->assertFalse($tess['high_load']);
    }

    public function testASectionWithTwoInstructorsCountsForBoth(): void
    {
        $mia = $this->byName($this->service->load($this->dean, $this->filters()))['Mia Mo'];

        $this->assertSame(1, $mia['sections']);
        $this->assertSame(2, $mia['students']);
        $this->assertSame(2, $mia['ungraded']);
        $this->assertSame(36.0, $mia['turnaround']);
    }

    public function testAnInstructorWithNoStudentsOrGradingHasZerosAndNoTurnaround(): void
    {
        $leo = $this->byName($this->service->load($this->dean, $this->filters()))['Leo Law'];

        $this->assertSame(1, $leo['sections']);
        $this->assertSame(0, $leo['students']);
        $this->assertSame(0, $leo['ungraded']);
        $this->assertNull($leo['turnaround']);
        $this->assertSame(21.0, $leo['units']);
    }

    public function testAHighLoadIsMarkedAtTheInstitutionsNumberOfUnits(): void
    {
        $result = $this->service->load($this->dean, $this->filters());

        $this->assertSame(AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS, $result['highLoadUnits']);
        $this->assertTrue($this->byName($result)['Leo Law']['high_load'], '21 units reaches the platform number');

        (new AcademicDefaults())->save($this->institution, ['high_load_units' => '9'], PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution));

        $result = $this->service->load($this->dean, $this->filters());

        $this->assertSame(9, $result['highLoadUnits']);
        $this->assertTrue($this->byName($result)['Tess Tan']['high_load'], 'her 9 units now reach it');
        $this->assertFalse($this->byName($result)['Mia Mo']['high_load']);
    }

    public function testItOpensOnTheNewestTermAndAnotherTermOfTheDepartmentCanBeChosen(): void
    {
        $this->assertSame($this->term, $this->service->load($this->dean, $this->filters())['term']);

        $earlier = $this->service->load($this->dean, $this->filters(['term' => (string) $this->earlier]));

        $this->assertSame($this->earlier, $earlier['term']);
        $this->assertSame(['Tess Tan'], array_column($earlier['rows'], 'name'));
        $this->assertSame(1, $earlier['rows'][0]['sections']);
    }

    public function testATermNotInTheDepartmentFallsBackToTheNewest(): void
    {
        $this->assertSame($this->term, $this->service->load($this->dean, $this->filters(['term' => '999999']))['term']);
    }

    public function testASubDepartmentNarrowsToTheSectionsInIt(): void
    {
        $rows = $this->byName($this->service->load($this->dean, $this->filters(['sub_department' => (string) $this->subB])));

        $this->assertSame(['Tess Tan'], array_keys($rows));
        $this->assertSame(1, $rows['Tess Tan']['sections']);
        $this->assertSame(3.0, $rows['Tess Tan']['units']);
        $this->assertSame([], $this->service->load($this->dean, $this->filters(['sub_department' => '999999']))['rows'], 'a sub-department of another department finds nobody');
    }

    public function testTheTableSortsByAColumnWithMissingValuesLastAndTiesByName(): void
    {
        $names = static fn (array $result): array => array_column($result['rows'], 'name');

        $this->assertSame(['Leo Law', 'Tess Tan', 'Mia Mo'], $names($this->service->load($this->dean, $this->filters())), 'heaviest first by default');
        $this->assertSame(['Mia Mo', 'Tess Tan', 'Leo Law'], $names($this->service->load($this->dean, $this->filters(['sort' => 'units', 'dir' => 'asc']))));
        $this->assertSame(['Leo Law', 'Mia Mo', 'Tess Tan'], $names($this->service->load($this->dean, $this->filters(['sort' => 'name']))));
        $this->assertSame(['Mia Mo', 'Tess Tan', 'Leo Law'], $names($this->service->load($this->dean, $this->filters(['sort' => 'turnaround', 'dir' => 'asc']))), 'no turnaround sorts last');
        $this->assertSame(['Mia Mo', 'Tess Tan', 'Leo Law'], $names($this->service->load($this->dean, $this->filters(['sort' => 'turnaround', 'dir' => 'desc']))), 'last again going down, and equal turnaround keeps name order');
        $this->assertSame(['Tess Tan', 'Mia Mo', 'Leo Law'], $names($this->service->load($this->dean, $this->filters(['sort' => 'students']))));
    }

    public function testOneInstructorsSectionsAreListedWithTheirOwnFigures(): void
    {
        $detail = $this->service->instructor($this->dean, (int) $this->tess->id, null);

        $this->assertSame('Tess Tan', $detail['instructor']['name']);
        $this->assertCount(3, $detail['sections']);

        $it101 = array_values(array_filter($detail['sections'], static fn (array $s): bool => $s['course_code'] === 'IT101'))[0];

        $this->assertSame($this->it101, $it101['offering_id']);
        $this->assertSame('course-it101', $it101['slug']);
        $this->assertSame(2, $it101['ungraded']);
        $this->assertSame(36.0, $it101['turnaround']);
        $this->assertCount(2, $it101['student_ids']);
    }

    public function testAnInstructorOutsideTheDepartmentIsNotFound(): void
    {
        $this->assertNull($this->service->instructor($this->dean, (int) $this->zed->id, null));
        $this->assertFalse($this->service->teachesInDepartment($this->dean, (int) $this->zed->id));
        $this->assertTrue($this->service->teachesInDepartment($this->dean, (int) $this->tess->id));
        $this->assertFalse($this->service->teachesInDepartment($this->dean, 999999));
    }

    public function testAnotherDeansDepartmentSeesOnlyItsOwnSectionsOfTheSameInstructor(): void
    {
        $other = DeanFactory::dean($this->institution, $this->otherDepartment, 'Eve');
        $rows  = $this->byName($this->service->load($other, $this->filters()));

        $this->assertEqualsCanonicalizing(['Tess Tan', 'Zed Zee'], array_keys($rows));
        $this->assertSame(1, $rows['Tess Tan']['sections'], 'Tess appears there with the one section she has there');
        $this->assertSame(3.0, $rows['Tess Tan']['units']);
    }

    public function testADeanWithoutADepartmentSeesNothing(): void
    {
        $stray = PeopleFactory::person('dean', 'No', 'Department', null, $this->institution);

        $this->assertSame(['term' => null, 'highLoadUnits' => AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS, 'rows' => []], $this->service->load($stray, $this->filters()));
        $this->assertSame([], $this->service->terms($stray));
        $this->assertNull($this->service->instructor($stray, (int) $this->tess->id, null));
        $this->assertFalse($this->service->teachesInDepartment($stray, (int) $this->tess->id));

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertSame([], $this->service->load($this->dean, $this->filters())['rows'], 'an ended deanship takes effect at once');
    }
}
