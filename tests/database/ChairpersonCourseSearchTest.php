<?php

declare(strict_types=1);

use App\Models\Users\UserProfileModel;
use App\Services\Courses\ChairpersonCourseFilters;
use App\Services\Courses\ChairpersonCourseSearch;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The chairperson Courses page's search/filter/sort/paging (R6): built off
 * ChairpersonAssignmentModel::chairedOfferingsBuilder(), so coverage here is
 * mostly about the filters actually narrowing the list, not re-proving
 * program-wide access (see ChairpersonAccessServiceTest for that).
 *
 * @internal
 */
final class ChairpersonCourseSearchTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ChairpersonCourseSearch $search;
    private User $chair;
    private int $sub;
    private int $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = new ChairpersonCourseSearch();
        $this->chair  = CourseFactory::user('chairperson');

        $db = db_connect();
        $db->table('institutions')->insert(['name' => 'Inst', 'code' => 'INS']);
        $institution = (int) $db->insertID();
        $db->table('departments')->insert(['institution_id' => $institution, 'code' => 'DEP', 'name' => 'Dept']);
        $department = (int) $db->insertID();
        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => 'SUB', 'name' => 'Sub']);
        $this->sub = (int) $db->insertID();
        $db->table('programs')->insert(['sub_department_id' => $this->sub, 'code' => 'PRG', 'name' => 'Program']);
        $this->program = (int) $db->insertID();

        $db->table('chairperson_assignments')->insert([
            'sub_department_id'   => $this->sub,
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** CourseFactory::user() skips user_profiles, which real registration always creates. */
    private function instructor(): User
    {
        $user = CourseFactory::user('instructor');

        (new UserProfileModel())->insert(['user_id' => $user->id, 'first_name' => 'Test', 'last_name' => 'Instructor']);

        return $user;
    }

    /** @return array{offering: int, course: int, term: int} */
    private function offering(string $courseCode, string $courseTitle, string $section = 'A', string $status = 'active', string $termName = 'Term 1'): array
    {
        $db = db_connect();

        $term = $db->table('academic_terms')->where('name', $termName)->get()->getRowArray();

        if ($term === null) {
            $db->table('academic_terms')->insert([
                'institution_id' => (int) $db->table('departments')->get()->getRowArray()['institution_id'],
                'name' => $termName,
                'academic_year' => '2026-2027',
                'start_date' => '2026-08-01',
                'end_date' => '2026-12-20',
            ]);
            $termId = (int) $db->insertID();
        } else {
            $termId = (int) $term['id'];
        }

        $db->table('courses')->insert(['program_id' => $this->program, 'course_code' => $courseCode, 'course_title' => $courseTitle, 'units' => 3]);
        $courseId = (int) $db->insertID();

        $db->table('course_offerings')->insert(['course_id' => $courseId, 'term_id' => $termId, 'section_code' => $section, 'status' => $status]);

        return ['offering' => (int) $db->insertID(), 'course' => $courseId, 'term' => $termId];
    }

    public function testSearchMatchesCourseCodeTitleAndSection(): void
    {
        $this->offering('IT101', 'Intro to Programming');
        $this->offering('CS202', 'Data Structures');

        $byCode = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['q' => 'IT101']));
        $this->assertSame(1, $byCode['total']);
        $this->assertSame('IT101', $byCode['rows'][0]['course_code']);

        $byTitle = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['q' => 'Data Structures']));
        $this->assertSame(1, $byTitle['total']);
    }

    public function testFilteringByTerm(): void
    {
        $this->offering('IT101', 'Course A', 'A', 'active', 'Term 1');
        $this->offering('IT102', 'Course B', 'A', 'active', 'Term 2');

        $term2 = db_connect()->table('academic_terms')->where('name', 'Term 2')->get()->getRowArray();

        $result = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['term' => (string) $term2['id']]));

        $this->assertSame(1, $result['total']);
        $this->assertSame('IT102', $result['rows'][0]['course_code']);
    }

    public function testFilteringByInstructor(): void
    {
        $withInstructor = $this->offering('IT101', 'Course A');
        $this->offering('IT102', 'Course B');

        $instructor = $this->instructor();
        CourseFactory::assignInstructor($withInstructor['offering'], $instructor);

        $result = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['instructor' => (string) $instructor->id]));

        $this->assertSame(1, $result['total']);
        $this->assertSame('IT101', $result['rows'][0]['course_code']);
    }

    public function testFilteringByStatus(): void
    {
        $this->offering('IT101', 'Active course', 'A', 'active');
        $this->offering('IT102', 'Archived course', 'A', 'archived');

        $this->assertSame(1, $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['status' => 'active']))['total']);
        $this->assertSame(1, $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['status' => 'archived']))['total']);

        // No status filter on this management page: every status shows, not just active.
        $this->assertSame(2, $this->search->page($this->chair, ChairpersonCourseFilters::fromInput([]))['total']);
    }

    public function testSortingByName(): void
    {
        $this->offering('CS202', 'B course');
        $this->offering('IT101', 'A course');

        $ascending = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['sort' => 'name', 'dir' => 'asc']));
        $this->assertSame(['CS202', 'IT101'], array_column($ascending['rows'], 'course_code'));

        $descending = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['sort' => 'name', 'dir' => 'desc']));
        $this->assertSame(['IT101', 'CS202'], array_column($descending['rows'], 'course_code'));
    }

    public function testSortingByEnrollment(): void
    {
        $quiet = $this->offering('IT101', 'Quiet course');
        $busy  = $this->offering('IT102', 'Busy course');

        CourseFactory::enroll($busy['offering'], CourseFactory::user('student'));
        CourseFactory::enroll($busy['offering'], CourseFactory::user('student'));
        CourseFactory::enroll($quiet['offering'], CourseFactory::user('student'));

        $result = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['sort' => 'enrollment', 'dir' => 'desc']));

        $this->assertSame(['IT102', 'IT101'], array_column($result['rows'], 'course_code'));
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->offering("CRS{$i}", "Course {$i}");
        }

        $pageOne = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['per_page' => '2', 'page' => '1']));
        $pageTwo = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput(['per_page' => '2', 'page' => '2']));

        $this->assertSame(5, $pageOne['total']);
        $this->assertCount(2, $pageOne['rows']);
        $this->assertCount(2, $pageTwo['rows']);
        $this->assertNotSame(
            array_column($pageOne['rows'], 'offering_id'),
            array_column($pageTwo['rows'], 'offering_id')
        );
    }

    public function testCardShowsWhenAnOfferingHasSeveralInstructors(): void
    {
        $offering = $this->offering('IT101', 'Team-taught course');
        $a = $this->instructor();
        $b = $this->instructor();
        CourseFactory::assignInstructor($offering['offering'], $a);
        CourseFactory::assignInstructor($offering['offering'], $b);

        $result = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput([]));

        $this->assertSame(2, (int) $result['rows'][0]['instructor_count']);
        $this->assertNotEmpty($result['rows'][0]['instructor_name']);
    }

    public function testCardHandlesAnOfferingWithNoInstructor(): void
    {
        $this->offering('IT101', 'Unassigned course');

        $result = $this->search->page($this->chair, ChairpersonCourseFilters::fromInput([]));

        $this->assertSame(0, (int) $result['rows'][0]['instructor_count']);
        $this->assertSame('', trim((string) $result['rows'][0]['instructor_name']));
    }

    public function testTermsAndInstructorsListOnlyWhatActuallyAppearsInThisChairsCourses(): void
    {
        $withInstructor = $this->offering('IT101', 'Course A', 'A', 'active', 'Term 1');
        $instructor = $this->instructor();
        CourseFactory::assignInstructor($withInstructor['offering'], $instructor);

        $terms = $this->search->terms($this->chair);
        $instructors = $this->search->instructors($this->chair);

        $this->assertSame(['Term 1'], array_column($terms, 'name'));
        $this->assertCount(1, $instructors);
        $this->assertSame((int) $instructor->id, (int) $instructors[0]['id']);
    }
}
