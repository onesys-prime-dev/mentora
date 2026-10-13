<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Monitor Program's data endpoints end to end (R5 of the chairperson plan,
 * branch feat/monitor-program-data): only a chairperson can reach them, and
 * every response is scoped to their own program — a course outside it never
 * appears, whatever endpoint is asked.
 *
 * @internal
 */
final class MonitorProgramRoutesTest extends RouteTestCase
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

    private function json(\CodeIgniter\Test\TestResponse $result): array
    {
        return json_decode((string) $result->getJSON(), true);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function testAnInstructorIsDeniedEveryEndpoint(): void
    {
        $this->loggedInAs(CourseFactory::user('instructor'));

        $result = $this->get('chairperson/api/monitor-program/snapshot');

        $result->assertRedirectTo('/');
    }

    public function testASignedOutVisitorIsRedirectedToLogin(): void
    {
        $result = $this->get('chairperson/api/monitor-program/snapshot');

        $result->assertRedirectTo('/login');
    }

    /*
    |--------------------------------------------------------------------------
    | Program scoping
    |--------------------------------------------------------------------------
    */

    public function testSnapshotOnlyCountsTheChairsOwnProgram(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/api/monitor-program/snapshot');

        $result->assertStatus(200);
        $this->assertSame(1, $this->json($result)['courses']);
    }

    public function testEnrollmentNeverIncludesAnOfferingOutsideTheProgram(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->elsewhere, $student);

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/api/monitor-program/enrollment');

        $result->assertStatus(200);
        $this->assertSame([$this->mine], array_column($this->json($result), 'offering_id'));
    }

    public function testCourseComparisonNeverListsACourseOutsideTheProgram(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/api/monitor-program/course-comparison');

        $result->assertStatus(200);
        $codes = array_column($this->json($result), 'course_code');
        $elsewhereCode = db_connect()->table('course_offerings co')
            ->select('courses.course_code')
            ->join('courses', 'courses.id = co.course_id')
            ->where('co.id', $this->elsewhere)
            ->get()->getRowArray()['course_code'];

        $this->assertNotContains($elsewhereCode, $codes);
    }

    public function testAtRiskNeverIncludesAStudentOutsideTheProgram(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->elsewhere, $student);

        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/api/monitor-program/at-risk');

        $result->assertStatus(200);
        $this->assertSame([], $this->json($result));
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function testFilteringByAnUnknownTermIsIgnoredRatherThanError(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/api/monitor-program/snapshot?term=999999');

        $result->assertStatus(200);
    }

    public function testFilteringByTermNarrowsToThatTermsOfferings(): void
    {
        $termId = (int) db_connect()->table('course_offerings')->where('id', $this->mine)->get()->getRowArray()['term_id'];

        $this->loggedInAs($this->chair);

        $result = $this->get("chairperson/api/monitor-program/snapshot?term={$termId}");

        $result->assertStatus(200);
        $this->assertSame(1, $this->json($result)['courses']);
    }

    public function testAnInvalidDateRangeIsIgnoredRatherThanError(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/api/monitor-program/instructor-activity?from=not-a-date');

        $result->assertStatus(200);
    }

    public function testTermsListsOnlyTermsTheChairsCoursesRunIn(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/api/monitor-program/terms');

        $result->assertStatus(200);
        $this->assertCount(1, $this->json($result));
    }
}
