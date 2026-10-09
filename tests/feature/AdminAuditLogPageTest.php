<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The audit log page, its CSV export, and the source-address setting, as a
 * system administrator sees them and as everyone else is refused.
 *
 * @internal
 */
final class AdminAuditLogPageTest extends RouteTestCase
{
    private const PASSWORD = 'a-long-password-1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $user = CourseFactory::user('admin');
        $user->createEmailIdentity(['email' => 'root@mentora.test', 'password' => self::PASSWORD]);
        $this->admin = model(UserModel::class)->findById($user->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        // Signing the admin in wrote an entry of its own; every test starts from an empty log.
        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        // The hostile-query test leaves ?q[]=x behind in the request data; later
        // tests in the same run would otherwise read it.
        $_GET = [];
        service('superglobals')->setGetArray([]);

        parent::tearDown();
    }

    /** @param array<string, mixed> $row */
    private function entry(array $row = []): void
    {
        db_connect()->table('audit_logs')->insert(array_merge([
            'actor_label' => 'sam@mentora.test',
            'acting_role' => 'student',
            'action'      => 'auth.login',
            'target_type' => 'user',
            'target_id'   => '1',
            'ip_address'  => '203.0.113.9',
            'created_at'  => '2026-03-10 12:00:00',
        ], $row));
    }

    private function showIp(bool $on): void
    {
        (new PlatformSettings())->set('auditShowSourceIp', $on);
        Services::resetSingle('settings');
    }

