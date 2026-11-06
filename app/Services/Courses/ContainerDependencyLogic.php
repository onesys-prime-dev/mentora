<?php

namespace App\Services\Courses;

/**
 * Dependencies for modules and learning units (used by LearningProgressService, which holds the item ones).
 *
 * The same three modes as an item: none, the previous one, or a custom list of earlier ones. A module is completed when
 * the student has completed every published item in it (a unit: every published item in the unit); one with nothing
 * published in it is completed, so it can never hold anything back. Only published, earlier siblings count, so a lock
 * cannot become impossible to open. A locked module or unit locks the items inside it.
 *
 * Needs completionMap() from the class that uses it.
 */
trait ContainerDependencyLogic
{
    /** @var array<string, array{table: string, link: string, own: string, other: string}> */
    private static array $containerKinds = [
        'module' => ['table' => 'course_modules', 'link' => 'course_module_prerequisites', 'own' => 'module_id', 'other' => 'prerequisite_module_id'],
        'unit'   => ['table' => 'learning_units', 'link' => 'learning_unit_prerequisites', 'own' => 'learning_unit_id', 'other' => 'prerequisite_unit_id'],
    ];

    /*
    |--------------------------------------------------------------------------
    | The instructor's modal
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed>|null */
    public function containerDependencyOptions(string $kind, int $offeringId, int $id): ?array
    {
        $row = $this->containerRow($kind, $offeringId, $id);

        if (! $row) {
            return null;
        }

        $config     = self::$containerKinds[$kind];
        $candidates = $this->earlierSiblings($kind, $row);
        $previous   = $candidates === [] ? null : end($candidates);

        $selected = array_map('intval', array_column(
            db_connect()->table($config['link'])->select($config['other'])->where($config['own'], $id)->get()->getResultArray(),
            $config['other']
        ));

        return [
            'success'         => true,
            'item'            => ['id' => (int) $row['id'], 'title' => $row['title']],
            'mode'            => $row['prerequisite_mode'] ?? 'none',
            'prerequisiteIds' => $selected,
            'previous'        => $previous ? ['id' => (int) $previous['id'], 'title' => $previous['title']] : null,
            'candidates'      => array_map(static fn (array $c) => [
                'id'     => (int) $c['id'],
                'title'  => $c['title'],
                'status' => $c['status'],
            ], $candidates),
        ];
    }

    /**
     * @param list<int|string> $prerequisiteIds
     *
     * @return array<string, mixed>
     */
    public function saveContainerDependencies(string $kind, int $offeringId, int $id, string $mode, array $prerequisiteIds): array
    {
        $row  = $this->containerRow($kind, $offeringId, $id);
        $noun = $kind === 'module' ? 'module' : 'learning unit';

        if (! $row) {
            return ['success' => false, 'message' => ucfirst($noun) . ' not found.'];
        }

        if (! in_array($mode, ['none', 'previous', 'custom'], true)) {
            return ['success' => false, 'message' => "Choose how this {$noun} should be locked."];
        }

        $config       = self::$containerKinds[$kind];
        $candidateIds = array_map(static fn (array $c) => (int) $c['id'], $this->earlierSiblings($kind, $row));

        if ($mode === 'previous' && $candidateIds === []) {
            return ['success' => false, 'message' => "This is the first {$noun}, so there's no previous one to wait for."];
        }

        $prerequisiteIds = array_values(array_intersect($candidateIds, array_unique(array_map('intval', $prerequisiteIds))));

        if ($mode === 'custom' && $prerequisiteIds === []) {
            $mode = 'none';
        }

        $db = db_connect();

        $db->transStart();

        $db->table($config['table'])->where('id', $id)->update(['prerequisite_mode' => $mode]);
        $db->table($config['link'])->where($config['own'], $id)->delete();

        if ($mode === 'custom') {
            $now = date('Y-m-d H:i:s');

            $db->table($config['link'])->insertBatch(array_map(
                static fn (int $other) => [$config['own'] => $id, $config['other'] => $other, 'created_at' => $now],
                $prerequisiteIds
            ));
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'Unable to save dependencies.'];
        }

