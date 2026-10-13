<?php

declare(strict_types=1);

use App\Services\Monitoring\AdminHome;
use App\Services\Monitoring\SystemHealth;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The administrator's home page and the grouped menu: the sections, the empty
 * states, that what it counts matches the data, and that the menu reaches the
 * right pages from every admin page.
 *
 * @internal
 */
final class AdminHomePageTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($admin);
        $this->withSession();

        // The disk and services of whatever machine runs the tests must not decide what the page says.
        $up = static fn (): array => ['status' => SystemHealth::UP, 'detail' => 'fine'];
        Services::injectMock('adminHome', new AdminHome(new SystemHealth(['database' => $up, 'storage' => $up, 'disk' => $up, 'ai' => $up, 'jobs' => $up, 'backups' => $up])));

        db_connect()->table('audit_logs')->emptyTable();
        db_connect()->table('backups')->insert(['status' => 'succeeded', 'trigger' => 'scheduled', 'created_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s')]);
        setting()->set('Monitoring.subscriptionSyncAt', time());
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('adminHome');

        parent::tearDown();
    }

    private function home(): string
    {
        return $this->step('GET', 'admin/dashboard')->response()->getBody();
    }

    public function testEveryPartIsThereAndTheShortcutsPointToTheRightPages(): void
    {
        $body = $this->home();

        foreach (['attention', 'snapshot', 'activity'] as $section) {
            $this->assertStringContainsString('data-section="' . $section . '"', $body);
        }

        $this->assertStringContainsString('href="' . site_url('admin/institutions/new') . '"', $body);
        $this->assertStringContainsString('href="' . site_url('admin/institutions') . '"', $body);
    }

    public function testNothingToAttendToSaysSo(): void
    {
        $this->assertStringContainsString('data-attention-empty', $this->home());
    }

    public function testAnEmptyActivityLogSaysSo(): void
    {
        db_connect()->table('audit_logs')->emptyTable();

        $this->assertMatchesRegularExpression('/data-activity-empty/', $this->step('GET', 'admin/dashboard')->response()->getBody());
    }

    public function testAnInstitutionThatNeedsAttentionIsListedWithALinkToIt(): void
    {
        $id = PeopleFactory::institution('LATE', 'active', 'Late College');
        PeopleFactory::subscribe($id, PeopleFactory::plan('Standard'));
        db_connect()->table('subscriptions')->update(['end_date' => date('Y-m-d', strtotime('-100 days'))]);

        $body = $this->home();

        $this->assertStringContainsString('data-attention="urgent"', $body);
        $this->assertStringContainsString('Late College', $body);
        $this->assertStringContainsString('href="' . site_url("admin/institutions/{$id}/subscription") . '"', $body);
        $this->assertStringNotContainsString('data-attention-empty', $body);
    }

    public function testTheSnapshotShowsTheRealCounts(): void
    {
        PeopleFactory::institution('A1', 'active');
        PeopleFactory::institution('A2', 'active');
        PeopleFactory::institution('R1', 'read_only');

        $body = $this->home();

        $this->assertMatchesRegularExpression('/data-status-count="active">.*?<dd[^>]*>2</s', $body);
        $this->assertMatchesRegularExpression('/data-status-count="read_only">.*?<dd[^>]*>1</s', $body);
        $this->assertMatchesRegularExpression('/data-status-count="past">.*?<dd[^>]*>0</s', $body);
    }

    public function testRecentActivityListsWhatHappened(): void
    {
        service('auditLogger')->log('institution.created', null, ['actor_label' => 'tester@example.com']);

        $body = $this->home();

        $this->assertStringContainsString('institution.created', $body);
        $this->assertStringContainsString('tester@example.com', $body);
    }

    public function testTheMenuHasTheFourGroupsWithTheirLinks(): void
    {
        $body = $this->home();

        $this->assertStringContainsString('data-admin-menu', $body);

        $expected = [
            'Customers'  => ['admin/institutions', 'admin/plans', 'admin/users'],
            'Platform'   => ['admin/flags', 'admin/roles'],
            'Operations' => ['admin/monitoring', 'admin/backups', 'admin/audit-log', 'admin/archive'],
            'Settings'   => ['admin/settings/general', 'admin/settings/security'],
        ];

        foreach ($expected as $group => $paths) {
            $this->assertStringContainsString('data-menu-group="' . $group . '"', $body);

            foreach ($paths as $path) {
                $this->assertStringContainsString('href="' . site_url($path) . '"', $body, "{$group}: {$path}");
            }
        }
    }

    public function testEveryMenuLinkOpensAPage(): void
    {
        preg_match_all('#data-menu-group="[^"]+".*?</ul>#s', $this->home(), $groups);
        preg_match_all('#href="([^"]+)"#', implode('', $groups[0]), $links);

        $this->assertCount(11, $links[1]);

        foreach ($links[1] as $url) {
            $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $path = preg_replace('#^.*?index\.php/#', '', $path);

            $this->assertSame(200, $this->step('GET', $path)->response()->getStatusCode(), $path);
        }
    }

    public function testTheCurrentPageIsMarkedInTheMenu(): void
    {
        $body = $this->step('GET', 'admin/backups')->response()->getBody();

        $this->assertMatchesRegularExpression('/href="[^"]*admin\/backups"\s+aria-current="page"/', $body);
        $this->assertSame(1, preg_match_all('/aria-current="page"/', $body));
    }

    public function testEveryAdminPageHasTheMenu(): void
    {
        foreach (['admin/institutions', 'admin/users', 'admin/plans', 'admin/flags', 'admin/roles', 'admin/archive', 'admin/audit-log'] as $path) {
            $this->assertStringContainsString('data-admin-menu', $this->step('GET', $path)->response()->getBody(), $path);
        }
    }
}
