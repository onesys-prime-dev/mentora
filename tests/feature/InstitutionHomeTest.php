<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The institutional administrator's area: who can enter, what the home page
 * shows, what the Apps menu offers them, and that everything that writes is
 * behind the read-only filter (the data export alone is not).
 *
 * @internal
 */
final class InstitutionHomeTest extends RouteTestCase
{
    private int $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('HOMEIA');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@home.example', $this->institution);

        $this->signIn($this->admin);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function body(string $uri = 'institution/dashboard'): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleReachesTheHome(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@home.example", $role === 'admin' ? null : $this->institution));

        foreach (['institution', 'institution/dashboard'] as $path) {
            $response = $this->step('GET', $path)->response();

            $this->assertSame(302, $response->getStatusCode(), "{$role} must not reach {$path}");
            $this->assertStringNotContainsString('institution', $response->getHeaderLine('Location'));
        }
    }

    public function testBothAddressesShowTheHome(): void
    {
        $this->assertStringContainsString('HOMEIA College', $this->body('institution'));
        $this->assertStringContainsString('HOMEIA College', $this->body('institution/dashboard'));
    }

    public function testTheHomeShowsTheInstitutionItsSnapshotAndItsUsage(): void
    {
        PeopleFactory::person('student', 'Stu', 'One', null, $this->institution);
        PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        SisFixture::course($this->institution);

        $body = $this->body();

        $this->assertStringContainsString('HOMEIA College', $body);
        $this->assertStringContainsString('data-section="snapshot"', $body);
        $this->assertStringContainsString('data-section="plan"', $body);
        $this->assertMatchesRegularExpression('/data-role-count="student">1</', $body);
        $this->assertMatchesRegularExpression('/data-role-count="instructor">1</', $body);
        $this->assertStringContainsString('First Term', $body);
        $this->assertStringContainsString('Student seats', $body);
        $this->assertStringContainsString('No plan yet', $body);
    }

    public function testAPlanAndItsLimitsAreShown(): void
    {
        PeopleFactory::subscribe($this->institution, PeopleFactory::plan('Gold', 100));

        $body = $this->body();

        $this->assertStringContainsString('Gold', $body);
        $this->assertStringContainsString('of 100', $body);
    }

    public function testTheOnlyOtherPeopleSeenAreThisInstitutions(): void
    {
        $other = PeopleFactory::institution('HOMEOTHER');
        PeopleFactory::person('student', 'Not', 'Mine', null, $other);

        $this->assertMatchesRegularExpression('/data-role-count="student">0</', $this->body());
    }

    #[DataProvider('provideStatuses')]
    public function testTheHomeSaysWhereTheInstitutionStands(string $status, string $badge, ?string $message): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => $status]);

        $body = $this->body();

        $this->assertStringContainsString($badge, $body);

        if ($message !== null) {
            $this->assertStringContainsString($message, $body);
        }
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function provideStatuses(): iterable
    {
        yield 'active' => ['active', 'Active', null];
        yield 'read-only' => ['read_only', 'Read-only', 'The subscription has ended'];
        yield 'past' => ['past', 'Past', 'This institution is past'];
    }

    public function testTheHomeStillOpensWhileTheInstitutionIsReadOnly(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(200, $this->step('GET', 'institution/dashboard')->response()->getStatusCode());
    }

    public function testAnAdministratorWithNoInstitutionSeesAnExplanation(): void
    {
        $this->signIn(PeopleFactory::person('institutional_admin', 'No', 'Home', 'nohome@home.example'));

        $body = $this->body();

        $this->assertStringContainsString('data-no-institution', $body);
        $this->assertStringContainsString('not linked to an institution', $body);
    }

    public function testTheAppsMenuOffersTheirToolsNotTheTeachersOnes(): void
    {
        $body = $this->body();

        foreach (['dashboard', 'institution'] as $live) {
            $this->assertMatchesRegularExpression('/data-app-tile="' . $live . '"/', $body);
        }

        $this->assertStringNotContainsString('data-app-tile="calendar"', $body);
        $this->assertStringNotContainsString('data-app-tile="schedule"', $body);
        $this->assertStringContainsString('institution/export', $body);
    }

    public function testTheLogoAndThePageTitleAreRight(): void
    {
        $export = $this->body('institution/export');

        $this->assertMatchesRegularExpression('/<a\s+class="flex gap-1 items-center self-center"\s+href="[^"]*institution\/dashboard"/', $export);
        $this->assertStringContainsString('Data export', $export);
    }

    public function testTheDefaultAdministratorHoldsTheInstitutionPermissionsAndNobodyElseDoes(): void
    {
        $permissions = ['structure.manage', 'terms.manage', 'offerings.manage', 'enrolments.manage', 'people.view', 'people.manage', 'reports.view', 'institution.settings', 'institution.audit', 'institution.impersonate', 'sis.run'];

        foreach ($permissions as $permission) {
            $this->assertContains($permission, \Config\AuthGroups::DEFAULT_MATRIX['institutional_admin'], $permission);

            foreach (['admin', 'chairperson', 'instructor', 'student', 'parent'] as $role) {
                $this->assertNotContains($permission, \Config\AuthGroups::DEFAULT_MATRIX[$role], "{$role} must not hold {$permission}");
            }
        }
    }

    public function testEveryRouteThatWritesIsBehindTheReadOnlyFilterExceptTheExport(): void
    {
        $routes  = service('routes')->loadRoutes();
        $options = $routes->getRoutesOptions();
        $checked = 0;

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                if (explode('/', $route)[0] !== 'institution' || str_starts_with($route, 'institution/export')) {
                    continue;
                }

                $filters = (array) ($routes->getRoutesOptions($route, $verb)['filter'] ?? []);

                $this->assertContains('readonly', $filters, "{$verb} {$route} writes, so it must be behind readonly");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(0, $checked);
        $this->assertNotEmpty($options);

        // The export itself must stay reachable once the subscription has ended.
        $exportFilters = (array) ($routes->getRoutesOptions('institution/export', 'post')['filter'] ?? []);
        $this->assertNotContains('readonly', $exportFilters);
    }
}
