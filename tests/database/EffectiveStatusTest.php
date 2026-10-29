<?php

declare(strict_types=1);

use App\Services\Subscriptions\EffectiveStatus;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * An institution's access follows its subscription dates even when the nightly
 * job has not run (audit decision 6.1). With the default timeline (14 days'
 * grace, then 30 read-only) and today 2026-10-12.
 *
 * @internal
 */
final class EffectiveStatusTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-12 08:00:00');
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function provideCombinations(): iterable
    {
        yield 'dates say read-only, job never ran' => ['active', '2026-09-12', 'read_only'];
        yield 'dates say past'                       => ['active', '2026-07-01', 'past'];
        yield 'still in grace'                       => ['active', '2026-10-05', 'active'];
        yield 'manual suspension is kept'            => ['past', '2027-10-01', 'past'];
        yield 'already read-only, renewal not run'   => ['read_only', '2027-10-01', 'read_only'];
        yield 'pending is left alone'                => ['pending', '2026-07-01', 'pending'];
        yield 'no subscription keeps the stored'     => ['active', null, 'active'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideCombinations')]
    public function testTheWorseOfTheStoredStatusAndTheDatesWins(string $stored, ?string $endDate, string $expected): void
    {
        $this->assertSame($expected, EffectiveStatus::combine($stored, $endDate, 0, $this->today()));
    }

    public function testOfReadsTheInstitutionAndItsSubscription(): void
    {
        $db = db_connect();
        $db->table('plans')->insert(['name' => 'Standard', 'status' => 'active']);
        $plan = (int) $db->insertID();
        $db->table('institutions')->insert(['name' => 'Lapsed U', 'code' => 'LAPSE', 'status' => 'active']);
        $id = (int) $db->insertID();
        $db->table('subscriptions')->insert(['institution_id' => $id, 'plan_id' => $plan, 'start_date' => '2025-01-01', 'end_date' => '2025-09-01']);

        $this->assertSame('past', EffectiveStatus::of($id, $this->today()));
    }
}
