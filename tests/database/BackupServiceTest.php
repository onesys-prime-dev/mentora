<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Notifications\NotificationTriggers;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeBackupRunner;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Backups: asking for one, what the server job takes and records, the daily
 * schedule, file retention, and the alert and notification when they go wrong.
 *
 * @internal
 */
final class BackupServiceTest extends DatabaseTestCase
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

    /** @return list<array<string, mixed>> oldest first */
    private function rows(): array
    {
        return db_connect()->table('backups')->orderBy('id')->get()->getResultArray();
    }

    public function testRequestingRecordsItAndIsAudited(): void
    {
        $admin = PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com');

        $id = $this->service->request($admin, $this->now);

        $row = $this->rows()[0];

        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('requested', $row['status']);
        $this->assertSame('manual', $row['trigger']);
        $this->assertSame('ada@example.com', $row['requested_by_label']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'backup.requested')->countAllResults());
        $this->assertSame(0, $this->runner->calls, 'the web request makes no dump');
    }

    public function testASecondRequestWhileOneIsWaitingIsRefused(): void
    {
        $admin = PeopleFactory::person('admin');
        $this->service->request($admin, $this->now);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already waiting');

        $this->service->request($admin, $this->now);
    }

    public function testTheServerJobTakesWhatWasRequested(): void
    {
        $this->service->request(PeopleFactory::person('admin'), $this->now);

        $ran = $this->service->runDue($this->now);

        $row = $this->rows()[0];

        $this->assertCount(1, $ran);
        $this->assertSame('succeeded', $row['status']);
        $this->assertSame('mentora-20261010-120000.sql.gz', $row['file_name']);
        $this->assertGreaterThan(0, (int) $row['size_bytes']);
        $this->assertFileExists($this->runner->folder . DIRECTORY_SEPARATOR . $row['file_name']);
        $this->assertNull($row['message']);
    }

    public function testAFailedDumpIsRecordedWithItsReason(): void
    {
        $this->runner->fail = true;
        $this->service->request(PeopleFactory::person('admin'), $this->now);

        $this->service->runDue($this->now);

        $row = $this->rows()[0];

        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('access denied', (string) $row['message']);
        $this->assertNull($row['file_name']);
    }

    public function testAnEmptyDumpCountsAsAFailure(): void
    {
        Services::injectMock('backupRunner', new class () implements App\Services\Backup\BackupRunner {
            public function dump(string $path): void
            {
                file_put_contents($path, '');
            }
        });

        $this->service->request(PeopleFactory::person('admin'), $this->now);
        $this->service->runDue($this->now);

        $this->assertSame('failed', $this->rows()[0]['status']);
        $this->assertStringContainsString('empty', (string) $this->rows()[0]['message']);
    }

    public function testTheDailyBackupIsTakenWhenNoneIsRecent(): void
    {
        $this->assertCount(1, $this->service->runDue($this->now));
        $this->assertSame('scheduled', $this->rows()[0]['trigger']);
    }

    public function testNoDailyBackupWhenOneSucceededRecently(): void
    {
        $this->service->runDue($this->now);

        $this->assertSame([], $this->service->runDue($this->now->modify('+22 hours')));
        $this->assertCount(1, $this->rows());

        $this->assertCount(1, $this->service->runDue($this->now->modify('+24 hours')));
    }

    public function testNothingIsDoneWhileAnotherIsRunning(): void
    {
        db_connect()->table('backups')->insert(['status' => 'running', 'trigger' => 'manual', 'created_at' => '2026-10-10 11:59:00']);

        $this->assertSame([], $this->service->runDue($this->now));
    }

    public function testOnlyTheNewestFilesAreKept(): void
    {
        for ($i = 0; $i < BackupService::KEEP_FILES + 3; $i++) {
            $this->service->take((int) db_connect()->table('backups')->insert(['status' => 'requested', 'trigger' => 'manual', 'created_at' => '2026-10-01 00:00:00']) ? (int) db_connect()->insertID() : 0, $this->now->modify("+{$i} seconds"));
        }

        $this->assertCount(BackupService::KEEP_FILES, glob($this->runner->folder . DIRECTORY_SEPARATOR . 'mentora-*.sql.gz'));
    }

    public function testTheAlertFollowsTheHistory(): void
    {
        $this->assertSame('none', $this->service->alert($this->now)['kind']);

        $this->service->runDue($this->now);
        $this->assertNull($this->service->alert($this->now));

        $this->assertSame('stale', $this->service->alert($this->now->modify('+25 hours'))['kind']);

        $this->runner->fail = true;
        $this->service->request(PeopleFactory::person('admin'), $this->now->modify('+1 hour'));
        $this->service->runDue($this->now->modify('+1 hour'));

        $this->assertSame('failed', $this->service->alert($this->now->modify('+2 hours'))['kind']);
    }

    public function testAdministratorsAreToldOnceAboutAProblemAndItIsUrgent(): void
    {
        $mailer = FakeEmail::install();
        $admin  = (int) PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com')->id;

        $first = (new NotificationTriggers())->run($this->now);
        $again = (new NotificationTriggers())->run($this->now->modify('+1 hour'));

        $note = db_connect()->table('notifications')->where('user_id', $admin)->get()->getRowArray();

        $this->assertSame(1, $first);
        $this->assertSame(0, $again);
        $this->assertSame('backup.none', $note['type']);
        $this->assertSame(1, (int) $note['urgent']);
        $this->assertSame('admin/backups', $note['link']);
        $this->assertCount(1, $mailer->sent);
    }

    public function testNothingIsToldWhileBackupsAreFine(): void
    {
        PeopleFactory::person('admin');
        $this->service->runDue(new DateTimeImmutable());

        $this->assertSame(0, (new NotificationTriggers())->run(new DateTimeImmutable()));
    }
}
