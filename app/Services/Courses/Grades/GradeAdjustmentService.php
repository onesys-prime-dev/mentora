<?php

namespace App\Services\Courses\Grades;

use App\Models\Grades\GradeAdjustmentModel;
use App\Models\Grades\GradeItemModel;
use App\Models\Grades\GradingSchemeModel;
use CodeIgniter\Shield\Entities\User;

/**
 * Grading on a curve.
 *
 * An adjustment changes how one item's scores, or every student's final
 * percentage, are counted. The scores the instructor entered are never
 * changed: the grading engine applies the adjustment while it calculates, so
 * every screen, export and handoff agrees, and taking it off puts everything
 * back. Its numbers are worked out once, when it is applied, and kept, so a
 * score entered later does not move a curve the class has already seen.
 *
 * Methods:
 *   add_points    add the same number of points to everyone
 *   scale_to_max  multiply so the highest score becomes full marks
 *   linear        stretch the class's lowest-to-highest range onto a new range
 *   set_mean      move everyone so the class average reaches a target
 * Results stay between 0 and the maximum. An instructor's override of a final
 * grade is never touched. Excused work is not curved.
 */
class GradeAdjustmentService
{
    public const METHODS = ['add_points', 'scale_to_max', 'linear', 'set_mean'];

    public const SCOPES = ['item', 'final'];

    private GradeAdjustmentModel $adjustments;

    private GradeItemModel $items;

    private GradingEngineService $engine;

    public function __construct()
    {
        $this->adjustments = new GradeAdjustmentModel();
        $this->items = new GradeItemModel();
        $this->engine = new GradingEngineService();
    }

    /*
    |--------------------------------------------------------------------------
    | The arithmetic (pure)
    |--------------------------------------------------------------------------
    */

    /**
     * One value after an adjustment, kept between 0 and `$max`.
     *
     * @param array<string, float|int> $params the numbers kept when it was applied
     */
    public static function adjust(string $method, array $params, float $value, float $max): float
    {
        $adjusted = match ($method) {
            'add_points' => $value + (float) ($params['points'] ?? 0),
            'scale_to_max' => $value * (float) ($params['factor'] ?? 1),
            'linear' => (float) ($params['slope'] ?? 1) * $value + (float) ($params['intercept'] ?? 0),
            'set_mean' => $value + (float) ($params['shift'] ?? 0),
            default => $value,
        };

        return round(max(0.0, min($max, $adjusted)), 2);
    }

    /**
     * Works out the numbers an adjustment will keep, from what the instructor
     * asked for and how the class scored.
     *
     * @param array<string, mixed> $input   what was asked for
     * @param list<float>          $values  the class's scores (or final percentages)
     * @param bool                 $percent true when the values are final percentages (not points of one item)
     *
     * @return array{success: true, params: array<string, float>, label: string}|array{success: false, message: string}
     */
    public static function freeze(string $method, array $input, array $values, float $max, bool $percent = false): array
    {
        $number = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        switch ($method) {

            case 'add_points':

                $points = $input['points'] ?? null;

                if (!is_numeric($points) || (float) $points == 0.0 || abs((float) $points) > $max) {
                    return ['success' => false, 'message' => 'Enter how many points to add (not 0, and no more than ' . $number($max) . ').'];
                }

                return ['success' => true, 'params' => ['points' => (float) $points], 'label' => ((float) $points > 0 ? 'Add ' : 'Take off ') . $number(abs((float) $points)) . ($percent ? ' percentage points' : ' points') . ' for everyone'];

            case 'scale_to_max':

                if ($values === []) {
                    return ['success' => false, 'message' => 'Nobody has a score to curve yet.'];
                }

                $highest = max($values);

                if ($highest <= 0) {
                    return ['success' => false, 'message' => 'The highest score is 0, so there is nothing to scale.'];
                }

                if ($highest >= $max) {
                    return ['success' => false, 'message' => 'The highest score is already full marks, so scaling would change nothing.'];
                }

                $factor = $max / $highest;

                return ['success' => true, 'params' => ['factor' => $factor], 'label' => 'Scale so the highest score (' . $number($highest) . ') becomes full marks (× ' . $number($factor) . ')'];

            case 'linear':

                $newLow = $input['new_low'] ?? null;
                $newHigh = $input['new_high'] ?? null;

                if (!is_numeric($newLow) || !is_numeric($newHigh) || (float) $newLow < 0 || (float) $newHigh > $max || (float) $newHigh <= (float) $newLow) {
                    return ['success' => false, 'message' => 'Enter a new lowest and highest score between 0 and ' . $number($max) . ', the highest above the lowest.'];
                }

                if (count($values) < 2 || max($values) <= min($values)) {
                    return ['success' => false, 'message' => 'The class needs at least two different scores to stretch them.'];
                }

                $low = min($values);
                $high = max($values);
                $slope = ((float) $newHigh - (float) $newLow) / ($high - $low);

                return [
                    'success' => true,
                    'params' => ['slope' => $slope, 'intercept' => (float) $newLow - $slope * $low],
                    'label' => 'Stretch the class range ' . $number($low) . ' to ' . $number($high) . ' onto ' . $number($newLow) . ' to ' . $number($newHigh),
                ];

            case 'set_mean':

                $target = $input['target_mean'] ?? null;

                if (!is_numeric($target) || (float) $target < 0 || (float) $target > $max) {
                    return ['success' => false, 'message' => 'Enter a class average between 0 and ' . $number($max) . '.'];
                }

                if ($values === []) {
                    return ['success' => false, 'message' => 'Nobody has a score to curve yet.'];
                }

                $mean = array_sum($values) / count($values);

                return ['success' => true, 'params' => ['shift' => (float) $target - $mean], 'label' => 'Move the class average from ' . $number($mean) . ' to ' . $number($target)];

        }

        return ['success' => false, 'message' => 'Choose how to curve.'];
    }

