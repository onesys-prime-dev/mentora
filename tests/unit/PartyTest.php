<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Messaging\Party;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PartyTest extends CIUnitTestCase
{
    public function testStaffIfAnyStaffRole(): void
    {
        $p = new Party(1, ['student', 'instructor'], true, 1);

        $this->assertTrue($p->isStaff());
        $this->assertFalse($p->isStudent());
        $this->assertSame('staff', $p->capacity(false));
    }

    public function testParentOnlyCannotActOutsideChildThread(): void
    {
        $p = new Party(1, ['parent'], true, 1);

        $this->assertTrue($p->isParent());
        $this->assertNull($p->capacity(false));
        $this->assertSame('parent', $p->capacity(true));
    }

    public function testStaffParentPicksByThread(): void
    {
        $p = new Party(1, ['instructor', 'parent'], true, 1);

        $this->assertSame('parent', $p->capacity(true));
        $this->assertSame('staff', $p->capacity(false));
    }

    public function testStudentCapacity(): void
    {
        $p = new Party(1, ['student'], true, 1);

        $this->assertTrue($p->isStudent());
        $this->assertFalse($p->isStaff());
        $this->assertSame('student', $p->capacity(false));
    }

    public function testSystemAdmin(): void
    {
        $this->assertTrue((new Party(1, ['admin'], true, null))->isSystemAdmin());
        $this->assertFalse((new Party(1, ['student'], true, 1))->isSystemAdmin());
        // Ruling R10: beside institution roles the admin role is ignored.
        $mixed = new Party(1, ['instructor', 'admin'], true, 1);
        $this->assertFalse($mixed->isSystemAdmin());
        $this->assertSame('staff', $mixed->capacity(false));
    }

    public function testTopRoleFollowsTheProfileRankOrder(): void
    {
        $this->assertSame('dean', (new Party(1, ['parent', 'dean', 'student'], true, 1))->topRole());
        $this->assertSame('institutional_admin', (new Party(1, ['parent', 'institutional_admin'], true, 1))->topRole());
        $this->assertSame('', (new Party(1, [], true, 1))->topRole());
    }

    public function testWithRoleAppendsOnceAndKeepsTheRest(): void
    {
        $p = (new Party(7, ['student'], true, 3, true))->withRole('instructor')->withRole('instructor');

        $this->assertSame(['student', 'instructor'], $p->roles);
        $this->assertSame([7, true, 3, true], [$p->id, $p->active, $p->institutionId, $p->minor]);
    }
}
