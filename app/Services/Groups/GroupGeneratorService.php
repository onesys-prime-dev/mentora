<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Services\Institutions\StructureException;

/**
 * Proposes groups for a pool of students (D18). Pure: nothing is read or written, so the
 * instructor can edit the preview before GroupSetService::saveGenerated stores it.
 */
class GroupGeneratorService
{
    /**
     * @param list<int> $pool student user ids
     * @param array{by?: string, n?: int, together?: list<array{int,int}>, apart?: list<array{int,int}>, max?: int, seed?: ?int} $opts
     *
     * @return array{groups: list<list<int>>, ungrouped: list<int>, conflicts: list<string>}
     */
    public function preview(int $offeringId, array $pool, array $opts): array
    {
        $pool = array_values(array_unique(array_map('intval', $pool)));
        $by   = (string) ($opts['by'] ?? '');
        $n    = (int) ($opts['n'] ?? 0);
        $max  = min((int) ($opts['max'] ?? GroupLimits::MAX_MEMBERS), GroupLimits::MAX_MEMBERS);

        if ($pool === []) {
            throw new StructureException('There are no students to put in groups.');
        }

        if (!in_array($by, ['size', 'count'], true) || $n < 1) {
            throw new StructureException('Give a group size or a number of groups of at least 1.');
        }

        if ($max < 1) {
            throw new StructureException('The largest group size must be at least 1.');
        }

        $total = count($pool);

        if ($by === 'size' && $n > GroupLimits::MAX_MEMBERS) {
            throw new StructureException('A group can have at most ' . GroupLimits::MAX_MEMBERS . ' members.');
        }

        $groups = $by === 'size' ? max(1, intdiv($total, $n)) : $n;

        if ($groups > GroupLimits::MAX_GROUPS_PER_ACTIVITY) {
            throw new StructureException('That makes ' . $groups . ' groups; a set can have at most ' . GroupLimits::MAX_GROUPS_PER_ACTIVITY . '.');
        }

        $caps = $by === 'size' ? $this->capsBySize($total, $n, $groups, $max) : $this->capsByCount($total, $groups, $max);

        $rng = new SeededShuffle($opts['seed'] ?? null);
        $rng->shuffle($pool);

        return $this->place($pool, $caps, $this->pairs($opts['together'] ?? [], $pool), $this->pairs($opts['apart'] ?? [], $pool));
    }

    /** @return list<int> seats per group: n each, leftovers one per group round-robin while below max */
    private function capsBySize(int $total, int $n, int $groups, int $max): array
    {
        $caps = array_fill(0, $groups, min($n, $max, $total));
        $left = $total - array_sum($caps);

        for ($i = 0; $left > 0 && min($caps) < $max; $i = ($i + 1) % $groups) {
            if ($caps[$i] < $max) {
                $caps[$i]++;
                $left--;
            }
        }

        return $caps;
    }

    /** @return list<int> */
    private function capsByCount(int $total, int $groups, int $max): array
    {
        $caps = [];

        for ($i = 0; $i < $groups; $i++) {
            $caps[] = min($max, intdiv($total, $groups) + ($i < $total % $groups ? 1 : 0));
        }

        return $caps;
    }

    /**
     * @param list<array{int,int}> $pairs
     * @param list<int>            $pool
     *
     * @return list<array{int,int}> pairs of two different pool students
     */
    private function pairs(array $pairs, array $pool): array
    {
        $out = [];

        foreach ($pairs as $p) {
            $a = (int) ($p[0] ?? 0);
            $b = (int) ($p[1] ?? 0);

            if ($a !== $b && in_array($a, $pool, true) && in_array($b, $pool, true)) {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /**
     * Keep-together clusters go first (biggest first), then single students; each goes where it fits
     * and clashes with nobody it must stay apart from, preferring the emptiest group.
     *
     * @param list<int>            $pool     shuffled
     * @param list<int>            $caps
     * @param list<array{int,int}> $together
     * @param list<array{int,int}> $apart
     */
    private function place(array $pool, array $caps, array $together, array $apart): array
    {
        $root = array_combine($pool, $pool);
        $find = static function (int $x) use (&$root): int {
            while ($root[$x] !== $x) {
                $x = $root[$x];
            }

            return $x;
        };

        foreach ($together as [$a, $b]) {
            $root[$find($a)] = $find($b);
        }

        $clusters = [];

        foreach ($pool as $id) {
            $clusters[$find($id)][] = $id;
        }

        $clusters = array_values($clusters);
        usort($clusters, static fn (array $x, array $y): int => count($y) <=> count($x)); // stable: ties keep shuffled order

        $enemies = [];

        foreach ($apart as [$a, $b]) {
            $enemies[$a][$b] = $enemies[$b][$a] = true;
        }

        $groups    = array_fill(0, count($caps), []);
        $ungrouped = [];
        $conflicts = [];

        foreach ($clusters as $cluster) {
            $whole = count($cluster) > 1 ? $this->bestGroup($groups, $caps, $cluster, $enemies) : null;

            if ($whole !== null) {
                array_push($groups[$whole], ...$cluster);

                continue;
            }

            if (count($cluster) > 1) {
                $conflicts[] = 'Students ' . implode(', ', $cluster) . ' were to stay together but no group has room for all of them.';
            }

            foreach ($cluster as $id) {
                $g = $this->bestGroup($groups, $caps, [$id], $enemies);

                if ($g === null) {
                    $g = $this->bestGroup($groups, $caps, [$id], []); // apart cannot be kept: take any seat
                }

                if ($g === null) {
                    $ungrouped[] = $id;

                    continue;
                }

                $groups[$g][] = $id;
            }
        }

        foreach ($groups as $group) {
            foreach ($group as $i => $a) {
                foreach (array_slice($group, $i + 1) as $b) {
                    if (isset($enemies[$a][$b])) {
                        $conflicts[] = "Students {$a} and {$b} were to stay apart but share a group.";
                    }
                }
            }
        }

        return ['groups' => $groups, 'ungrouped' => $ungrouped, 'conflicts' => array_values(array_unique($conflicts))];
    }

    /** @return ?int the group with the most free seats that fits all of $ids and holds none of their enemies */
    private function bestGroup(array $groups, array $caps, array $ids, array $enemies): ?int
    {
        $best = null;

        foreach ($groups as $i => $group) {
            $free = $caps[$i] - count($group);

            if ($free < count($ids) || ($best !== null && $free <= $caps[$best] - count($groups[$best]))) {
                continue;
            }

            foreach ($ids as $id) {
                if (array_intersect_key($enemies[$id] ?? [], array_flip($group)) !== []) {
                    continue 2;
                }
            }

            $best = $i;
        }

        return $best;
    }
}

/** A tiny xorshift32 shuffle: same seed, same order, and no touching PHP's global random state. */
final class SeededShuffle
{
    private int $state;

    private bool $random;

    public function __construct(?int $seed)
    {
        $this->random = $seed === null;
        $this->state  = ((($seed ?? 0) & 0xFFFFFFFF) ^ 0x9E3779B9) ?: 1;
    }

    /** @param list<int> $items */
    public function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->random ? random_int(0, $i) : $this->next() % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }

    private function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;

        return $this->state = $x;
    }
}