    /*
    |--------------------------------------------------------------------------
    | Preview, apply, undo
    |--------------------------------------------------------------------------
    */

    /**
     * What a curve would do, without saving anything.
     *
     * @return array<string, mixed>
     */
    public function preview(int $offeringId, string $scope, ?int $itemId, string $method, array $input): array
    {
        $prepared = $this->prepare($offeringId, $scope, $itemId, $method, $input);

        if (!$prepared['success']) {
            return $prepared;
        }

        ['context' => $context, 'candidate' => $candidate, 'item' => $item, 'max' => $max] = $prepared;

        $passing = (float) $context['scheme']['passing_percentage'];

        $with = $context;
        $this->withAdjustment($with, $candidate);

        $names = [];

        foreach (GradeSheetExportService::roster($offeringId) as $row) {
            $names[(int) $row['student_id']] = (string) $row['student_name'];
        }

        $rows = [];
        $raised = $unchanged = $capped = 0;
        $before = $after = [];
        $passBefore = $passAfter = 0;

        foreach ($context['studentIds'] as $studentId) {

            $studentId = (int) $studentId;

            $was = $this->engine->compute($context, $studentId)['percentage'];
            $now = $this->engine->compute($with, $studentId)['percentage'];

            $itemBefore = $itemAfter = null;

            if ($scope === 'item') {
                $cell = $context['scores'][(int) $item['id']][$studentId] ?? null;

                if ($cell && $cell['raw_score'] !== null && empty($cell['is_missing'])) {
                    $itemBefore = (float) $cell['raw_score'];
                    $itemAfter = self::adjust($method, $candidate['params'], $itemBefore, $max);
                }
            }

            $moved = $scope === 'item' ? ($itemAfter !== null && $itemAfter > $itemBefore) : ($now !== null && $was !== null && $now > $was);
            $lowered = $scope === 'item' ? ($itemAfter !== null && $itemAfter < $itemBefore) : ($now !== null && $was !== null && $now < $was);

            $reached = $scope === 'item' ? ($itemAfter !== null && $itemAfter >= $max) : ($now !== null && $now >= 100.0);

            $moved ? $raised++ : ($lowered ? null : $unchanged++);

            if ($reached && $moved) {
                $capped++;
            }

            if ($was !== null) {
                $before[] = $was;
                $passBefore += $was >= $passing ? 1 : 0;
            }

            if ($now !== null) {
                $after[] = $now;
                $passAfter += $now >= $passing ? 1 : 0;
            }

            $rows[] = [
                'student_id' => $studentId,
                'name' => $names[$studentId] ?? ('Student ' . $studentId),
                'item_before' => $itemBefore,
                'item_after' => $itemAfter,
                'final_before' => $was,
                'final_after' => $now,
            ];

        }

        $mean = static fn (array $values): ?float => $values === [] ? null : round(array_sum($values) / count($values), 2);

        return [
            'success' => true,
            'label' => $candidate['label'],
            'scope' => $scope,
            'item' => $item ? ['id' => (int) $item['id'], 'name' => $item['assessment_title'] ?? $item['name'], 'max' => $max] : null,
            'summary' => [
                'students' => count($rows),
                'raised' => $raised,
                'unchanged' => $unchanged,
                'lowered' => count($rows) - $raised - $unchanged,
                'at_full_marks' => $capped,
                'mean_before' => $mean($before),
                'mean_after' => $mean($after),
                'passing_before' => $passBefore,
                'passing_after' => $passAfter,
                'passing_mark' => $passing,
            ],
            'rows' => $rows,
        ];
    }

