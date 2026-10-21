<?php

declare(strict_types=1);

use App\Services\Courses\TeachingLoadFilters;
use PHPUnit\Framework\TestCase;

/**
 * The dean's teaching-load filters and sort read from an address: bad values are dropped, never an error.
 *
 * @internal
 */
final class TeachingLoadFiltersTest extends TestCase
{
    public function testItDefaultsToTheHeaviestLoadFirst(): void
    {
        $f = TeachingLoadFilters::fromInput([]);

        $this->assertSame('units', $f->sort);
        $this->assertSame('desc', $f->dir);
        $this->assertNull($f->termId);
        $this->assertFalse($f->isFiltered());
        $this->assertSame([], $f->toQuery());
    }

    public function testANameReadsAToZAndANumberBiggestFirstUnlessAskedOtherwise(): void
    {
        $this->assertSame('asc', TeachingLoadFilters::fromInput(['sort' => 'name'])->dir);
        $this->assertSame('desc', TeachingLoadFilters::fromInput(['sort' => 'students'])->dir);
        $this->assertSame('asc', TeachingLoadFilters::fromInput(['sort' => 'students', 'dir' => 'ASC'])->dir);
        $this->assertSame('desc', TeachingLoadFilters::fromInput(['sort' => 'name', 'dir' => 'desc'])->dir);
    }

    public function testBadValuesAreDropped(): void
    {
        $f = TeachingLoadFilters::fromInput(['term' => '-3', 'sub_department' => 'x', 'sort' => 'password', 'dir' => 'sideways']);

        $this->assertNull($f->termId);
        $this->assertNull($f->subDepartmentId);
        $this->assertSame('units', $f->sort);
        $this->assertSame('desc', $f->dir);

        $arrays = TeachingLoadFilters::fromInput(['term' => ['1'], 'sort' => ['name'], 'dir' => ['asc']]);

        $this->assertNull($arrays->termId);
        $this->assertSame('units', $arrays->sort);
    }

    public function testTheQueryKeepsOnlyWhatIsNotTheDefault(): void
    {
        $f = TeachingLoadFilters::fromInput(['term' => '4', 'sub_department' => '7', 'sort' => 'name', 'dir' => 'desc']);

        $this->assertTrue($f->isFiltered());
        $this->assertSame(['term' => 4, 'sub_department' => 7, 'sort' => 'name', 'dir' => 'desc'], $f->toQuery());
        $this->assertSame(['term' => 4, 'sub_department' => 7], $f->toQuery(['sort' => null, 'dir' => null]));
        $this->assertSame(['sort' => 'name'], TeachingLoadFilters::fromInput(['sort' => 'name'])->toQuery());
        $this->assertSame(['sort' => 'ungraded', 'dir' => 'asc'], TeachingLoadFilters::fromInput(['sort' => 'ungraded', 'dir' => 'asc'])->toQuery());
    }
}
