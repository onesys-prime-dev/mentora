<?php

declare(strict_types=1);

use App\Services\Monitoring\UsageReport;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The numbers on the system status page: usage against plans per institution,
 * the subscription watch, and platform counts.
 *
 * @internal
 */
final class UsageReportTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-10-10 12:00:00');
    }

    public function testEachOnboardedInstitutionGetsARowWithItsUsage(): void
    {
        $acme = PeopleFactory::institution('ACME', 'active', 'Acme University');
        PeopleFactory::institution('NEWCO', 'pending', 'Pending College');
        PeopleFactory::subscribe($acme, PeopleFactory::plan('Standard', 2));
        PeopleFactory::person('student', 'Ann', 'One', null, $acme);
        PeopleFactory::person('student', 'Ben', 'Two', null, $acme);
        PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $acme);
        PeopleFactory::person('student', 'Off', 'Line', null, $acme, false);

        $rows = (new UsageReport())->institutions($this->now);

        $this->assertCount(1, $rows, 'a pending institution is not listed');
        $this->assertSame('Acme University', $rows[0]['name']);
        $this->assertSame('Standard', $rows[0]['plan']);
        $this->assertSame(3, $rows[0]['activeUsers'], 'deactivated people are not counted');
        $this->assertSame(2, $rows[0]['seats']['used']);
        $this->assertSame('full', $rows[0]['seats']['state']);
    }

    public function testAnInstitutionWithNoSubscriptionHasNoPlanAndNoLimits(): void
    {
        PeopleFactory::institution('ACME', 'active', 'Acme University');

        $row = (new UsageReport())->institutions($this->now)[0];

        $this->assertNull($row['plan']);
        $this->assertSame('unlimited', $row['seats']['state']);
    }

    public function testTheWatchListsOnlySubscriptionsThatNeedAttentionSoonestFirst(): void
    {
        foreach ([['FAR', 200], ['SOON', 10], ['GRACE', -3], ['OLD', -100]] as [$code, $days]) {
            $id = PeopleFactory::institution($code, 'active', $code . ' College');
            PeopleFactory::subscribe($id, PeopleFactory::plan('Plan' . $code));
            db_connect()->table('subscriptions')->where('institution_id', $id)->update(['end_date' => $this->now->modify("{$days} days")->format('Y-m-d')]);
        }

        $watch = (new UsageReport())->subscriptionWatch($this->now);

        $this->assertSame(['OLD College', 'GRACE College', 'SOON College'], array_column($watch, 'name'));
        $this->assertSame(['expired', 'grace', 'expiring'], array_column($watch, 'state'));
    }

    public function testPlatformCounts(): void
    {
        $acme = PeopleFactory::institution('ACME');
        PeopleFactory::person('student', 'Ann', 'One', null, $acme, true, '2026-10-10 09:00:00');
        PeopleFactory::person('student', 'Ben', 'Two', null, $acme, true, '2026-10-01 09:00:00');
        PeopleFactory::person('student', 'Off', 'Line', null, $acme, false);

        foreach (['2026-10-10 11:00:00', '2026-10-08 11:00:00', '2026-10-01 11:00:00'] as $when) {
            db_connect()->table('auth_logins')->insert(['ip_address' => '1.1.1.1', 'user_agent' => 't', 'id_type' => 'email_password', 'identifier' => 'x@example.com', 'date' => $when, 'success' => 0]);
        }

        $counts = (new UsageReport())->platform($this->now);

        $this->assertSame(1, $counts['institutions']);
        $this->assertSame(2, $counts['users']);
        $this->assertSame(1, $counts['signedInToday']);
        $this->assertSame(1, $counts['failedLogins24h']);
        $this->assertSame(2, $counts['failedLogins7d']);
    }
}
