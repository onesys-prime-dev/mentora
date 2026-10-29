<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The system status page: administrators only, read-only, with the health
 * checks, platform counts, subscription watch and usage against plans. The
 * public /health endpoint is unchanged.
 *
 * @internal
 */
final class AdminMonitoringPageTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($admin);
        $this->withSession();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanOpenIt(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', 'admin/monitoring')->response()->getStatusCode());
    }

    public function testThePageShowsEveryCheckAnOverallStatusAndRefreshesItself(): void
    {
        $response = $this->step('GET', 'admin/monitoring');
        $body     = $response->response()->getBody();

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertMatchesRegularExpression('/data-overall="(up|degraded|down)"/', $body);
        $this->assertStringContainsString('http-equiv="refresh"', $body);

        foreach (['database', 'storage', 'disk', 'ai', 'jobs'] as $check) {
            $this->assertStringContainsString('data-check="' . $check . '"', $body);
        }

        $this->assertMatchesRegularExpression('/data-check="database" data-status="up"/', $body);
    }

    public function testTheLiveFeedGivesTheNumbersTheGraphsDrawWithoutTheAiCheck(): void
    {
        $response = $this->step('GET', 'admin/monitoring/live');
        $data     = json_decode($response->response()->getBody(), true);

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertIsInt($data['databaseMs']);
        $this->assertIsInt($data['sessions']);
        $this->assertArrayHasKey('failed24h', $data);
        $this->assertSame('up', $data['checks']['database']['status']);
        $this->assertArrayNotHasKey('ai', $data['checks']);
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseGetsTheLiveFeed(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', 'admin/monitoring/live')->response()->getStatusCode());
    }

    public function testAJobThatNeverRanIsFlaggedAndOneThatRanIsUp(): void
    {
        $this->assertMatchesRegularExpression('/data-check="jobs" data-status="degraded"/', $this->step('GET', 'admin/monitoring')->response()->getBody());

        // All seven report (6.2), not only the nightly one.
        foreach (array_keys(\App\Services\Monitoring\ScheduledJobs::JOBS) as $command) {
            \App\Services\Monitoring\ScheduledJobs::heartbeat($command, time() - 60);
        }

        $this->assertMatchesRegularExpression('/data-check="jobs" data-status="up"/', $this->step('GET', 'admin/monitoring')->response()->getBody());
    }

    public function testTheDailyCommandRecordsThatItRan(): void
    {
        command('subscriptions:sync');

        $this->assertEqualsWithDelta(time(), (int) setting('Monitoring.subscriptionSyncAt'), 10);
    }

    public function testADryRunDoesNotCountAsARun(): void
    {
        command('subscriptions:sync --dry-run');

        $this->assertNull(setting('Monitoring.subscriptionSyncAt'));
    }

    public function testPlatformCountsAndTheSubscriptionWatchAreShown(): void
    {
        $acme = PeopleFactory::institution('ACME', 'active', 'Acme University');
        PeopleFactory::person('student', 'Stu', 'Dent', null, $acme);
        PeopleFactory::subscribe($acme, PeopleFactory::plan('Standard', 10));
        db_connect()->table('subscriptions')->update(['end_date' => date('Y-m-d', strtotime('-3 days'))]);

        db_connect()->table('auth_logins')->insert(['ip_address' => '1.1.1.1', 'user_agent' => 't', 'id_type' => 'email_password', 'identifier' => 'x@example.com', 'date' => date('Y-m-d H:i:s'), 'success' => 0]);

        $body = $this->step('GET', 'admin/monitoring')->response()->getBody();

        $this->assertStringContainsString('data-watch="grace"', $body);
        $this->assertMatchesRegularExpression('/data-institution="' . $acme . '"/', $body);
        $this->assertStringContainsString('Standard', $body);
    }

    public function testAnEmptyPlatformSaysSo(): void
    {
        $body = $this->step('GET', 'admin/monitoring')->response()->getBody();

        $this->assertStringContainsString('Nothing is expiring', $body);
        $this->assertStringContainsString('No institutions yet', $body);
    }

    public function testThePublicHealthEndpointIsUnchanged(): void
    {
        auth()->logout();
        $this->withSession([]);

        $response = $this->step('GET', 'health');

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertSame(['status' => 'ok', 'database' => 'up'], json_decode($response->response()->getBody(), true));
    }
}
