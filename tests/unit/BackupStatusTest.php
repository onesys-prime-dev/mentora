<?php

declare(strict_types=1);

use App\Services\Backup\BackupStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * When the backups are worth worrying about: failed, stale, or none. Pure
 * logic, the time is passed in.
 *
 * @internal
 */
final class BackupStatusTest extends CIUnitTestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-10 12:00:00');
    }

    public function testARecentGoodBackupIsFine(): void
    {
        $this->assertNull(BackupStatus::assess('2026-10-10 03:00:00', null, null, $this->now(), 24));
    }

    public function testStaleOnlyAfterTheAlertAge(): void
    {
        $this->assertNull(BackupStatus::assess('2026-10-09 12:00:00', null, null, $this->now(), 24), 'exactly 24 hours is still fine');

        $alert = BackupStatus::assess('2026-10-09 11:59:59', null, null, $this->now(), 24);

        $this->assertSame(BackupStatus::STALE, $alert['kind']);
        $this->assertStringContainsString('24 hours', $alert['message']);
    }

    public function testTheAlertAgeIsTheSettings(): void
    {
        $this->assertNull(BackupStatus::assess('2026-10-08 12:00:00', null, null, $this->now(), 72));
        $this->assertSame(BackupStatus::STALE, BackupStatus::assess('2026-10-08 12:00:00', null, null, $this->now(), 24)['kind']);
    }

    public function testNeverHavingHadOneIsAnAlert(): void
    {
        $this->assertSame(BackupStatus::NONE, BackupStatus::assess(null, null, null, $this->now(), 24)['kind']);
    }

    public function testAFailureAfterTheLastSuccessOutranksStale(): void
    {
        $alert = BackupStatus::assess('2026-10-01 00:00:00', '2026-10-10 03:00:00', 'disk full', $this->now(), 24);

        $this->assertSame(BackupStatus::FAILED, $alert['kind']);
        $this->assertStringContainsString('disk full', $alert['message']);
    }

    public function testAnOldFailureBeforeAGoodBackupIsForgotten(): void
    {
        $this->assertNull(BackupStatus::assess('2026-10-10 06:00:00', '2026-10-10 03:00:00', 'disk full', $this->now(), 24));
    }

    public function testFailureWithNoSuccessAtAll(): void
    {
        $this->assertSame(BackupStatus::FAILED, BackupStatus::assess(null, '2026-10-10 03:00:00', null, $this->now(), 24)['kind']);
    }
}
