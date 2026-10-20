<?php

declare(strict_types=1);

use App\Services\Courses\Grades\OverrideApprovalFilters;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The dean's Approvals list reads its tab and filters from the address:
 * anything invalid is dropped or defaulted, never an error.
 *
 * @internal
 */
final class OverrideApprovalFiltersTest extends CIUnitTestCase
{
    public function testAnEmptyAddressIsTheWaitingTabWithNoFilters(): void
    {
        $filters = OverrideApprovalFilters::fromInput([]);

        $this->assertSame('pending', $filters->view);
        $this->assertNull($filters->q);
        $this->assertNull($filters->programId);
        $this->assertNull($filters->requesterId);
        $this->assertFalse($filters->isFiltered());
        $this->assertSame([], $filters->toQuery());
    }

    public function testValidValuesAreKeptAndRoundTripThroughTheQuery(): void
    {
        $filters = OverrideApprovalFilters::fromInput(['view' => 'decided', 'q' => ' ann ', 'program' => '4', 'requested_by' => '9']);

        $this->assertSame('decided', $filters->view);
        $this->assertSame('ann', $filters->q);
        $this->assertSame([4, 9], [$filters->programId, $filters->requesterId]);
        $this->assertTrue($filters->isFiltered());
        $this->assertSame(['view' => 'decided', 'q' => 'ann', 'program' => 4, 'requested_by' => 9], $filters->toQuery());
    }

    public function testInvalidValuesAreDroppedOrDefaulted(): void
    {
        $filters = OverrideApprovalFilters::fromInput(['view' => 'everything', 'q' => ['x'], 'program' => '-2', 'requested_by' => 'abc']);

        $this->assertSame('pending', $filters->view);
        $this->assertNull($filters->q);
        $this->assertNull($filters->programId);
        $this->assertNull($filters->requesterId);
    }

    public function testTheTabIsNotAFilterAndASearchIsTrimmedAndCapped(): void
    {
        $this->assertFalse(OverrideApprovalFilters::fromInput(['view' => 'decided'])->isFiltered());
        $this->assertNull(OverrideApprovalFilters::fromInput(['q' => '   '])->q);
        $this->assertSame(100, mb_strlen(OverrideApprovalFilters::fromInput(['q' => str_repeat('a', 300)])->q));
    }

    public function testASortAndDirectionAreReadSafely(): void
    {
        $filters = OverrideApprovalFilters::fromInput(['sort' => 'requested', 'dir' => 'DESC']);

        $this->assertSame('requested', $filters->sort);
        $this->assertSame('desc', $filters->dir);
        $this->assertFalse($filters->isFiltered(), 'sorting is not filtering');
        $this->assertEquals(['sort' => 'requested', 'dir' => 'desc'], $filters->toQuery());

        $bad = OverrideApprovalFilters::fromInput(['sort' => 'password', 'dir' => 'sideways']);

        $this->assertSame('age', $bad->sort);
        $this->assertNull($bad->dir);
        $this->assertSame([], $bad->toQuery(), 'the defaults are not written into the address');
    }

    public function testTheDefaultDirectionIsOldestFirstWhileWaitingAndNewestFirstOnceDecided(): void
    {
        $this->assertSame('asc', (new OverrideApprovalFilters('pending'))->direction());
        $this->assertSame('desc', (new OverrideApprovalFilters('decided'))->direction());
        $this->assertSame('asc', (new OverrideApprovalFilters('decided', dir: 'asc'))->direction(), 'a chosen direction wins');
        $this->assertSame('desc', (new OverrideApprovalFilters('pending', dir: 'desc'))->direction());
    }

    public function testEverySortableColumnIsAccepted(): void
    {
        foreach (OverrideApprovalFilters::SORTABLE as $column) {
            $this->assertSame($column, OverrideApprovalFilters::fromInput(['sort' => $column])->sort);
        }
    }

    public function testAnOverrideReplacesOrRemovesAParameter(): void
    {
        $filters = OverrideApprovalFilters::fromInput(['view' => 'decided', 'program' => '4']);

        $this->assertSame(['program' => 4], $filters->toQuery(['view' => null]));
        $this->assertEquals(['view' => 'decided', 'program' => 4, 'q' => 'x'], $filters->toQuery(['q' => 'x']));
    }
}
