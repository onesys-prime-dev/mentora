<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Signing in and landing in the right place, the public pages, the sign-up
 * routes that are switched off, and course-level access: being a student or
 * an instructor is not enough, you must belong to that course offering.
 *
 * @internal
 */
final class AuthFlowTest extends RouteTestCase
{
    private function location(string $verb, string $uri): string
    {
        return $this->call($verb, $uri)->response()->getHeaderLine('Location');
    }

    /** A signed-in user who belongs to no group at all. */
    private function userWithoutARole(): User
    {
        db_connect()->table('users')->insert(['username' => 'norole' . uniqid(), 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        return model(\CodeIgniter\Shield\Models\UserModel::class)->findById((int) db_connect()->insertID());
    }

    /*
    |--------------------------------------------------------------------------
    | Landing page by role
    |--------------------------------------------------------------------------
    */

    public function testALoggedOutVisitorOnTheHomePageGoesToLogin(): void
    {
        $this->assertStringEndsWith('/login', $this->location('GET', '/'));
    }

    public function testStudentsLandOnTheStudentDashboard(): void
    {
        $this->loggedInAs(CourseFactory::user('student'));

        $this->assertStringEndsWith('/student/dashboard', $this->location('GET', '/'));
    }

    public function testInstructorsLandOnTheInstructorDashboard(): void
    {
        $this->loggedInAs(CourseFactory::user('instructor'));

        $this->assertStringEndsWith('/instructor/dashboard', $this->location('GET', '/'));
    }

    public function testSomeoneWithoutAnyRoleGetsAForbiddenPageNotACrash(): void
    {
        $this->loggedInAs($this->userWithoutARole());

        $response = $this->get('/')->response();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('does not have a role', (string) $response->getBody());
    }

    /*
    |--------------------------------------------------------------------------
    | Public pages and switched-off sign-up
    |--------------------------------------------------------------------------
    */

    public function testTheLoginPageAndTheHealthCheckNeedNoLogin(): void
    {
        $this->assertSame(200, $this->get('login')->response()->getStatusCode());
        $this->assertSame(200, $this->get('health')->response()->getStatusCode());
    }

    public function testSelfRegistrationIsSwitchedOff(): void
    {
        foreach (['GET', 'POST'] as $verb) {
            $response = $this->call($verb, 'register')->response();

            $this->assertSame(302, $response->getStatusCode(), "{$verb} register must not show or accept a sign-up");
            $this->assertStringNotContainsString('register', $response->getHeaderLine('Location'));
        }
    }

    public function testMagicLinkLoginIsSwitchedOff(): void
    {
        foreach (['GET', 'POST'] as $verb) {
            $response = $this->call($verb, 'login/magic-link')->response();

            $this->assertSame(302, $response->getStatusCode(), "{$verb} login/magic-link must not work");
            $this->assertStringEndsWith('/login', $response->getHeaderLine('Location'));
        }
    }

    public function testUploadedFilesNeedALogin(): void
    {
        $this->assertStringEndsWith('/login', $this->location('GET', 'files/1'));
    }

    public function testLoggingOutEndsTheSession(): void
    {
        $this->loggedInAs(CourseFactory::user('student'));
        $this->assertTrue(auth()->loggedIn());

        $this->get('logout');

        $this->assertFalse(auth()->loggedIn());
        $this->assertStringEndsWith('/login', $this->location('GET', 'student/courses'));
    }

    /*
    |--------------------------------------------------------------------------
    | Signing in with a password (the real login form route)
    |--------------------------------------------------------------------------
    */

    private const EMAIL = 'sign.in@example.com';

    private const PASSWORD = 'CorrectHorse!42';

    /** A student who can really sign in: an email and a password hash, not just a session. */
    private function studentWithAPassword(): User
    {
        $user = CourseFactory::user('student');
        $user->createEmailIdentity(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        return $user;
    }

    /** Submits the login form and returns the address the browser is sent to. */
    private function signIn(string $email, string $password): string
    {
        return $this->post('login', ['email' => $email, 'password' => $password])->response()->getHeaderLine('Location');
    }

    private function assertRefusedAndSignedOut(string $location): void
    {
        $this->assertStringEndsWith('/login', $location, 'a refused sign-in goes back to the login page');
        $this->assertFalse(auth()->loggedIn(), 'nobody is signed in after a refused sign-in');
        $this->assertStringStartsWith('Unable to log you in.', (string) session('error'), 'the page says the sign-in failed');
        $this->assertStringEndsWith('/login', $this->location('GET', 'student/dashboard'), 'protected pages still need a login');
    }

    public function testAWrongPasswordIsRefusedAndNobodyIsSignedIn(): void
    {
        $this->studentWithAPassword();

        $this->assertRefusedAndSignedOut($this->signIn(self::EMAIL, 'wrong-password'));
    }

    public function testAnEmailWithNoAccountIsRefusedToo(): void
    {
        $this->studentWithAPassword();

        $this->assertRefusedAndSignedOut($this->signIn('nobody@example.com', self::PASSWORD));
    }

    public function testAnEmptyPasswordIsRefusedByTheForm(): void
    {
        $this->studentWithAPassword();

        // Left blank, the form is rejected before any password is checked: the
        // browser is sent back with a validation message (not the "Unable to
        // log you in" banner) and nobody is signed in.
        $this->signIn(self::EMAIL, '');

        $this->assertFalse(auth()->loggedIn());
        $this->assertNotEmpty(session('errors'), 'the form says the password is required');
        $this->assertStringEndsWith('/login', $this->location('GET', 'student/dashboard'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAlmostRightPasswords(): iterable
    {
        yield 'other letter case' => [strtolower(self::PASSWORD)];
        yield 'trailing space' => [self::PASSWORD . ' '];
        yield 'one character short' => [substr(self::PASSWORD, 0, -1)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideAlmostRightPasswords')]
    public function testAPasswordThatIsAlmostRightIsRefused(string $password): void
    {
        $this->studentWithAPassword();

        $this->assertRefusedAndSignedOut($this->signIn(self::EMAIL, $password));
    }

    public function testTheRightPasswordSignsInEvenAfterAFailedAttempt(): void
    {
        $this->studentWithAPassword();

        $this->signIn(self::EMAIL, 'wrong-password');
        $this->assertFalse(auth()->loggedIn());

        $location = $this->signIn(self::EMAIL, self::PASSWORD);

        $this->assertTrue(auth()->loggedIn(), 'the right password still works after a failed attempt');
        $this->assertStringNotContainsString('/login', $location);
        $this->assertStringEndsWith('/student/dashboard', $this->location('GET', '/'));
    }

    /*
    |--------------------------------------------------------------------------
    | The right role reaches the page
    |--------------------------------------------------------------------------
    */

    public function testEachRoleReachesItsOwnCoursesPage(): void
    {
        $this->loggedInAs(CourseFactory::user('student'));
        $this->assertSame(200, $this->get('student/courses')->response()->getStatusCode());

        auth()->logout();

        $this->loggedInAs(CourseFactory::user('instructor'));
        $this->assertSame(200, $this->get('instructor/courses')->response()->getStatusCode());
    }

    /*
    |--------------------------------------------------------------------------
    | Belonging to the course
    |--------------------------------------------------------------------------
    */

    public function testAnEnrolledStudentCanReadTheirCoursesDiscussions(): void
    {
        $offering = CourseFactory::offering();
        $student = CourseFactory::user('student');
        CourseFactory::enroll($offering, $student);

        $this->loggedInAs($student);

        $this->assertSame(200, $this->get("student/api/courses/{$offering}/discussions")->response()->getStatusCode());
    }

    public function testAStudentCannotReadACourseTheyAreNotEnrolledIn(): void
    {
        $mine = CourseFactory::offering('A');
        $other = CourseFactory::offering('B');
        $student = CourseFactory::user('student');
        CourseFactory::enroll($mine, $student);

        $this->loggedInAs($student);

        $this->expectException(PageNotFoundException::class);
        $this->get("student/api/courses/{$other}/discussions");
    }

    public function testAnInstructorCanOnlyOpenTheirOwnSection(): void
    {
        $mine = CourseFactory::offering('A');
        $other = CourseFactory::offering('B');
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($mine, $instructor);

        $this->loggedInAs($instructor);

        $this->assertSame(200, $this->get("instructor/api/courses/{$mine}/discussions")->response()->getStatusCode());

        $this->expectException(PageNotFoundException::class);
        $this->get("instructor/api/courses/{$other}/discussions");
    }

    public function testAStudentWhoIsNotEnrolledCannotPostToTheCourse(): void
    {
        $offering = CourseFactory::offering();
        $stranger = CourseFactory::user('student');

        $this->loggedInAs($stranger);

        $body = json_decode(
            $this->withBody(json_encode(['title' => 'Let me in']))
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("student/api/courses/{$offering}/discussions")
                ->getJSON(),
            true
        );

        $this->assertFalse($body['success']);
        $this->assertSame('Not authorized.', $body['message']);
        $this->assertSame(0, db_connect()->table('discussion_threads')->countAllResults());
    }

    public function testAnEnrolledStudentCanPostToTheCourse(): void
    {
        $offering = CourseFactory::offering();
        $student = CourseFactory::user('student');
        CourseFactory::enroll($offering, $student);

        $this->loggedInAs($student);

        $body = json_decode(
            $this->withBody(json_encode(['title' => 'A real question']))
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("student/api/courses/{$offering}/discussions")
                ->getJSON(),
            true
        );

        $this->assertTrue($body['success']);
        $this->assertSame(1, db_connect()->table('discussion_threads')->where('offering_id', $offering)->countAllResults());
    }
}
