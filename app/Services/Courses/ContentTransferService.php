<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Services\Courses\Grades\GradeItemSyncService;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Copying and moving course content between course offerings: a module (with the units and items chosen), a learning
 * unit (with the items chosen) or a learning item.
 *
 * Copy
 *  - The copy is a draft, at the end of where it goes, and a quiz or assignment comes with its settings, questions and
 *    choices, rubrics, and dates moved by the difference between the two terms' start dates (the way copying a whole
 *    offering does). A file is referenced, not duplicated.
 *  - Dependencies are kept between what was copied together (an item that waited on the item before it still does,
 *    when that one came too) and dropped otherwise. A scheduled publish is not copied.
 *  - What belongs to the old students is never copied: progress, attempts, grades, replies. "Specific students" is
 *    switched off on a copied assessment (its list of students would be empty, hiding it from everyone).
 *  - Copied into the offering it came from, the title gets " (copy)".
 *
 * Move
 *  - Only into another offering, and only while no student has worked on any of it (progress, a quiz attempt or a
 *    submission, a discussion reply): otherwise that work would be left behind or attached to the wrong course.
 *  - What moves becomes a draft (its parts keep their own state, hidden under it), and loses the dependencies that tie it to
 *    what stays behind; what stays behind loses its dependencies on it.
 *
 * Both need the person to teach the offering they take from and the one they put it into. Each is all or nothing.
 */
