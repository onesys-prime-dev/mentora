<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use Config\AuthGroups;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Each role is offered only the permissions that do something for it (audit
 * decision D2), and the permission list after finding 7.
 *
 * @internal
 */
final class ApplicablePermissionsTest extends DatabaseTestCase
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

    public function testARoleIsOfferedOnlyWhatDoesSomethingForIt(): void
    {
        $this->assertSame(['courses.view'], PermissionMatrix::applicable('student'));
        $this->assertSame(['children.view'], PermissionMatrix::applicable('parent'));
        $this->assertContains('discussions.manage', PermissionMatrix::applicable('instructor'));
        $this->assertNotContains('grades.approve', PermissionMatrix::applicable('instructor'));
        $this->assertContains('followups.manage', PermissionMatrix::applicable('chairperson'));
    }

    public function testGrantingAPermissionThatDoesNothingIsRefused(): void
    {
        $this->expectExceptionMessage('That permission does nothing for that role.');

        (new PermissionMatrix())->set('student', 'grading.manage', true, CourseFactory::user('admin'));
    }

    public function testAStoredRowThatDoesNothingIsIgnored(): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => 0, 'role' => 'student', 'permission' => 'grading.manage', 'granted' => 1]);
        PermissionMatrix::flush();

        $this->assertNotContains('grading.manage', (new PermissionMatrix())->effective()['student']);
    }

    public function testTheNewDefaults(): void
    {
        $m = AuthGroups::DEFAULT_MATRIX;

        $this->assertContains('discussions.manage', $m['instructor']);
        $this->assertContains('discussions.manage', $m['chairperson']);
        $this->assertContains('followups.manage', $m['chairperson']);
        $this->assertNotContains('program.monitor', $m['institutional_admin']);
        $this->assertContains('institution.roles', $m['institutional_admin']);
        $this->assertArrayNotHasKey('monitoring.manage', config(AuthGroups::class)->permissions);
        $this->assertNotContains('monitoring.manage', $m['admin']);
    }

    public function testEveryDefaultIsOneTheRoleIsOffered(): void
    {
        foreach (AuthGroups::DEFAULT_MATRIX as $role => $permissions) {
            $this->assertSame([], array_values(array_diff($permissions, PermissionMatrix::applicable($role))), $role);
        }
    }
}
