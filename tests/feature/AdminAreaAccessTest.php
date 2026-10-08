<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The system administrator area: only the admin group gets in, every other
 * role (and anyone logged out) is turned away before a controller runs, and
 * an administrator who signs in lands there.
 *
 * @internal
 */
final class AdminAreaAccessTest extends RouteTestCase
{
    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    public function testAnAdministratorSeesTheAdminHome(): void
    {
        $this->loggedInAs(CourseFactory::user('admin'));

        $result = $this->call('GET', 'admin/dashboard');

        $this->assertTrue($result->isOK());
        $this->assertStringContainsString('System Administration', $result->getBody());
    }

    public function testTheAdminRootIsTheSamePage(): void
    {
        $this->loggedInAs(CourseFactory::user('admin'));

        $this->assertTrue($this->call('GET', 'admin')->isOK());
    }

    public function testALoggedOutVisitorIsSentToTheLoginPage(): void
    {
        $response = $this->call('GET', 'admin/dashboard')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
    }

    #[DataProvider('provideOtherRoles')]
    public function testEveryOtherRoleIsTurnedAway(string $role): void
    {
        $this->loggedInAs(CourseFactory::user($role));

        $response = $this->call('GET', 'admin/dashboard')->response();

        $this->assertSame(302, $response->getStatusCode(), "a {$role} must not reach the admin area");
        $this->assertStringNotContainsString('admin/dashboard', $response->getHeaderLine('Location'));
        $this->assertStringNotContainsString('/login', $response->getHeaderLine('Location'));
    }

    public function testAnAdministratorIsSentToTheAdminHomeFromTheSiteRoot(): void
    {
        $this->loggedInAs(CourseFactory::user('admin'));

        $response = $this->call('GET', '/')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('admin/dashboard', $response->getHeaderLine('Location'));
    }
}
