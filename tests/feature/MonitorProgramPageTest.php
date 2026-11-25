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
            'department_id'   => $this->departmentOf($this->mine),
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    private function departmentOf(int $offeringId): int
    {
        return (int) db_connect()->table('course_offerings co')
            ->select('programs.department_id')
            ->join('courses', 'courses.id = co.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray()['department_id'];
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

    public function testAtRiskStudentsHaveGatedAvatarsBesideTheirLegalNamesFromOneLookup(): void
    {
        CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->mine)), array_fill(0, 2, []));

        $seen   = CourseFactory::user('student');
        $hidden = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $seen->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        db_connect()->table('user_profiles')->insert(['user_id' => $hidden->id, 'first_name' => 'Hal', 'last_name' => 'Hidden', 'preferred_first_name' => 'Zedekiah']);
        CourseFactory::enroll($this->mine, $seen);
        CourseFactory::enroll($this->mine, $hidden);
        CourseFactory::members($this->mine, [$this->chair, $seen]);
        // In the course but in no institution: the chairperson may not open this student's card.
        CourseFactory::members($this->mine, [$hidden], false);

        $lookups = function (): array {
            $n = 0;
            $l = static function (\CodeIgniter\Database\Query $q) use (&$n): void {
                $n += str_contains($q->getQuery(), 'preferred_first_name') ? 1 : 0;
            };
            $this->loggedInAs($this->chair);
            \CodeIgniter\Events\Events::on('DBQuery', $l);
            $body = $this->get('chairperson/monitor-program')->response()->getBody();
            \CodeIgniter\Events\Events::removeListener('DBQuery', $l);

            return [$n, $body];
        };
        [$few, $body] = $lookups();

        $this->assertStringContainsString('data-person-avatar="' . $seen->id . '"', $body);
        // The text a reader sees (no tags or attributes, so the avatar's own label does not count).
        $visible = html_entity_decode(strip_tags(preg_replace('#<(script|template)[ >].*?</(script|template)>#s', '', $body)));
        $this->assertStringContainsString('Juan Dela Cruz', $visible, 'a monitoring list keeps the legal name');
        $this->assertStringNotContainsString('Jun Dela Cruz', $visible);
        $this->assertStringNotContainsString('data-person-avatar="' . $hidden->id . '"', $body);
        $this->assertStringContainsString('Hal Hidden', $body);
        $this->assertStringNotContainsString('Zedekiah', $body);

        for ($i = 0; $i < 3; $i++) {
            $more = CourseFactory::user('student');
            CourseFactory::enroll($this->mine, $more);
            CourseFactory::members($this->mine, [$more]);
        }
        $this->assertLessThanOrEqual($few, $lookups()[0], 'one avatar lookup for the page, not one per student');
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
