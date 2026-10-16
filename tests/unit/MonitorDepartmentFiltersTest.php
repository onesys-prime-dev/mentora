<?php

declare(strict_types=1);

use App\Services\Courses\MonitorDepartmentFilters;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Monitor Department reads its filters, grouping and sort from the address:
 * anything invalid is dropped or defaulted, never an error.
 *
 * @internal
 */
final class MonitorDepartmentFiltersTest extends CIUnitTestCase
{
    public function testAnEmptyAddressGivesTheDefaults(): void
    {
        $filters = MonitorDepartmentFilters::fromInput([]);

        $this->assertNull($filters->base->termId);
        $this->assertNull($filters->subDepartmentId);
        $this->assertNull($filters->programId);
        $this->assertSame('sub_department', $filters->compare);
        $this->assertSame('name', $filters->sort);
        $this->assertSame('asc', $filters->dir);
        $this->assertFalse($filters->isFiltered());
        $this->assertSame([], $filters->toQuery());
    }

    public function testValidValuesAreKeptAndRoundTripThroughTheQuery(): void
    {
        $input = ['term' => '3', 'from' => '2026-09-01', 'to' => '2026-09-30', 'sub_department' => '4', 'program' => '5', 'compare' => 'program', 'sort' => 'at_risk', 'dir' => 'DESC'];

        $filters = MonitorDepartmentFilters::fromInput($input);

        $this->assertSame(3, $filters->base->termId);
        $this->assertSame('2026-09-01', $filters->base->from);
        $this->assertSame([4, 5], [$filters->subDepartmentId, $filters->programId]);
        $this->assertTrue($filters->isFiltered());
        $this->assertSame(
            ['term' => 3, 'from' => '2026-09-01', 'to' => '2026-09-30', 'sub_department' => 4, 'program' => 5, 'compare' => 'program', 'sort' => 'at_risk', 'dir' => 'desc'],
            $filters->toQuery()
        );
    }

    public function testInvalidValuesAreDroppedOrDefaulted(): void
    {
        $filters = MonitorDepartmentFilters::fromInput([
            'term' => '-3', 'from' => 'not a date', 'sub_department' => 'abc', 'program' => '0',
            'compare' => 'everything', 'sort' => 'password', 'dir' => 'sideways',
        ]);

        $this->assertNull($filters->base->termId);
        $this->assertNull($filters->base->from);
        $this->assertNull($filters->subDepartmentId);
        $this->assertNull($filters->programId);
        $this->assertSame('sub_department', $filters->compare);
        $this->assertSame('name', $filters->sort);
        $this->assertSame('asc', $filters->dir);
        $this->assertFalse($filters->isFiltered());
    }

    public function testComparingAndSortingAreNotFilters(): void
    {
        $filters = MonitorDepartmentFilters::fromInput(['compare' => 'program', 'sort' => 'students', 'dir' => 'desc']);

        $this->assertFalse($filters->isFiltered());
    }

    public function testAnOverrideReplacesOrRemovesAParameter(): void
    {
        $filters = MonitorDepartmentFilters::fromInput(['program' => '5', 'sort' => 'students']);

        $this->assertSame(['program' => 5], $filters->toQuery(['sort' => null]));
        $this->assertEquals(['program' => 5, 'sort' => 'students', 'compare' => 'program'], $filters->toQuery(['compare' => 'program']));
    }
}
