<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Read-only mode: for people of an institution that is read-only, past or
 * deleted, every request that would change something is refused before any
 * controller runs, while signing in, looking and exporting still work, and a
 * banner says why. Everyone else is unaffected.
 *
 * Every changing route in the student and instructor areas is read from the
 * real route table, so a new one is covered automatically.
 *
 * @internal
 */
final class ReadOnlyModeTest extends RouteTestCase
{
    private const WRITING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @return list<array{string, string, string}> verb, example URL, role area
     */
    private static function writingRoutes(): array
    {
        $routes = service('routes')->loadRoutes();
        $found  = [];

        foreach (self::WRITING as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                $prefix = explode('/', $route)[0];

                if (! in_array($prefix, ['student', 'instructor'], true)) {
                    continue;
                }

                $uri = str_replace('([0-9]+)', '1', $route);
                $uri = preg_replace('/\([^()]*\)/', 'x', $uri);

                // A person's own things stay open in a read-only institution (decision 8.2).
                foreach (App\Filters\ReadOnlyFilter::OWN_ACCOUNT as $pattern) {
                    if (preg_match($pattern, $uri) === 1) {
                        continue 2;
                    }
                }

                $found[] = [$verb, $uri, $prefix];
            }
        }

        return $found;
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provideWritingRoutes(): iterable
    {
        foreach (self::writingRoutes() as [$verb, $uri, $area]) {
            yield "{$verb} {$uri}" => [$verb, $uri, $area];
        }
    }

    private function institution(string $status, ?string $deletedAt = null): int
    {
        static $n = 0;
        $n++;

        db_connect()->table('institutions')->insert(['name' => "Institution RO{$n}", 'code' => "RO{$n}", 'status' => $status, 'deleted_at' => $deletedAt]);

        return (int) db_connect()->insertID();
    }

    private function userIn(string $role, string $status, ?string $deletedAt = null): User
    {
        $user = CourseFactory::user($role);
        db_connect()->table('institution_users')->insert(['institution_id' => $this->institution($status, $deletedAt), 'user_id' => $user->id]);

        return $user;
    }

