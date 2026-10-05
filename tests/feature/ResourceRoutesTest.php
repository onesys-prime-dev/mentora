<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The Resources route for both roles: the query string reaches the service,
 * the answer is JSON the page can use, and people outside the section get a 404.
 *
 * @internal
 */
final class ResourceRoutesTest extends RouteTestCase
{
    private int $offering;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
    }

    private function get_(User $user, string $query = '', ?int $offering = null)
    {
        $this->loggedInAs($user);

        $prefix = $user->inGroup('instructor') ? 'instructor' : 'student';

        return $this->get("{$prefix}/api/courses/" . ($offering ?? $this->offering) . "/resources{$query}");
    }

    public function testBothRolesGetTheBrowserAsJson(): void
    {
        $module = CourseFactory::module($this->offering);
        db_connect()->table('course_modules')->where('id', $module)->update(['title' => 'Week One']);

        foreach ([$this->student, $this->instructor] as $user) {
            $result = $this->get_($user);

            $this->assertSame(200, $result->response()->getStatusCode());
            $this->assertStringContainsString('Week One', json_decode((string) $result->getJSON(), true)['html']);
        }
    }

    public function testTheQueryStringReachesTheService(): void
    {
        $a = CourseFactory::module($this->offering, 'published', 1);
        $b = CourseFactory::module($this->offering, 'published', 2);
        db_connect()->table('course_modules')->where('id', $a)->update(['title' => 'Alpha Mod']);
        db_connect()->table('course_modules')->where('id', $b)->update(['title' => 'Beta Mod']);

        $html = json_decode((string) $this->get_($this->student, '?search=beta')->getJSON(), true)['html'];

        $this->assertStringContainsString('Beta Mod', $html);
        $this->assertStringNotContainsString('Alpha Mod', $html);
    }

    public function testAnotherSectionIsNotFound(): void
    {
        $other = CourseFactory::offering('B');

        $this->expectException(PageNotFoundException::class);

        $this->get_($this->student, '', $other);
    }

    public function testALoggedOutVisitorIsSentToLogin(): void
    {
        $response = $this->get("student/api/courses/{$this->offering}/resources")->response();

        $this->assertContains($response->getStatusCode(), [301, 302, 303, 401, 403]);
    }
}
