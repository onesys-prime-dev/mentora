<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * How customisations are laid over the default role/permission matrix, and
 * the rules that protect it: the admin role is locked, junk rows are ignored,
 * and a role is never left with nothing.
 *
 * @internal
 */
final class PermissionMatrixApplyTest extends CIUnitTestCase
{
    /** Every role offered every permission, so these tests see apply()'s own rules. */
    private const ANY = [
        'admin'      => ['users.manage', 'roles.manage', 'courses.view', 'courses.manage', 'grading.manage', 'program.monitor'],
        'instructor' => ['users.manage', 'roles.manage', 'courses.view', 'courses.manage', 'grading.manage', 'program.monitor'],
        'student'    => ['users.manage', 'roles.manage', 'courses.view', 'courses.manage', 'grading.manage', 'program.monitor'],
    ];

    /**
     * @param list<array{role: string, permission: string, granted: int|string|bool}> $overrides
     * @param list<string>|null                                                    $known
     *
     * @return array<string, list<string>>
     */
    private static function open(array $overrides, ?array $known = null): array
    {
        return PermissionMatrix::apply(self::DEFAULTS, $overrides, $known, self::ANY);
    }

    public function testAPermissionTheRoleIsNotOfferedIsIgnored(): void
    {
        $matrix = PermissionMatrix::apply(self::DEFAULTS, [self::row('student', 'grading.manage', 1)], null, ['student' => ['courses.view']]);

        $this->assertSame(['courses.view'], $matrix['student']);
    }

    private const DEFAULTS = [
        'admin'      => ['users.manage', 'roles.manage'],
        'instructor' => ['courses.view', 'courses.manage', 'grading.manage'],
        'student'    => ['courses.view'],
    ];

    /** @return array{role: string, permission: string, granted: int|string|bool} */
    private static function row(string $role, string $permission, int|string|bool $granted): array
    {
        return ['role' => $role, 'permission' => $permission, 'granted' => $granted];
    }

    public function testNoCustomisationsGivesTheDefaults(): void
    {
        $this->assertSame(self::DEFAULTS, self::open([]));
    }

    public function testAGrantAddsAPermissionToThatRoleOnly(): void
    {
        $matrix = self::open([self::row('student', 'grading.manage', 1)]);

        $this->assertSame(['courses.view', 'grading.manage'], $matrix['student']);
        $this->assertSame(self::DEFAULTS['instructor'], $matrix['instructor']);
    }

    public function testADenialRemovesAPermissionFromThatRoleOnly(): void
    {
        $matrix = self::open([self::row('instructor', 'grading.manage', 0)]);

        $this->assertSame(['courses.view', 'courses.manage'], $matrix['instructor']);
        $this->assertSame(['courses.view'], $matrix['student']);
    }

    public function testGrantedMayBeAnyOfTheFormsADatabaseReturns(): void
    {
        $matrix = self::open([
            self::row('student', 'courses.manage', '1'),
            self::row('instructor', 'courses.manage', '0'),
            self::row('instructor', 'grading.manage', false),
        ]);

        $this->assertSame(['courses.view', 'courses.manage'], $matrix['student']);
        $this->assertSame(['courses.view'], $matrix['instructor']);
    }

    public function testGrantingWhatARoleAlreadyHasChangesNothing(): void
    {
        $matrix = self::open([self::row('instructor', 'courses.view', 1)]);

        $this->assertSame(self::DEFAULTS['instructor'], $matrix['instructor']);
    }

    public function testDenyingWhatARoleNeverHadChangesNothing(): void
    {
        $matrix = self::open([self::row('student', 'grading.manage', 0)]);

        $this->assertSame(self::DEFAULTS['student'], $matrix['student']);
    }

    public function testTheLaterRowWinsWhenTwoDisagree(): void
    {
        $matrix = self::open([
            self::row('student', 'grading.manage', 1),
            self::row('student', 'grading.manage', 0),
        ]);

        $this->assertSame(['courses.view'], $matrix['student']);
    }

    public function testPermissionNamesAreCaseInsensitive(): void
    {
        $matrix = self::open([self::row('instructor', 'Grading.Manage', 0)]);

        $this->assertSame(['courses.view', 'courses.manage'], $matrix['instructor']);
    }

    public function testTheListStaysAListWithNoGaps(): void
    {
        $matrix = self::open([self::row('instructor', 'courses.view', 0)]);

        $this->assertSame([0, 1], array_keys($matrix['instructor']));
    }

    /*
    |--------------------------------------------------------------------------
    | The locked admin role
    |--------------------------------------------------------------------------
    */

    public function testTheAdminRoleCannotLoseAPermission(): void
    {
        $matrix = self::open([self::row('admin', 'roles.manage', 0)]);

        $this->assertSame(self::DEFAULTS['admin'], $matrix['admin']);
    }

    public function testTheAdminRoleCannotGainAPermission(): void
    {
        $matrix = self::open([self::row('admin', 'courses.manage', 1)]);

        $this->assertSame(self::DEFAULTS['admin'], $matrix['admin']);
    }

    /*
    |--------------------------------------------------------------------------
    | Junk rows
    |--------------------------------------------------------------------------
    */

    public function testARowForAnUnknownRoleIsIgnored(): void
    {
        $matrix = self::open([self::row('wizard', 'courses.view', 1)]);

        $this->assertSame(self::DEFAULTS, $matrix);
    }

    public function testARowForAnUnknownPermissionIsIgnored(): void
    {
        $matrix = self::open([self::row('student', 'rockets.launch', 1)]);

        $this->assertSame(self::DEFAULTS, $matrix);
    }

    public function testAPermissionNobodyHasByDefaultCanBeGrantedWhenItIsInTheKnownList(): void
    {
        $known = ['users.manage', 'roles.manage', 'courses.view', 'courses.manage', 'grading.manage', 'program.monitor'];

        $matrix = self::open([self::row('student', 'program.monitor', 1)], $known);

        $this->assertSame(['courses.view', 'program.monitor'], $matrix['student']);
    }

    public function testAPermissionOutsideTheKnownListIsIgnoredEvenIfSomeoneHasIt(): void
    {
        $matrix = self::open([self::row('student', 'grading.manage', 1)], ['courses.view']);

        $this->assertSame(['courses.view'], $matrix['student']);
    }

    /*
    |--------------------------------------------------------------------------
    | Never leave a role with nothing
    |--------------------------------------------------------------------------
    */

    public function testDenyingARolesLastPermissionKeepsItsDefaults(): void
    {
        $matrix = self::open([self::row('student', 'courses.view', 0)]);

        $this->assertSame(['courses.view'], $matrix['student']);
    }

    public function testDenyingEverythingARoleHasRestoresItsDefaultsNotJustOnePermission(): void
    {
        $matrix = self::open([
            self::row('instructor', 'courses.view', 0),
            self::row('instructor', 'courses.manage', 0),
            self::row('instructor', 'grading.manage', 0),
        ]);

        $this->assertSame(self::DEFAULTS['instructor'], $matrix['instructor']);
    }

    public function testSwappingAllPermissionsForOthersIsAllowed(): void
    {
        $matrix = self::open([
            self::row('student', 'grading.manage', 1),
            self::row('student', 'courses.view', 0),
        ], ['courses.view', 'grading.manage', 'users.manage', 'roles.manage', 'courses.manage']);

        $this->assertSame(['grading.manage'], $matrix['student']);
    }
}