        return [
            'success'         => true,
            'message'         => $mode === 'none' ? 'Dependencies removed.' : 'Dependencies saved.',
            'mode'            => $mode,
            'prerequisiteIds' => $mode === 'custom' ? $prerequisiteIds : [],
        ];
    }

    /**
     * Drops custom dependencies that are no longer on something earlier (after a reorder). One left with none falls
     * back to no dependency.
     *
     * @param int $parentId the offering (modules) or the module (units) whose children were reordered
     */
    public function pruneContainerDependencies(string $kind, int $parentId): void
    {
        $config = self::$containerKinds[$kind];
        $db     = db_connect();

        $siblings = $db->table($config['table'])
            ->select(['id', 'prerequisite_mode'])
            ->where($kind === 'module' ? 'offering_id' : 'module_id', $parentId)
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $position = [];

        foreach ($siblings as $index => $sibling) {
            $position[(int) $sibling['id']] = $index;
        }

        foreach ($siblings as $sibling) {
            if ($sibling['prerequisite_mode'] !== 'custom') {
                continue;
            }

            $id    = (int) $sibling['id'];
            $links = $db->table($config['link'])->where($config['own'], $id)->get()->getResultArray();

            $stale = array_values(array_filter(
                $links,
                static fn (array $link) => ! isset($position[(int) $link[$config['other']]])
                    || $position[(int) $link[$config['other']]] >= $position[$id]
            ));

            if ($stale !== []) {
                $db->table($config['link'])->whereIn('id', array_column($stale, 'id'))->delete();
            }

            if (count($stale) === count($links)) {
                $db->table($config['table'])->where('id', $id)->update(['prerequisite_mode' => 'none']);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | What a student meets
    |--------------------------------------------------------------------------
    */

    /**
     * The modules and units of a course that are locked for this student, and by what.
     *
     * @return array{modules: array<int, list<array{id: int, title: string}>>, units: array<int, list<array{id: int, title: string}>>}
     */
    public function containerLocks(int $userId, int $offeringId): array
    {
        $none = ['modules' => [], 'units' => []];
        $db   = db_connect();

        // Most courses have none: one question answers it.
        $any = $db->query(
            "SELECT 1 FROM course_modules WHERE offering_id = ? AND prerequisite_mode <> 'none'
             UNION ALL
             SELECT 1 FROM learning_units u JOIN course_modules m ON m.id = u.module_id WHERE m.offering_id = ? AND u.prerequisite_mode <> 'none' LIMIT 1",
            [$offeringId, $offeringId]
        )->getRowArray();

        if ($any === null) {
            return $none;
        }

        $modules = $db->table('course_modules')->select(['id', 'title', 'prerequisite_mode'])
            ->where('offering_id', $offeringId)->where('status', 'published')
            ->orderBy('order_index', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();

        if ($modules === []) {
            return $none;
        }

        $units = $db->table('learning_units')->select(['id', 'module_id', 'title', 'prerequisite_mode'])
            ->whereIn('module_id', array_column($modules, 'id'))->where('status', 'published')
            ->orderBy('order_index', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();

        $items = $units === [] ? [] : $db->table('learning_items')->select(['id', 'learning_unit_id'])
            ->whereIn('learning_unit_id', array_column($units, 'id'))->where('status', 'published')
            ->get()->getResultArray();

        $completions = $this->completionMap($userId, array_map(static fn (array $i) => (int) $i['id'], $items));

        $unitDone   = [];
        $moduleDone = [];

        foreach ($units as $unit) {
            $unitDone[(int) $unit['id']] = true;
        }

        foreach ($modules as $module) {
            $moduleDone[(int) $module['id']] = true;
        }

        $moduleOfUnit = array_column($units, 'module_id', 'id');

        foreach ($items as $item) {
            if (($completions[(int) $item['id']]['status'] ?? null) !== 'completed') {
                $unitId                  = (int) $item['learning_unit_id'];
                $unitDone[$unitId]       = false;
                $moduleDone[(int) $moduleOfUnit[$unitId]] = false;
            }
        }

        $moduleLinks = $this->containerLinks('module', array_map(static fn (array $m) => (int) $m['id'], $modules));
        $unitLinks   = $this->containerLinks('unit', array_map(static fn (array $u) => (int) $u['id'], $units));

        $byModule = [];

        foreach ($units as $unit) {
            $byModule[(int) $unit['module_id']][] = $unit;
        }

        $locks = ['modules' => $this->lockedAmong($modules, $moduleDone, $moduleLinks), 'units' => []];

        foreach ($byModule as $group) {
            $locks['units'] += $this->lockedAmong($group, $unitDone, $unitLinks);
        }

        return $locks;
    }

    /**
     * What labels an instructor sees: for each module or unit, the titles it waits on (any status).
     *
     * @param list<array{id: int|string, title: string}> $siblings in order
     *
     * @return array<int, list<string>> id → titles
     */
    public function containerLabels(string $kind, array $siblings): array
    {
        $links  = $this->containerLinks($kind, array_map(static fn (array $s) => (int) $s['id'], $siblings));
        $titles = array_column($siblings, 'title', 'id');
        $modes  = $this->containerModes($kind, array_map(static fn (array $s) => (int) $s['id'], $siblings));
        $labels = [];

        foreach (array_values($siblings) as $index => $sibling) {
            $id = (int) $sibling['id'];

            $labels[$id] = match ($modes[$id] ?? 'none') {
                'previous' => $index > 0 ? [$siblings[$index - 1]['title']] : [],
                'custom'   => array_values(array_map(static fn (int $other) => (string) ($titles[$other] ?? ''), array_filter($links[$id] ?? [], static fn (int $other) => isset($titles[$other])))),
                default    => [],
            };
        }

        return $labels;
    }

    /** The lock on the module or unit an item sits in (module first), or null. @return list<array{id: int, title: string}>|null */
    private function containerLockOfItem(int $userId, int $learningUnitId): ?array
    {
        $row = db_connect()->table('learning_units')
            ->select(['learning_units.module_id', 'course_modules.offering_id'])
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_units.id', $learningUnitId)
            ->get()->getRowArray();

        if (! $row) {
            return null;
        }

        $locks = $this->containerLocks($userId, (int) $row['offering_id']);

        return $locks['modules'][(int) $row['module_id']] ?? $locks['units'][$learningUnitId] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<array> $siblings published, in order
     * @param array<int, bool> $done id → completed
     * @param array<int, list<int>> $links id → custom prerequisite ids
     *
     * @return array<int, list<array{id: int, title: string}>> id → what it waits on, for the locked ones only
     */
    private function lockedAmong(array $siblings, array $done, array $links): array
    {
        $siblings = array_values($siblings);
        $position = [];

        foreach ($siblings as $index => $sibling) {
            $position[(int) $sibling['id']] = $index;
        }

        $locked = [];

        foreach ($siblings as $index => $sibling) {
            $id = (int) $sibling['id'];

            $effective = match ($sibling['prerequisite_mode'] ?? 'none') {
                'previous' => $index > 0 ? [$siblings[$index - 1]] : [],
                'custom'   => array_values(array_filter(
                    $siblings,
                    static fn (array $candidate) => in_array((int) $candidate['id'], $links[$id] ?? [], true) && $position[(int) $candidate['id']] < $index
                )),
                default => [],
            };

            $missing = array_values(array_filter($effective, static fn (array $p) => ! ($done[(int) $p['id']] ?? true)));

            if ($missing !== []) {
                $locked[$id] = array_map(static fn (array $p) => ['id' => (int) $p['id'], 'title' => $p['title']], $missing);
            }
        }

        return $locked;
    }

    /** @return array<int, list<int>> id → its custom prerequisite ids */
    private function containerLinks(string $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $config = self::$containerKinds[$kind];
        $map    = [];

        foreach (db_connect()->table($config['link'])->select([$config['own'], $config['other']])->whereIn($config['own'], $ids)->get()->getResultArray() as $row) {
            $map[(int) $row[$config['own']]][] = (int) $row[$config['other']];
        }

        return $map;
    }

    /** @return array<int, string> id → prerequisite_mode */
    private function containerModes(string $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = db_connect()->table(self::$containerKinds[$kind]['table'])->select(['id', 'prerequisite_mode'])->whereIn('id', $ids)->get()->getResultArray();

        return array_column($rows, 'prerequisite_mode', 'id');
    }

    /** The module (in this offering) or unit (in a module of this offering). @return array<string, mixed>|null */
    private function containerRow(string $kind, int $offeringId, int $id): ?array
    {
        $db = db_connect();

        if ($kind === 'module') {
            return $db->table('course_modules')->where(['id' => $id, 'offering_id' => $offeringId])->get()->getRowArray();
        }

        return $db->table('learning_units')
            ->select('learning_units.*')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_units.id', $id)
            ->where('course_modules.offering_id', $offeringId)
            ->get()->getRowArray();
    }

    /** Siblings before this one (any status), in order. @return list<array<string, mixed>> */
    private function earlierSiblings(string $kind, array $row): array
    {
        $config = self::$containerKinds[$kind];

        $siblings = db_connect()->table($config['table'])
            ->select(['id', 'title', 'status'])
            ->where($kind === 'module' ? 'offering_id' : 'module_id', $kind === 'module' ? $row['offering_id'] : $row['module_id'])
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $earlier = [];

        foreach ($siblings as $sibling) {
            if ((int) $sibling['id'] === (int) $row['id']) {
                break;
            }

            $earlier[] = $sibling;
        }

        return $earlier;
    }
}
