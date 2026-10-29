<?php

declare(strict_types=1);

use App\Services\Courses\RowSort;
use PHPUnit\Framework\TestCase;

/**
 * How the dean's comparison tables order their rows: by the chosen column, a missing value always last, and
 * equal values in name order so the order is the same on every reload.
 *
 * @internal
 */
final class RowSortTest extends TestCase
{
    /** @return list<array{name: string, n: int|float|null}> */
    private function rows(): array
    {
        return [
            ['name' => 'Cara', 'n' => 5],
            ['name' => 'Abe', 'n' => null],
            ['name' => 'Bea', 'n' => 9],
            ['name' => 'Dan', 'n' => 5],
        ];
    }

    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    public function testAscendingPutsTheSmallestFirstAndTheMissingLast(): void
    {
        $this->assertSame(['Cara', 'Dan', 'Bea', 'Abe'], $this->names(RowSort::by($this->rows(), 'n', 'asc')));
    }

    public function testDescendingPutsTheBiggestFirstAndTheMissingStillLast(): void
    {
        $this->assertSame(['Bea', 'Cara', 'Dan', 'Abe'], $this->names(RowSort::by($this->rows(), 'n', 'desc')));
    }

    public function testEqualValuesKeepNameOrderWhicheverWayItGoes(): void
    {
        $this->assertSame(['Cara', 'Dan'], array_slice($this->names(RowSort::by($this->rows(), 'n', 'asc')), 0, 2));
        $this->assertSame(['Cara', 'Dan'], array_slice($this->names(RowSort::by($this->rows(), 'n', 'desc')), 1, 2));
    }

    public function testSortingByNameIsCaseInsensitive(): void
    {
        $rows = [['name' => 'bea'], ['name' => 'Cara'], ['name' => 'Abe']];

        $this->assertSame(['Abe', 'bea', 'Cara'], $this->names(RowSort::by($rows, 'name', 'asc')));
        $this->assertSame(['Cara', 'bea', 'Abe'], $this->names(RowSort::by($rows, 'name', 'desc')));
    }

    public function testAColumnNobodyHasLeavesNameOrderAndNothingIsLost(): void
    {
        $sorted = RowSort::by($this->rows(), 'nothing', 'asc');

        $this->assertSame(['Abe', 'Bea', 'Cara', 'Dan'], $this->names($sorted));
        $this->assertCount(4, $sorted);
        $this->assertSame([], RowSort::by([], 'n', 'asc'));
    }
}
