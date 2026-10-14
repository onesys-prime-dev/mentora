<?php

declare(strict_types=1);

use App\Services\Auth\ActiveRoleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Which role a multi-role person opens as the first time they sign in:
 * the highest one held.
 *
 * @internal
 */
final class PreferredRoleOrderTest extends CIUnitTestCase
{
    public function testDeanComesBeforeEveryOtherRole(): void
    {
        $this->assertSame('dean', ActiveRoleService::preferredRole(['instructor', 'chairperson', 'dean']));
        $this->assertSame('dean', ActiveRoleService::preferredRole(['instructor', 'dean']));
    }

    public function testChairpersonComesBeforeInstructorAndStudent(): void
    {
        $this->assertSame('chairperson', ActiveRoleService::preferredRole(['instructor', 'chairperson']));
        $this->assertSame('chairperson', ActiveRoleService::preferredRole(['student', 'chairperson']));
    }

    public function testOtherwiseTheFirstGroupIsUsed(): void
    {
        $this->assertSame('instructor', ActiveRoleService::preferredRole(['instructor', 'student']));
        $this->assertSame('student', ActiveRoleService::preferredRole(['student']));
    }
}
