<?php

declare(strict_types=1);

use App\Services\Courses\DeanCourseFilters;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The dean Courses page reads its filters from the address: anything invalid
 * is dropped or defaulted, never an error.
 *
 * @internal
 */
final class DeanCourseFiltersTest extends CIUnitTestCase
{
    public function testAnEmptyAddressGivesTheDefaults(): void
    {
        $filters = DeanCourseFilters::fromInput([]);

        $this->assertNull($filters->q);
        $this->assertSame('name', $filters->sort);
        $this->assertSame('asc', $filters->dir);
        $this->assertSame(1, $filters->page);
        $this->assertSame(DeanCourseFilters::DEFAULT_PER_PAGE, $filters->perPage);
        $this->assertFalse($filters->isFiltered());
        $this->assertSame([], $filters->toQuery());
    }

    public function testValidValuesAreKeptAndRoundTripThroughTheQuery(): void
    {
        $input = ['q' => ' networks ', 'term' => '3', 'sub_department' => '4', 'program' => '5', 'instructor' => '6', 'status' => 'completed', 'sort' => 'enrollment', 'dir' => 'DESC', 'page' => '2', 'per_page' => '24'];

        $filters = DeanCourseFilters::fromInput($input);

        $this->assertSame('networks', $filters->q);
        $this->assertSame([3, 4, 5, 6], [$filters->termId, $filters->subDepartmentId, $filters->programId, $filters->instructorId]);
        $this->assertSame('completed', $filters->status);
        $this->assertTrue($filters->isFiltered());
        $this->assertSame(
            ['q' => 'networks', 'term' => 3, 'sub_department' => 4, 'program' => 5, 'instructor' => 6, 'status' => 'completed', 'sort' => 'enrollment', 'dir' => 'desc', 'page' => 2, 'per_page' => 24],
            $filters->toQuery()
        );
    }

    public function testInvalidValuesAreDroppedOrDefaulted(): void
    {
        $filters = DeanCourseFilters::fromInput([
            'q' => ['array'], 'term' => '-3', 'sub_department' => 'abc', 'program' => '0', 'instructor' => '1.5',
            'status' => 'deleted', 'sort' => 'password', 'dir' => 'sideways', 'page' => '-3', 'per_page' => '9999',
        ]);

        $this->assertNull($filters->q);
        $this->assertNull($filters->termId);
        $this->assertNull($filters->subDepartmentId);
        $this->assertNull($filters->programId);
        $this->assertNull($filters->instructorId);
        $this->assertNull($filters->status);
        $this->assertSame('name', $filters->sort);
        $this->assertSame('asc', $filters->dir);
        $this->assertSame(1, $filters->page);
        $this->assertSame(DeanCourseFilters::MAX_PER_PAGE, $filters->perPage);
    }

    public function testASearchIsTrimmedAndCapped(): void
    {
        $this->assertNull(DeanCourseFilters::fromInput(['q' => '   '])->q);
        $this->assertSame(100, mb_strlen(DeanCourseFilters::fromInput(['q' => str_repeat('a', 300)])->q));
    }

    public function testAnOverrideReplacesOrRemovesAParameter(): void
    {
        $filters = DeanCourseFilters::fromInput(['q' => 'x', 'page' => '3']);

        $this->assertSame(['q' => 'x'], $filters->toQuery(['page' => null]));
        $this->assertSame(['q' => 'x', 'page' => 4], $filters->toQuery(['page' => 4]));
    }
}