    private function signIn(User $user): void
    {
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @return array<string, mixed> */
    private function json(string $verb, string $uri): array
    {
        $this->withHeaders(['Accept' => 'application/json']);

        try {
            $response = $this->step($verb, $uri)->response();
        } catch (CodeIgniter\Exceptions\PageNotFoundException) {
            // Got past the read-only gate and met the real rules: this user is in no course.
            return ['status' => 404, 'body' => 'not found'];
        }

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /*
    |--------------------------------------------------------------------------
    | Every changing route is closed
    |--------------------------------------------------------------------------
    */

    public function testTheRouteTableHasChangingRoutesToCheck(): void
    {
        $areas = array_count_values(array_column(self::writingRoutes(), 2));

        $this->assertGreaterThanOrEqual(10, $areas['student'] ?? 0);
        $this->assertGreaterThanOrEqual(50, $areas['instructor'] ?? 0);
    }

    #[DataProvider('provideWritingRoutes')]
    public function testAReadOnlyInstitutionsPeopleCannotChangeAnything(string $verb, string $uri, string $area): void
    {
        $this->signIn($this->userIn($area, 'read_only'));

        $result = $this->json($verb, $uri);

        $this->assertSame(403, $result['status'], "{$verb} {$uri} must be refused in read-only mode");
        $this->assertSame('institution_read_only', json_decode($result['body'], true)['error']);
    }

    #[DataProvider('provideWritingRoutes')]
    public function testThePeopleOfAPastInstitutionCannotChangeAnythingEither(string $verb, string $uri, string $area): void
    {
        $this->signIn($this->userIn($area, 'past'));

        $this->assertSame(403, $this->json($verb, $uri)['status']);
    }

    #[DataProvider('provideWritingRoutes')]
    public function testNorCanThePeopleOfADeletedInstitution(string $verb, string $uri, string $area): void
    {
        $this->signIn($this->userIn($area, 'active', '2026-10-01 10:00:00'));

        $this->assertSame(403, $this->json($verb, $uri)['status']);
    }

    #[DataProvider('provideWritingRoutes')]
    public function testAnActiveInstitutionsPeopleAreNotBlockedByThisRule(string $verb, string $uri, string $area): void
    {
        $this->signIn($this->userIn($area, 'active'));

        // Past the read-only gate the request meets the real rules (this user is in no course, so
        // many answer "not found" or fail validation). Any of that is fine; only the read-only
        // refusal would be wrong.
        try {
            $result = $this->json($verb, $uri);
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertStringNotContainsString('institution_read_only', $result['body'], "{$verb} {$uri} must not be refused for an active institution");
    }

    /*
    |--------------------------------------------------------------------------
    | Looking still works
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string}> */
    public static function provideRoles(): iterable
    {
        yield 'student' => ['student'];
        yield 'instructor' => ['instructor'];
    }

    #[DataProvider('provideRoles')]
    public function testThePeopleOfAReadOnlyInstitutionCanStillOpenTheirPages(string $role): void
    {
        $this->signIn($this->userIn($role, 'read_only'));

        $this->assertTrue($this->step('GET', "{$role}/dashboard")->isOK());
        $this->assertTrue($this->step('GET', "{$role}/courses")->isOK());
    }

    #[DataProvider('provideRoles')]
    public function testReadingThroughTheJsonApiStillWorks(string $role): void
    {
        $this->signIn($this->userIn($role, 'past'));

        $this->withHeaders(['Accept' => 'application/json']);

        $this->assertNotSame(403, $this->step('GET', "{$role}/api/calendar/events")->response()->getStatusCode());
    }

    public function testAChangeFromABrowserGoesBackWithAMessage(): void
    {
        $this->signIn($this->userIn('student', 'read_only'));

        $response = $this->step('POST', 'student/api/courses/1/announcements/1/react')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('read-only', (string) session('error'));
    }

    /*
    |--------------------------------------------------------------------------
    | The banner
    |--------------------------------------------------------------------------
    */

    #[DataProvider('provideRoles')]
    public function testAReadOnlyInstitutionsPeopleSeeTheBanner(string $role): void
    {
        $this->signIn($this->userIn($role, 'read_only'));

        $body = $this->step('GET', "{$role}/dashboard")->response()->getBody();

        $this->assertStringContainsString('data-read-only-banner', $body);
        $this->assertStringContainsString('Read-only.', $body);
        $this->assertStringContainsString('subscription has ended', $body);
    }

    public function testAPastInstitutionsPeopleSeeADifferentBanner(): void
    {
        $this->signIn($this->userIn('student', 'past'));

        $body = $this->step('GET', 'student/dashboard')->response()->getBody();

        $this->assertStringContainsString('Institution no longer active.', $body);
        $this->assertStringNotContainsString('subscription has ended', $body);
    }

    #[DataProvider('provideRoles')]
    public function testNoBannerForAnActiveInstitution(string $role): void
    {
        $this->signIn($this->userIn($role, 'active'));

        $this->assertStringNotContainsString('data-read-only-banner', $this->step('GET', "{$role}/dashboard")->response()->getBody());
    }

    public function testNoBannerForSomeoneWithNoInstitution(): void
    {
        $this->signIn(CourseFactory::user('student'));

        $this->assertStringNotContainsString('data-read-only-banner', $this->step('GET', 'student/dashboard')->response()->getBody());
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing else is affected
    |--------------------------------------------------------------------------
    */

    public function testTheSystemAdministratorIsNotRestrictedByAnyInstitution(): void
    {
        db_connect()->table('institutions')->insert(['name' => 'Past Univ', 'code' => 'PASTU', 'status' => 'past']);

        $admin = model(CodeIgniter\Shield\Models\UserModel::class)->findById(CourseFactory::user('admin')->id);
        $this->signIn($admin);

        $this->assertTrue($this->step('GET', 'admin/dashboard')->isOK());
        $this->assertStringNotContainsString('data-read-only-banner', $this->step('GET', 'admin/dashboard')->response()->getBody());
    }

    public function testRenewingLiftsTheRestrictionOnTheNextRequest(): void
    {
        $user = $this->userIn('student', 'read_only');
        $this->signIn($user);

        $this->assertSame(403, $this->json('POST', 'student/api/courses/1/announcements/1/react')['status']);

        db_connect()->table('institutions')->update(['status' => 'active']);

        $this->assertStringNotContainsString('institution_read_only', $this->json('POST', 'student/api/courses/1/announcements/1/react')['body']);
    }

    public function testAnotherInstitutionsRestrictionDoesNotReachMe(): void
    {
        $this->institution('read_only');
        $this->signIn($this->userIn('student', 'active'));

        $this->assertStringNotContainsString('institution_read_only', $this->json('POST', 'student/api/courses/1/announcements/1/react')['body']);
    }

    public function testSigningOutStillWorksInReadOnlyMode(): void
    {
        $this->signIn($this->userIn('student', 'past'));

        $this->assertStringContainsString('login', $this->step('POST', 'logout')->response()->getHeaderLine('Location'));
    }
}