    public function apply(User $user, int $offeringId, string $scope, ?int $itemId, string $method, array $input, string $note = ''): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $preview = $this->preview($offeringId, $scope, $itemId, $method, $input);

        if (!$preview['success']) {
            return $preview;
        }

        $prepared = $this->prepare($offeringId, $scope, $itemId, $method, $input);

        $candidate = $prepared['candidate'];

        $targetKey = $scope === 'item' ? (int) $itemId : 0;

        $now = date('Y-m-d H:i:s');

        $db = db_connect();

        $db->transStart();

        // A newer curve on the same target replaces the old one.
        $this->adjustments
            ->where('course_offering_id', $offeringId)->where('target_key', $targetKey)->where('live_marker', 1)
            ->set(['live_marker' => null, 'undone_at' => $now, 'undone_by' => $user->id])
            ->update();

        $this->adjustments->insert([
            'course_offering_id' => $offeringId,
            'scope' => $scope,
            'grade_item_id' => $scope === 'item' ? $itemId : null,
            'method' => $method,
            'input' => json_encode($input),
            'params' => json_encode($candidate['params'] + ['label' => $candidate['label']]),
            'note' => trim($note) !== '' ? trim($note) : null,
            'students_raised' => $preview['summary']['raised'],
            'created_by' => $user->id,
            'created_at' => $now,
            'live_marker' => 1,
            'target_key' => $targetKey,
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'The curve could not be applied. Nothing was changed.'];
        }

        $this->refresh($offeringId);

        GradeAudit::record('grades.curve_applied', $offeringId, [
            'new' => ['scope' => $scope, 'item_id' => $itemId, 'method' => $method, 'label' => $candidate['label'], 'params' => $candidate['params'], 'students_raised' => $preview['summary']['raised'], 'note' => trim($note)],
        ], $user);