    /** Pretends the password was just confirmed. */
    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Who may see it
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
    public function testNobodyElseCanOpenTheListOrTheExport(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        foreach (['admin/audit-log', 'admin/audit-log/export'] as $uri) {
            $response = $this->step('GET', $uri)->response();

            $this->assertSame(302, $response->getStatusCode(), "{$role} must not reach {$uri}");
            $this->assertStringNotContainsString('audit-log', $response->getHeaderLine('Location'));
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanChangeTheAddressSetting(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '1']);

        $this->assertFalse((new PlatformSettings())->get('auditShowSourceIp'));
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function testTheListShowsEntries(): void
    {
        $this->entry(['actor_label' => 'visible@mentora.test', 'action' => 'course.updated']);

        $result = $this->step('GET', 'admin/audit-log');

        $this->assertTrue($result->isOK());
        $this->assertStringContainsString('visible@mentora.test', $result->response()->getBody());
        $this->assertStringContainsString('course.updated', $result->response()->getBody());
    }

    public function testAnEmptyLogSaysSo(): void
    {
        $body = $this->step('GET', 'admin/audit-log')->response()->getBody();

        $this->assertStringContainsString('0 entries', $body);
        $this->assertStringContainsString('No entries yet', $body);
    }

    public function testFiltersNarrowTheList(): void
    {
        $this->entry(['actor_label' => 'keep@mentora.test', 'action' => 'auth.login']);
        $this->entry(['actor_label' => 'drop@mentora.test', 'action' => 'auth.logout']);

        $body = $this->step('GET', 'admin/audit-log?action=auth.login')->response()->getBody();

        $this->assertStringContainsString('keep@mentora.test', $body);
        $this->assertStringNotContainsString('drop@mentora.test', $body);
        $this->assertStringContainsString('1 entry match', $body);
        $this->assertStringContainsString('Clear', $body);
    }

    public function testNoMatchSaysSoAndOffersToClear(): void
    {
        $this->entry();

        $body = $this->step('GET', 'admin/audit-log?q=zzz-nothing')->response()->getBody();

        $this->assertStringContainsString('No entries match these filters', $body);
    }

    public function testSortingReordersTheRows(): void
    {
        $this->entry(['actor_label' => 'aaa@mentora.test', 'action' => 'z.last']);
        $this->entry(['actor_label' => 'bbb@mentora.test', 'action' => 'a.first']);

        $body = $this->step('GET', 'admin/audit-log?sort=action&dir=asc')->response()->getBody();

        $this->assertLessThan(strpos($body, 'z.last'), strpos($body, 'a.first'));
    }

    public function testPagingShowsAPageAtATimeWithLinksThatKeepTheFilters(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->entry(['actor_label' => "p{$i}@mentora.test", 'created_at' => "2026-03-0{$i} 10:00:00"]);
        }

        $body = $this->step('GET', 'admin/audit-log?per_page=2&target_type=user')->response()->getBody();

        $this->assertStringContainsString('Page 1 of 2', $body);
        $this->assertStringContainsString('p3@mentora.test', $body);
        $this->assertStringNotContainsString('p1@mentora.test', $body);
        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*"/', $body);
        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*target_type=user|href="[^"]*target_type=user[^"]*page=2[^"]*"/', $body);
    }

    public function testHostileQueryValuesAreHarmless(): void
    {
        $this->entry();

        foreach (['sort[]=x', 'dir[]=x', 'page=-5', 'per_page=999999', 'from=not-a-date', 'institution=abc', 'q[]=x', "sort=id%3B%20DROP%20TABLE%20audit_logs"] as $query) {
            $this->assertTrue($this->step('GET', "admin/audit-log?{$query}")->isOK(), "?{$query} must not break the page");
        }

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'auth.login')->countAllResults());
    }

    public function testValuesAreEscapedOnThePage(): void
    {
        $this->entry(['actor_label' => '<script>alert(1)</script>']);

        $body = $this->step('GET', 'admin/audit-log')->response()->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Source addresses
    |--------------------------------------------------------------------------
    */

    public function testAddressesAreHiddenByDefault(): void
    {
        $this->entry(['ip_address' => '198.51.100.77']);

        $body = $this->step('GET', 'admin/audit-log')->response()->getBody();

        $this->assertStringNotContainsString('198.51.100.77', $body);
        $this->assertStringNotContainsString('Source IP', $body);
    }

    public function testAddressesAreShownWhenTheSettingIsOn(): void
    {
        $this->showIp(true);
        $this->entry(['ip_address' => '198.51.100.77']);

        $body = $this->step('GET', 'admin/audit-log')->response()->getBody();

        $this->assertStringContainsString('198.51.100.77', $body);
        $this->assertStringContainsString('Source IP', $body);
    }

    public function testAHiddenAddressCannotBeFoundBySearching(): void
    {
        $this->entry(['actor_label' => 'someone@mentora.test', 'ip_address' => '198.51.100.77']);

        $body = $this->step('GET', 'admin/audit-log?q=198.51.100.77')->response()->getBody();

        $this->assertStringNotContainsString('someone@mentora.test', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Changing the address setting
    |--------------------------------------------------------------------------
    */

    public function testChangingTheSettingNeedsThePasswordFirst(): void
    {
        $response = $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '1'])->response();

        $this->assertStringEndsWith('admin/reauth', $response->getHeaderLine('Location'));
        $this->assertFalse((new PlatformSettings())->get('auditShowSourceIp'), 'nothing changed');
    }

    public function testTheSettingCanBeTurnedOnAndOffOnceConfirmed(): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '1']);
        Services::resetSingle('settings');
        $this->assertTrue((new PlatformSettings())->get('auditShowSourceIp'));

        $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '0']);
        Services::resetSingle('settings');
        $this->assertFalse((new PlatformSettings())->get('auditShowSourceIp'));
    }

    public function testChangingTheSettingIsAudited(): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '1']);

        $entries = $this->entries('settings.changed');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $this->admin->id, (string) $entries[0]['actor_user_id']);
        $this->assertSame('auditShowSourceIp', $entries[0]['target_id']);
        $this->assertSame(['auditShowSourceIp' => false], json_decode($entries[0]['old_values'], true));
        $this->assertSame(['auditShowSourceIp' => true], json_decode($entries[0]['new_values'], true));
    }

    public function testSavingTheSameValueAgainLeavesNoEntry(): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/audit-log/settings', ['show_ip' => '0']);

        $this->assertSame([], $this->entries('settings.changed'));
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    /** @return list<list<string>> */
    private function csv(string $body): array
    {
        $rows   = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function testTheExportIsACsvDownload(): void
    {
        $this->entry();

        $response = $this->step('GET', 'admin/audit-log/export')->response();

        $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="audit-log-', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheExportHasTheMatchingRowsOnly(): void
    {
        $this->entry(['actor_label' => 'keep@mentora.test', 'action' => 'auth.login']);
        $this->entry(['actor_label' => 'drop@mentora.test', 'action' => 'auth.logout']);

        $rows = $this->csv($this->step('GET', 'admin/audit-log/export?action=auth.login')->response()->getBody());

        $this->assertCount(2, $rows, 'a header and one row');
        $this->assertSame('keep@mentora.test', $rows[1][array_search('actor', $rows[0], true)]);
    }

    public function testTheExportLeavesOutAddressesWhenTheyAreHidden(): void
    {
        $this->entry(['ip_address' => '198.51.100.77']);

        $body = $this->step('GET', 'admin/audit-log/export')->response()->getBody();

        $this->assertStringNotContainsString('198.51.100.77', $body);
        $this->assertNotContains('ip_address', $this->csv($body)[0]);
    }

    public function testTheExportIncludesAddressesWhenTheyMayBeShown(): void
    {
        $this->showIp(true);
        $this->entry(['ip_address' => '198.51.100.77']);

        $body = $this->step('GET', 'admin/audit-log/export')->response()->getBody();

        $this->assertStringContainsString('198.51.100.77', $body);
        $this->assertContains('ip_address', $this->csv($body)[0]);
    }

    public function testSpreadsheetFormulasInTheDataAreMadeHarmless(): void
    {
        $this->entry(['actor_label' => '=HYPERLINK("http://evil.example","x")', 'target_id' => '@SUM(A1)']);

        $rows   = $this->csv($this->step('GET', 'admin/audit-log/export')->response()->getBody());
        $header = $rows[0];

        $this->assertSame("'=HYPERLINK(\"http://evil.example\",\"x\")", $rows[1][array_search('actor', $header, true)]);
        $this->assertSame("'@SUM(A1)", $rows[1][array_search('target_id', $header, true)]);
    }

    public function testTheExportItselfIsRecorded(): void
    {
        $this->entry();
        $this->entry();

        $this->step('GET', 'admin/audit-log/export?action=auth.login&q=sam');

        $entries = $this->entries('audit.exported');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $this->admin->id, (string) $entries[0]['actor_user_id']);

        $new = json_decode($entries[0]['new_values'], true);

        $this->assertSame(2, $new['rows']);
        $this->assertSame(['action' => 'auth.login', 'q' => 'sam'], $new['filters']);
        $this->assertFalse($new['includes_ip']);
    }

    public function testTheExportIsRecordedAfterItsRowsAreCounted(): void
    {
        $this->step('GET', 'admin/audit-log/export');

        $new = json_decode($this->entries('audit.exported')[0]['new_values'], true);

        $this->assertSame(0, $new['rows'], 'an empty export is still an export');
    }
}
