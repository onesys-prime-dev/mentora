<?php

declare(strict_types=1);

use App\Services\Monitoring\ScheduledJobs;
use App\Services\Monitoring\SchedulerCheck;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * The hourly scheduler check tells every system administrator once when a job stops, and once when it is
 * back (audit decisions 6.2, U2, D11).
 *
 * @internal
 */
final class SchedulerCheckTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $now;

    /** @var list<User> */
    private array $admins;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        FakeEmail::install();
        $this->now    = time();
        $this->admins = [PeopleFactory::person('admin', 'Root', 'One', 'root1@check.test'), PeopleFactory::person('admin', 'Root', 'Two', 'root2@check.test')];

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

    private function told(User $admin, string $title): int
    {
        return db_connect()->table('notifications')->where(['user_id' => $admin->id, 'title' => $title])->countAllResults();
    }

    public function testASecondStallIsANewPeriod(): void
    {
        ScheduledJobs::heartbeat('backup:run', $this->now - 3600);
        (new SchedulerCheck())->run($this->now);

        // It reported once, then stopped again before the next hourly check.
        ScheduledJobs::heartbeat('backup:run', $this->now + 60);
        $later  = $this->now + 60 + 3600;

        foreach (array_diff(array_keys(ScheduledJobs::JOBS), ['backup:run']) as $command) {
            ScheduledJobs::heartbeat($command, $later - 60); // the others kept running
        }

        $second = (new SchedulerCheck())->run($later);
        $third  = (new SchedulerCheck())->run($later + 60);

        $this->assertSame(4, $second['alerted'], 'running again, and stopped again, to each of the two');
        $this->assertSame(0, $third['alerted'], 'the new period is told once');

        foreach ($this->admins as $admin) {
            $this->assertSame(1, $this->told($admin, 'Scheduled job running again: backup:run'));
            $this->assertSame(2, $this->told($admin, 'Scheduled job stopped: backup:run'));
        }
    }

    public function testNoPeriodIsRecordedBeforeThereIsAnAdministrator(): void
    {
        db_connect()->table('auth_groups_users')->where('group', 'admin')->delete();
        ScheduledJobs::heartbeat('backup:run', $this->now - 3600);

        $this->assertSame(0, (new SchedulerCheck())->run($this->now)['alerted']);
        $this->assertArrayNotHasKey('backup:run', json_decode((string) setting('Monitoring.staleJobs'), true) ?? []);

        // The first administrator arrives; the next check tells them.
        db_connect()->table('auth_groups_users')->insert(['user_id' => $this->admins[0]->id, 'group' => 'admin', 'created_at' => date('Y-m-d H:i:s')]);
        (new SchedulerCheck())->run($this->now + 60);

        $this->assertSame(1, $this->told($this->admins[0], 'Scheduled job stopped: backup:run'));
    }

    public function testASisJobLeftOutWhenSisIsOffIsDroppedQuietly(): void
    {
        $institution = PeopleFactory::institution('SCHK');
        db_connect()->table('sis_connections')->insert(['institution_id' => $institution, 'connector' => 'file', 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        // sis:sync has never reported (no heartbeat at all): the case that once said "1970".
        setting()->forget('Monitoring.sisSyncAt');

        $first = (new SchedulerCheck())->run($this->now);
        $this->assertContains('sis:sync', $first['stale']);
        $this->assertSame(1, $this->told($this->admins[0], 'Scheduled job stopped: sis:sync'));

        db_connect()->table('sis_connections')->where('institution_id', $institution)->update(['enabled' => 0]);
        $after = (new SchedulerCheck())->run($this->now + 60);

        $this->assertSame(0, $after['alerted']);
        $this->assertSame(0, db_connect()->table('notifications')->like('body', '1970')->countAllResults());
        $this->assertArrayNotHasKey('sis:sync', json_decode((string) setting('Monitoring.staleJobs'), true) ?? []);
    }

    /** @return array<string, int> */
    private function periods(): array
    {
        return json_decode((string) setting('Monitoring.staleJobs'), true) ?? [];
    }

    private function sisStall(): int
    {
        $institution = PeopleFactory::institution('SCHK');
        db_connect()->table('sis_connections')->insert(['institution_id' => $institution, 'connector' => 'file', 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        ScheduledJobs::heartbeat('sis:sync', $this->now - 30 * 3600);

        return $institution;
    }

    private function sis(int $institution, bool $enabled): void
    {
        db_connect()->table('sis_connections')->where('institution_id', $institution)->update(['enabled' => $enabled ? 1 : 0]);
    }

    public function testASisStallStillThereWhenSisComesBackIsToldWhenItEnds(): void
    {
        $institution = $this->sisStall();
        (new SchedulerCheck())->run($this->now);

        foreach ($this->admins as $admin) {
            $this->assertSame(1, $this->told($admin, 'Scheduled job stopped: sis:sync'));
        }

        $this->sis($institution, false);
        (new SchedulerCheck())->run($this->now + 60);
        $this->sis($institution, true);
        $back = (new SchedulerCheck())->run($this->now + 120);

        $this->assertSame(0, $back['alerted'], 'everyone already knows');
        $this->assertArrayHasKey('sis:sync', $this->periods(), 'the stop is remembered again');

        ScheduledJobs::heartbeat('sis:sync', $this->now + 180);
        (new SchedulerCheck())->run($this->now + 240);

        foreach ($this->admins as $admin) {
            $this->assertSame(1, $this->told($admin, 'Scheduled job running again: sis:sync'));
        }

        $this->assertArrayNotHasKey('sis:sync', $this->periods());
    }

    public function testAnAdministratorAddedWhileSisWasOffIsToldOfTheStallAndItIsKept(): void
    {
        db_connect()->table('auth_groups_users')->where(['user_id' => $this->admins[1]->id, 'group' => 'admin'])->delete();
        $institution = $this->sisStall();
        (new SchedulerCheck())->run($this->now);

        $this->sis($institution, false);
        (new SchedulerCheck())->run($this->now + 60);
        db_connect()->table('auth_groups_users')->insert(['user_id' => $this->admins[1]->id, 'group' => 'admin', 'created_at' => date('Y-m-d H:i:s')]);
        $this->sis($institution, true);
        (new SchedulerCheck())->run($this->now + 120);

        $this->assertSame(1, $this->told($this->admins[0], 'Scheduled job stopped: sis:sync'));
        $this->assertSame(1, $this->told($this->admins[1], 'Scheduled job stopped: sis:sync'));
        $this->assertArrayHasKey('sis:sync', $this->periods());
    }

    public function testAStaleJobAlertsEverySystemAdministratorOnce(): void
    {
        ScheduledJobs::heartbeat('backup:run', $this->now - 3600);

        $first  = (new SchedulerCheck())->run($this->now);
        $second = (new SchedulerCheck())->run($this->now + 60); // the next check, the job still stopped

        $this->assertSame(['backup:run'], $first['stale']);
        $this->assertSame(2, $first['alerted']);
        $this->assertSame(0, $second['alerted'], 'not again while it stays stopped');

        foreach ($this->admins as $admin) {
            $this->assertSame(1, $this->told($admin, 'Scheduled job stopped: backup:run'));
        }

        $this->assertSame(2, db_connect()->table('notifications')->where('title', 'Scheduled job stopped: backup:run')->where('emailed_at IS NOT NULL', null, false)->countAllResults(), 'urgent, so it is emailed');
    }

    public function testRecoveryIsToldOnceAndOnlyAfterAStop(): void
    {
        (new SchedulerCheck())->run($this->now);
        $this->assertSame(0, $this->told($this->admins[0], 'Scheduled job running again: backup:run'), 'nothing had stopped');

        ScheduledJobs::heartbeat('backup:run', $this->now - 3600);
        (new SchedulerCheck())->run($this->now);

        ScheduledJobs::heartbeat('backup:run', $this->now + 60);
        (new SchedulerCheck())->run($this->now + 120);
        (new SchedulerCheck())->run($this->now + 3600 + 60);

        $this->assertSame(1, $this->told($this->admins[0], 'Scheduled job running again: backup:run'));
    }

    public function testANewStallAfterRecoveryAlertsAgain(): void
    {
        ScheduledJobs::heartbeat('backup:run', $this->now - 3600);
        (new SchedulerCheck())->run($this->now);
        ScheduledJobs::heartbeat('backup:run', $this->now);
        (new SchedulerCheck())->run($this->now + 60);

        ScheduledJobs::heartbeat('backup:run', $this->now);
        (new SchedulerCheck())->run($this->now + 7200);

        $this->assertSame(2, $this->told($this->admins[0], 'Scheduled job stopped: backup:run'));
    }

    public function testAJobThatNeverReportedSaysSo(): void
    {
        setting()->forget('Monitoring.exportsRunAt');

        (new SchedulerCheck())->run($this->now);

        $body = (string) db_connect()->table('notifications')->where(['user_id' => $this->admins[0]->id, 'title' => 'Scheduled job stopped: exports:run'])->get()->getRow('body');
        $this->assertSame("exports:run has not reported since it was installed. Check the server's crontab (docs/server-setup.md, section 6).", $body);
    }
}
