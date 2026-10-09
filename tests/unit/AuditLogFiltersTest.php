<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogFilters;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reading the audit log's filters from a query string: valid values are
 * kept, invalid ones are dropped or defaulted, and nothing a visitor types
 * can produce an error or an unexpected sort column.
 *
 * @internal
 */
final class AuditLogFiltersTest extends CIUnitTestCase
{
    public function testNothingGivenMeansTheDefaults(): void
    {
        $filters = AuditLogFilters::fromInput([]);

        $this->assertNull($filters->from);
        $this->assertNull($filters->to);
        $this->assertNull($filters->actor);
        $this->assertNull($filters->institutionId);
        $this->assertNull($filters->action);
        $this->assertNull($filters->targetType);
        $this->assertNull($filters->q);
        $this->assertSame('created_at', $filters->sort);
        $this->assertSame('desc', $filters->dir);
        $this->assertSame(1, $filters->page);
        $this->assertSame(25, $filters->perPage);
        $this->assertFalse($filters->isFiltered());
    }

    public function testValidValuesAreKept(): void
    {
        $filters = AuditLogFilters::fromInput([
            'from' => '2026-01-05', 'to' => '2026-02-10', 'actor' => 'sam@x.test', 'institution' => '7',
            'action' => 'auth.login', 'target_type' => 'user', 'q' => 'hello', 'sort' => 'action', 'dir' => 'asc',
            'page' => '3', 'per_page' => '50',
        ]);

        $this->assertSame('2026-01-05', $filters->from);
        $this->assertSame('2026-02-10', $filters->to);
        $this->assertSame('sam@x.test', $filters->actor);
        $this->assertSame(7, $filters->institutionId);
        $this->assertSame('auth.login', $filters->action);
        $this->assertSame('user', $filters->targetType);
        $this->assertSame('hello', $filters->q);
        $this->assertSame('action', $filters->sort);
        $this->assertSame('asc', $filters->dir);
        $this->assertSame(3, $filters->page);
        $this->assertSame(50, $filters->perPage);
        $this->assertTrue($filters->isFiltered());
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideBadDates(): iterable
    {
        yield 'words' => ['yesterday'];
        yield 'wrong order' => ['05-01-2026'];
        yield 'not a real day' => ['2026-02-30'];
        yield 'month 13' => ['2026-13-01'];
        yield 'with a time' => ['2026-01-05 10:00:00'];
        yield 'an array' => [['2026-01-05']];
        yield 'empty' => [''];
    }

    #[DataProvider('provideBadDates')]
    public function testABadDateIsDropped(mixed $date): void
    {
        $this->assertNull(AuditLogFilters::fromInput(['from' => $date, 'to' => $date])->from);
        $this->assertNull(AuditLogFilters::fromInput(['from' => $date, 'to' => $date])->to);
    }

    public function testTheDateRangeCoversWholeDaysAtBothEnds(): void
    {
        $filters = AuditLogFilters::fromInput(['from' => '2026-01-31', 'to' => '2026-02-28']);

        $this->assertSame('2026-01-31 00:00:00', $filters->fromTimestamp());
        $this->assertSame('2026-03-01 00:00:00', $filters->untilTimestamp(), 'the end date is included through its last second');
    }

    public function testNoDatesMeansNoTimestamps(): void
    {
        $filters = AuditLogFilters::fromInput([]);

        $this->assertNull($filters->fromTimestamp());
        $this->assertNull($filters->untilTimestamp());
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideBadNumbers(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-4'];
        yield 'words' => ['abc'];
        yield 'a decimal' => ['2.5'];
        yield 'too long' => ['12345678901'];
        yield 'an array' => [['1']];
    }

    #[DataProvider('provideBadNumbers')]
    public function testABadNumberFallsBack(mixed $value): void
    {
        $filters = AuditLogFilters::fromInput(['institution' => $value, 'page' => $value, 'per_page' => $value]);

        $this->assertNull($filters->institutionId);
        $this->assertSame(1, $filters->page);
        $this->assertSame(25, $filters->perPage);
    }

    public function testThePageSizeIsKeptWithinLimits(): void
    {
        $this->assertSame(100, AuditLogFilters::fromInput(['per_page' => '5000'])->perPage);
        $this->assertSame(1, AuditLogFilters::fromInput(['per_page' => '1'])->perPage);
    }

    public function testAnUnknownSortColumnFallsBackToTime(): void
    {
        $this->assertSame('created_at', AuditLogFilters::fromInput(['sort' => 'ip_address'])->sort);
        $this->assertSame('created_at', AuditLogFilters::fromInput(['sort' => 'id; DROP TABLE audit_logs'])->sort);
        $this->assertSame('created_at', AuditLogFilters::fromInput(['sort' => ['action']])->sort);
    }

    public function testEveryListedSortColumnIsAccepted(): void
    {
        foreach (AuditLogFilters::SORTABLE as $column) {
            $this->assertSame($column, AuditLogFilters::fromInput(['sort' => $column])->sort);
        }
    }

    public function testADirectionOtherThanAscIsDescending(): void
    {
        $this->assertSame('asc', AuditLogFilters::fromInput(['dir' => 'ASC'])->dir);
        $this->assertSame('desc', AuditLogFilters::fromInput(['dir' => 'sideways'])->dir);
    }

    public function testTextIsTrimmedBlankIsNothingAndLongTextIsCut(): void
    {
        $this->assertSame('abc', AuditLogFilters::fromInput(['q' => '  abc  '])->q);
        $this->assertNull(AuditLogFilters::fromInput(['q' => '   '])->q);
        $this->assertNull(AuditLogFilters::fromInput(['q' => ['x']])->q);
        $this->assertSame(100, mb_strlen((string) AuditLogFilters::fromInput(['q' => str_repeat('a', 500)])->q));
    }

    public function testTheQueryLeavesOutDefaultsAndAppliesOverrides(): void
    {
        $filters = AuditLogFilters::fromInput(['action' => 'auth.login', 'sort' => 'action', 'dir' => 'asc', 'page' => '2']);

        $this->assertSame(['action' => 'auth.login', 'sort' => 'action', 'dir' => 'asc', 'page' => 2], $filters->toQuery());
        $this->assertSame(['action' => 'auth.login', 'sort' => 'action', 'dir' => 'asc'], $filters->toQuery(['page' => null]));
        $this->assertSame(['action' => 'auth.login', 'sort' => 'action', 'dir' => 'asc', 'page' => 3], $filters->toQuery(['page' => 3]));
        $this->assertSame([], AuditLogFilters::fromInput([])->toQuery());
    }

    public function testSortAndPagingAloneDoNotCountAsFiltering(): void
    {
        $this->assertFalse(AuditLogFilters::fromInput(['sort' => 'action', 'dir' => 'asc', 'page' => '4', 'per_page' => '10'])->isFiltered());
    }
}
