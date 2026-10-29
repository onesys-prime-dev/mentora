<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Notifications\NotificationTriggers;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeBackupRunner;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * A backup counts only once it reads back complete, and a failing one never pushes out a good one
 * (audit decisions 11.1–11.3).
 *
 * @internal
 */
final class BackupProvenTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FakeBackupRunner $runner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->runner = FakeBackupRunner::install();
        $this->admin  = PeopleFactory::person('admin', 'Root', 'Admin', 'root@backup.test');
    }

    protected function tearDown(): void
    {
        $this->runner->remove();
        Services::resetSingle('settings');
        Services::resetSingle('email');

        parent::tearDown();
    }

    /** Takes one backup at this moment and returns its row. */
    private function take(string $when, string $mode = 'complete'): array
    {
        $this->runner->mode = $mode;
        db_connect()->table('backups')->insert(['status' => 'requested', 'trigger' => 'manual', 'created_at' => $when]);
        $id = (int) db_connect()->insertID();
        (new BackupService())->take($id, new DateTimeImmutable($when));

        return db_connect()->table('backups')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_map('basename', glob($this->runner->folder . DIRECTORY_SEPARATOR . '*') ?: []);
        sort($files);

        return $files;
    }

    private function alerts(): int
    {
        return db_connect()->table('notifications')->where(['user_id' => $this->admin->id, 'title' => 'Backups need attention'])->countAllResults();
    }

    public function testACompleteDumpSucceeds(): void
    {
        $row = $this->take('2026-10-10 12:00:00');

        $this->assertSame('succeeded', $row['status']);
        $this->assertSame([$row['file_name']], $this->files());
    }

    public function testATruncatedFileFailsIsDeletedAndAlertsAtOnce(): void
    {
        $row = $this->take('2026-10-10 12:00:00', 'truncated');

        $this->assertSame('failed', $row['status']);
        $this->assertSame([], $this->files(), 'the broken file is gone');
        $this->assertSame(1, $this->alerts());
        $this->assertNotNull(db_connect()->table('notifications')->where('user_id', $this->admin->id)->get()->getRow('emailed_at'), 'urgent, so emailed');
    }

    public function testADumpWithoutItsClosingLineFails(): void
    {
        $row = $this->take('2026-10-10 12:00:00', 'unfinished');

        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('incomplete', (string) $row['message']);
    }

    public function testTheNightlyTriggerDoesNotRepeatTheAlert(): void
    {
        $this->take('2026-10-10 12:00:00', 'truncated');

        (new NotificationTriggers())->run(new DateTimeImmutable('2026-10-10 13:00:00'));

        $this->assertSame(1, $this->alerts());
    }

    public function testBackupsThatKeepFailingAlertOnceUntilOneSucceeds(): void
    {
        // The scheduler retries every five minutes while nothing succeeds: one alert, not one per attempt.
        $this->take('2026-10-10 12:00:00', 'truncated');
        $this->take('2026-10-10 12:05:00', 'truncated');
        $this->take('2026-10-10 12:10:00', 'unfinished');
        (new NotificationTriggers())->run(new DateTimeImmutable('2026-10-10 13:00:00'));

        $this->assertSame(1, $this->alerts());

        // A good one ends the streak; the next failure is a new problem, told again.
        $this->take('2026-10-11 12:00:00');
        $this->take('2026-10-12 12:00:00', 'truncated');

        $this->assertSame(2, $this->alerts());
    }

    public function testAFailingRunNeverDeletesAGoodBackup(): void
    {
        for ($day = 1; $day <= 14; $day++) {
            $this->take(sprintf('2026-10-%02d 12:00:00', $day));
        }

        $this->take('2026-10-15 12:00:00', 'truncated');

        $this->assertCount(14, $this->files());
    }

    public function testOnlyGoodBackupsCountTowardTheFourteen(): void
    {
        for ($day = 1; $day <= 14; $day++) {
            $this->take(sprintf('2026-10-%02d 12:00:00', $day));
        }

        // A file a crashed run left behind, newer than every good one.
        file_put_contents($this->runner->folder . DIRECTORY_SEPARATOR . 'mentora-20261015-110000.sql.gz', 'half a dump');

        $newest = $this->take('2026-10-15 12:00:00');

        $files = $this->files();
        $this->assertCount(14, $files);
        $this->assertContains($newest['file_name'], $files);
        $this->assertNotContains('mentora-20261001-120000.sql.gz', $files, 'the oldest good one makes room');
        $this->assertNotContains('mentora-20261015-110000.sql.gz', $files, 'the stray never counted');
    }
}
