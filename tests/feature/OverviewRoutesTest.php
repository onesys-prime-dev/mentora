<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The Overview route for both roles: members get JSON, everyone else a 404.
 *
 * @internal
 */
final class OverviewRoutesTest extends RouteTestCase
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

        foreach ([$this->instructor, $this->student] as $user) {
            db_connect()->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => 'Pat', 'last_name' => 'Person']);
            db_connect()->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => "p{$user->id}@example.test", 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);
        }

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
    }

    private function open(User $user, ?int $offering = null)
    {
        $this->loggedInAs($user);

        return $this->get(($user->inGroup('instructor') ? 'instructor' : 'student') . '/api/courses/' . ($offering ?? $this->offering) . '/overview');
    }

    public function testMembersGetTheOverviewAsJson(): void
    {
        foreach ([$this->student, $this->instructor] as $user) {
            $result = $this->open($user);

            $this->assertSame(200, $result->response()->getStatusCode());
            $this->assertArrayHasKey('html', json_decode((string) $result->getJSON(), true));
        }
    }

    public function testAnotherSectionsPeopleGetA404(): void
    {
        $other = CourseFactory::offering('B');
        $outsider = CourseFactory::user('student');
        $otherTeacher = CourseFactory::user('instructor');
        CourseFactory::enroll($other, $outsider);
        CourseFactory::assignInstructor($other, $otherTeacher);

        foreach ([$outsider, $otherTeacher] as $user) {
            try {
                $this->open($user);
                $this->fail('reached another section\'s overview');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testALoggedOutVisitorIsSentToLogin(): void
    {
        $this->assertContains($this->get("student/api/courses/{$this->offering}/overview")->response()->getStatusCode(), [301, 302, 303, 401, 403]);
    }

    public function testTheStudentsInstructorAvatarOpensTheirProfile(): void
    {
        CourseFactory::members($this->offering, [$this->instructor, $this->student]);
        $html = fn (User $u): string => json_decode((string) $this->open($u)->getJSON(), true)['html'];

        $this->assertStringContainsString('data-person-avatar="' . $this->instructor->id . '"', $html($this->student));
        $this->assertStringNotContainsString('placeholder/profile.png', $html($this->student));

        // An instructor from another institution is not someone the student may see (FR2).
        db_connect()->table('institution_users')->where('user_id', $this->instructor->id)->delete();
        $this->assertStringNotContainsString('data-person-avatar', $html($this->student));
        $this->assertStringContainsString('data-avatar', $html($this->student));
    }

    public function testASectionWithNoInstructorYetShowsNoAvatar(): void
    {
        $empty = CourseFactory::offering('C');
        CourseFactory::enroll($empty, $this->student);

        $html = json_decode((string) $this->open($this->student, $empty)->getJSON(), true)['html'];
        $this->assertStringContainsString('Not assigned yet', $html);
        $this->assertStringNotContainsString('data-avatar', $html);
        $this->assertStringNotContainsString('placeholder/profile.png', $html);
    }
}
