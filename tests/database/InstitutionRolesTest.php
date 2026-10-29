<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use App\Services\Auth\RolePermissions;
use App\Services\Institutions\InstitutionRoles;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The institution's own settings for its roles' academic permissions (audit
 * decisions U1a–U1d).
 *
 * @internal
 */
final class InstitutionRolesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->institution = PeopleFactory::institution('ROLES');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', institutionId: $this->institution);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function rows(): int
    {
        return db_connect()->table('role_permissions')->where('institution_id', $this->institution)->countAllResults();
    }

    public function testAnInstitutionCanClearAnInstructorPermission(): void
    {
        $this->assertTrue((new InstitutionRoles())->set($this->institution, 'instructor', 'grading.manage', false, $this->admin));

        $this->assertSame(1, $this->rows());
        $this->assertFalse((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $this->institution), 'grading.manage'));
    }

    public function testABoxWhoseRowIsAlreadyThereIsUpdatedInPlace(): void
    {
        // The row another request has just written for the same box (the race the single statement closes).
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 1]);

        $this->assertTrue((new InstitutionRoles())->set($this->institution, 'instructor', 'grading.manage', false, $this->admin));

        $rows = db_connect()->table('role_permissions')->where(['institution_id' => $this->institution, 'role' => 'instructor', 'permission' => 'grading.manage'])->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]['granted']);
    }

    public function testSettingThePlatformValueBackRemovesTheRow(): void
    {
        $roles = new InstitutionRoles();
        $roles->set($this->institution, 'instructor', 'grading.manage', false, $this->admin);
        $roles->set($this->institution, 'instructor', 'grading.manage', true, $this->admin);

        $this->assertSame(0, $this->rows());
    }

    public function testTheAdministratorRolesAreNotTheInstitutions(): void
    {
        foreach (['institutional_admin', 'admin'] as $role) {
            try {
                (new InstitutionRoles())->set($this->institution, $role, 'courses.view', false, $this->admin);
                $this->fail("{$role} must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('That role is set by the platform.', $e->getMessage());
            }
        }
    }

    public function testOnlyAcademicPermissionsAreOffered(): void
    {
        $this->expectExceptionMessage('That permission is not one an institution sets.');

        (new InstitutionRoles())->set($this->institution, 'dean', 'people.manage', true, $this->admin);
    }

    public function testAPermissionThatDoesNothingForTheRoleIsRefused(): void
    {
        $this->expectExceptionMessage('That permission does nothing for that role.');

        (new InstitutionRoles())->set($this->institution, 'student', 'grading.manage', true, $this->admin);
    }

    public function testARoleKeepsAtLeastOnePermission(): void
    {
        $this->expectExceptionMessage('A role must keep at least one permission.');

        (new InstitutionRoles())->set($this->institution, 'student', 'courses.view', false, $this->admin);
    }

    public function testTheChangeIsAuditedWithTheInstitution(): void
    {
        (new InstitutionRoles())->set($this->institution, 'instructor', 'grading.manage', false, $this->admin);

        $row = db_connect()->table('audit_logs')->where('action', 'roles.permission_changed')->get()->getRowArray();

        $this->assertSame($this->institution, (int) $row['institution_id']);
        $this->assertSame('instructor', $row['target_id']);
    }

    public function testAskingForWhatIsAlreadySoDoesNothing(): void
    {
        $this->assertFalse((new InstitutionRoles())->set($this->institution, 'instructor', 'grading.manage', true, $this->admin));
        $this->assertSame(0, $this->rows());
    }
}
