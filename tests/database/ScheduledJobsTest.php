<?php

declare(strict_types=1);

use App\Services\Monitoring\ScheduledJobs;
use App\Services\Monitoring\SystemHealth;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\SisFixture;
use Tests\Support\PeopleFactory;

/**
 * Every scheduled job reports in, and one that misses its window is stale (audit decisions 6.2, 6.4).
 *
 * @internal
 */
final class ScheduledJobsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        FakeEmail::install();
        $this->now = time();

        // Every job reported a minute ago, unless a test says otherwise.
        foreach (array_keys(ScheduledJobs::JOBS) as $command) {
            ScheduledJobs::heartbeat($command, $this->now - 60);
        }
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('email');

        parent::tearDown();
    }

    public function testThereAreSevenJobs(): void
    {
        $this->assertSame(['backup:run', 'parents:notify', 'exports:run', 'subscriptions:sync', 'institutions:purge-expired', 'sis:sync', 'sis:grades'], array_keys(ScheduledJobs::JOBS));
    }

    public function testAJobWithinItsGapIsNotStale(): void
    {
        ScheduledJobs::heartbeat('backup:run', $this->now - 10 * 60);

        $this->assertSame([], ScheduledJobs::stale($this->now));
    }

    public function testAJobPastItsGapIsStale(): void
    {
        ScheduledJobs::heartbeat('backup:run', $this->now - 20 * 60);
        ScheduledJobs::heartbeat('subscriptions:sync', $this->now - 27 * 3600);

        $this->assertSame(['backup:run', 'subscriptions:sync'], ScheduledJobs::stale($this->now));
        $this->assertSame(SystemHealth::DEGRADED, ScheduledJobs::status('backup:run', $this->now)['status']);
    }

    public function testAJobThatNeverReportedIsStale(): void
    {
        setting()->forget('Monitoring.parentsNotifyAt');

        $this->assertNull(ScheduledJobs::lastRun('parents:notify'));
        $this->assertSame(['parents:notify'], ScheduledJobs::stale($this->now));
    }

    public function testTheSisJobsCountOnlyWhenSisIsEnabled(): void
    {
        ScheduledJobs::heartbeat('sis:sync', $this->now - 30 * 3600);

        $this->assertSame([], ScheduledJobs::stale($this->now), 'no institution uses SIS');

        SisFixture::connect(SisFixture::institution(), PeopleFactory::person('admin', 'Root', 'Admin', 'root@jobs.test'));

        $this->assertSame(['sis:sync'], ScheduledJobs::stale($this->now));
    }

    public function testEachCommandRecordsItsHeartbeat(): void
    {
        foreach (array_keys(ScheduledJobs::JOBS) as $command) {
            setting()->forget(ScheduledJobs::JOBS[$command]['setting']);
        }

        foreach (array_keys(ScheduledJobs::JOBS) as $command) {
            ob_start();
            command($command);
            ob_end_clean();

            $this->assertNotNull(ScheduledJobs::lastRun($command), "{$command} reported its run");
        }
    }

    public function testTheHealthCheckNamesTheStaleJobs(): void
    {
        ScheduledJobs::heartbeat('backup:run', $this->now - 20 * 60);
        ScheduledJobs::heartbeat('parents:notify', $this->now - 50 * 60);

        $jobs = (new SystemHealth(['database' => static fn (): array => ['status' => 'up', 'detail' => ''], 'storage' => static fn (): array => ['status' => 'up', 'detail' => ''], 'disk' => static fn (): array => ['status' => 'up', 'detail' => ''], 'ai' => static fn (): array => ['status' => 'up', 'detail' => ''], 'backups' => static fn (): array => ['status' => 'up', 'detail' => '']]))->run()['jobs'];

        $this->assertSame(SystemHealth::DEGRADED, $jobs['status']);
        $this->assertSame('Not reporting: backup:run, parents:notify.', $jobs['detail']);
    }

    public function testAJobSilentForThreeTimesItsGapIsDown(): void
    {
        ScheduledJobs::heartbeat('backup:run', $this->now - 46 * 60);

        $jobs = (new SystemHealth(['database' => static fn (): array => ['status' => 'up', 'detail' => ''], 'storage' => static fn (): array => ['status' => 'up', 'detail' => ''], 'disk' => static fn (): array => ['status' => 'up', 'detail' => ''], 'ai' => static fn (): array => ['status' => 'up', 'detail' => ''], 'backups' => static fn (): array => ['status' => 'up', 'detail' => '']]))->run()['jobs'];

        $this->assertSame(SystemHealth::DOWN, $jobs['status']);
    }
}
