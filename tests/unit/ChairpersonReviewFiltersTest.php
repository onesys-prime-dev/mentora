<?php

declare(strict_types=1);

use App\Services\Courses\ChairpersonReviewFilters;
use PHPUnit\Framework\TestCase;

/**
 * The dean's chairperson-review filters read from an address: bad values are dropped, never an error.
 *
 * @internal
 */
final class ChairpersonReviewFiltersTest extends TestCase
{
    public function testGoodValuesAreKept(): void
    {
        $f = ChairpersonReviewFilters::fromInput(['chairperson' => '4', 'sub_department' => '7', 'from' => '2026-10-01', 'to' => '2026-10-31', 'view' => 'reviewed']);

        $this->assertSame(4, $f->chairpersonId);
        $this->assertSame(7, $f->subDepartmentId);
        $this->assertSame('2026-10-01 00:00:00', $f->fromTimestamp());
        $this->assertSame('2026-11-01 00:00:00', $f->untilTimestamp(), 'the whole last day counts');
        $this->assertSame('reviewed', $f->view);
        $this->assertTrue($f->isFiltered());
    }

    public function testBadValuesAreDroppedAndTheViewFallsBackToWhatIsLeftToReview(): void
    {
        $f = ChairpersonReviewFilters::fromInput(['chairperson' => '-1', 'sub_department' => 'abc', 'from' => '2026-02-31', 'to' => 'tomorrow', 'view' => 'everything']);

        $this->assertNull($f->chairpersonId);
        $this->assertNull($f->subDepartmentId);
        $this->assertNull($f->fromTimestamp());
        $this->assertNull($f->untilTimestamp());
        $this->assertSame('unreviewed', $f->view);
        $this->assertFalse($f->isFiltered());
        $this->assertSame([], $f->toQuery());
    }

    public function testArraysAndOtherTypesAreIgnored(): void
    {
        $f = ChairpersonReviewFilters::fromInput(['chairperson' => ['1'], 'view' => ['all'], 'from' => 5]);

        $this->assertNull($f->chairpersonId);
        $this->assertSame('unreviewed', $f->view);
        $this->assertNull($f->from);
    }

    public function testTheQueryKeepsOnlyWhatIsNotTheDefaultAndTakesOverrides(): void
    {
        $f = ChairpersonReviewFilters::fromInput(['chairperson' => '4', 'view' => 'all']);

        $this->assertSame(['chairperson' => 4, 'view' => 'all'], $f->toQuery());
        $this->assertSame(['chairperson' => 4], $f->toQuery(['view' => null]));
        $this->assertSame(['chairperson' => 4, 'view' => 'all', 'page' => 2], $f->toQuery(['page' => 2]));
    }
}
