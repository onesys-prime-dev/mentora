<?php

declare(strict_types=1);

use App\Services\Monitoring\AdminHome;
use App\Services\Monitoring\SystemHealth;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * What the admin home page works out: what needs attention (and in what
 * order), the snapshot, and recent activity, against known fixtures.
 *
 * @internal
 */
final class AdminHomeTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->now = new DateTimeImmutable('2026-10-10 12:00:00');

        // A recent good backup, so these tests are about the rest.
        db_connect()->table('backups')->insert(['status' => 'succeeded', 'trigger' => 'scheduled', 'created_at' => '2026-10-10 06:00:00', 'finished_at' => '2026-10-10 06:00:00']);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function healthy(): SystemHealth
    {
        $up = static fn (): array => ['status' => SystemHealth::UP, 'detail' => 'fine'];

        return new SystemHealth(['database' => $up, 'storage' => $up, 'disk' => $up, 'ai' => $up, 'jobs' => $up, 'backups' => $up]);
    }

    private function subscribed(string $code, int $endsInDays, ?int $seats = null): int
    {
        $id = PeopleFactory::institution($code, 'active', $code . ' College');
        PeopleFactory::subscribe($id, PeopleFactory::plan('Plan' . $code, $seats));
        db_connect()->table('subscriptions')->where('institution_id', $id)->update(['end_date' => $this->now->modify("{$endsInDays} days")->format('Y-m-d')]);

        return $id;
    }

    public function testNothingToReportWhenEverythingIsFine(): void
    {
        $this->subscribed('FAR', 200);

        $this->assertSame([], (new AdminHome($this->healthy()))->needsAttention($this->now));
    }

    public function testEachSubscriptionStageIsListedAndTheUrgentOnesComeFirst(): void
    {
        $soon  = $this->subscribed('SOON', 10);
        $grace = $this->subscribed('GRACE', -3);
        $old   = $this->subscribed('OLD', -100);

        $items = (new AdminHome($this->healthy()))->needsAttention($this->now);

        $this->assertCount(3, $items);
        $this->assertSame(['urgent', 'warning', 'warning'], array_column($items, 'severity'));
        $this->assertStringContainsString('OLD College', $items[0]['text']);
        $this->assertSame("admin/institutions/{$old}/subscription", $items[0]['link']);
        $this->assertContains("admin/institutions/{$soon}/subscription", array_column($items, 'link'));
        $this->assertContains("admin/institutions/{$grace}/subscription", array_column($items, 'link'));
    }

    public function testLimitsNearAndAtTheirCapAreListed(): void
    {
        $id = $this->subscribed('SEATS', 200, 10);

        foreach (range(1, 9) as $n) {
            PeopleFactory::person('student', 'Stu', "N{$n}", null, $id);
        }

        $near = (new AdminHome($this->healthy()))->needsAttention($this->now);
        $this->assertCount(1, $near);
        $this->assertSame('warning', $near[0]['severity']);
        $this->assertStringContainsString('nearly full', $near[0]['text']);

        PeopleFactory::person('student', 'Stu', 'N10', null, $id);

        $full = (new AdminHome($this->healthy()))->needsAttention($this->now);
        $this->assertSame('urgent', $full[0]['severity']);
        $this->assertStringContainsString('limit reached', $full[0]['text']);
    }

    public function testABackupProblemIsUrgent(): void
    {
        db_connect()->table('backups')->truncate();

        $items = (new AdminHome($this->healthy()))->needsAttention($this->now);

        $this->assertSame('urgent', $items[0]['severity']);
        $this->assertSame('admin/backups', $items[0]['link']);
    }

    public function testAServiceThatIsDownIsListedWithAPointerToTheStatusPage(): void
    {
        $up   = static fn (): array => ['status' => SystemHealth::UP, 'detail' => 'fine'];
        $down = static fn (): array => ['status' => SystemHealth::DOWN, 'detail' => 'The database does not answer.'];

        $items = (new AdminHome(new SystemHealth(['database' => $down, 'storage' => $up, 'disk' => $up, 'ai' => $up, 'jobs' => $up, 'backups' => $up])))->needsAttention($this->now);

        $this->assertCount(1, $items);
        $this->assertSame('urgent', $items[0]['severity']);
        $this->assertSame('admin/monitoring', $items[0]['link']);
        $this->assertStringContainsString('Database', $items[0]['text']);
    }

    public function testTheSnapshotCountsMatchTheFixtures(): void
    {
        PeopleFactory::institution('A1', 'active');
        PeopleFactory::institution('A2', 'active');
        PeopleFactory::institution('P1', 'pending');
        PeopleFactory::institution('R1', 'read_only');
        $gone = PeopleFactory::institution('X1', 'past');
        db_connect()->table('institutions')->where('id', $gone)->update(['deleted_at' => '2026-10-01 00:00:00']);

        PeopleFactory::person('student', 'Ann', 'One', null, null, true, '2026-10-10 08:00:00');
        PeopleFactory::person('student', 'Ben', 'Two', null, null, true, '2026-10-01 08:00:00');
        PeopleFactory::person('student', 'Off', 'Line', null, null, false);

        $snapshot = (new AdminHome($this->healthy()))->snapshot($this->now);

        $this->assertSame(['pending' => 1, 'active' => 2, 'read_only' => 1, 'past' => 0], $snapshot['byStatus']);
        $this->assertSame(4, $snapshot['institutions']);
        $this->assertSame(2, $snapshot['users']);
        $this->assertSame(1, $snapshot['signedInToday']);
    }

    public function testRecentActivityIsTheLatestEntriesNewestFirst(): void
    {
        $logger = service('auditLogger');

        foreach (range(1, 12) as $n) {
            $logger->log("thing.number{$n}", null, ['actor_label' => 'tester']);
        }

        $rows = (new AdminHome($this->healthy()))->recentActivity(5);

        $this->assertCount(5, $rows);
        $this->assertSame('thing.number12', $rows[0]['action']);
    }
}
