<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The chairperson-only Courses page end to end (R6): the route actually
 * renders, query-string filters reach the page, and an empty result shows
 * the empty state instead of an error.
 *
 * @internal
 */
final class ChairpersonCoursesPageTest extends RouteTestCase
{
    private User $chair;
    private int $sub;
    private int $program;
    private static int $termSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chair = CourseFactory::user('chairperson');

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

    private function offering(string $courseCode): int
    {
        $db = db_connect();

        // academic_terms has a UNIQUE(institution_id, name, academic_year) —
        // a name shared across calls would silently fail the second insert
        // (and cascade into a failed course_offerings insert via a bad
        // term_id FK), so each offering gets its own term.
        // A generic name, not derived from $courseCode: the term filter
        // dropdown always lists every term regardless of the current
        // search, so a name containing another test's course code would
        // leak into the page and break a assertDontSee() on it.
        $db->table('academic_terms')->insert([
            'institution_id' => (int) $db->table('departments')->get()->getRowArray()['institution_id'],
            'name' => 'Term ' . (++self::$termSequence),
            'academic_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-20',
        ]);
        $term = (int) $db->insertID();

        $db->table('courses')->insert(['program_id' => $this->program, 'course_code' => $courseCode, 'course_title' => "Course {$courseCode}", 'units' => 3]);
        $course = (int) $db->insertID();

        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'A']);

        return (int) $db->insertID();
    }

    public function testThePageListsEveryCourseInTheChairsProgram(): void
    {
        $this->offering('IT101');
        $this->offering('CS202');

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/courses');

        $result->assertStatus(200);
        $result->assertSee('IT101');
        $result->assertSee('CS202');
    }

    public function testSearchingNarrowsTheList(): void
    {
        $this->offering('IT101');
        $this->offering('CS202');

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/courses?q=IT101');

        $result->assertStatus(200);
        $result->assertSee('IT101');
        $result->assertDontSee('CS202');
    }

    public function testFilteringByAnUnknownTermIsIgnoredRatherThanError(): void
    {
        $this->offering('IT101');

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/courses?term=999999');

        $result->assertStatus(200);
    }

    public function testPaginationQueryStringReachesThePage(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->offering("CRS{$i}");
        }

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/courses?per_page=2&page=2');

        $result->assertStatus(200);
    }

    public function testAChairWithNoCoursesSeesTheEmptyState(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/courses');

        $result->assertStatus(200);
        $result->assertSee('No courses yet');
    }

    public function testACourseOutsideTheChairsProgramNeverAppears(): void
    {
        $this->offering('IT101');

        $elsewhereSub = db_connect()->table('sub_departments')->insert(['department_id' => db_connect()->table('departments')->get()->getRowArray()['id'], 'code' => 'ELSE', 'name' => 'Elsewhere']);
        $elsewhereSubId = (int) db_connect()->insertID();
        db_connect()->table('programs')->insert(['sub_department_id' => $elsewhereSubId, 'code' => 'ELSE', 'name' => 'Elsewhere program']);
        $elsewhereProgram = (int) db_connect()->insertID();
        db_connect()->table('courses')->insert(['program_id' => $elsewhereProgram, 'course_code' => 'XX999', 'course_title' => 'Not mine', 'units' => 3]);

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/courses');

        $result->assertStatus(200);
        $result->assertDontSee('XX999');
    }
}
