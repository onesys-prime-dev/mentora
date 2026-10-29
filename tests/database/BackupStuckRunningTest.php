<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeBackupRunner;
use Tests\Support\PeopleFactory;

/**
 * A backup left `running` by a server that died mid-dump is failed after two
 * hours and told to the system administrators once, so it no longer blocks
 * every later backup (QA MISS-OPS-03, TC-SA-18). A younger one still waits.
 *
 * @internal
 */
final class BackupStuckRunningTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FakeBackupRunner $runner;

    private BackupService $service;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->runner  = FakeBackupRunner::install();
        $this->service = new BackupService();
        $this->now     = new DateTimeImmutable('2026-10-10 12:00:00');
    }

    protected function tearDown(): void
    {
        $this->runner->remove();
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        Services::resetSingle('email');

        parent::tearDown();
    }

    private function running(string $startedAt): int
    {
        db_connect()->table('backups')->insert(['status' => 'running', 'trigger' => 'scheduled', 'created_at' => $startedAt, 'started_at' => $startedAt]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('backups')->where('id', $id)->get()->getRowArray();
    }

    public function testAStuckOneIsFailedAndTheNightlyBackupRuns(): void
    {
        $stuck = $this->running('2026-10-10 09:00:00');

        $ran = $this->service->runDue($this->now);

        $this->assertSame('failed', $this->row($stuck)['status']);
        $this->assertStringContainsString('stopped while running', $this->row($stuck)['message']);
        $this->assertSame('2026-10-10 12:00:00', $this->row($stuck)['finished_at']);
        $this->assertCount(1, $ran);
        $this->assertSame('succeeded', $this->row($ran[0])['status']);
    }

    public function testAStuckOneDoesNotBlockARequest(): void
    {
        $stuck = $this->running('2026-10-10 09:00:00');

        $id = $this->service->request(PeopleFactory::person('admin'), $this->now);

        $this->assertSame('failed', $this->row($stuck)['status']);
        $this->assertSame('requested', $this->row($id)['status']);
    }

    public function testTheAdministratorsAreToldOnce(): void
    {
        $admin = (int) PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com')->id;
        $this->running('2026-10-10 09:00:00');
        $this->runner->fail = true;

        $this->service->runDue($this->now);
        $this->service->runDue($this->now->modify('+5 minutes'));

        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $admin)->where('type', 'backup.failed')->countAllResults());
    }

    public function testOneRunningUnderTwoHoursIsLeftAloneAndWaitedFor(): void
    {
        $young = $this->running('2026-10-10 10:30:00');

        $this->assertSame([], $this->service->runDue($this->now));
        $this->assertSame('running', $this->row($young)['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->request(PeopleFactory::person('admin'), $this->now);
    }
}
