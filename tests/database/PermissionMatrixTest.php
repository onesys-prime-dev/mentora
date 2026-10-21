<?php

declare(strict_types=1);

use App\Models\Users\RolePermissionModel;
use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\AuthGroups;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The role_permissions table and how customisations in it reach the matrix
 * Shield reads: the table itself, the resolver, and `$user->can()`.
 *
 * @internal
 */
final class PermissionMatrixTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private PermissionMatrix $matrix;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::flush();
        $this->matrix = new PermissionMatrix();
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function override(string $role, string $permission, bool $granted): void
    {
        (new RolePermissionModel())->insert(['role' => $role, 'permission' => $permission, 'granted' => $granted ? 1 : 0]);

        PermissionMatrix::flush();
    }

    /*
    |--------------------------------------------------------------------------
    | The table
    |--------------------------------------------------------------------------
    */

    public function testTheTableExistsWithTheExpectedColumns(): void
    {
        $this->assertTrue(db_connect()->tableExists('role_permissions'));

        $columns = db_connect()->getFieldNames('role_permissions');

        foreach (['id', 'role', 'permission', 'granted', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columns);
        }
    }

    public function testARolePermissionPairCanBeStoredOnlyOnce(): void
    {
        $this->override('student', 'grading.manage', true);

        try {
            $stored = (new RolePermissionModel())->insert(['role' => 'student', 'permission' => 'grading.manage', 'granted' => 0]);
        } catch (DatabaseException) {
            $stored = false;
        }

        $this->assertFalse($stored, 'the second row for the same role and permission is refused');
        $this->assertSame(1, db_connect()->table('role_permissions')->countAllResults());
        $this->assertSame(1, (int) db_connect()->table('role_permissions')->get()->getRowArray()['granted'], 'the first row is untouched');
    }

    public function testTheSamePermissionCanBeStoredForDifferentRoles(): void
    {
        $this->override('student', 'grading.manage', true);
        $this->override('parent', 'grading.manage', true);

        $this->assertSame(2, db_connect()->table('role_permissions')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | The resolver
    |--------------------------------------------------------------------------
    */

    public function testWithNothingStoredTheEffectiveMatrixIsTheDefault(): void
    {
        $this->assertSame(AuthGroups::DEFAULT_MATRIX, $this->matrix->effective());
        $this->assertSame(AuthGroups::DEFAULT_MATRIX, $this->matrix->defaults());
        $this->assertSame([], PermissionMatrix::storedOverrides());
    }

    public function testAStoredGrantShowsInTheEffectiveMatrixButNotTheDefaults(): void
    {
        $this->override('student', 'announcements.manage', true);

        $this->assertContains('announcements.manage', $this->matrix->effective()['student']);
        $this->assertNotContains('announcements.manage', $this->matrix->defaults()['student']);
        $this->assertTrue($this->matrix->has('student', 'announcements.manage'));
    }

    public function testAStoredDenialRemovesThePermissionFromTheEffectiveMatrix(): void
    {
        $this->override('instructor', 'grading.manage', false);

        $this->assertNotContains('grading.manage', $this->matrix->effective()['instructor']);
        $this->assertFalse($this->matrix->has('instructor', 'grading.manage'));
        $this->assertTrue($this->matrix->has('chairperson', 'grading.manage'), 'other roles are unaffected');
    }

    public function testDeletingTheRowBringsTheDefaultBack(): void
    {
        $this->override('instructor', 'grading.manage', false);
        db_connect()->table('role_permissions')->emptyTable();
        PermissionMatrix::flush();

        $this->assertTrue($this->matrix->has('instructor', 'grading.manage'));
    }

    public function testTheAdminRoleIgnoresStoredRows(): void
    {
        $this->override('admin', 'roles.manage', false);
        $this->override('admin', 'courses.manage', true);

        $this->assertSame(AuthGroups::DEFAULT_MATRIX['admin'], $this->matrix->effective()['admin']);
    }

    public function testRowsForUnknownRolesOrPermissionsAreIgnored(): void
    {
        $this->override('wizard', 'courses.view', true);
        $this->override('student', 'rockets.launch', true);

        $this->assertSame(AuthGroups::DEFAULT_MATRIX, $this->matrix->effective());
    }

    public function testARoleCannotBeEmptiedByStoredRows(): void
    {
        $this->override('student', 'courses.view', false);

        $this->assertSame(['courses.view'], $this->matrix->effective()['student']);
    }

    public function testAParentCanOnlyViewTheirChildren(): void
    {
        $this->assertSame(['children.view'], AuthGroups::DEFAULT_MATRIX['parent']);
        $this->assertArrayHasKey('children.view', $this->matrix->permissions());
    }

    public function testRolesAndPermissionsAreListedFromTheConfig(): void
    {
        $this->assertEqualsCanonicalizing(['student', 'instructor', 'chairperson', 'dean', 'parent', 'institutional_admin', 'admin'], $this->matrix->roles());
        $this->assertArrayHasKey('courses.view', $this->matrix->permissions());
    }

    /*
    |--------------------------------------------------------------------------
    | What Shield reads
    |--------------------------------------------------------------------------
    */

    public function testAUserCanDoWhatTheirRoleCanByDefault(): void
    {
        $instructor = CourseFactory::user('instructor');
        $student    = CourseFactory::user('student');
        $admin      = CourseFactory::user('admin');

        $this->assertTrue($instructor->can('grading.manage'));
        $this->assertFalse($student->can('grading.manage'));
        $this->assertTrue($admin->can('roles.manage'));
        $this->assertFalse($admin->can('grading.manage'), 'a system administrator has no direct academic access');
        $this->assertFalse($instructor->can('roles.manage'));
    }

    public function testARevokedPermissionStopsTheUserCanCheckOnTheNextLookup(): void
    {
        $instructor = CourseFactory::user('instructor');
        $this->assertTrue($instructor->can('grading.manage'));

        $this->override('instructor', 'grading.manage', false);

        $fresh = CourseFactory::user('instructor');

        $this->assertFalse($fresh->can('grading.manage'));
        $this->assertTrue($fresh->can('courses.manage'), 'only the revoked permission is lost');
    }

    public function testAGrantedPermissionStartsWorking(): void
    {
        $this->override('student', 'announcements.manage', true);

        $this->assertTrue(CourseFactory::user('student')->can('announcements.manage'));
    }

    public function testAUserWithSeveralRolesGetsWhatEitherAllows(): void
    {
        $user = CourseFactory::user('instructor');
        $user->addGroup('chairperson');
        $this->override('instructor', 'program.monitor', false);

        $user = model(CodeIgniter\Shield\Models\UserModel::class)->findById($user->id);

        $this->assertTrue($user->can('program.monitor'), 'the chairperson role still allows it');
    }
}