        return ['success' => true, 'message' => 'Curve applied: ' . $candidate['label'] . '.', 'summary' => $preview['summary']];
    }

    public function undo(User $user, int $offeringId, int $adjustmentId): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $row = $this->adjustments->find($adjustmentId);

        if (!$row || (int) $row['course_offering_id'] !== $offeringId || (int) ($row['live_marker'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'That curve is not applied.'];
        }

        $this->adjustments->update($adjustmentId, ['live_marker' => null, 'undone_at' => date('Y-m-d H:i:s'), 'undone_by' => $user->id]);

        $this->refresh($offeringId);

        $params = json_decode((string) $row['params'], true) ?: [];

        GradeAudit::record('grades.curve_undone', $offeringId, [
            'old' => ['adjustment_id' => $adjustmentId, 'scope' => $row['scope'], 'item_id' => $row['grade_item_id'], 'method' => $row['method'], 'label' => $params['label'] ?? null],
        ], $user);

        return ['success' => true, 'message' => 'Curve removed. Grades are back as they were.'];
    }

    /**
     * The curves that count now, and the items one can be applied to.
     *
     * @return array{live: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function describe(int $offeringId): array
    {
        $scheme = (new GradingSchemeService())->forOffering($offeringId);

        $itemNames = [];

        $items = [];

        foreach ($this->items->listForScheme((int) $scheme['id'], true) as $item) {

            $name = (string) ($item['assessment_title'] ?? $item['name']);

            $itemNames[(int) $item['id']] = $name;

            $items[] = ['id' => (int) $item['id'], 'name' => $name, 'max' => (float) $item['max_points']];

        }

        $live = [];

        foreach ($this->adjustments->where('course_offering_id', $offeringId)->where('live_marker', 1)->orderBy('id')->findAll() as $row) {

            $params = json_decode((string) $row['params'], true) ?: [];

            $live[] = [
                'id' => (int) $row['id'],
                'scope' => $row['scope'],
                'target' => $row['scope'] === 'final' ? 'Final grade' : ($itemNames[(int) $row['grade_item_id']] ?? 'An item'),
                'label' => (string) ($params['label'] ?? $row['method']),
                'note' => $row['note'],
                'created_at' => $row['created_at'],
            ];

        }

        return ['live' => $live, 'items' => $items];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function prepare(int $offeringId, string $scope, ?int $itemId, string $method, array $input): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            return ['success' => false, 'message' => 'Choose what to curve.'];
        }

        if (!in_array($method, self::METHODS, true)) {
            return ['success' => false, 'message' => 'Choose how to curve.'];
        }

        $context = $this->engine->context($offeringId);

        if (!$context) {
            return ['success' => false, 'message' => 'This section has no grading yet.'];
        }

        // What the class scored without any curve on the same target.
        $bare = $context;

        $item = null;

        if ($scope === 'item') {

            foreach ($context['items'] as $candidate) {
                if ((int) $candidate['id'] === (int) $itemId) {
                    $item = $candidate;
                }
            }

            if ($item === null) {
                return ['success' => false, 'message' => 'That item does not count toward the grade (it may be a draft, or removed).'];
            }

            unset($bare['adjustments']['items'][(int) $item['id']]);

            $max = (float) $item['max_points'];

            $values = [];

            foreach ($context['studentIds'] as $studentId) {

                $cell = $context['scores'][(int) $item['id']][(int) $studentId] ?? null;

                if ($cell && $cell['raw_score'] !== null && empty($cell['is_missing'])) {
                    $values[] = (float) $cell['raw_score'];
                }

            }

        } else {

            $bare['adjustments']['final'] = null;

            $max = 100.0;

            $overridden = db_connect()->table('gradebook_records')
                ->select('student_id')
                ->where('course_offering_id', $offeringId)->where('is_override', 1)
                ->get()->getResultArray();

            $skip = array_map('intval', array_column($overridden, 'student_id'));

            $values = [];

            foreach ($context['studentIds'] as $studentId) {

                if (in_array((int) $studentId, $skip, true)) {
                    continue;
                }

                $percentage = $this->engine->compute($bare, (int) $studentId)['percentage'];

                if ($percentage !== null) {
                    $values[] = $percentage;
                }

            }

        }

        $frozen = self::freeze($method, $input, $values, $max, $scope === 'final');

        if (!$frozen['success']) {
            return $frozen;
        }

        return [
            'success' => true,
            // The preview compares against the class without a curve on this target.
            'context' => $bare,
            'item' => $item,
            'max' => $max,
            'candidate' => [
                'scope' => $scope,
                'grade_item_id' => $item ? (int) $item['id'] : null,
                'method' => $method,
                'params' => $frozen['params'],
                'label' => $frozen['label'],
            ],
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function withAdjustment(array &$context, array $candidate): void
    {
        $row = ['method' => $candidate['method'], 'params' => $candidate['params']];

        if ($candidate['scope'] === 'final') {
            $context['adjustments']['final'] = $row;

            return;
        }

        $context['adjustments']['items'][(int) $candidate['grade_item_id']] = $row;
    }

    /** Grades follow the curve at once on a live scheme; on a draft one they wait for the next calculation. */
    private function refresh(int $offeringId): void
    {
        $schemes = new GradingSchemeModel();

        $scheme = $schemes->findForOffering($offeringId);

        if ($scheme && $scheme['status'] === 'Published') {
            $this->engine->recalculateOffering($offeringId);

            return;
        }

        if ($scheme) {
            $schemes->markNeedsRecalculation((int) $scheme['id']);
        }
    }
}
