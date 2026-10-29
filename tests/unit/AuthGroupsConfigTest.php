<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use Config\AuthGroups;

/**
 * The shipped roles, permissions and default matrix agree with each other,
 * and say what the requirements say.
 *
 * @internal
 */
final class AuthGroupsConfigTest extends CIUnitTestCase
{
    private const PLATFORM_AREAS = ['institutions', 'subscriptions', 'users', 'roles', 'flags', 'settings', 'audit', 'monitoring', 'backup', 'integrations'];

    /** Permissions that belong inside an institution (the system administrator never holds them). */
    private const ACADEMIC = [
        'courses.view', 'courses.manage', 'grading.manage', 'announcements.manage', 'program.monitor',
        'department.monitor', 'grades.approve', 'grades.signoff', 'followups.manage', 'department.announce',
        'children.view',
        // The institutional administrator's own administration.
        'structure.manage', 'terms.manage', 'offerings.manage', 'enrolments.manage', 'people.view', 'people.manage',
        'reports.view', 'institution.settings', 'institution.audit', 'institution.impersonate', 'sis.run',
    ];

    private AuthGroups $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new AuthGroups();
    }

    public function testEveryRoleHasADefaultRowAndEveryRowIsARole(): void
    {
        $this->assertEqualsCanonicalizing(array_keys($this->config->groups), array_keys(AuthGroups::DEFAULT_MATRIX));
    }

    public function testNoRoleIsLeftWithoutPermissions(): void
    {
        foreach (AuthGroups::DEFAULT_MATRIX as $role => $permissions) {
            $this->assertNotSame([], $permissions, "{$role} has no default permission");
        }
    }

    public function testEveryDefaultPermissionIsDeclaredAndHasADescription(): void
    {
        foreach (AuthGroups::DEFAULT_MATRIX as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertArrayHasKey($permission, $this->config->permissions, "{$role} is given undeclared {$permission}");
            }
        }

        foreach ($this->config->permissions as $permission => $description) {
            $this->assertNotSame('', trim($description), "{$permission} has no description");
        }
    }

    public function testNoDefaultUsesAWildcardSoTheMatrixCanBeShownAndEditedPermissionByPermission(): void
    {
        foreach (AuthGroups::DEFAULT_MATRIX as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertStringNotContainsString('*', $permission, "{$role} uses a wildcard");
            }
        }
    }

    public function testNoDeclaredPermissionIsUnused(): void
    {
        $used = array_unique(array_merge(...array_values(AuthGroups::DEFAULT_MATRIX)));

        foreach (array_keys($this->config->permissions) as $permission) {
            $this->assertContains($permission, $used, "{$permission} is declared but no role has it");
        }
    }

    public function testPermissionNamesAreLowerCaseDottedPairs(): void
    {
        foreach (array_keys($this->config->permissions) as $permission) {
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $permission);
        }
    }

    public function testEveryPlatformAreaHasAViewAndAManagePermission(): void
    {
        foreach (self::PLATFORM_AREAS as $area) {
            $this->assertArrayHasKey("{$area}.view", $this->config->permissions);
            $this->assertArrayHasKey("{$area}.manage", $this->config->permissions);
        }

        $this->assertArrayHasKey('impersonation.start', $this->config->permissions);
    }

    public function testTheSystemAdministratorHasEveryPlatformPermission(): void
    {
        $admin = AuthGroups::DEFAULT_MATRIX['admin'];

        foreach (self::PLATFORM_AREAS as $area) {
            $this->assertContains("{$area}.view", $admin);
            $this->assertContains("{$area}.manage", $admin);
        }

        $this->assertContains('impersonation.start', $admin);
    }

    public function testTheSystemAdministratorHasNoDirectAccessToAcademicWork(): void
    {
        $this->assertSame([], array_intersect(self::ACADEMIC, AuthGroups::DEFAULT_MATRIX['admin']));
    }

    public function testNoOtherRoleHasAnyPlatformPermission(): void
    {
        $platform = array_diff(array_keys($this->config->permissions), self::ACADEMIC);

        foreach (AuthGroups::DEFAULT_MATRIX as $role => $permissions) {
            if ($role === 'admin') {
                continue;
            }

            $this->assertSame([], array_values(array_intersect($platform, $permissions)), "{$role} must not hold a platform permission");
        }
    }

    public function testAChairpersonCanDoEverythingAnInstructorCanAndMonitorTheProgram(): void
    {
        $chairperson = AuthGroups::DEFAULT_MATRIX['chairperson'];

        foreach (AuthGroups::DEFAULT_MATRIX['instructor'] as $permission) {
            $this->assertContains($permission, $chairperson);
        }

        $this->assertContains('program.monitor', $chairperson);
        $this->assertNotContains('program.monitor', AuthGroups::DEFAULT_MATRIX['instructor']);
    }

    public function testStudentsOnlyViewCourses(): void
    {
        $this->assertSame(['courses.view'], AuthGroups::DEFAULT_MATRIX['student']);
    }

    public function testTheOldShieldSampleRolesAreGone(): void
    {
        foreach (['superadmin', 'developer', 'beta', 'user'] as $sample) {
            $this->assertArrayNotHasKey($sample, AuthGroups::DEFAULT_MATRIX);
        }

        $this->assertArrayNotHasKey('admin.access', $this->config->permissions);
        $this->assertArrayNotHasKey('beta.access', $this->config->permissions);
    }
}
