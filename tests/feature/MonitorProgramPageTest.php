<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\RouteTestCase;

/**
 * Monitor Program's page end to end (R5 of the chairperson plan, branch
 * feat/monitor-program-page): the route renders for a chairperson, filters
 * reach the page, course comparison rows link into the chairperson's own
 * courses, and a course outside the program never appears.
 *
 * @internal
 */
final class MonitorProgramPageTest extends RouteTestCase
{
    private int $mine;
    private int $elsewhere;
    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = CourseFactory::offering('A');
        $this->elsewhere = CourseFactory::offering('B');

        $this->chair = CourseFactory::user('chairperson');

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id'   => $this->subDepartmentOf($this->mine),
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    private function subDepartmentOf(int $offeringId): int
    {
        return (int) db_connect()->table('course_offerings co')
            ->select('programs.sub_department_id')
            ->join('courses', 'courses.id = co.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray()['sub_department_id'];
    }

    public function testThePageLoadsForAChairperson(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/monitor-program');

        $result->assertStatus(200);
        $result->assertSee('Monitor Program');
    }

    public function testAnInstructorIsDenied(): void
    {
        $this->loggedInAs(CourseFactory::user('instructor'));

        $result = $this->get('chairperson/monitor-program');

        $result->assertRedirectTo('/');
    }

    public function testCourseComparisonLinksIntoTheChairsOwnCourse(): void
    {
        $this->loggedInAs($this->chair);

        $mineCode = (string) db_connect()->table('course_offerings co')
            ->select('courses.course_code')
            ->join('courses', 'courses.id = co.course_id')
            ->where('co.id', $this->mine)
            ->get()->getRowArray()['course_code'];

        $result = $this->get('chairperson/monitor-program');

        $result->assertStatus(200);
        $result->assertSee($mineCode);
        $result->assertSee("chairperson/courses/{$this->mine}/");
    }

    public function testACourseOutsideTheProgramNeverAppears(): void
    {
        $this->loggedInAs($this->chair);

        $elsewhereCode = (string) db_connect()->table('course_offerings co')
            ->select('courses.course_code')
            ->join('courses', 'courses.id = co.course_id')
            ->where('co.id', $this->elsewhere)
            ->get()->getRowArray()['course_code'];

        $result = $this->get('chairperson/monitor-program');

        $result->assertStatus(200);
        $result->assertDontSee($elsewhereCode);
    }

    public function testFilteringByAnUnknownTermIsIgnoredRatherThanError(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/monitor-program?term=999999');

        $result->assertStatus(200);
    }

    public function testSortingByAnUnknownColumnIsIgnoredRatherThanError(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/monitor-program?sort=not_a_real_column');

        $result->assertStatus(200);
    }

    public function testAChairWithNoCoursesSeesTheEmptyState(): void
    {
        $this->loggedInAs(CourseFactory::user('chairperson'));

        $result = $this->get('chairperson/monitor-program');

        $result->assertStatus(200);
        $result->assertSee('Nothing to monitor yet');
    }

    public function testAnAtRiskStudentShowsWithTheirCourseLabel(): void
    {
        $module = CourseFactory::module($this->mine);
        $unit = CourseFactory::unit($module);
        CourseFactory::items($unit, array_fill(0, 4, []));

        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
        CourseFactory::enroll($this->mine, $student);

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/monitor-program');

        $result->assertStatus(200);
        $result->assertSee('Ada Lovelace');
        $result->assertSee('Never active');
    }

    public function testThePublishedGradeAverageAppearsOnThePage(): void
    {
        $schemeId = GradeFactory::scheme($this->mine);
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['status' => 'Published']);

        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'A', 'last_name' => 'B']);
        CourseFactory::enroll($this->mine, $student);

        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $this->mine,
            'student_id' => $student->id,
            'grading_scheme_id' => $schemeId,
            'calculated_percentage' => 88.0,
            'is_override' => 0,
        ]);

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/monitor-program');

        $result->assertStatus(200);
        $result->assertSee('88');
    }
}
