<?php

declare(strict_types=1);

use App\Services\Courses\DeanCourseFilters;
use App\Services\Courses\DeanCourseSearch;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The dean's Courses list: exactly the offerings of their department, read
 * live from the hierarchy, filtered, sorted and paged.
 *
 * @internal
 */
final class DeanCourseSearchTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DeanCourseSearch $search;
    private int $institution;
    private int $department;
    private int $subA;
    private int $subB;
    private int $programA;
    private int $programB;
    private int $term;
    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('H');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'A');
        $this->subB        = DeanFactory::subDepartment($this->department, 'B');
        $this->programA    = DeanFactory::program($this->subA, 'A');
        $this->programB    = DeanFactory::program($this->subB, 'B');
        $this->term        = DeanFactory::term($this->institution);
        $this->dean        = DeanFactory::dean($this->institution, $this->department);
        $this->search      = new DeanCourseSearch();
    }

    /** @return list<string> course codes on the page */
    private function codes(?DeanCourseFilters $filters = null, ?User $dean = null): array
    {
        $rows = $this->search->page($dean ?? $this->dean, $filters ?? new DeanCourseFilters(perPage: 60))['rows'];

        return array_column($rows, 'course_code');
    }

    public function testOnlyTheOfferingsOfTheDeansDepartmentAreListed(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');
        DeanFactory::offering($this->programB, $this->term, 'CS201');

        $elsewhere = DeanFactory::department('X');
        $otherProgram = DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X');
        DeanFactory::offering($otherProgram, DeanFactory::term($elsewhere['institution']), 'ZZ999');

        $sameInstitutionOther = DeanFactory::department('Y', $this->institution);
        $siblingProgram = DeanFactory::program(DeanFactory::subDepartment($sameInstitutionOther['department'], 'Y'), 'Y');
        DeanFactory::offering($siblingProgram, $this->term, 'YY100');

        $this->assertSame(['CS201', 'IT101'], $this->codes());
        $this->assertSame(2, $this->search->page($this->dean, new DeanCourseFilters())['total']);
    }

    public function testAProgramAddedLaterIsCoveredAtOnce(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');
        $this->assertSame(['IT101'], $this->codes());

        $later = DeanFactory::program($this->subA, 'N');
        DeanFactory::offering($later, $this->term, 'NEW100');

        $this->assertSame(['IT101', 'NEW100'], $this->codes());
    }

    public function testAnEndedAssignmentListsNothing(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');
        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertSame([], $this->codes());
        $this->assertSame([], $this->search->terms($this->dean));
        $this->assertSame([], $this->search->subDepartments($this->dean));
        $this->assertSame([], $this->search->programs($this->dean));
        $this->assertSame([], $this->search->instructors($this->dean));
    }

    public function testADeanWhoWasNeverAssignedListsNothing(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');

        $this->assertSame([], $this->codes(null, PeopleFactory::person('dean', 'No', 'Department', null, $this->institution)));
    }

    public function testEachFilterNarrowsTheList(): void
    {
        $laterTerm = DeanFactory::term($this->institution, 'Second', '2027-01-10');
        $it101     = DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        DeanFactory::offering($this->programA, $laterTerm, 'IT102', 'A', 'completed', 'Networks');
        DeanFactory::offering($this->programB, $this->term, 'CS201', 'B', 'active', 'Algorithms');

        $teacher = PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution);
        CourseFactory::assignInstructor($it101, $teacher);

        $this->assertSame(['IT102'], $this->codes(new DeanCourseFilters(termId: $laterTerm)));
        $this->assertSame(['CS201'], $this->codes(new DeanCourseFilters(subDepartmentId: $this->subB)));
        $this->assertSame(['CS201'], $this->codes(new DeanCourseFilters(programId: $this->programB)));
        $this->assertSame(['IT101'], $this->codes(new DeanCourseFilters(instructorId: (int) $teacher->id)));
        $this->assertSame(['IT102'], $this->codes(new DeanCourseFilters(status: 'completed')));
        $this->assertSame(['IT101'], $this->codes(new DeanCourseFilters(q: 'computing')));
        $this->assertSame(['CS201'], $this->codes(new DeanCourseFilters(q: 'B')), 'a section code matches too');
        $this->assertSame([], $this->codes(new DeanCourseFilters(termId: $laterTerm, programId: $this->programB)));
    }

    public function testASearchTreatsPercentAndUnderscoreAsPlainText(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');

        $this->assertSame([], $this->codes(new DeanCourseFilters(q: '%')));
        $this->assertSame([], $this->codes(new DeanCourseFilters(q: '_')));
    }

    public function testAFilterForAnotherDepartmentsProgramFindsNothing(): void
    {
        $elsewhere = DeanFactory::department('X');
        $foreign   = DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X');
        DeanFactory::offering($foreign, DeanFactory::term($elsewhere['institution']), 'ZZ999');

        $this->assertSame([], $this->codes(new DeanCourseFilters(programId: $foreign)));
    }

    public function testSortingAndPaging(): void
    {
        foreach (['IT103', 'IT101', 'IT102', 'IT104', 'IT105'] as $code) {
            DeanFactory::offering($this->programA, $this->term, $code);
        }

        $this->assertSame(['IT101', 'IT102', 'IT103', 'IT104', 'IT105'], $this->codes());
        $this->assertSame(['IT105', 'IT104', 'IT103', 'IT102', 'IT101'], $this->codes(new DeanCourseFilters(dir: 'desc')));

        $second = $this->search->page($this->dean, new DeanCourseFilters(page: 2, perPage: 2));

        $this->assertSame(5, $second['total']);
        $this->assertSame(['IT103', 'IT104'], array_column($second['rows'], 'course_code'));
    }

    public function testEnrollmentSortPutsTheBiggestClassFirst(): void
    {
        $small = DeanFactory::offering($this->programA, $this->term, 'IT101');
        $big   = DeanFactory::offering($this->programA, $this->term, 'IT102');

        CourseFactory::enroll($small, PeopleFactory::person('student', 'S', 'One', null, $this->institution));
        foreach (['Two', 'Three'] as $name) {
            CourseFactory::enroll($big, PeopleFactory::person('student', 'S', $name, null, $this->institution));
        }

        $rows = $this->search->page($this->dean, new DeanCourseFilters(sort: 'enrollment', dir: 'desc'))['rows'];

        $this->assertSame(['IT102', 'IT101'], array_column($rows, 'course_code'));
        $this->assertSame([2, 1], array_map('intval', array_column($rows, 'enrolled')));
    }

    public function testEachRowNamesTheInstructorAndTheChairpersonOfItsSubDepartment(): void
    {
        $offering = DeanFactory::offering($this->programA, $this->term, 'IT101');
        DeanFactory::offering($this->programB, $this->term, 'CS201');
        CourseFactory::assignInstructor($offering, PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution));
        DeanFactory::chair($this->subA, 'Maria');
        DeanFactory::chair($this->subB, 'Gone', 'ended');

        $rows = array_column($this->search->page($this->dean, new DeanCourseFilters())['rows'], null, 'course_code');

        $this->assertSame('Tess Tan', $rows['IT101']['instructor_name']);
        $this->assertSame('Maria Chair', $rows['IT101']['chairperson_name']);
        $this->assertNull($rows['CS201']['instructor_name']);
        $this->assertNull($rows['CS201']['chairperson_name'], 'an ended chairperson is not named');
    }

    public function testTheFilterListsHoldOnlyTheDepartmentsOwn(): void
    {
        $offering = DeanFactory::offering($this->programA, $this->term, 'IT101');
        CourseFactory::assignInstructor($offering, PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution));

        $elsewhere = DeanFactory::department('X');
        $foreign   = DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X');
        $foreignOffering = DeanFactory::offering($foreign, DeanFactory::term($elsewhere['institution'], 'Foreign'), 'ZZ999');
        CourseFactory::assignInstructor($foreignOffering, PeopleFactory::person('instructor', 'Fay', 'Far', null, $elsewhere['institution']));

        $this->assertSame(['First'], array_column($this->search->terms($this->dean), 'name'));
        $this->assertEqualsCanonicalizing(['Sub A', 'Sub B'], array_column($this->search->subDepartments($this->dean), 'name'));
        $this->assertEqualsCanonicalizing(['Program A', 'Program B'], array_column($this->search->programs($this->dean), 'name'));
        $this->assertSame(['Tess Tan'], array_column($this->search->instructors($this->dean), 'name'));
    }
}
