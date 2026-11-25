<?php

declare(strict_types=1);

use App\Services\Profiles\RoleRank;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class RoleRankTest extends CIUnitTestCase
{
    public function testOrderIsFixed(): void
    {
        $this->assertSame(['dean', 'chairperson', 'instructor', 'institutional_admin', 'student', 'parent'], RoleRank::ORDER);
    }

    public function testSortOrdersByRankAndDropsUnknownRoles(): void
    {
        $this->assertSame(
            ['chairperson', 'instructor', 'student', 'parent'],
            RoleRank::sort(['parent', 'student', 'system_admin', 'instructor', 'chairperson']),
        );
    }

    public function testSortDeduplicates(): void
    {
        $this->assertSame(['student'], RoleRank::sort(['student', 'student']));
    }

    public function testHighest(): void
    {
        $this->assertSame('instructor', RoleRank::highest(['parent', 'instructor']));
        $this->assertSame('student', RoleRank::highest(['parent', 'student']));
        $this->assertNull(RoleRank::highest([]));
        $this->assertNull(RoleRank::highest(['system_admin']));
    }
}
