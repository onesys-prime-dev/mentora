<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogFilters;
use App\Services\Audit\AuditLogSearch;
use Tests\Support\DatabaseTestCase;

/**
 * Searching the audit log: each filter, free-text search, sort, paging, the
 * export reader, and the rule that a source address that may not be shown is
 * never selected or searched.
 *
 * @internal
 */
final class AuditLogSearchTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    /** @param array<string, mixed> $row */
    private function entry(array $row = []): int
    {
        db_connect()->table('audit_logs')->insert(array_merge([
            'actor_user_id' => null,
            'actor_label'   => 'sam@mentora.test',
            'acting_role'   => 'student',
            'action'        => 'auth.login',
            'target_type'   => 'user',
            'target_id'     => '1',
            'institution_id' => null,
            'ip_address'    => '203.0.113.9',
            'created_at'    => '2026-03-10 12:00:00',
        ], $row));

        return (int) db_connect()->insertID();
    }

    private function institution(string $name): int
    {
        db_connect()->table('institutions')->insert(['name' => $name, 'code' => strtoupper(substr(md5($name), 0, 8))]);

        return (int) db_connect()->insertID();
    }

    /** @param array<string, mixed> $input */
    private function ids(array $input, bool $showIp = true): array
    {
        $result = (new AuditLogSearch($showIp))->page(AuditLogFilters::fromInput($input));

        return array_map('intval', array_column($result['rows'], 'id'));
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function testNoFiltersReturnsEverythingNewestFirst(): void
    {
        $old = $this->entry(['created_at' => '2026-01-01 08:00:00']);
        $new = $this->entry(['created_at' => '2026-02-01 08:00:00']);

        $this->assertSame([$new, $old], $this->ids([]));
    }

    public function testTheDateRangeIncludesBothEndDaysInFull(): void
    {
        $before = $this->entry(['created_at' => '2026-03-09 23:59:59']);
        $first  = $this->entry(['created_at' => '2026-03-10 00:00:00']);
        $last   = $this->entry(['created_at' => '2026-03-12 23:59:59']);
        $after  = $this->entry(['created_at' => '2026-03-13 00:00:00']);

        $this->assertEqualsCanonicalizing([$first, $last], $this->ids(['from' => '2026-03-10', 'to' => '2026-03-12']));
        $this->assertEqualsCanonicalizing([$last, $after], $this->ids(['from' => '2026-03-11']));
        $this->assertEqualsCanonicalizing([$before, $first], $this->ids(['to' => '2026-03-10']));
    }

    public function testFilterByActorUserId(): void
    {
        $mine  = $this->entry(['actor_user_id' => 41]);
        $other = $this->entry(['actor_user_id' => 42]);

        $this->assertSame([$mine], $this->ids(['actor' => '41']));
        $this->assertNotContains($other, $this->ids(['actor' => '41']));
    }

    public function testFilterByActorEmailMatchesPartsOfIt(): void
    {
        $sam = $this->entry(['actor_label' => 'sam@mentora.test']);
        $this->entry(['actor_label' => 'kim@mentora.test']);

        $this->assertSame([$sam], $this->ids(['actor' => 'sam@']));
    }

    public function testFilterByInstitution(): void
    {
        $acme   = $this->institution('Acme');
        $globex = $this->institution('Globex');
        $inAcme = $this->entry(['institution_id' => $acme]);
        $this->entry(['institution_id' => $globex]);
        $this->entry();

        $this->assertSame([$inAcme], $this->ids(['institution' => (string) $acme]));
    }

    public function testFilterByActionIsExact(): void
    {
        $login = $this->entry(['action' => 'auth.login']);
        $this->entry(['action' => 'auth.login_failed']);

        $this->assertSame([$login], $this->ids(['action' => 'auth.login']));
    }

    public function testFilterByTargetType(): void
    {
        $setting = $this->entry(['target_type' => 'setting']);
        $this->entry(['target_type' => 'user']);

        $this->assertSame([$setting], $this->ids(['target_type' => 'setting']));
    }

    public function testFiltersCombine(): void
    {
        $hit = $this->entry(['action' => 'auth.login', 'actor_label' => 'sam@mentora.test', 'created_at' => '2026-03-10 09:00:00']);
        $this->entry(['action' => 'auth.logout', 'actor_label' => 'sam@mentora.test', 'created_at' => '2026-03-10 09:00:00']);
        $this->entry(['action' => 'auth.login', 'actor_label' => 'kim@mentora.test', 'created_at' => '2026-03-10 09:00:00']);
        $this->entry(['action' => 'auth.login', 'actor_label' => 'sam@mentora.test', 'created_at' => '2026-04-10 09:00:00']);

        $this->assertSame([$hit], $this->ids(['action' => 'auth.login', 'actor' => 'sam', 'to' => '2026-03-31']));
    }

    /*
    |--------------------------------------------------------------------------
    | Free-text search
    |--------------------------------------------------------------------------
    */

    public function testSearchLooksInActorActionTargetAndInstitutionName(): void
    {
        $acme    = $this->institution('Acme University');
        $byActor = $this->entry(['actor_label' => 'zed@mentora.test']);
        $byAct   = $this->entry(['action' => 'institution.suspended']);
        $byTgt   = $this->entry(['target_type' => 'plan', 'target_id' => 'gold-99']);
        $byInst  = $this->entry(['institution_id' => $acme]);

        $this->assertSame([$byActor], $this->ids(['q' => 'zed@']));
        $this->assertSame([$byAct], $this->ids(['q' => 'suspended']));
        $this->assertSame([$byTgt], $this->ids(['q' => 'gold-99']));
        $this->assertSame([$byInst], $this->ids(['q' => 'Acme Univ']));
    }

    public function testSearchTreatsPercentAndUnderscoreAsPlainCharacters(): void
    {
        $this->entry(['actor_label' => 'a@x.test']);
        $literal = $this->entry(['actor_label' => '100%_sure@x.test']);

        $this->assertSame([$literal], $this->ids(['q' => '%_']));
        $this->assertSame([], $this->ids(['q' => '%%%']));
    }

    public function testTheSourceAddressIsSearchableOnlyWhenItMayBeShown(): void
    {
        $this->entry(['ip_address' => '198.51.100.77']);

        $this->assertCount(1, $this->ids(['q' => '198.51.100.77'], showIp: true));
        $this->assertSame([], $this->ids(['q' => '198.51.100.77'], showIp: false), 'a hidden address must not be discoverable by searching');
    }

    /*
    |--------------------------------------------------------------------------
    | The address column
    |--------------------------------------------------------------------------
    */

    public function testTheAddressIsReturnedOnlyWhenItMayBeShown(): void
    {
        $this->entry(['ip_address' => '203.0.113.9']);

        $shown  = (new AuditLogSearch(true))->page(AuditLogFilters::fromInput([]))['rows'][0];
        $hidden = (new AuditLogSearch(false))->page(AuditLogFilters::fromInput([]))['rows'][0];

        $this->assertSame('203.0.113.9', $shown['ip_address']);
        $this->assertArrayNotHasKey('ip_address', $hidden);
        $this->assertStringNotContainsString('203.0.113.9', json_encode($hidden));
    }

    /*
    |--------------------------------------------------------------------------
    | Sort and paging
    |--------------------------------------------------------------------------
    */

    public function testSortByActionBothWays(): void
    {
        $b = $this->entry(['action' => 'b.thing']);
        $a = $this->entry(['action' => 'a.thing']);
        $c = $this->entry(['action' => 'c.thing']);

        $this->assertSame([$a, $b, $c], $this->ids(['sort' => 'action', 'dir' => 'asc']));
        $this->assertSame([$c, $b, $a], $this->ids(['sort' => 'action', 'dir' => 'desc']));
    }

    public function testEntriesWithTheSameSortValueKeepAStableOrder(): void
    {
        $first  = $this->entry(['created_at' => '2026-03-10 12:00:00']);
        $second = $this->entry(['created_at' => '2026-03-10 12:00:00']);

        $this->assertSame([$first, $second], $this->ids(['dir' => 'asc']));
        $this->assertSame([$second, $first], $this->ids(['dir' => 'desc']));
    }

    public function testPagingSplitsTheResultAndReportsTheTotal(): void
    {
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = $this->entry(['created_at' => sprintf('2026-03-%02d 10:00:00', $i)]);
        }

        $search = new AuditLogSearch(true);

        $page1 = $search->page(AuditLogFilters::fromInput(['per_page' => '3', 'page' => '1']));
        $page3 = $search->page(AuditLogFilters::fromInput(['per_page' => '3', 'page' => '3']));
        $page9 = $search->page(AuditLogFilters::fromInput(['per_page' => '3', 'page' => '9']));

        $this->assertSame(7, $page1['total']);
        $this->assertSame([$ids[6], $ids[5], $ids[4]], array_map('intval', array_column($page1['rows'], 'id')));
        $this->assertSame([$ids[0]], array_map('intval', array_column($page3['rows'], 'id')));
        $this->assertSame([], $page9['rows']);
        $this->assertSame(7, $page9['total']);
    }

    public function testTheInstitutionNameComesWithTheRow(): void
    {
        $acme = $this->institution('Acme');
        $this->entry(['institution_id' => $acme]);

        $this->assertSame('Acme', (new AuditLogSearch(true))->page(AuditLogFilters::fromInput([]))['rows'][0]['institution_name']);
    }

    /*
    |--------------------------------------------------------------------------
    | Export reader
    |--------------------------------------------------------------------------
    */

    public function testTheExportReaderReturnsEveryMatchOldestFirst(): void
    {
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->entry(['action' => $i % 2 === 0 ? 'even' : 'odd']);
        }

        $all  = iterator_to_array((new AuditLogSearch(false))->each(AuditLogFilters::fromInput([]), 100), false);
        $even = iterator_to_array((new AuditLogSearch(false))->each(AuditLogFilters::fromInput(['action' => 'even']), 100), false);

        $this->assertSame($ids, array_map('intval', array_column($all, 'id')));
        $this->assertSame([$ids[1], $ids[3]], array_map('intval', array_column($even, 'id')));
    }

    public function testTheExportReaderStopsAtTheCap(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->entry();
        }

        $rows = iterator_to_array((new AuditLogSearch(false))->each(AuditLogFilters::fromInput([]), 3), false);

        $this->assertCount(3, $rows);
    }

    public function testTheExportReaderCrossesBatchBoundaries(): void
    {
        for ($i = 0; $i < 1005; $i++) {
            $this->entry();
        }

        $rows = iterator_to_array((new AuditLogSearch(false))->each(AuditLogFilters::fromInput([]), 5000), false);

        $this->assertCount(1005, $rows);
        $this->assertCount(1005, array_unique(array_column($rows, 'id')), 'no row is read twice');
    }

    public function testTheListOfActionsIsDistinctAndSorted(): void
    {
        $this->entry(['action' => 'b.two']);
        $this->entry(['action' => 'a.one']);
        $this->entry(['action' => 'b.two']);

        $this->assertSame(['a.one', 'b.two'], (new AuditLogSearch(false))->actions());
    }
}
