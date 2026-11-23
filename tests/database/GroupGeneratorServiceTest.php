<?php

declare(strict_types=1);

use App\Services\Groups\GroupGeneratorService;
use App\Services\Groups\GroupLimits;
use App\Services\Institutions\StructureException;
use PHPUnit\Framework\TestCase;

/**
 * Pure: the generator reads and writes nothing.
 *
 * @internal
 */
final class GroupGeneratorServiceTest extends TestCase
{
    private GroupGeneratorService $gen;

    protected function setUp(): void
    {
        $this->gen = new GroupGeneratorService();
    }

    /** @return list<int> */
    private function pool(int $n): array
    {
        return range(101, 100 + $n);
    }

    private function opts(array $over = []): array
    {
        return $over + ['by' => 'size', 'n' => 4, 'together' => [], 'apart' => [], 'min' => 1, 'max' => 5, 'seed' => 1];
    }

    private function sizes(array $result): array
    {
        $sizes = array_map('count', $result['groups']);
        sort($sizes);

        return $sizes;
    }

    private function everyone(array $result): array
    {
        $all = array_merge([], ...$result['groups'], ...[$result['ungrouped']]);
        sort($all);

        return $all;
    }

    private function groupOf(array $result, int $id): ?int
    {
        foreach ($result['groups'] as $i => $g) {
            if (in_array($id, $g, true)) {
                return $i;
            }
        }

        return null;
    }

    public function testBySize4Of42GivesTenGroupsAndLeftoversSpreadWithinMax(): void
    {
        $r = $this->gen->preview(1, $this->pool(42), $this->opts());

        $this->assertCount(10, $r['groups']);
        $this->assertSame([4, 4, 4, 4, 4, 4, 4, 4, 5, 5], $this->sizes($r));
        $this->assertSame([], $r['ungrouped']);
        $this->assertSame([], $r['conflicts']);
    }

    public function testLeftoversUngroupedWhenMaxExceeded(): void
    {
        $r = $this->gen->preview(1, $this->pool(42), $this->opts(['max' => 4]));

        $this->assertCount(10, $r['groups']);
        $this->assertSame(array_fill(0, 10, 4), $this->sizes($r));
        $this->assertCount(2, $r['ungrouped']);
    }

    public function testByCountSizesDifferByAtMostOne(): void
    {
        $r = $this->gen->preview(1, $this->pool(23), $this->opts(['by' => 'count', 'n' => 5, 'max' => 30]));

        $this->assertCount(5, $r['groups']);
        $sizes = $this->sizes($r);
        $this->assertLessThanOrEqual(1, end($sizes) - $sizes[0]);
        $this->assertSame([], $r['ungrouped']);

        $capped = $this->gen->preview(1, $this->pool(23), $this->opts(['by' => 'count', 'n' => 5, 'max' => 4]));
        $this->assertCount(3, $capped['ungrouped']);
    }

    public function testKeepTogetherPairsShareAGroup(): void
    {
        for ($seed = 1; $seed <= 15; $seed++) {
            $r = $this->gen->preview(1, $this->pool(12), $this->opts(['n' => 3, 'max' => 4, 'seed' => $seed, 'together' => [[101, 102], [102, 103], [110, 111], [101, 999]]]));

            $this->assertNotNull($this->groupOf($r, 101));
            $this->assertSame($this->groupOf($r, 101), $this->groupOf($r, 102));
            $this->assertSame($this->groupOf($r, 102), $this->groupOf($r, 103));
            $this->assertSame($this->groupOf($r, 110), $this->groupOf($r, 111));
            $this->assertSame([], $r['conflicts']);
        }
    }

    public function testKeepApartPairsNeverShareAGroup(): void
    {
        for ($seed = 1; $seed <= 15; $seed++) {
            $r = $this->gen->preview(1, $this->pool(12), $this->opts(['n' => 3, 'max' => 3, 'seed' => $seed, 'apart' => [[101, 102], [103, 104], [105, 999]]]));

            $this->assertNotSame($this->groupOf($r, 101), $this->groupOf($r, 102));
            $this->assertNotSame($this->groupOf($r, 103), $this->groupOf($r, 104));
            $this->assertSame([], $r['conflicts']);
        }
    }

    public function testSeededRunIsRepeatable(): void
    {
        $o = $this->opts(['seed' => 77]);

        $this->assertSame($this->gen->preview(1, $this->pool(30), $o), $this->gen->preview(1, $this->pool(30), $o));
    }

    public function testDifferentSeedsCanDiffer(): void
    {
        $seen = [];
        for ($seed = 1; $seed <= 8; $seed++) {
            $seen[json_encode($this->gen->preview(1, $this->pool(30), $this->opts(['seed' => $seed])))] = true;
        }
        $this->assertGreaterThan(1, count($seen));

        $a = $this->gen->preview(1, $this->pool(30), $this->opts(['seed' => null]));
        $b = $this->gen->preview(1, $this->pool(30), $this->opts(['seed' => null]));
        $this->assertNotSame($a, $b);
    }

    public function testEveryStudentAppearsExactlyOnce(): void
    {
        foreach ([[1, 'size', 3, 5], [7, 'size', 4, 4], [42, 'size', 4, 5], [42, 'count', 7, 6], [10, 'count', 10, 30], [5, 'size', 8, 8], [29, 'size', 2, 2]] as [$count, $by, $n, $max]) {
            $pool = $this->pool($count);
            $r    = $this->gen->preview(1, $pool, $this->opts(['by' => $by, 'n' => $n, 'max' => $max, 'together' => [[101, 102]], 'apart' => [[103, 104]]]));

            $this->assertSame($pool, $this->everyone($r), "{$by} {$n} of {$count}");

            foreach ($r['groups'] as $g) {
                $this->assertLessThanOrEqual($max, count($g));
            }
        }
    }

    public function testInvalidOptionsRefused(): void
    {
        $bad = [
            ['by' => 'count', 'n' => 0],
            ['by' => 'size', 'n' => 0],
            ['by' => 'count', 'n' => GroupLimits::MAX_GROUPS_PER_ACTIVITY + 1],
            ['by' => 'size', 'n' => GroupLimits::MAX_MEMBERS + 1],
            ['by' => 'bogus', 'n' => 3],
        ];

        foreach ($bad as $o) {
            try {
                $this->gen->preview(1, $this->pool(100), $this->opts($o));
                $this->fail('Expected refusal for ' . json_encode($o));
            } catch (StructureException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(StructureException::class);
        $this->gen->preview(1, [], $this->opts());
    }

    public function testConflictsReportedForImpossibleApart(): void
    {
        $r = $this->gen->preview(1, [101, 102], $this->opts(['by' => 'count', 'n' => 1, 'max' => 5, 'apart' => [[101, 102]]]));

        $this->assertCount(1, $r['groups']);
        $this->assertCount(2, $r['groups'][0]);
        $this->assertCount(1, $r['conflicts']);
    }

    public function testOversizedTogetherClusterReported(): void
    {
        $r = $this->gen->preview(1, $this->pool(8), $this->opts(['by' => 'count', 'n' => 2, 'max' => 3, 'together' => [[101, 102], [102, 103], [103, 104]]]));

        $this->assertNotEmpty($r['conflicts']);
        $this->assertSame($this->pool(8), $this->everyone($r));
    }
}
