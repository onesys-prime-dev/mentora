<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\Grades\GradeAuthority;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Where nobody may approve overrides or sign off grades (audit decisions 7.3, U1f).
 *
 * @internal
 */
final class GradeAuthorityGapsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $withDean          = DeanFactory::college('GP');
        $this->institution = $withDean['institution'];
        DeanFactory::dean($this->institution, $withDean['college']);
        DeanFactory::college('GQ', $this->institution); // College GQ: no dean

        PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function clear(string $role, string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => $role, 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();
    }

    public function testThereAreNoGapsByDefault(): void
    {
        $this->assertSame([], GradeAuthority::gaps($this->institution));
    }

    public function testACollegeWithNoDeanIsAGapWhenNoAdministratorMayApprove(): void
    {
        $this->clear('institutional_admin', 'grades.approve');

        $this->assertSame([['college' => 'College GQ', 'permission' => 'grades.approve']], GradeAuthority::gaps($this->institution));
    }

    public function testDeansWithoutThePermissionAddTheirColleges(): void
    {
        $this->clear('institutional_admin', 'grades.signoff');
        $this->clear('dean', 'grades.signoff');

        $this->assertSame(['College GP', 'College GQ'], array_column(GradeAuthority::gaps($this->institution), 'college'));
    }

    public function testAnInstitutionWithNoDeanHasNoGaps(): void
    {
        ['institution' => $noDeans] = DeanFactory::college('GR');
        db_connect()->table('role_permissions')->insert(['institution_id' => $noDeans, 'role' => 'institutional_admin', 'permission' => 'grades.approve', 'granted' => 0]);
        PermissionMatrix::flush();

        $this->assertSame([], GradeAuthority::gaps($noDeans), 'without deans there is no approval to be stuck');
    }
}
