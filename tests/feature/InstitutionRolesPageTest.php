<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Admin\PermissionMatrix;
use App\Services\Auth\RolePermissions;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The institution admin's "Roles and permissions" page (audit decisions U1a–U1d).
 *
 * @internal
 */
final class InstitutionRolesPageTest extends RouteTestCase
{
    private int $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->institution = PeopleFactory::institution('IRPG');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', institutionId: $this->institution);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function asAdmin(bool $confirmed = false): void
    {
        $this->loggedInAs($this->admin);
        $this->withSession();

        if ($confirmed) {
            $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
            $this->withSession();
        }
    }

    public function testTheAdministratorSeesTheirFiveRolesAndTheAcademicPermissions(): void
    {
        $this->asAdmin();

        $body = (string) $this->step('GET', 'institution/roles')->getBody();

        foreach (['Dean', 'Chairperson', 'Instructor', 'Student', 'Parent'] as $role) {
            $this->assertMatchesRegularExpression('#>\s*' . $role . '\s*<#', $body);
        }

        $this->assertStringNotContainsString('value="institutional_admin"', $body);
        $this->assertStringNotContainsString('people.manage', $body);
        $this->assertStringContainsString('aria-label="Revoke grading.manage for Instructor"', $body);
        $this->assertStringContainsString('title="Not used by this role"', $body);
        $this->assertStringContainsString('A box you have not changed follows the platform', $body);
    }

    public function testAChangeNeedsThePasswordFirst(): void
    {
        $this->asAdmin();

        $response = $this->step('POST', 'institution/roles/permissions', ['role' => 'instructor', 'permission' => 'grading.manage', 'granted' => '0']);

        $this->assertStringContainsString('reauth', $response->response()->getHeaderLine('Location'));
        $this->assertSame(0, db_connect()->table('role_permissions')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testOnceConfirmedABoxChangesForThisInstitution(): void
    {
        $this->asAdmin(true);

        $location = $this->step('POST', 'institution/roles/permissions', ['role' => 'instructor', 'permission' => 'grading.manage', 'granted' => '0'])->response()->getHeaderLine('Location');

        $this->assertStringEndsWith('institution/roles', $location);
        $this->assertFalse((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $this->institution), 'grading.manage'));
        $this->assertStringContainsString('Revoked grading.manage for instructor', (string) $this->step('GET', 'institution/roles')->getBody());
    }

    public function testSomeoneWhoIsNotTheAdministratorCannotOpenIt(): void
    {
        $this->loggedInAs(PeopleFactory::person('instructor', institutionId: $this->institution));
        $this->withSession();

        $this->assertNotSame(200, $this->step('GET', 'institution/roles')->response()->getStatusCode());
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->asAdmin(true);

        $this->assertSame(200, $this->step('GET', 'institution/roles')->response()->getStatusCode());

        $this->step('POST', 'institution/roles/permissions', ['role' => 'instructor', 'permission' => 'grading.manage', 'granted' => '0']);

        $this->assertSame(0, db_connect()->table('role_permissions')->where('institution_id', $this->institution)->countAllResults());
    }
}
