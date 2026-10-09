<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionFilters;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Reading the institutions list's filters from a query string: valid values
 * are kept, anything else falls back, and nothing typed in the address can
 * fail or reach a column that is not listed.
 *
 * @internal
 */
final class InstitutionFiltersTest extends CIUnitTestCase
{
    public function testNothingGivenMeansTheDefaults(): void
    {
        $filters = InstitutionFilters::fromInput([]);

        $this->assertNull($filters->q);
        $this->assertNull($filters->status);
        $this->assertSame('name', $filters->sort);
        $this->assertSame('asc', $filters->dir);
        $this->assertSame(1, $filters->page);
        $this->assertSame(20, $filters->perPage);
        $this->assertFalse($filters->isFiltered());
        $this->assertSame([], $filters->toQuery());
    }

    public function testValidValuesAreKept(): void
    {
        $filters = InstitutionFilters::fromInput(['q' => ' acme ', 'status' => 'past', 'sort' => 'created_at', 'dir' => 'DESC', 'page' => '3', 'per_page' => '50']);

        $this->assertSame('acme', $filters->q);
        $this->assertSame('past', $filters->status);
        $this->assertSame('created_at', $filters->sort);
        $this->assertSame('desc', $filters->dir);
        $this->assertSame(3, $filters->page);
        $this->assertSame(50, $filters->perPage);
        $this->assertTrue($filters->isFiltered());
    }

    public function testEveryListedStatusAndSortColumnIsAccepted(): void
    {
        foreach (InstitutionFilters::STATUSES as $status) {
            $this->assertSame($status, InstitutionFilters::fromInput(['status' => $status])->status);
        }

        foreach (InstitutionFilters::SORTABLE as $column) {
            $this->assertSame($column, InstitutionFilters::fromInput(['sort' => $column])->sort);
        }
    }

    public function testUnknownStatusesAndSortColumnsFallBack(): void
    {
        $this->assertNull(InstitutionFilters::fromInput(['status' => 'inactive'])->status);
        $this->assertNull(InstitutionFilters::fromInput(['status' => 'all'])->status);
        $this->assertNull(InstitutionFilters::fromInput(['status' => ['past']])->status);
        $this->assertSame('name', InstitutionFilters::fromInput(['sort' => 'deleted_at'])->sort);
        $this->assertSame('name', InstitutionFilters::fromInput(['sort' => 'name; DROP TABLE institutions'])->sort);
        $this->assertSame('name', InstitutionFilters::fromInput(['sort' => ['code']])->sort);
    }

    public function testThePlanFilterIsAPositiveWholeNumber(): void
    {
        $this->assertSame(7, InstitutionFilters::fromInput(['plan' => '7'])->planId);
        $this->assertTrue(InstitutionFilters::fromInput(['plan' => '7'])->isFiltered());

        foreach (['0', '-1', 'gold', '2.5', ['3'], ''] as $bad) {
            $this->assertNull(InstitutionFilters::fromInput(['plan' => $bad])->planId);
        }

        $this->assertSame(['plan' => 7], InstitutionFilters::fromInput(['plan' => '7'])->toQuery());
    }

    public function testAnythingButDescIsAscending(): void
    {
        $this->assertSame('asc', InstitutionFilters::fromInput(['dir' => 'sideways'])->dir);
        $this->assertSame('asc', InstitutionFilters::fromInput(['dir' => ['desc']])->dir);
    }

    public function testBadNumbersFallBack(): void
    {
        foreach (['0', '-4', 'abc', '2.5', '12345678901', ['1']] as $bad) {
            $filters = InstitutionFilters::fromInput(['page' => $bad, 'per_page' => $bad]);

            $this->assertSame(1, $filters->page);
            $this->assertSame(20, $filters->perPage);
        }
    }

    public function testThePageSizeStaysWithinLimits(): void
    {
        $this->assertSame(100, InstitutionFilters::fromInput(['per_page' => '5000'])->perPage);
        $this->assertSame(1, InstitutionFilters::fromInput(['per_page' => '1'])->perPage);
    }

    public function testTextIsTrimmedBlankIsNothingAndLongTextIsCut(): void
    {
        $this->assertNull(InstitutionFilters::fromInput(['q' => '   '])->q);
        $this->assertNull(InstitutionFilters::fromInput(['q' => ['x']])->q);
        $this->assertSame(100, mb_strlen((string) InstitutionFilters::fromInput(['q' => str_repeat('a', 500)])->q));
    }

    public function testTheQueryLeavesOutDefaultsAndAppliesOverrides(): void
    {
        $filters = InstitutionFilters::fromInput(['status' => 'active', 'sort' => 'code', 'dir' => 'desc', 'page' => '2']);

        $this->assertSame(['status' => 'active', 'sort' => 'code', 'dir' => 'desc', 'page' => 2], $filters->toQuery());
        $this->assertSame(['status' => 'active', 'sort' => 'code', 'dir' => 'desc'], $filters->toQuery(['page' => null]));
        $this->assertSame(['status' => 'active', 'sort' => 'code', 'dir' => 'desc', 'page' => 3], $filters->toQuery(['page' => 3]));
    }

    public function testSortAndPagingAloneDoNotCountAsFiltering(): void
    {
        $this->assertFalse(InstitutionFilters::fromInput(['sort' => 'code', 'dir' => 'desc', 'page' => '4', 'per_page' => '10'])->isFiltered());
    }
}
