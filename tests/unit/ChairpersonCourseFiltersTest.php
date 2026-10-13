<?php

declare(strict_types=1);

use App\Services\Courses\ChairpersonCourseFilters;

/**
 * @internal
 */
final class ChairpersonCourseFiltersTest extends CodeIgniter\Test\CIUnitTestCase
{
    public function testDefaultsWhenNothingIsGiven(): void
    {
        $filters = ChairpersonCourseFilters::fromInput([]);

        $this->assertNull($filters->q);
        $this->assertNull($filters->termId);
        $this->assertNull($filters->instructorId);
        $this->assertNull($filters->status);
        $this->assertSame('name', $filters->sort);
        $this->assertSame('asc', $filters->dir);
        $this->assertSame(1, $filters->page);
        $this->assertSame(ChairpersonCourseFilters::DEFAULT_PER_PAGE, $filters->perPage);
        $this->assertFalse($filters->isFiltered());
    }

    public function testValidInputIsKept(): void
    {
        $filters = ChairpersonCourseFilters::fromInput([
            'q' => '  IT101  ',
            'term' => '3',
            'instructor' => '7',
            'status' => 'archived',
            'sort' => 'enrollment',
            'dir' => 'DESC',
            'page' => '2',
            'per_page' => '30',
        ]);

        $this->assertSame('IT101', $filters->q);
        $this->assertSame(3, $filters->termId);
        $this->assertSame(7, $filters->instructorId);
        $this->assertSame('archived', $filters->status);
        $this->assertSame('enrollment', $filters->sort);
        $this->assertSame('desc', $filters->dir);
        $this->assertSame(2, $filters->page);
        $this->assertSame(30, $filters->perPage);
        $this->assertTrue($filters->isFiltered());
    }

    public function testAnUnknownSortColumnFallsBackToName(): void
    {
        $filters = ChairpersonCourseFilters::fromInput(['sort' => 'DROP TABLE courses']);

        $this->assertSame('name', $filters->sort);
    }

    public function testAnUnknownStatusIsDropped(): void
    {
        $filters = ChairpersonCourseFilters::fromInput(['status' => 'deleted']);

        $this->assertNull($filters->status);
        $this->assertFalse($filters->isFiltered());
    }

    public function testPerPageIsClampedToTheMaximum(): void
    {
        $filters = ChairpersonCourseFilters::fromInput(['per_page' => '99999']);

        $this->assertSame(ChairpersonCourseFilters::MAX_PER_PAGE, $filters->perPage);
    }

    public function testANegativeOrZeroPageFallsBackToOne(): void
    {
        $this->assertSame(1, ChairpersonCourseFilters::fromInput(['page' => '-3'])->page);
        $this->assertSame(1, ChairpersonCourseFilters::fromInput(['page' => '0'])->page);
    }

    public function testToQueryLeavesOutDefaultsAndAppliesOverrides(): void
    {
        $filters = ChairpersonCourseFilters::fromInput(['q' => 'IT101']);

        $this->assertSame(['q' => 'IT101'], $filters->toQuery());
        $this->assertSame(['q' => 'IT101', 'page' => 2], $filters->toQuery(['page' => 2]));
    }

    public function testToQueryIncludesNonDefaultSortAndPaging(): void
    {
        $filters = ChairpersonCourseFilters::fromInput(['sort' => 'instructor', 'dir' => 'desc', 'page' => '3']);

        $this->assertSame(['sort' => 'instructor', 'dir' => 'desc', 'page' => 3], $filters->toQuery());
    }

    public function testARepeatedQueryParameterIsIgnoredRatherThanError(): void
    {
        $filters = ChairpersonCourseFilters::fromInput(['sort' => ['name', 'instructor']]);

        $this->assertSame('name', $filters->sort);
    }
}
