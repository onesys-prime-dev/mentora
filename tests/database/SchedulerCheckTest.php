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
