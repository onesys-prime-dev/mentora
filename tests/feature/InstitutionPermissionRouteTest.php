<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The `permission:` route filter reads the person's institution's settings
 * (audit decision U1b), not only the platform's.
 *
 * @internal
 */
final class InstitutionPermissionRouteTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::flush();

        $this->withRoutes([
            ['get', 'probe/grading', static fn () => 'allowed', ['filter' => 'permission:grading.manage']],
        ]);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function statusFor(int $institution): int
    {
        $this->loggedInAs(PeopleFactory::person('instructor', institutionId: $institution));
        $this->withSession();

        $status = $this->step('GET', 'probe/grading')->response()->getStatusCode();

        auth()->logout();
        $this->withSession([]);

        return $status;
    }

    public function testAnInstitutionThatClearedAPermissionKeepsItsOwnInstructorsOut(): void
    {
        $a = PeopleFactory::institution('ROUTA');
        $b = PeopleFactory::institution('ROUTB');
        db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
        PermissionMatrix::flush();

        $this->assertSame(302, $this->statusFor($a));
        $this->assertSame(200, $this->statusFor($b));
    }
}
