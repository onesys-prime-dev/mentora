<?php

declare(strict_types=1);

use App\Services\Monitoring\SystemHealth;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What counts as up, degraded or down: the mappers with plain numbers, and
 * the runner with every probe faked so no real service is touched.
 *
 * @internal
 */
final class SystemHealthTest extends CIUnitTestCase
{
    private const GB = 1073741824;

    /** @return iterable<string, array{float, string}> */
    public static function provideDisk(): iterable
    {
        yield 'plenty free' => [50.0, SystemHealth::UP];
        yield 'just under 90% used' => [10.1, SystemHealth::UP];
        yield '90% used' => [10.0, SystemHealth::DEGRADED];
        yield '96% used' => [4.0, SystemHealth::DEGRADED];
        yield '97% used' => [3.0, SystemHealth::DOWN];
        yield 'nothing free' => [0.0, SystemHealth::DOWN];
    }

    #[DataProvider('provideDisk')]
    public function testDiskUseMapsToAStatus(float $freeGb, string $expected): void
    {
        $this->assertSame($expected, SystemHealth::diskStatus($freeGb * self::GB, 100 * self::GB)['status']);
    }

    public function testWithJobsOnOnlyTheWorkerHeartbeatCounts(): void
    {
        $neverCalled = static fn (): bool => self::fail('The original service must not be asked when jobs are on.');

        $this->assertSame(SystemHealth::UP, SystemHealth::aiStatus(true, 1000, 1030, $neverCalled)['status']);
        $this->assertSame(SystemHealth::DEGRADED, SystemHealth::aiStatus(true, 1000, 1200, $neverCalled)['status']);
        $this->assertSame(SystemHealth::DEGRADED, SystemHealth::aiStatus(true, null, 1200, $neverCalled)['status']);
    }

    public function testWithJobsOffAFreshWorkerWinsOtherwiseTheServiceDecides(): void
    {
        $up   = static fn (): bool => true;
        $down = static fn (): bool => false;

        $this->assertStringContainsString('worker', SystemHealth::aiStatus(false, 1000, 1030, $down)['detail']);
        $this->assertSame(SystemHealth::UP, SystemHealth::aiStatus(false, null, 1200, $up)['status']);
        $this->assertSame(SystemHealth::DEGRADED, SystemHealth::aiStatus(false, 1000, 1200, $down)['status']);
    }

    public function testAnUnreadableDiskIsDegradedNotDown(): void
    {
        $this->assertSame(SystemHealth::DEGRADED, SystemHealth::diskStatus(0.0, 0.0)['status']);
    }

    public function testASlowDatabaseIsDegraded(): void
    {
        $this->assertSame(SystemHealth::UP, SystemHealth::databaseStatus(999)['status']);
        $this->assertSame(SystemHealth::DEGRADED, SystemHealth::databaseStatus(1000)['status']);
    }

    public function testTheOverallStatusIsTheWorst(): void
    {
        $up   = ['status' => SystemHealth::UP, 'detail' => ''];
        $warn = ['status' => SystemHealth::DEGRADED, 'detail' => ''];
        $down = ['status' => SystemHealth::DOWN, 'detail' => ''];

        $this->assertSame(SystemHealth::UP, SystemHealth::overall(['a' => $up, 'b' => $up]));
        $this->assertSame(SystemHealth::DEGRADED, SystemHealth::overall(['a' => $up, 'b' => $warn]));
        $this->assertSame(SystemHealth::DOWN, SystemHealth::overall(['a' => $warn, 'b' => $down, 'c' => $up]));
    }

    public function testTheRunnerAsksEveryCheckInOrderAndUsesTheFakes(): void
    {
        $ok = static fn (): array => ['status' => SystemHealth::UP, 'detail' => 'fine'];

        $results = (new SystemHealth(['database' => $ok, 'storage' => $ok, 'disk' => $ok, 'ai' => $ok, 'jobs' => $ok, 'backups' => $ok]))->run();

        $this->assertSame(['database', 'storage', 'disk', 'ai', 'jobs', 'backups'], array_keys($results));
        $this->assertSame('fine', $results['ai']['detail']);
    }

    public function testACheckThatThrowsIsDownAndDoesNotStopTheRest(): void
    {
        $ok = static fn (): array => ['status' => SystemHealth::UP, 'detail' => 'fine'];

        $results = (new SystemHealth([
            'database' => static function (): array {
                throw new RuntimeException('secret detail that must not be shown');
            },
            'storage' => $ok, 'disk' => $ok, 'ai' => $ok, 'jobs' => $ok, 'backups' => $ok,
        ]))->run();

        $this->assertSame(SystemHealth::DOWN, $results['database']['status']);
        $this->assertStringNotContainsString('secret', $results['database']['detail']);
        $this->assertSame(SystemHealth::UP, $results['jobs']['status']);
    }
}
