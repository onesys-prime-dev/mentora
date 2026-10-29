<?php

declare(strict_types=1);

use App\Services\Users\AccountControl;
use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\Grades\GradeAuthority;
use CodeIgniter\Config\Services;
use Config\AuthGroups;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Who decides a grade override or a sign-off here (audit F3, 7.2, 7.3, U1e).
 *
 * @internal
 */
final class GradeAuthorityTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    public function testADeanWithThePermissionDecides(): void
    {
        ['institution' => $i, 'department' => $d] = DeanFactory::department('GA1');
        $dean = DeanFactory::dean($i, $d);

        $this->assertSame((int) $dean->id, GradeAuthority::deanFor($d, 'grades.approve'));
    }

    public function testADeanWithoutThePermissionCountsAsNoDean(): void
    {
        ['institution' => $i, 'department' => $d] = DeanFactory::department('GA2');
        $dean = DeanFactory::dean($i, $d);
        db_connect()->table('role_permissions')->insert(['institution_id' => $i, 'role' => 'dean', 'permission' => 'grades.approve', 'granted' => 0]);
        PermissionMatrix::flush();

        $this->assertNull(GradeAuthority::deanFor($d, 'grades.approve'));
        $this->assertSame((int) $dean->id, GradeAuthority::deanFor($d, 'grades.signoff'));
    }

    public function testADepartmentWithNoDeanHasNone(): void
    {
        ['department' => $d] = DeanFactory::department('GA3');

        $this->assertNull(GradeAuthority::deanFor($d, 'grades.approve'));
    }

    public function testTheAdministratorsWhoHoldThePermission(): void
    {
        $i      = PeopleFactory::institution('GA4');
        $active = PeopleFactory::person('institutional_admin', 'Ada', 'One', institutionId: $i);
        $other  = PeopleFactory::person('institutional_admin', 'Bo', 'Two', institutionId: $i);
        $gone   = PeopleFactory::person('institutional_admin', 'Cy', 'Gone', institutionId: $i);
        (new AccountControl())->deactivate((int) $gone->id);

        $this->assertEqualsCanonicalizing([(int) $active->id, (int) $other->id], GradeAuthority::institutionAdmins($i, 'grades.approve'));
        $this->assertSame([(int) $other->id], GradeAuthority::institutionAdmins($i, 'grades.approve', [(int) $active->id]));
    }

    public function testApprovalIsUsedWhenTheInstitutionHasAnyDean(): void
    {
        ['institution' => $with, 'department' => $d] = DeanFactory::department('GA5');
        DeanFactory::dean($with, $d);
        ['institution' => $without] = DeanFactory::department('GA6');

        $this->assertTrue(GradeAuthority::usesApproval($with));
        $this->assertFalse(GradeAuthority::usesApproval($without));
    }

    public function testTheAdministratorHoldsBothByDefault(): void
    {
        $this->assertContains('grades.approve', AuthGroups::DEFAULT_MATRIX['institutional_admin']);
        $this->assertContains('grades.signoff', AuthGroups::DEFAULT_MATRIX['institutional_admin']);
    }
}