class ContentTransferService
{
    /** Where a person may put content: their own offerings of the same institution, not archived. @return list<array<string, mixed>> */
    public function offeringsFor(User $user, int $currentOfferingId): array
    {
        $db = db_connect();

        $institution = (int) ($db->table('course_offerings o')
            ->select('t.institution_id')
            ->join('academic_terms t', 't.id = o.term_id')
            ->where('o.id', $currentOfferingId)
            ->get()->getRowArray()['institution_id'] ?? 0);

        $rows = $db->table('instructor_assignments a')
            ->select('o.id, o.section_code, c.course_code, c.course_title, t.name AS term_name, t.academic_year')
            ->join('course_offerings o', 'o.id = a.offering_id')
            ->join('courses c', 'c.id = o.course_id')
            ->join('academic_terms t', 't.id = o.term_id')
            ->where('a.instructor_user_id', $user->id)
            ->where('t.institution_id', $institution)
            ->where('o.status !=', 'archived')
            ->orderBy('t.start_date', 'DESC')
            ->orderBy('c.course_code', 'ASC')
            ->orderBy('o.section_code', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r) => [
            'id'         => (int) $r['id'],
            'label'      => $r['course_code'] . ' · ' . $r['section_code'],
            'title'      => $r['course_title'],
            'term'       => trim($r['term_name'] . ' ' . $r['academic_year']),
            'is_current' => (int) $r['id'] === $currentOfferingId,
        ], $rows);
    }

    /** @return list<array{id: int, title: string, status: string}>|null null when the person does not teach that offering */
    public function modulesOf(User $user, int $offeringId): ?array
    {
        if (! $this->mayEdit($user, $offeringId)) {
            return null;
        }

        return array_map(static fn (array $m) => ['id' => (int) $m['id'], 'title' => $m['title'], 'status' => $m['status']], db_connect()->table('course_modules')
            ->select('id, title, status')->where('offering_id', $offeringId)->orderBy('order_index')->orderBy('id')->get()->getResultArray());
    }

    /** @return list<array{id: int, title: string, status: string}>|null null when the module is not one the person teaches */
    public function unitsOf(User $user, int $moduleId): ?array
    {
        $module = db_connect()->table('course_modules')->where('id', $moduleId)->get()->getRowArray();

        if (! $module || ! $this->mayEdit($user, (int) $module['offering_id'])) {
            return null;
        }

        return array_map(static fn (array $u) => ['id' => (int) $u['id'], 'title' => $u['title'], 'status' => $u['status']], db_connect()->table('learning_units')
            ->select('id, title, status')->where('module_id', $moduleId)->orderBy('order_index')->orderBy('id')->get()->getResultArray());
    }

    /**
     * What a module or unit holds, for the choosing step.
     *
     * @return array{title: string, units: list<array{id: int, title: string, items: list<array{id: int, title: string, item_type: string}>}>}|null
     */
    public function treeOf(User $user, int $offeringId, string $kind, int $id): ?array
    {
        $db = db_connect();

        if (! $this->mayEdit($user, $offeringId)) {
            return null;
        }

        if ($kind === 'module') {
            $root  = $db->table('course_modules')->where(['id' => $id, 'offering_id' => $offeringId])->get()->getRowArray();
            $units = $root ? $db->table('learning_units')->where('module_id', $id)->orderBy('order_index')->orderBy('id')->get()->getResultArray() : [];
        } else {
            $root  = $db->table('learning_units')->select('learning_units.*')->join('course_modules', 'course_modules.id = learning_units.module_id')
                ->where('learning_units.id', $id)->where('course_modules.offering_id', $offeringId)->get()->getRowArray();
            $units = $root ? [$root] : [];
        }

        if (! $root) {
            return null;
        }

        $tree = [];

        foreach ($units as $unit) {
            $tree[] = [
                'id'    => (int) $unit['id'],
                'title' => $unit['title'],
                'items' => array_map(static fn (array $i) => ['id' => (int) $i['id'], 'title' => $i['title'], 'item_type' => $i['item_type']], $db->table('learning_items')
                    ->select('id, title, item_type')->where('learning_unit_id', $unit['id'])->orderBy('order_index')->orderBy('id')->get()->getResultArray()),
            ];
        }

        return ['title' => $root['title'], 'units' => $tree];
    }

    /*
    |--------------------------------------------------------------------------
    | Copy
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{offering_id?: int|string, module_id?: int|string, unit_id?: int|string} $target where it goes
     * @param list<int|string>|null $unitIds units of a module to copy (null: all)
     * @param list<int|string>|null $itemIds items to copy (null: all)
     *
     * @return array<string, mixed>
     */
    public function copy(User $user, int $offeringId, string $kind, int $id, array $target, ?array $unitIds = null, ?array $itemIds = null): array
    {
        try {
            $plan = $this->plan($user, $offeringId, $kind, $id, $target, false);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $db = db_connect();

        $db->transStart();

        try {
            $copied = match ($kind) {
                'module' => $this->copyModule($user, $plan, $unitIds, $itemIds),
                'unit'   => $this->copyUnit($user, $plan, $itemIds),
                default  => $this->copyItem($user, $plan),
            };

            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Copying content failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'It could not be copied. Nothing was changed.'];
        }

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'It could not be copied. Nothing was changed.'];
        }

        return ['success' => true, 'message' => $this->copiedMessage($kind, $copied)] + $copied;
    }

    /** @return array<string, mixed> */
    private function copyModule(User $user, array $plan, ?array $unitIds, ?array $itemIds): array
    {
        $source = $plan['source'];
        $same   = $plan['sameOffering'];

        $moduleId = $this->insert('course_modules', $this->draft() + $this->fresh($source, [
            'offering_id' => $plan['targetOffering'],
            'title'       => $this->titled($source['title'], $same),
            'order_index' => $this->nextOrder('course_modules', 'offering_id', $plan['targetOffering']),
        ]));

        $units = db_connect()->table('learning_units')->where('module_id', $source['id'])->orderBy('order_index')->orderBy('id')->get()->getResultArray();

        if ($unitIds !== null) {
            $wanted = array_flip(array_map('intval', $unitIds));
            $units  = array_values(array_filter($units, static fn (array $u) => isset($wanted[(int) $u['id']])));
        }

        $unitMap = [];
        $count   = ['units' => 0, 'items' => 0, 'assessments' => 0];

        foreach ($units as $unit) {
            $made = $this->cloneUnit($user, $unit, $moduleId, $plan, $itemIds, false);

            $unitMap[(int) $unit['id']] = $made['id'];
            $count['units']++;
            $count['items'] += $made['items'];
            $count['assessments'] += $made['assessments'];
        }

        $this->keepUnitDependencies($units, $unitMap);

        return ['kind' => 'module', 'module_id' => $moduleId, 'offering_id' => $plan['targetOffering']] + $count;
    }

    /** @return array<string, mixed> */
    private function copyUnit(User $user, array $plan, ?array $itemIds): array
    {
        $made = $this->cloneUnit($user, $plan['source'], $plan['targetModule'], $plan, $itemIds, $plan['sameOffering']);

        return ['kind' => 'unit', 'unit_id' => $made['id'], 'module_id' => $plan['targetModule'], 'offering_id' => $plan['targetOffering'], 'units' => 1, 'items' => $made['items'], 'assessments' => $made['assessments']];
    }

    /** @return array<string, mixed> */
    private function copyItem(User $user, array $plan): array
    {
        $made = $this->cloneItem($user, $plan['source'], $plan['targetUnit'], $plan['targetModule'], $plan, $plan['sameOffering']);

        return ['kind' => 'item', 'item_id' => $made['id'], 'unit_id' => $plan['targetUnit'], 'module_id' => $plan['targetModule'], 'offering_id' => $plan['targetOffering'], 'items' => 1, 'assessments' => $made['assessment'] ? 1 : 0];
    }

    /**
     * A unit and the items chosen, into a module.
     *
     * @return array{id: int, items: int, assessments: int}
     */
    private function cloneUnit(User $user, array $unit, int $moduleId, array $plan, ?array $itemIds, bool $suffix): array
    {
        $unitId = $this->insert('learning_units', $this->draft() + $this->fresh($unit, [
            'module_id'   => $moduleId,
            'title'       => $this->titled($unit['title'], $suffix),
            'order_index' => $this->nextOrder('learning_units', 'module_id', $moduleId),
        ]));

        $items = db_connect()->table('learning_items')->where('learning_unit_id', $unit['id'])->orderBy('order_index')->orderBy('id')->get()->getResultArray();

        if ($itemIds !== null) {
            $wanted = array_flip(array_map('intval', $itemIds));
            $items  = array_values(array_filter($items, static fn (array $i) => isset($wanted[(int) $i['id']])));
        }

        $map         = [];
        $assessments = 0;

        foreach ($items as $item) {
            $made = $this->cloneItem($user, $item, $unitId, $moduleId, $plan, false);

            $map[(int) $item['id']] = $made['id'];
            $assessments += $made['assessment'] ? 1 : 0;
        }

        $this->keepItemDependencies((int) $unit['id'], $map);

        return ['id' => $unitId, 'items' => count($items), 'assessments' => $assessments];
    }

    /**
     * One item, with its resources, assessment (settings, questions, rubrics) or discussion prompt.
     *
     * @return array{id: int, assessment: bool}
     */
    private function cloneItem(User $user, array $item, int $unitId, int $moduleId, array $plan, bool $suffix): array
    {
        $db = db_connect();

        $itemId = $this->insert('learning_items', $this->draft() + $this->fresh($item, [
            'learning_unit_id'             => $unitId,
            'title'                        => $this->titled($item['title'], $suffix),
            'order_index'                  => $this->nextOrder('learning_items', 'learning_unit_id', $unitId),
            'prerequisite_mode'            => 'none',
            'dependency_min_score_mode'    => 'none',
            'dependency_min_score_percent' => null,
        ]));

        // A file is the same row: referenced, not duplicated.
        $this->cloneRows('learning_resources', ['learning_item_id' => $item['id']], ['learning_item_id' => $itemId]);

        $hasAssessment = false;
        $assessment    = $db->table('assessments')->where('learning_item_id', $item['id'])->get()->getRowArray();

        if ($assessment) {
            $this->cloneAssessment($user, $assessment, $itemId, $moduleId, $plan, $this->titled($item['title'], $suffix));
            $hasAssessment = true;
        }

        if ($item['item_type'] === 'discussion') {
            $thread = $db->table('discussion_threads')->where('learning_item_id', $item['id'])->get()->getRowArray();

            $this->insert('discussion_threads', [
                'offering_id'      => $plan['targetOffering'],
                'learning_item_id' => $itemId,
                'author_id'        => $user->id,
                'title'            => $this->titled($thread['title'] ?? $item['title'], $suffix),
                'body'             => $thread['body'] ?? '',
                'last_activity_at' => date('Y-m-d H:i:s.u'),
            ]);
        }

        return ['id' => $itemId, 'assessment' => $hasAssessment];
    }

    private function cloneAssessment(User $user, array $assessment, int $itemId, int $moduleId, array $plan, string $title): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $row = $assessment;
        unset($row['id']);

        $row['course_id']        = $plan['targetCourse'];
        $row['module_id']        = $moduleId;
        $row['learning_item_id'] = $itemId;
        $row['title']            = $title;
        $row['status']           = 'Draft';
        $row['created_by']       = $user->id;
        $row['updated_by']       = null;
        $row['created_at']       = $now;
        $row['updated_at']       = $now;

        foreach (['available_from', 'due_date', 'available_until'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->shift($row[$field], $plan['shiftDays']);
            }
        }

        $new = $this->insert('assessments', $row);

        // The settings, but not "specific students": the students it was given to are not in the new course.
        $this->cloneRows('assessment_settings', ['assessment_id' => $assessment['id']], ['assessment_id' => $new, 'assign_to_specific_students' => 0]);

        $questions = $this->cloneRows('assessment_questions', ['assessment_id' => $assessment['id']], ['assessment_id' => $new]);

        foreach ($questions as $old => $newQuestion) {
            $this->cloneRows('assessment_question_choices', ['question_id' => $old], ['question_id' => $newQuestion]);
        }

        foreach ($db->table('assessment_rubrics')->where('assessment_id', $assessment['id'])->orderBy('id')->get()->getResultArray() as $rubric) {
            $old = (int) $rubric['id'];
            unset($rubric['id']);

            $rubric['assessment_id'] = $new;
            $rubric['question_id']   = $rubric['question_id'] === null ? null : ($questions[(int) $rubric['question_id']] ?? null);
            $rubric['created_at']    = $now;
            $rubric['updated_at']    = $now;

            $this->cloneRows('assessment_rubric_criteria', ['rubric_id' => $old], ['rubric_id' => $this->insert('assessment_rubrics', $rubric)]);
        }

        return $new;
    }

    /**
     * Items copied together keep what they waited on, when it was copied too.
     *
     * @param array<int, int> $map old item id => new
     */
    private function keepItemDependencies(int $oldUnitId, array $map): void
    {
        if (count($map) < 2) {
            return;
        }

        $db    = db_connect();
        $order = array_map('intval', array_column($db->table('learning_items')->select('id')->where('learning_unit_id', $oldUnitId)->orderBy('order_index')->orderBy('id')->get()->getResultArray(), 'id'));
        $old   = $db->table('learning_items')->whereIn('id', array_keys($map))->get()->getResultArray();
        $links = $db->table('learning_item_prerequisites')->whereIn('learning_item_id', array_keys($map))->get()->getResultArray();

        $now = date('Y-m-d H:i:s');

        foreach ($old as $item) {
            $id   = (int) $item['id'];
            $mode = $item['prerequisite_mode'];

            if ($mode === 'none') {
                continue;
            }

            $keep = [];

            if ($mode === 'previous') {
                $position = array_search($id, $order, true);
                $before   = $position > 0 ? $order[$position - 1] : null;
                $mode     = $before !== null && isset($map[$before]) ? 'previous' : 'none';
            } else {
                foreach ($links as $link) {
                    if ((int) $link['learning_item_id'] === $id && isset($map[(int) $link['prerequisite_item_id']])) {
                        $keep[] = $map[(int) $link['prerequisite_item_id']];
                    }
                }

                $mode = $keep === [] ? 'none' : 'custom';
            }

            $db->table('learning_items')->where('id', $map[$id])->update([
                'prerequisite_mode'            => $mode,
                'dependency_min_score_mode'    => $mode === 'none' ? 'none' : $item['dependency_min_score_mode'],
                'dependency_min_score_percent' => $mode === 'none' ? null : $item['dependency_min_score_percent'],
            ]);

            foreach ($keep as $needs) {
                $db->table('learning_item_prerequisites')->insert(['learning_item_id' => $map[$id], 'prerequisite_item_id' => $needs, 'created_at' => $now]);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $units the units copied, in order
     * @param array<int, int> $map old unit id => new
     */
    private function keepUnitDependencies(array $units, array $map): void
    {
        if (count($map) < 2) {
            return;
        }

        $db    = db_connect();
        $order = array_map(static fn (array $u) => (int) $u['id'], $db->table('learning_units')->select('id')->where('module_id', $units[0]['module_id'])->orderBy('order_index')->orderBy('id')->get()->getResultArray());
        $links = $db->table('learning_unit_prerequisites')->whereIn('learning_unit_id', array_keys($map))->get()->getResultArray();
        $now   = date('Y-m-d H:i:s');

        foreach ($units as $unit) {
            $id   = (int) $unit['id'];
            $mode = $unit['prerequisite_mode'];

            if ($mode === 'none') {
                continue;
            }

            $keep = [];

            if ($mode === 'previous') {
                $position = array_search($id, $order, true);
                $before   = $position > 0 ? $order[$position - 1] : null;
                $mode     = $before !== null && isset($map[$before]) ? 'previous' : 'none';
            } else {
                foreach ($links as $link) {
                    if ((int) $link['learning_unit_id'] === $id && isset($map[(int) $link['prerequisite_unit_id']])) {
                        $keep[] = $map[(int) $link['prerequisite_unit_id']];
                    }
                }

                $mode = $keep === [] ? 'none' : 'custom';
            }

            $db->table('learning_units')->where('id', $map[$id])->update(['prerequisite_mode' => $mode]);

            foreach ($keep as $needs) {
                $db->table('learning_unit_prerequisites')->insert(['learning_unit_id' => $map[$id], 'prerequisite_unit_id' => $needs, 'created_at' => $now]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Move
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{offering_id?: int|string, module_id?: int|string, unit_id?: int|string} $target
     *
     * @return array<string, mixed>
     */
    public function move(User $user, int $offeringId, string $kind, int $id, array $target): array
    {
        try {
            $plan = $this->plan($user, $offeringId, $kind, $id, $target, true);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $items = $this->itemsUnder($kind, $id);

        if (($busy = $this->studentWorkOn($items)) > 0) {
            return ['success' => false, 'message' => $this->workMessage($kind, $busy)];
        }

        $db = db_connect();
        $db->transStart();

        try {
            match ($kind) {
                'module' => $this->moveModule($plan, $items),
                'unit'   => $this->moveUnit($plan, $items),
                default  => $this->moveItem($plan),
            };

            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Moving content failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'It could not be moved. Nothing was changed.'];
        }

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'It could not be moved. Nothing was changed.'];
        }

        // What stays behind no longer waits on what left.
        $progress = new LearningProgressService();

        match ($kind) {
            'module' => $progress->pruneContainerDependencies('module', $offeringId),
            'unit'   => $progress->pruneContainerDependencies('unit', (int) $plan['source']['module_id']),
            default  => $progress->pruneDependencies((int) $plan['source']['learning_unit_id']),
        };

        return [
            'success'     => true,
            'message'     => ucfirst($this->noun($kind)) . ' moved. It is a draft there until you publish it.',
            'kind'        => $kind,
            'offering_id' => $plan['targetOffering'],
        ];
    }

    private function moveModule(array $plan, array $items): void
    {
        $db = db_connect();
        $id = (int) $plan['source']['id'];

        $db->table('course_modules')->where('id', $id)->update($this->draft() + [
            'offering_id'       => $plan['targetOffering'],
            'order_index'       => $this->nextOrder('course_modules', 'offering_id', $plan['targetOffering']),
            'prerequisite_mode' => 'none',
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        // Its dependencies, and anything in the course it left that waited on it.
        $db->table('course_module_prerequisites')->groupStart()->where('module_id', $id)->orWhere('prerequisite_module_id', $id)->groupEnd()->delete();

        $this->followAcross($items, (int) $plan['targetCourse'], (int) $plan['targetOffering'], null);
    }

    private function moveUnit(array $plan, array $items): void
    {
        $db = db_connect();
        $id = (int) $plan['source']['id'];

        $db->table('learning_units')->where('id', $id)->update($this->draft() + [
            'module_id'         => $plan['targetModule'],
            'order_index'       => $this->nextOrder('learning_units', 'module_id', $plan['targetModule']),
            'prerequisite_mode' => 'none',
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $db->table('learning_unit_prerequisites')->groupStart()->where('learning_unit_id', $id)->orWhere('prerequisite_unit_id', $id)->groupEnd()->delete();

        $this->followAcross($items, (int) $plan['targetCourse'], (int) $plan['targetOffering'], (int) $plan['targetModule']);
    }

    private function moveItem(array $plan): void
    {
        $db = db_connect();
        $id = (int) $plan['source']['id'];

        $db->table('learning_items')->where('id', $id)->update($this->draft() + [
            'learning_unit_id'             => $plan['targetUnit'],
            'order_index'                  => $this->nextOrder('learning_items', 'learning_unit_id', $plan['targetUnit']),
            'prerequisite_mode'            => 'none',
            'dependency_min_score_mode'    => 'none',
            'dependency_min_score_percent' => null,
            'updated_at'                   => date('Y-m-d H:i:s'),
        ]);

        $db->table('learning_item_prerequisites')->groupStart()->where('learning_item_id', $id)->orWhere('prerequisite_item_id', $id)->groupEnd()->delete();

        $this->followAcross([$id], (int) $plan['targetCourse'], (int) $plan['targetOffering'], (int) $plan['targetModule']);

        (new ModuleService())->syncLinkedAssessment($id, ['status' => 'draft']);
    }

    /**
     * What hangs off the moved items goes with them: their assessments (new course, and the new module when the
     * module changed) and discussion threads (new offering). Their gradebook items stay with the old course, archived.
     *
     * @param list<int> $itemIds
     */
    private function followAcross(array $itemIds, int $courseId, int $offeringId, ?int $moduleId): void
    {
        if ($itemIds === []) {
            return;
        }

        $db     = db_connect();
        $grades = new GradeItemSyncService();

        $set = ['course_id' => $courseId];

        if ($moduleId !== null) {
            $set['module_id'] = $moduleId;
        }

        foreach ($db->table('assessments')->select('id, module_id')->whereIn('learning_item_id', $itemIds)->get()->getResultArray() as $assessment) {
            $grades->archiveForAssessment((int) $assessment['id']);
        }

        // A module's own move keeps each assessment on its module; a unit or item move puts it on the new one.
        $db->table('assessments')->whereIn('learning_item_id', $itemIds)->update($set);

        $db->table('discussion_threads')->whereIn('learning_item_id', $itemIds)->update(['offering_id' => $offeringId]);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared
    |--------------------------------------------------------------------------
    */

    /**
     * What is being moved or copied, and where to: all checked before anything is written.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException with the sentence to show
     */
    private function plan(User $user, int $offeringId, string $kind, int $id, array $target, bool $moving): array
    {
        if (! in_array($kind, ['module', 'unit', 'item'], true)) {
            throw new RuntimeException('Choose what to ' . ($moving ? 'move' : 'copy') . '.');
        }

        $db = db_connect();

        if (! $this->mayEdit($user, $offeringId)) {
            throw new RuntimeException('You are not allowed to change this course.');
        }

        $source = match ($kind) {
            'module' => $db->table('course_modules')->where(['id' => $id, 'offering_id' => $offeringId])->get()->getRowArray(),
            'unit'   => $db->table('learning_units')->select('learning_units.*')->join('course_modules', 'course_modules.id = learning_units.module_id')
                ->where('learning_units.id', $id)->where('course_modules.offering_id', $offeringId)->get()->getRowArray(),
            default  => $db->table('learning_items')->select('learning_items.*')
                ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
                ->join('course_modules', 'course_modules.id = learning_units.module_id')
                ->where('learning_items.id', $id)->where('course_modules.offering_id', $offeringId)->get()->getRowArray(),
        };

        if (! $source) {
            throw new RuntimeException(ucfirst($this->noun($kind)) . ' not found.');
        }

        $targetOffering = (int) ($target['offering_id'] ?? 0);

        if ($targetOffering === 0 || ! $this->mayEdit($user, $targetOffering)) {
            throw new RuntimeException('Choose a course you teach.');
        }

        if ($moving && $targetOffering === $offeringId) {
            throw new RuntimeException('Choose another course to move it to.');
        }

        $offerings = $db->table('course_offerings o')
            ->select('o.id, o.course_id, o.status, t.start_date, t.institution_id')
            ->join('academic_terms t', 't.id = o.term_id')
            ->whereIn('o.id', [$offeringId, $targetOffering])->get()->getResultArray();
        $offerings = array_column($offerings, null, 'id');

        $from = $offerings[$offeringId] ?? null;
        $to   = $offerings[$targetOffering] ?? null;

        if (! $from || ! $to || $to['status'] === 'archived' || (int) $from['institution_id'] !== (int) $to['institution_id']) {
            throw new RuntimeException('Choose a course you teach.');
        }

        $plan = [
            'source'         => $source,
            'targetOffering' => $targetOffering,
            'targetCourse'   => (int) $to['course_id'],
            'sameOffering'   => $targetOffering === $offeringId,
            'shiftDays'      => $this->shiftDays((string) $from['start_date'], (string) $to['start_date']),
            'targetModule'   => 0,
            'targetUnit'     => 0,
        ];

        if ($kind === 'unit' || $kind === 'item') {
            $module = $db->table('course_modules')->where(['id' => (int) ($target['module_id'] ?? 0), 'offering_id' => $targetOffering])->get()->getRowArray();

            if (! $module) {
                throw new RuntimeException('Choose a module.');
            }

            $plan['targetModule'] = (int) $module['id'];
        }

        if ($kind === 'item') {
            $unit = $db->table('learning_units')->where(['id' => (int) ($target['unit_id'] ?? 0), 'module_id' => $plan['targetModule']])->get()->getRowArray();

            if (! $unit) {
                throw new RuntimeException('Choose a learning unit.');
            }

            $plan['targetUnit'] = (int) $unit['id'];
        }

        return $plan;
    }

    private function mayEdit(User $user, int $offeringId): bool
    {
        return in_array((new CourseMembership())->roleIn($user, $offeringId), ['instructor', 'chairperson'], true);
    }

    /** @return list<int> every item in the module, unit or item */
    private function itemsUnder(string $kind, int $id): array
    {
        $db = db_connect();

        $builder = $db->table('learning_items')->select('learning_items.id');

        match ($kind) {
            'module' => $builder->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')->where('learning_units.module_id', $id),
            'unit'   => $builder->where('learning_items.learning_unit_id', $id),
            default  => $builder->where('learning_items.id', $id),
        };

        return array_map('intval', array_column($builder->get()->getResultArray(), 'id'));
    }

    /** How many of these items have student work on them: progress, an attempt or submission, a reply. */
    private function studentWorkOn(array $itemIds): int
    {
        if ($itemIds === []) {
            return 0;
        }

        $db = db_connect();

        $busy = array_column($db->table('learning_completions')->select('learning_item_id')->whereIn('learning_item_id', $itemIds)->get()->getResultArray(), 'learning_item_id');

        $busy = array_merge($busy, array_column($db->table('assessments a')->select('a.learning_item_id')
            ->join('assessment_submissions s', 's.assessment_id = a.id')->whereIn('a.learning_item_id', $itemIds)->get()->getResultArray(), 'learning_item_id'));

        $busy = array_merge($busy, array_column($db->table('discussion_threads t')->select('t.learning_item_id')
            ->join('discussion_replies r', 'r.thread_id = t.id')->whereIn('t.learning_item_id', $itemIds)->get()->getResultArray(), 'learning_item_id'));

        return count(array_unique(array_map('intval', $busy)));
    }

    private function workMessage(string $kind, int $busy): string
    {
        return sprintf(
            'Students have already worked on %d item%s in this %s (progress, quiz attempts or submissions, or discussion replies), so it cannot be moved. Copy it instead.',
            $busy,
            $busy === 1 ? '' : 's',
            $this->noun($kind)
        );
    }

    /** @param array<string, mixed> $copied */
    private function copiedMessage(string $kind, array $copied): string
    {
        $what = match ($kind) {
            'module' => "Module copied with {$copied['units']} learning unit" . ($copied['units'] === 1 ? '' : 's') . " and {$copied['items']} item" . ($copied['items'] === 1 ? '' : 's'),
            'unit'   => "Learning unit copied with {$copied['items']} item" . ($copied['items'] === 1 ? '' : 's'),
            default  => 'Learning item copied',
        };

        return $what . '. It is a draft there until you publish it.';
    }

    private function noun(string $kind): string
    {
        return match ($kind) {
            'module' => 'module',
            'unit'   => 'learning unit',
            default  => 'learning item',
        };
    }

    private function titled(string $title, bool $copy): string
    {
        return $copy ? mb_substr($title, 0, 244) . ' (copy)' : $title;
    }

    /** @return array{status: string, scheduled_publish_at: null} */
    private function draft(): array
    {
        return ['status' => 'draft', 'scheduled_publish_at' => null];
    }

    /**
     * The source row as a new row: no id, new timestamps, the dependencies reset (kept later where both ends came along).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function fresh(array $row, array $overrides): array
    {
        unset($row['id']);

        $now = date('Y-m-d H:i:s');

        foreach (['created_at', 'updated_at'] as $stamp) {
            if (array_key_exists($stamp, $row)) {
                $row[$stamp] = $now;
            }
        }

        if (array_key_exists('prerequisite_mode', $row)) {
            $row['prerequisite_mode'] = 'none';
        }

        return $overrides + $row;
    }

    private function nextOrder(string $table, string $parent, int $parentId): int
    {
        $max = db_connect()->table($table)->selectMax('order_index', 'm')->where($parent, $parentId)->get()->getRowArray()['m'] ?? 0;

        return (int) $max + 1;
    }

    /** @return array<int, int> old id => new id */
    private function cloneRows(string $table, array $where, array $overrides): array
    {
        $map = [];
        $now = date('Y-m-d H:i:s');

        foreach (db_connect()->table($table)->where($where)->orderBy('id')->get()->getResultArray() as $row) {
            $old = (int) $row['id'];
            unset($row['id']);

            foreach (['created_at', 'updated_at'] as $stamp) {
                if (array_key_exists($stamp, $row)) {
                    $row[$stamp] = $now;
                }
            }

            $map[$old] = $this->insert($table, $overrides + $row);
        }

        return $map;
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $db = db_connect();

        if (! $db->table($table)->insert($row)) {
            throw new RuntimeException("Could not write to {$table}.");
        }

        return (int) $db->insertID();
    }

    private function shiftDays(string $from, string $to): int
    {
        if ($from === '' || $to === '') {
            return 0;
        }

        return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
    }

    private function shift(?string $stamp, int $days): ?string
    {
        if ($stamp === null || $stamp === '' || $days === 0) {
            return $stamp;
        }

        return (new DateTimeImmutable($stamp))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d H:i:s');
    }
}
