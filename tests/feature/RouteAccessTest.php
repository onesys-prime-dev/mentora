<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The doors: every route under /student and /instructor (read from the real
 * route table, so a new route is checked automatically) must send a logged-out
 * visitor to the login page, and turn away someone who is logged in with the
 * other role, before any controller code runs.
 *
 * @internal
 */
final class RouteAccessTest extends RouteTestCase
{
    private const VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** Top-level route prefixes that may exist: public pages, Shield's auth pages, and the role areas. */
    private const KNOWN_PREFIXES = ['', 'register', 'login', 'logout', 'auth', 'health', 'files', 'student', 'instructor', 'admin'];

    /**
     * Every registered route as [verb, example URL, role area].
     *
     * @return list<array{string, string, string}>
     */
    private static function routes(?string $area = null): array
    {
        $routes = service('routes')->loadRoutes();
        $found = [];

        foreach (self::VERBS as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                $prefix = explode('/', $route)[0];

                if ($area !== null ? $prefix !== $area : ! in_array($prefix, ['student', 'instructor', 'admin'], true)) {
                    continue;
                }

                // The route table holds the expanded patterns: (:num) is
                // "([0-9]+)" and (:segment) is "([^/]+)". Numbers become 1,
                // any other placeholder becomes x.
                $uri = str_replace('([0-9]+)', '1', $route);
                $uri = preg_replace('/\([^()]*\)/', 'x', $uri);

                $found[] = [$verb, $uri, $prefix];
            }
        }

        return $found;
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideRoleRoutes(): iterable
    {
        foreach (self::routes() as [$verb, $uri, $area]) {
            yield "{$verb} {$uri}" => [$verb, $uri, $area];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The inventory
    |--------------------------------------------------------------------------
    */

    public function testTheRouteTableHasEveryRoleRouteWeExpect(): void
    {
        $this->assertGreaterThanOrEqual(40, count(self::routes('student')), 'student routes were not discovered');
        $this->assertGreaterThanOrEqual(100, count(self::routes('instructor')), 'instructor routes were not discovered');
        $this->assertGreaterThanOrEqual(1, count(self::routes('admin')), 'admin routes were not discovered');
    }

    public function testEveryRouteIsPublicKnownOrBehindARoleArea(): void
    {
        $routes = service('routes')->loadRoutes();
        $unknown = [];

        foreach (self::VERBS as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                if (! in_array(explode('/', $route)[0], self::KNOWN_PREFIXES, true)) {
                    $unknown[] = "{$verb} {$route}";
                }
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'A new top-level route appeared. Put it under student/, instructor/ or admin/, or add it to KNOWN_PREFIXES here once you have decided who may reach it.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Logged out
    |--------------------------------------------------------------------------
    */

    #[DataProvider('provideRoleRoutes')]
    public function testALoggedOutVisitorIsSentToTheLoginPage(string $verb, string $uri): void
    {
        $response = $this->call($verb, $uri)->response();

        $this->assertSame(302, $response->getStatusCode(), "{$verb} {$uri} must not be reachable without logging in");
        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
    }

    /*
    |--------------------------------------------------------------------------
    | Wrong role
    |--------------------------------------------------------------------------
    */

    #[DataProvider('provideRoleRoutes')]
    public function testSomeoneWithTheOtherRoleIsTurnedAway(string $verb, string $uri, string $area): void
    {
        $other = $area === 'student' ? 'instructor' : 'student';

        $this->loggedInAs(CourseFactory::user($other));

        $response = $this->call($verb, $uri)->response();

        $this->assertSame(302, $response->getStatusCode(), "a {$other} must not reach {$verb} {$uri}");
        $this->assertStringNotContainsString($uri, $response->getHeaderLine('Location'), 'they must be sent away, not onward');
        $this->assertStringNotContainsString('/login', $response->getHeaderLine('Location'), 'they are signed in, so this is a role refusal, not a login prompt');
    }
}
