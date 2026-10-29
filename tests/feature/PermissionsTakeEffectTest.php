<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Every permission on the Roles pages takes effect (audit decisions 7.1, 7.5–7.7).
 *
 * @internal
 */
final class PermissionsTakeEffectTest extends RouteTestCase
{
    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->institution = PeopleFactory::institution('TAKE');
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function clear(string $role, string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => $role, 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();
    }

    private function refused(User $user, string $verb, string $uri): bool
    {
        $this->loggedInAs($user);
        $this->withSession();

        try {
            $this->step($verb, $uri);
            $refused = session('error') === lang('Auth.notEnoughPrivilege');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $refused = false; // past the permission check, met the real rules
        }

        // Each check is a fresh visit by a freshly loaded user.
        auth()->logout();
        $this->withSession([]);

        return $refused;
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function provideGuardedRoutes(): iterable
    {
        yield 'program monitor'     => ['chairperson', 'program.monitor', 'GET', 'chairperson/monitor-program'];
        yield 'department monitor'  => ['dean', 'department.monitor', 'GET', 'dean/monitor-department'];
        yield 'chair follow-up'     => ['chairperson', 'followups.manage', 'POST', 'chairperson/follow-ups'];
        yield 'chair pass down'     => ['chairperson', 'followups.manage', 'POST', 'chairperson/follow-ups/1/pass-down'];
        yield 'dean follow-up'      => ['dean', 'followups.manage', 'POST', 'dean/follow-ups'];
        yield 'instructor courses'  => ['instructor', 'courses.view', 'GET', 'instructor/courses'];
        yield 'chairperson courses' => ['chairperson', 'courses.view', 'GET', 'chairperson/courses'];
        yield 'dean courses'        => ['dean', 'courses.view', 'GET', 'dean/courses'];
        yield 'dean approvals'      => ['dean', 'grades.approve', 'GET', 'dean/approvals'];
        yield 'dean sign-off'       => ['dean', 'grades.signoff', 'GET', 'dean/signoff'];
        yield 'dean announcements'  => ['dean', 'department.announce', 'GET', 'dean/announcements'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideDeanTiles(): iterable
    {
        yield 'Approvals'     => ['approvals', 'grades.approve'];
        yield 'Sign-off'      => ['signoff', 'grades.signoff'];
        yield 'Announcements' => ['announcements', 'department.announce'];
        yield 'Courses'       => ['courses', 'courses.view'];
        yield 'Monitor'       => ['monitor-department', 'department.monitor'];
    }

    /** A dean's menu shows only the pages they can open (QA AMB-AUTH-08); the rest of it stays. */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideDeanTiles')]
    public function testADeansTileGoesWithThePermissionBehindIt(string $tile, string $permission): void
    {
        helper('navigation');
        $dean = PeopleFactory::person('dean', institutionId: $this->institution);
        $keys = static fn (): array => array_column(global_apps('dean'), 'key');

        $this->loggedInAs($dean);
        $this->assertContains($tile, $keys());

        $this->clear('dean', $permission);
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(model(\CodeIgniter\Shield\Models\UserModel::class)->findById($dean->id));

        $this->assertNotContains($tile, $keys());
        $this->assertContains('follow-ups', $keys(), 'received follow-ups can still be answered, so the page stays');
        $this->assertContains('dashboard', $keys());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideGuardedRoutes')]
    public function testARouteRefusesARoleWhoseInstitutionClearedItsPermission(string $role, string $permission, string $verb, string $uri): void
    {
        $person = PeopleFactory::person($role, institutionId: $this->institution);

        $this->assertFalse($this->refused($person, $verb, $uri), 'allowed with the permission');

        $this->clear($role, $permission);

        $this->assertTrue($this->refused($person, $verb, $uri), 'refused without it');
    }

    public function testAReceivedFollowUpCanStillBeAnsweredWithoutFollowupsManage(): void
    {
        $this->clear('chairperson', 'followups.manage');
        $chair = PeopleFactory::person('chairperson', institutionId: $this->institution);

        $this->assertFalse($this->refused($chair, 'POST', 'chairperson/follow-ups/1/reply'));
        $this->assertFalse($this->refused($chair, 'POST', 'chairperson/follow-ups/1/done'));
    }

    public function testTheFollowUpPageOffersNoNewFollowUpWithoutTheAbility(): void
    {
        $chair = PeopleFactory::person('chairperson', institutionId: $this->institution);
        $this->loggedInAs($chair);
        $this->withSession();
        $this->assertStringContainsString('data-dialog-open="follow-up-pick"', (string) $this->step('GET', 'chairperson/follow-ups')->getBody());
        auth()->logout();
        $this->withSession([]);

        $this->clear('chairperson', 'followups.manage');
        $this->loggedInAs($chair);
        $this->withSession();

        $this->assertStringNotContainsString('data-dialog-open="follow-up-pick"', (string) $this->step('GET', 'chairperson/follow-ups')->getBody());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideAdminRoutes(): iterable
    {
        yield 'audit log'        => ['admin/audit-log', 'permission:audit.view'];
        yield 'audit export'     => ['admin/audit-log/export', 'permission:audit.view'];
        yield 'audit settings'   => ['admin/audit-log/settings', 'permission:audit.manage'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideAdminRoutes')]
    public function testTheAuditLogAsksItsPermissions(string $uri, string $filter): void
    {
        $routes  = service('routes')->loadRoutes();
        $options = [];

        foreach (['GET', 'POST'] as $verb) {
            if (isset($routes->getRoutes($verb)[$uri])) {
                $options = $routes->getRoutesOptions($uri, $verb);
            }
        }

        $this->assertContains($filter, (array) ($options['filter'] ?? []));
    }

    public function testADeanWithoutCoursesViewHasNoCoursesEntryButStillAHome(): void
    {
        $this->clear('dean', 'courses.view');
        $dean = PeopleFactory::person('dean', institutionId: $this->institution);
        $this->loggedInAs($dean);

        $urls = array_column(global_apps('dean'), 'url');

        $this->assertNotContains(site_url('dean/courses'), $urls);
        $this->assertContains(site_url('dean/dashboard'), $urls);

        auth()->logout();
        $this->withSession([]);
        $this->assertFalse($this->refused($dean, 'GET', 'dean/dashboard'), 'the dashboard still opens');
    }

    public function testADeanWhoAlsoTeachesLosesTheDeansCoursesWithTheDeansPermission(): void
    {
        $home = \Tests\Support\DeanFactory::department('TKD', $this->institution);
        $dean = \Tests\Support\DeanFactory::dean($this->institution, $home['department']);
        db_connect()->table('auth_groups_users')->insert(['user_id' => $dean->id, 'group' => 'instructor', 'created_at' => date('Y-m-d H:i:s')]);
        $dean = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($dean->id);

        $this->clear('dean', 'courses.view');

        // Their instructor role still has courses.view; acting as dean, it is the dean's that counts.
        $this->assertTrue($this->refused($dean, 'GET', 'dean/courses'));

        $this->loggedInAs($dean);
        $this->assertNotContains(site_url('dean/courses'), array_column(global_apps('dean'), 'url'));
    }

    public function testNavigationLeavesOutAMonitorThePersonCannotOpen(): void
    {
        $this->clear('chairperson', 'program.monitor');
        $chair = PeopleFactory::person('chairperson', institutionId: $this->institution);
        $this->loggedInAs($chair);

        $urls = array_column(global_apps('chairperson'), 'url');

        $this->assertNotContains(site_url('chairperson/monitor-program'), $urls);
    }
}
