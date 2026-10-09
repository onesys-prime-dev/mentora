<?php

declare(strict_types=1);

use App\Models\Users\RolePermissionModel;
use App\Services\Admin\PermissionMatrix;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Permissions through real requests: a route guarded with Shield's
 * `permission:` filter lets a role in, and a customisation made in the
 * database changes the answer on the very next request.
 *
 * @internal
 */
final class PermissionRouteTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::flush();

        $this->withRoutes([
            ['get', 'probe/grading', static fn () => 'allowed', ['filter' => 'permission:grading.manage']],
            ['get', 'probe/roles', static fn () => 'allowed', ['filter' => 'permission:roles.manage']],
        ]);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function override(string $role, string $permission, bool $granted): void
    {
        (new RolePermissionModel())->insert(['role' => $role, 'permission' => $permission, 'granted' => $granted ? 1 : 0]);

        PermissionMatrix::flush();
    }

    private function asRole(string $role): void
    {
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();
    }

    private function statusOf(string $uri): int
    {
        $status = $this->step('GET', $uri)->response()->getStatusCode();

        // Each request in this test is a fresh visit by a freshly loaded user.
        auth()->logout();
        $this->withSession([]);

        return $status;
    }

    public function testARoleWithThePermissionGetsIn(): void
    {
        $this->asRole('instructor');

        $this->assertSame(200, $this->statusOf('probe/grading'));
    }

    public function testARoleWithoutThePermissionIsTurnedAway(): void
    {
        $this->asRole('student');

        $this->assertSame(302, $this->statusOf('probe/grading'));
    }

    public function testTheSystemAdministratorIsKeptOutOfAcademicActions(): void
    {
        $this->asRole('admin');

        $this->assertSame(302, $this->statusOf('probe/grading'));
    }

    public function testOnlyTheSystemAdministratorGetsIntoPlatformActions(): void
    {
        $this->asRole('admin');
        $this->assertSame(200, $this->statusOf('probe/roles'));

        foreach (['instructor', 'chairperson', 'institutional_admin', 'student', 'parent'] as $role) {
            $this->asRole($role);
            $this->assertSame(302, $this->statusOf('probe/roles'), "{$role} must not get into a platform action");
        }
    }

    public function testARevokedPermissionIsRefusedOnTheNextRequest(): void
    {
        $this->asRole('instructor');
        $this->assertSame(200, $this->statusOf('probe/grading'));

        $this->override('instructor', 'grading.manage', false);

        $this->asRole('instructor');
        $this->assertSame(302, $this->statusOf('probe/grading'));
    }

    public function testARevocationLeavesOtherRolesAlone(): void
    {
        $this->override('instructor', 'grading.manage', false);

        $this->asRole('chairperson');

        $this->assertSame(200, $this->statusOf('probe/grading'));
    }

    public function testAGrantedPermissionLetsTheRoleIn(): void
    {
        $this->asRole('student');
        $this->assertSame(302, $this->statusOf('probe/grading'));

        $this->override('student', 'grading.manage', true);

        $this->asRole('student');
        $this->assertSame(200, $this->statusOf('probe/grading'));
    }

    public function testNothingCanBeGrantedToOrTakenFromTheSystemAdministrator(): void
    {
        $this->override('admin', 'roles.manage', false);
        $this->override('admin', 'grading.manage', true);

        $this->asRole('admin');
        $this->assertSame(200, $this->statusOf('probe/roles'));

        $this->asRole('admin');
        $this->assertSame(302, $this->statusOf('probe/grading'));
    }
}
