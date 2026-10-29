<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use App\Services\Auth\RolePermissions;
use CodeIgniter\Config\Services;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * What a person may do: their roles, in their institution's settings on top
 * of the platform's (audit decisions U1a, U1b).
 *
 * @internal
 */
final class RolePermissionsTest extends DatabaseTestCase
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

    public function testAnInstitutionsChangeAppliesToItsOwnPeopleOnly(): void
    {
        $a = PeopleFactory::institution('PERMA');
        $b = PeopleFactory::institution('PERMB');
        db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);

        $this->assertFalse((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $a), 'grading.manage'));
        $this->assertTrue((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $b), 'grading.manage'));
    }

    public function testAnUntouchedBoxFollowsALaterPlatformChange(): void
    {
        $teacher = PeopleFactory::person('instructor', institutionId: PeopleFactory::institution('PERMC'));

        (new PermissionMatrix())->set('instructor', 'announcements.manage', false, CourseFactory::user('admin'));

        $this->assertFalse((new RolePermissions())->allows($teacher, 'announcements.manage'));
    }

    public function testABoxTheInstitutionSetKeepsItsValueWhenTheDefaultChanges(): void
    {
        $a = PeopleFactory::institution('PERMD');
        db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'instructor', 'permission' => 'announcements.manage', 'granted' => 1]);

        (new PermissionMatrix())->set('instructor', 'announcements.manage', false, CourseFactory::user('admin'));

        $this->assertTrue((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $a), 'announcements.manage'));
    }

    public function testSomeoneWithNoInstitutionUsesThePlatformMatrix(): void
    {
        $this->assertTrue((new RolePermissions())->allows(CourseFactory::user('admin'), 'roles.manage'));
        $this->assertFalse((new RolePermissions())->allows(CourseFactory::user('admin'), 'grading.manage'));
    }

    public function testAskedForOneRoleOnlyThatRoleCounts(): void
    {
        $a = PeopleFactory::institution('PERMF');
        db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'chairperson', 'permission' => 'courses.manage', 'granted' => 0]);
        $both = PeopleFactory::person('chairperson', institutionId: $a);
        $both->addGroup('instructor');

        $this->assertFalse((new RolePermissions())->allows($both, 'courses.manage', 'chairperson'));
        $this->assertTrue((new RolePermissions())->allows($both, 'courses.manage', 'instructor'));
        $this->assertFalse((new RolePermissions())->allows($both, 'courses.manage', 'dean'), 'a role they do not hold counts for nothing');
    }

    public function testAPlatformChangeThatEmptiesAnInstitutionsRoleDoesNotBringItsPermissionsBack(): void
    {
        $a = PeopleFactory::institution('PERMG');

        foreach (['courses.view', 'department.monitor', 'grades.approve', 'grades.signoff', 'department.announce'] as $permission) {
            db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'dean', 'permission' => $permission, 'granted' => 0]);
        }

        (new PermissionMatrix())->set('dean', 'followups.manage', false, CourseFactory::user('admin'));

        $dean = PeopleFactory::person('dean', institutionId: $a);

        $this->assertFalse((new RolePermissions())->allows($dean, 'grades.approve'));
        $this->assertSame([], (new RolePermissions())->matrixFor($a)['dean']);
    }

    public function testARoleAnInstitutionsRowsEmptyStaysEmpty(): void
    {
        // The institution page refuses this (a role keeps one permission); a row written by hand fails closed.
        $a = PeopleFactory::institution('PERME');
        db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'student', 'permission' => 'courses.view', 'granted' => 0]);

        $this->assertFalse((new RolePermissions())->allows(PeopleFactory::person('student', institutionId: $a), 'courses.view'));
    }
}
