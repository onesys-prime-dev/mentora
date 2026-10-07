<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

/**
 * Student progress through a module: completion, video watch progress and
 * prerequisite locks.
 *
 * Locks. A learning item's prerequisite_mode decides what it waits on:
 *   - previous: the published item directly before it in the same unit
 *   - custom:   the items listed in learning_item_prerequisites
 * Only prerequisites that are published and still *earlier* in the same
 * unit count — a draft or moved-after prerequisite is ignored — so a lock
 * can never become impossible to open. Instructors are never locked.
 *
 * Completion. "Mark as Completed" is allowed once the item is unlocked and:
 *   - video: watched to 90% (uploaded or YouTube; other hosts can't be tracked)
 *   - quiz / assignment: the student has submitted it at least once. Handing
 *     it in completes it automatically (completeOnSubmission); "Mark as
 *     Completed" still works for work handed in before that existed
 *   - everything else: immediately
 * The server enforces this; the disabled button in the UI is only a hint.
 */
class LearningProgressService
{
    public const VIDEO_COMPLETE_PERCENT = 90;

    /*
    |--------------------------------------------------------------------------
    | Module tree annotation (views)
    |--------------------------------------------------------------------------
    */

    /**
     * Adds progress/lock fields to every item of a module tree built by
     * ModuleService (module → learning_units → learning_items):
     *   is_completed, progress_percent, is_locked, locked_by, can_complete,
     *   complete_hint — and, for instructors, dependency_labels.
     * Also recomputes the module's progress from the same data.
     */
    public function annotateTree(User $user, array &$module): void
    {
        $isInstructor = $user->inGroup('instructor');

        $itemIds = [];

        foreach ($module['learning_units'] as $unit) {
            foreach ($unit['learning_items'] as $item) {
                $itemIds[] = (int) $item['id'];
            }
        }

        $completions = $isInstructor ? [] : $this->completionMap((int) $user->id, $itemIds);

        $customPrerequisites = $this->customPrerequisiteMap($itemIds);

        $total = 0;
        $completed = 0;

        foreach ($module['learning_units'] as &$unit) {

            $evaluation = $isInstructor
                ? []
                : $this->evaluateItems($unit['learning_items'], $completions, $customPrerequisites);

            foreach ($unit['learning_items'] as $index => &$item) {

                $id = (int) $item['id'];

                if ($isInstructor) {

                    $item['is_locked'] = false;
                    $item['locked_by'] = [];
                    $item['can_complete'] = false;
                    $item['complete_hint'] = null;
                    $item['progress_percent'] = 0;
                    $item['dependency_labels'] = $this->dependencyLabels($unit['learning_items'], $index, $customPrerequisites[$id] ?? []);

                    continue;

                }

                $state = $evaluation[$id];

                [$canComplete, $hint] = $state['is_locked']
                    ? [false, 'Complete the required items first.']
                    : $this->completionRule($user, $item, $state['progress_percent'], $item['resource'] ?? null);

                $item['is_completed'] = $state['is_completed'];
                $item['progress_percent'] = $state['progress_percent'];
                $item['is_locked'] = $state['is_locked'];
                $item['locked_by'] = $state['locked_by'];
                $item['can_complete'] = $canComplete;
                $item['complete_hint'] = $hint;
                $item['dependency_labels'] = [];

                $total++;

                if ($state['is_completed']) {
                    $completed++;
                }

            }
            unset($item);

        }
        unset($unit);

        if (! $isInstructor) {
            $module['total_items'] = $total;
            $module['completed_items'] = $completed;
            $module['progress'] = $total > 0 ? (int) round($completed / $total * 100) : 0;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Locks
    |--------------------------------------------------------------------------
    */

    /**
     * Why a student can't open an item yet, or null when they can.
     *
     * @return array{message: string, prerequisites: list<array{id: int, title: string}>}|null
     */
    public function lockFor(User $user, int $learningItemId): ?array
    {
        if ($user->inGroup('instructor')) {
            return null;
        }

        $item = db_connect()->table('learning_items')->where('id', $learningItemId)->get()->getRowArray();

        if (! $item || ($item['prerequisite_mode'] ?? 'none') === 'none') {
            return null;
        }

        $state = $this->evaluateUnitFor((int) $user->id, (int) $item['learning_unit_id'])[$learningItemId] ?? null;

        if (! $state || ! $state['is_locked']) {
            return null;
        }

        return [
            'message' => $this->lockMessage($state['locked_by']),
            'prerequisites' => $state['locked_by'],
        ];
    }

    /**
     * lockFor() for many items at once, in a fixed number of queries
     * regardless of how many items or units are involved. For lists (the
     * dashboard's assessments) where asking per item re-reads each unit.
     *
     * @param list<int> $learningItemIds
     * @return array<int, array{message: string, prerequisites: list<array{id: int, title: string}>}|null> item id → lock or null
     */
    public function locksFor(User $user, array $learningItemIds): array
    {
        $locks = array_fill_keys(array_map('intval', $learningItemIds), null);

        if ($locks === [] || $user->inGroup('instructor')) {
            return $locks;
        }

        $db = db_connect();

        $gated = $db->table('learning_items')
            ->select(['id', 'learning_unit_id'])
            ->whereIn('id', array_keys($locks))
            ->where('prerequisite_mode !=', 'none')
            ->get()
            ->getResultArray();

        if ($gated === []) {
            return $locks;
        }

        $unitIds = array_values(array_unique(array_map(static fn (array $r) => (int) $r['learning_unit_id'], $gated)));

        $rows = $db->table('learning_items')
            ->select(['id', 'learning_unit_id', 'title', 'item_type', 'prerequisite_mode'])
            ->whereIn('learning_unit_id', $unitIds)
            ->where('status', 'published')
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $byUnit = [];
        $allIds = [];

        foreach ($rows as $row) {
            $byUnit[(int) $row['learning_unit_id']][] = $row;
            $allIds[] = (int) $row['id'];
        }

        $completions = $this->completionMap((int) $user->id, $allIds);
        $custom = $this->customPrerequisiteMap($allIds);

        $states = [];

        foreach ($byUnit as $items) {
            $states += $this->evaluateItems($items, $completions, $custom);
        }

        foreach ($gated as $row) {

            $id = (int) $row['id'];

            $state = $states[$id] ?? null;

            if ($state && $state['is_locked']) {
                $locks[$id] = [
                    'message' => $this->lockMessage($state['locked_by']),
                    'prerequisites' => $state['locked_by'],
                ];
            }

        }

        return $locks;
    }

    public function lockMessage(array $lockedBy): string
    {
        $titles = array_map(static fn (array $p) => '“' . $p['title'] . '”', $lockedBy);

        $list = count($titles) > 1
            ? implode(', ', array_slice($titles, 0, -1)) . ' and ' . end($titles)
            : ($titles[0] ?? 'the previous item');

        return "Complete {$list} first.";
    }

    /*
    |--------------------------------------------------------------------------
    | Completion
    |--------------------------------------------------------------------------
    */

    public function markComplete(User $user, int $offeringId, int $learningItemId): array
    {
        $item = $this->visibleItemForStudent($user, $offeringId, $learningItemId);

        if (! $item) {
            return ['success' => false, 'message' => 'Learning item not found.'];
        }

        $unitId = (int) $item['learning_unit_id'];

        $before = $this->evaluateUnitFor((int) $user->id, $unitId);

        $state = $before[$learningItemId] ?? null;

        if (! $state) {
            return ['success' => false, 'message' => 'Learning item not found.'];
        }

        if ($state['is_locked']) {
            return ['success' => false, 'message' => $this->lockMessage($state['locked_by'])];
        }

        if (! $state['is_completed']) {

            [$canComplete, $hint] = $this->completionRule($user, $item, $state['progress_percent']);

            if (! $canComplete) {
                return ['success' => false, 'message' => $hint];
            }

            $this->upsertCompletion((int) $user->id, $learningItemId, [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

        }

        $after = $this->evaluateUnitFor((int) $user->id, $unitId);

        $unlocked = [];

        foreach ($after as $id => $afterState) {
            if (($before[$id]['is_locked'] ?? false) && ! $afterState['is_locked']) {
                $unlocked[] = $id;
            }
        }

        return [
            'success' => true,
            'message' => 'Marked as completed.',
            'itemId' => $learningItemId,
            'module' => $this->moduleProgress((int) $user->id, (int) $item['module_id']),
            'unlockedItemIds' => $unlocked,
        ];
    }

    /**
     * A quiz or assignment counts as completed the moment the student hands it
     * in (any submission that is no longer in progress). The quiz and
     * assignment services call this right after recording a submission.
     *
     * Does nothing for an assessment with no learning item, or one the student
     * already completed. The submission itself was already checked (enrolled,
     * open, not locked), so this only records the completion.
     */
    public function completeOnSubmission(int $studentId, int $assessmentId): void
    {
        $assessment = db_connect()->table('assessments')
            ->select('learning_item_id')
            ->where('id', $assessmentId)
            ->get()
            ->getRowArray();

        $learningItemId = (int) ($assessment['learning_item_id'] ?? 0);

        if ($learningItemId === 0) {
            return;
        }

        $existing = db_connect()->table('learning_completions')
            ->select('status')
            ->where('learning_item_id', $learningItemId)
            ->where('student_user_id', $studentId)
            ->get()
            ->getRowArray();

        if (($existing['status'] ?? null) === 'completed') {
            return;
        }

        $this->upsertCompletion($studentId, $learningItemId, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Records how far a student has watched a video. Progress only ever
     * goes up, so replaying the start doesn't undo it.
     */
    public function saveVideoProgress(User $user, int $offeringId, int $learningItemId, int $percent): array
    {
        $item = $this->visibleItemForStudent($user, $offeringId, $learningItemId);

        if (! $item || $item['item_type'] !== 'video') {
            return ['success' => false, 'message' => 'Video not found.'];
        }

        if ($this->lockFor($user, $learningItemId)) {
            return ['success' => false, 'message' => 'This item is locked.'];
        }

        $percent = max(0, min(100, $percent));

        $existing = db_connect()->table('learning_completions')
            ->where('learning_item_id', $learningItemId)
            ->where('student_user_id', $user->id)
            ->get()
            ->getRowArray();

        $progress = max($percent, (int) ($existing['progress_percent'] ?? 0));

        $this->upsertCompletion((int) $user->id, $learningItemId, [
            'progress_percent' => $progress,
            'status' => ($existing['status'] ?? null) === 'completed' ? 'completed' : 'in_progress',
        ]);

        return [
            'success' => true,
            'progress' => $progress,
            'canComplete' => $progress >= self::VIDEO_COMPLETE_PERCENT,
        ];
    }

    /**
     * Whether the item's own requirement is met (ignoring locks).
     *
     * @param array|null $resource the item's learning_resources row, when already loaded
     * @return array{0: bool, 1: string|null} [can complete, hint when not]
     */
    public function completionRule(User $user, array $item, int $progressPercent, ?array $resource = null): array
    {
        $itemId = (int) $item['id'];

        switch ($item['item_type']) {

            case 'video':

                if (! $this->isTrackableVideo($itemId, $resource)) {
                    return [true, null];
                }

                return $progressPercent >= self::VIDEO_COMPLETE_PERCENT
                    ? [true, null]
                    : [false, 'Watch at least ' . self::VIDEO_COMPLETE_PERCENT . '% of the video to complete it.'];

            case 'quiz':
            case 'assignment':

                $isQuiz = $item['item_type'] === 'quiz';

                $assessment = db_connect()->table('assessments')->select('id')->where('learning_item_id', $itemId)->get()->getRowArray();

                if (! $assessment) {
                    return [false, "This {$item['item_type']} isn't set up yet."];
                }

                $submitted = db_connect()->table('assessment_submissions')
                    ->where('assessment_id', $assessment['id'])
                    ->where('student_id', $user->id)
                    ->whereNotIn('status', ['In Progress', 'Cancelled'])
                    ->countAllResults() > 0;

                return $submitted
                    ? [true, null]
                    : [false, $isQuiz ? 'Submit the quiz to complete it.' : 'Submit your work to complete it.'];

            case 'discussion':

                $replied = db_connect()->table('discussion_replies')
                    ->join('discussion_threads', 'discussion_threads.id = discussion_replies.thread_id')
                    ->where('discussion_threads.learning_item_id', $itemId)
                    ->where('discussion_replies.author_id', $user->id)
                    ->countAllResults() > 0;

                return $replied
                    ? [true, null]
                    : [false, 'Post a reply in the discussion to complete it.'];

            default:

                return [true, null];

        }
    }

    /**
     * Uploaded videos and YouTube links can report playback progress;
     * other embeds can't, so they don't gate completion.
     */
    public function isTrackableVideo(int $learningItemId, ?array $resource = null): bool
    {
        $resource ??= db_connect()->table('learning_resources')->where('learning_item_id', $learningItemId)->get()->getRowArray();

        if (! $resource) {
            return false;
        }

        if (! empty($resource['file_id'])) {
            return true;
        }

        return $this->youtubeId((string) ($resource['resource_url'] ?? '')) !== null;
    }

    public function youtubeId(string $url): ?string
    {
        return preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $match)
            ? $match[1]
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Dependencies (instructor)
    |--------------------------------------------------------------------------
    */

    public function dependencyOptions(int $offeringId, int $learningItemId): ?array
    {
        $item = $this->itemInOffering($offeringId, $learningItemId);

        if (! $item) {
            return null;
        }

        $candidates = $this->earlierItems($item);

        $previous = $candidates === [] ? null : end($candidates);

        $selected = array_map(
            'intval',
            array_column(
                db_connect()->table('learning_item_prerequisites')->select('prerequisite_item_id')
                    ->where('learning_item_id', $learningItemId)->get()->getResultArray(),
                'prerequisite_item_id'
            )
        );

        return [
            'success' => true,
            'item' => ['id' => (int) $item['id'], 'title' => $item['title']],
            'mode' => $item['prerequisite_mode'] ?? 'none',
            'prerequisiteIds' => $selected,
            'previous' => $previous ? ['id' => (int) $previous['id'], 'title' => $previous['title']] : null,
            'candidates' => array_map(static fn (array $c) => [
                'id' => (int) $c['id'],
                'title' => $c['title'],
                'item_type' => $c['item_type'],
                'status' => $c['status'],
            ], $candidates),
        ];
    }

    public function saveDependencies(int $offeringId, int $learningItemId, string $mode, array $prerequisiteIds): array
    {
        $item = $this->itemInOffering($offeringId, $learningItemId);

        if (! $item) {
            return ['success' => false, 'message' => 'Learning item not found.'];
        }

        if (! in_array($mode, ['none', 'previous', 'custom'], true)) {
            return ['success' => false, 'message' => 'Choose how this item should be locked.'];
        }

        $candidateIds = array_map(static fn (array $c) => (int) $c['id'], $this->earlierItems($item));

        if ($mode === 'previous' && $candidateIds === []) {
            return ['success' => false, 'message' => "This is the first item in its unit, so there's no previous item to wait for."];
        }

        $prerequisiteIds = array_values(array_intersect(
            $candidateIds,
            array_unique(array_map('intval', $prerequisiteIds))
        ));

        if ($mode === 'custom' && $prerequisiteIds === []) {
            $mode = 'none';
        }

        $db = db_connect();

        $db->transStart();

        $db->table('learning_items')->where('id', $learningItemId)->update(['prerequisite_mode' => $mode]);

        $db->table('learning_item_prerequisites')->where('learning_item_id', $learningItemId)->delete();

        if ($mode === 'custom') {

            $now = date('Y-m-d H:i:s');

            $db->table('learning_item_prerequisites')->insertBatch(array_map(
                static fn (int $id) => [
                    'learning_item_id' => $learningItemId,
                    'prerequisite_item_id' => $id,
                    'created_at' => $now,
                ],
                $prerequisiteIds
            ));

        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'Unable to save dependencies.'];
        }

        return [
            'success' => true,
            'message' => $mode === 'none' ? 'Dependencies removed.' : 'Dependencies saved.',
            'mode' => $mode,
            'prerequisiteIds' => $mode === 'custom' ? $prerequisiteIds : [],
        ];
    }

    /**
     * Drops custom prerequisites that are no longer earlier in the unit
     * (after a reorder or a deletion). An item left with none falls back
     * to no dependency.
     */
    public function pruneDependencies(int $learningUnitId): void
    {
        $db = db_connect();

        $items = $db->table('learning_items')
            ->select(['id', 'order_index', 'prerequisite_mode'])
            ->where('learning_unit_id', $learningUnitId)
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $position = [];

        foreach ($items as $index => $item) {
            $position[(int) $item['id']] = $index;
        }

        foreach ($items as $item) {

            if ($item['prerequisite_mode'] !== 'custom') {
                continue;
            }

            $id = (int) $item['id'];

            $links = $db->table('learning_item_prerequisites')->where('learning_item_id', $id)->get()->getResultArray();

            $stale = array_values(array_filter(
                $links,
                static fn (array $link) => ! isset($position[(int) $link['prerequisite_item_id']])
                    || $position[(int) $link['prerequisite_item_id']] >= $position[$id]
            ));

            if ($stale !== []) {
                $db->table('learning_item_prerequisites')->whereIn('id', array_column($stale, 'id'))->delete();
            }

            if (count($stale) === count($links)) {
                $db->table('learning_items')->where('id', $id)->update(['prerequisite_mode' => 'none']);
            }

        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Lock/completion state for a unit's published items, in order.
     *
     * @param list<array> $items published items of one unit, ordered
     * @return array<int, array{is_completed: bool, progress_percent: int, is_locked: bool, locked_by: list<array{id: int, title: string}>}>
     */
    private function evaluateItems(array $items, array $completions, array $customPrerequisites): array
    {
        $items = array_values($items);

        $position = [];

        foreach ($items as $index => $item) {
            $position[(int) $item['id']] = $index;
        }

        $result = [];

        foreach ($items as $index => $item) {

            $id = (int) $item['id'];

            $effective = match ($item['prerequisite_mode'] ?? 'none') {
                'previous' => $index > 0 ? [$items[$index - 1]] : [],
                'custom' => array_values(array_filter(
                    $items,
                    static fn (array $candidate) => in_array((int) $candidate['id'], $customPrerequisites[$id] ?? [], true)
                        && $position[(int) $candidate['id']] < $index
                )),
                default => [],
            };

            $missing = array_values(array_filter(
                $effective,
                static fn (array $prerequisite) => ($completions[(int) $prerequisite['id']]['status'] ?? null) !== 'completed'
            ));

            $result[$id] = [
                'is_completed' => ($completions[$id]['status'] ?? null) === 'completed',
                'progress_percent' => (int) ($completions[$id]['progress_percent'] ?? 0),
                'is_locked' => $missing !== [],
                'locked_by' => array_map(
                    static fn (array $p) => ['id' => (int) $p['id'], 'title' => $p['title']],
                    $missing
                ),
            ];

        }

        return $result;
    }

    private function evaluateUnitFor(int $userId, int $learningUnitId): array
    {
        $items = db_connect()->table('learning_items')
            ->select(['id', 'title', 'item_type', 'prerequisite_mode'])
            ->where('learning_unit_id', $learningUnitId)
            ->where('status', 'published')
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $ids = array_map(static fn (array $i) => (int) $i['id'], $items);

        return $this->evaluateItems($items, $this->completionMap($userId, $ids), $this->customPrerequisiteMap($ids));
    }

    /**
     * Human-readable "locked until" labels for the instructor's view,
     * computed over all items (drafts included) in the unit.
     *
     * @return list<string>
     */
    private function dependencyLabels(array $unitItems, int $index, array $customIds): array
    {
        $item = $unitItems[$index];

        return match ($item['prerequisite_mode'] ?? 'none') {
            'previous' => $index > 0 ? [$unitItems[$index - 1]['title']] : [],
            'custom' => array_values(array_map(
                static fn (array $c) => $c['title'],
                array_filter($unitItems, static fn (array $c) => in_array((int) $c['id'], $customIds, true))
            )),
            default => [],
        };
    }

    /**
     * @return array<int, array> item id → completion row
     */
    private function completionMap(int $userId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = db_connect()->table('learning_completions')
            ->select(['learning_item_id', 'status', 'progress_percent'])
            ->where('student_user_id', $userId)
            ->whereIn('learning_item_id', $itemIds)
            ->get()
            ->getResultArray();

        return array_column($rows, null, 'learning_item_id');
    }

    /**
     * @return array<int, list<int>> item id → its custom prerequisite ids
     */
    private function customPrerequisiteMap(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $map = [];

        $rows = db_connect()->table('learning_item_prerequisites')
            ->select(['learning_item_id', 'prerequisite_item_id'])
            ->whereIn('learning_item_id', $itemIds)
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $map[(int) $row['learning_item_id']][] = (int) $row['prerequisite_item_id'];
        }

        return $map;
    }

    private function upsertCompletion(int $userId, int $learningItemId, array $fields): void
    {
        $db = db_connect();

        $now = date('Y-m-d H:i:s');

        $existing = $db->table('learning_completions')
            ->select('id')
            ->where('learning_item_id', $learningItemId)
            ->where('student_user_id', $userId)
            ->get()
            ->getRowArray();

        if ($existing) {
            $db->table('learning_completions')->where('id', $existing['id'])->update($fields + ['updated_at' => $now]);
            return;
        }

        $db->table('learning_completions')->insert($fields + [
            'learning_item_id' => $learningItemId,
            'student_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The item, when a student enrolled in the offering can see it
     * (item, unit and module all published).
     */
    private function visibleItemForStudent(User $user, int $offeringId, int $learningItemId): ?array
    {
        if (! $user->inGroup('student')) {
            return null;
        }

        $db = db_connect();

        $enrolled = $db->table('enrollments')
            ->where('offering_id', $offeringId)
            ->where('student_user_id', $user->id)
            ->where('status', 'enrolled')
            ->countAllResults() > 0;

        if (! $enrolled) {
            return null;
        }

        return $db->table('learning_items')
            ->select(['learning_items.*', 'learning_units.module_id'])
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_items.id', $learningItemId)
            ->where('course_modules.offering_id', $offeringId)
            ->where('learning_items.status', 'published')
            ->where('learning_units.status', 'published')
            ->where('course_modules.status', 'published')
            ->get()
            ->getRowArray();
    }

    private function itemInOffering(int $offeringId, int $learningItemId): ?array
    {
        return db_connect()->table('learning_items')
            ->select('learning_items.*')
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_items.id', $learningItemId)
            ->where('course_modules.offering_id', $offeringId)
            ->get()
            ->getRowArray();
    }

    /**
     * Items before this one in its unit (any status), in order.
     */
    private function earlierItems(array $item): array
    {
        $siblings = db_connect()->table('learning_items')
            ->select(['id', 'title', 'item_type', 'status'])
            ->where('learning_unit_id', $item['learning_unit_id'])
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $earlier = [];

        foreach ($siblings as $sibling) {

            if ((int) $sibling['id'] === (int) $item['id']) {
                break;
            }

            $earlier[] = $sibling;

        }

        return $earlier;
    }

    /**
     * The student's progress over the module's visible items.
     */
    private function moduleProgress(int $userId, int $moduleId): array
    {
        $itemIds = array_map(
            'intval',
            array_column(
                db_connect()->table('learning_items')
                    ->select('learning_items.id')
                    ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
                    ->where('learning_units.module_id', $moduleId)
                    ->where('learning_units.status', 'published')
                    ->where('learning_items.status', 'published')
                    ->get()
                    ->getResultArray(),
                'id'
            )
        );

        $completed = count(array_filter(
            $this->completionMap($userId, $itemIds),
            static fn (array $row) => $row['status'] === 'completed'
        ));

        $total = count($itemIds);

        return [
            'progress' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'completed_items' => $completed,
            'total_items' => $total,
        ];
    }
}
