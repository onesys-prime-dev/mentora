<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The roles and permissions page as a system administrator uses it: the
 * matrix is shown, a cell can be flipped once the password is confirmed, the
 * locks hold, and nobody else gets in.
 *
 * @internal
 */
final class AdminRolesPageTest extends RouteTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::flush();
        Services::resetSingle('auditLogger');

        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        // Signing in wrote an entry of its own; start from an empty log.
        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    /** @return array<string, string> */
    private function cell(string $role, string $permission, string $granted): array
    {
        return ['role' => $role, 'permission' => $permission, 'granted' => $granted];
    }

    private function flip(string $role, string $permission, string $granted): \CodeIgniter\Test\TestResponse
    {
        return $this->step('POST', 'admin/roles/permissions', $this->cell($role, $permission, $granted));
    }

    private function body(string $uri = 'admin/roles'): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    private function overrideCount(): int
    {
        return db_connect()->table('role_permissions')->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | Who may use it
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrChangeIt(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', 'admin/roles')->response()->getStatusCode());
        $this->flip('institutional_admin', 'grades.approve', '1');

        $this->assertSame(0, $this->overrideCount());
    }

    /*
    |--------------------------------------------------------------------------
    | The page
    |--------------------------------------------------------------------------
    */

    public function testTheMatrixListsEveryRoleAndPermission(): void
    {
        $body = $this->body();

        foreach (['Student', 'Instructor', 'Chairperson', 'Parent', 'Institutional Admin', 'Admin'] as $title) {
            $this->assertStringContainsString($title, $body);
        }

        foreach (['institutions.manage', 'roles.manage', 'impersonation.start', 'courses.view', 'grading.manage', 'program.monitor'] as $permission) {
            $this->assertStringContainsString($permission, $body);
        }

        $this->assertStringContainsString('Platform', $body);
        $this->assertStringContainsString('Inside an institution', $body);
    }

    public function testTheSystemAdministratorColumnIsLockedAndHasNoControls(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('locked', $body);
        $this->assertStringNotContainsString('value="admin"', $body, 'no cell for the admin role can be submitted');
    }

    public function testCellsOfOtherRolesAreControls(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('aria-label="Revoke grading.manage for Instructor"', $body);
        $this->assertStringContainsString('aria-label="Revoke discussions.manage for Instructor"', $body);
    }

    public function testACellThatDoesNothingForTheRoleIsNotAControl(): void
    {
        $body = $this->body();

        $this->assertStringNotContainsString('aria-label="Grant grading.manage for Student"', $body);
        $this->assertStringContainsString('title="Not used by this role"', $body);
    }

    public function testAnEditedCellIsMarked(): void
    {
        $this->assertStringNotContainsString('text-[10px] font-semibold text-amber-700">Edited</span>', $this->body());

        $this->confirmedPassword();
        $this->flip('institutional_admin', 'grades.approve', '1');

        $this->assertStringContainsString('text-[10px] font-semibold text-amber-700">Edited</span>', $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | Changing a cell
    |--------------------------------------------------------------------------
    */

    public function testChangingACellNeedsThePasswordFirst(): void
    {
        $response = $this->flip('institutional_admin', 'grades.approve', '1')->response();

        $this->assertStringEndsWith('admin/reauth', $response->getHeaderLine('Location'));
        $this->assertSame(0, $this->overrideCount(), 'nothing changed');
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'roles.permission_changed')->countAllResults());
    }

    public function testOnceConfirmedACellCanBeGrantedAndRevoked(): void
    {
        $this->confirmedPassword();

        $location = $this->flip('institutional_admin', 'grades.approve', '1')->response()->getHeaderLine('Location');
        $this->assertStringEndsWith('admin/roles', $location);
        $this->assertTrue((new PermissionMatrix())->has('institutional_admin', 'grades.approve'));
        $this->assertStringContainsString('Granted grades.approve for institutional_admin', $this->body());

        $this->flip('instructor', 'grading.manage', '0');
        $this->assertFalse((new PermissionMatrix())->has('instructor', 'grading.manage'));
        $this->assertStringContainsString('Revoked grading.manage for instructor', $this->body());
    }

    public function testTheChangeShowsInThePageAfterwards(): void
    {
        $this->confirmedPassword();
        $this->flip('instructor', 'grading.manage', '0');

        $this->assertStringContainsString('aria-label="Grant grading.manage for Instructor"', $this->body());
    }

    public function testTheChangeIsAudited(): void
    {
        $this->confirmedPassword();
        $this->flip('instructor', 'grading.manage', '0');

        $entry = db_connect()->table('audit_logs')->where('action', 'roles.permission_changed')->get()->getRowArray();

        $this->assertSame((string) $this->admin->id, (string) $entry['actor_user_id']);
        $this->assertSame('instructor', $entry['target_id']);
        $this->assertSame(['grading.manage' => false], json_decode($entry['new_values'], true));
    }

    public function testTheChangeTakesEffectOnTheNextRequestForThatRole(): void
    {
        $this->confirmedPassword();
        $this->flip('institutional_admin', 'grades.approve', '1');

        PermissionMatrix::flush();

        $this->assertTrue(CourseFactory::user('institutional_admin')->can('grades.approve'));
    }

    public function testRepeatingAChangeSaysNothingChanged(): void
    {
        $this->confirmedPassword();
        $this->flip('instructor', 'courses.view', '1');

        $this->assertStringContainsString('No change', $this->body());
        $this->assertSame(0, $this->overrideCount());
    }

    /*
    |--------------------------------------------------------------------------
    | The locks
    |--------------------------------------------------------------------------
    */

    public function testTheSystemAdministratorRoleCannotBeChangedEvenByForgingTheRequest(): void
    {
        $this->confirmedPassword();

        $this->flip('admin', 'roles.manage', '0');
        $this->assertStringContainsString('locked', $this->body());

        $this->flip('admin', 'courses.manage', '1');

        $this->assertSame(0, $this->overrideCount());
        $this->assertTrue((new PermissionMatrix())->has('admin', 'roles.manage'));
        $this->assertFalse((new PermissionMatrix())->has('admin', 'courses.manage'));
    }

    public function testARoleCannotBeLeftWithNothing(): void
    {
        $this->confirmedPassword();

        $this->flip('student', 'courses.view', '0');

        $this->assertStringContainsString('at least one permission', $this->body());
        $this->assertSame(0, $this->overrideCount());
    }

    public function testBadInputIsRefusedPolitely(): void
    {
        $this->confirmedPassword();

        foreach ([
            $this->cell('student', 'rockets.launch', '1'),
            $this->cell('wizard', 'courses.view', '1'),
            $this->cell('student', 'courses.manage', 'maybe'),
            $this->cell('student', 'courses.manage', ''),
            ['role' => 'student'],
            [],
        ] as $input) {
            $response = $this->step('POST', 'admin/roles/permissions', $input)->response();

            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringEndsWith('admin/roles', $response->getHeaderLine('Location'));
        }

        $this->assertSame(0, $this->overrideCount());
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'roles.permission_changed')->countAllResults());
    }

    public function testHostileValuesAreEscapedWhenEchoedBack(): void
    {
        $this->confirmedPassword();

        $this->flip('student', '<script>alert(1)</script>', '1');
        $body = $this->body();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }
}
