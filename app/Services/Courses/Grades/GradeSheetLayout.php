<?php

namespace App\Services\Courses\Grades;

/**
 * The offline grade sheet's structure, shared by export and import so the
 * two can never disagree.
 *
 * Rows:   1  META_PREFIX | template signature      (hidden in XLSX)
 *         2  column keys (student_id, item:12, …)  (hidden in XLSX)
 *         3  human headers
 *         4+ one row per enrolled student
 *
 * Columns: student id (hidden) · student number · name · one column per
 * grade item (grouped by category) · one per category % · final % · grade ·
 * remarks · row hash (hidden). Only manual-item columns are editable.
 *
 * - The signature fingerprints the grading setup (categories, weights,
 *   items, max points, passing mark, scale). A file exported under a
 *   different setup is rejected rather than half-applied.
 * - The row hash fingerprints each row's locked values as exported (student
 *   and quiz/assignment scores), so an edit to a locked cell is caught even
 *   when those scores have since changed in Mentora.
 * - A curve in force (GradeAdjustmentService) is part of the formulas: the
 *   score cells keep the scores as entered, and the computed columns count
 *   them the way the grading engine does, curved. The curve is in the
 *   signature too, so a sheet exported before a curve was applied or removed
 *   is refused with the same clear message as one exported before any other
 *   change to the grading setup.
 */
class GradeSheetLayout
{
    public const META_PREFIX = '[machine-readable — do not edit]';

    public const FIRST_DATA_ROW = 4;

    private array $columns;

    public function __construct(private array $context)
    {
        $this->columns = $this->buildColumns();
    }

    /**
     * @return list<array{key: string, header: string, type: string, item?: array, category?: array}>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function keys(): array
    {
        return array_column($this->columns, 'key');
    }

    /**
     * Items that appear in the sheet: active, in an active category, in
     * category then item order. (Uncategorized items don't count, so they're
     * left out.)
     */
    public function items(): array
    {
        return array_values(array_map(
            static fn (array $column) => $column['item'],
            array_filter($this->columns, static fn (array $column) => $column['type'] === 'item')
        ));
    }

    public function isManual(array $item): bool
    {
        return empty($item['assessment_id']);
    }

    /**
     * True when the scheme has at least one active grading period — the one
     * switch that decides whether period columns/formulas appear at all.
     */
    public function usesPeriods(): bool
    {
        return !empty($this->context['scheme']['uses_periods']) && ($this->context['periods'] ?? []) !== [];
    }

    private function buildColumns(): array
    {
        $columns = [
            ['key' => 'student_id', 'header' => 'ID (do not edit)', 'type' => 'student_id'],
            ['key' => 'student_number', 'header' => 'Student Number', 'type' => 'student_number'],
            ['key' => 'student_name', 'header' => 'Student Name', 'type' => 'student_name'],
        ];

        $periodColumns = [];

        if ($this->usesPeriods()) {

            // A category with no period doesn't count (mirrors the engine),
            // so it — and its items — are simply left out of the sheet.
            foreach ($this->context['periods'] as $period) {

                foreach ($this->context['categories'] as $category) {
                    if ((int) ($category['period_id'] ?? 0) === (int) $period['id']) {
                        $columns = array_merge($columns, $this->columnsForCategory($category));
                    }
                }

                $periodColumns[] = [
                    'key' => 'period:' . $period['id'],
                    'header' => $period['name'] . ' % (' . self::number((float) $period['weight_percentage']) . '%)',
                    'type' => 'period',
                    'period' => $period,
                ];

            }

        } else {

            foreach ($this->context['categories'] as $category) {
                $columns = array_merge($columns, $this->columnsForCategory($category));
            }

        }

        return array_merge($columns, $periodColumns, [
            ['key' => 'final', 'header' => 'Final %' . ($this->finalCurve() !== null ? ' [curved]' : ''), 'type' => 'final'],
            ['key' => 'grade', 'header' => 'Grade', 'type' => 'grade'],
            ['key' => 'remarks', 'header' => 'Remarks', 'type' => 'remarks'],
            ['key' => 'row_hash', 'header' => 'Check (do not edit)', 'type' => 'row_hash'],
        ]);
    }

    /**
     * One category's item columns followed by its own percentage column —
     * the repeating unit buildColumns() lays out once per category, whether
     * that's flat or nested under a period.
     */
    private function columnsForCategory(array $category): array
    {
        $columns = [];

        foreach ($this->context['items'] as $item) {

            if ((int) $item['category_id'] !== (int) $category['id']) {
                continue;
            }

            $name = $item['assessment_title'] ?? $item['name'];

            $columns[] = [
                'key' => 'item:' . $item['id'],
                'header' => $name . ' (/' . self::number((float) $item['max_points']) . ')'
                    . ($this->isManual($item) ? '' : ' [locked]')
                    . ($this->curveOf($item) !== null ? ' [curved]' : ''),
                'type' => 'item',
                'item' => $item,
                'category' => $category,
            ];

        }

        $columns[] = [
            'key' => 'category:' . $category['id'],
            'header' => $category['name'] . ' % (' . self::number((float) $category['weight_percentage']) . '%)',
            'type' => 'category',
            'category' => $category,
        ];

        return $columns;
    }

    /**
     * Fingerprint of everything the sheet's structure and formulas depend on.
     */
    public function signature(): string
    {
        $scheme = $this->context['scheme'];

        return substr(hash('sha256', json_encode([
            'offering' => (int) $this->context['offeringId'],
            'scheme' => (int) $scheme['id'],
            'passing' => (float) $scheme['passing_percentage'],
            'scale' => (int) ($scheme['grade_scale_id'] ?? 0),
            'uses_periods' => $this->usesPeriods(),
            'periods' => array_map(static fn ($p) => [(int) $p['id'], (float) $p['weight_percentage']], $this->context['periods'] ?? []),
            'bands' => array_map(static fn ($b) => [(int) $b['id'], (float) $b['minimum_percentage']], $this->context['bands']),
            'categories' => array_map(static fn ($c) => [(int) $c['id'], $c['period_id'] !== null ? (int) $c['period_id'] : null, (float) $c['weight_percentage']], $this->context['categories']),
            'items' => array_map(static fn ($i) => [(int) $i['id'], (int) $i['category_id'], (float) $i['max_points']], $this->items()),
            'curves' => [
                'items' => array_map(static fn ($c) => [$c['method'], $c['params']], $this->context['adjustments']['items'] ?? []),
                'final' => isset($this->context['adjustments']['final']) ? [$this->context['adjustments']['final']['method'], $this->context['adjustments']['final']['params']] : null,
            ],
        ])), 0, 32);
    }

    /**
     * Fingerprint of one row's locked values: the student and their
     * quiz/assignment scores (blank = null), as exported.
     *
     * @param array<int, ?float> $lockedScores grade_item_id => score
     */
    public function rowHash(int $studentId, string $studentNumber, array $lockedScores): string
    {
        $values = [];

        foreach ($this->items() as $item) {
            if (!$this->isManual($item)) {
                $score = $lockedScores[(int) $item['id']] ?? null;
                $values[] = $score === null ? '' : self::number((float) $score);
            }
        }

        $secret = (string) (config('Encryption')->key ?? '') ?: 'mentora-grade-sheet';

        return substr(hash_hmac('sha256', implode('|', [
            (int) $this->context['offeringId'],
            $studentId,
            trim($studentNumber),
            implode(',', $values),
        ]), $secret), 0, 16);
    }

    /**
     * 10 → "10", 7.5 → "7.5" — how numbers are written and compared.
     */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Spreadsheet column letter for a 1-based index (1 → A, 27 → AA).
     */
    public static function letter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $index--;
            $letter = chr(65 + $index % 26) . $letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    /*
    |--------------------------------------------------------------------------
    | XLSX formulas — must compute exactly what GradingEngineService does
    |--------------------------------------------------------------------------
    */

    /**
     * Category %: earned ÷ possible over the category's GRADED (non-blank)
     * items, blank when none are graded.
     */
    public function categoryFormula(array $category, int $row): string
    {
        $cells = [];
        $possible = [];

        foreach ($this->columns as $index => $column) {
            if ($column['type'] === 'item' && (int) $column['category']['id'] === (int) $category['id']) {
                $cell = self::letter($index + 1) . $row;
                $possible[] = 'IF(ISNUMBER(' . $cell . '),' . self::number((float) $column['item']['max_points']) . ',0)';

                // A curved item counts its curved score, never the one typed in the cell.
                $curve = $this->curveOf($column['item']);

                $cells[] = $curve === null
                    ? $cell
                    : 'IF(ISNUMBER(' . $cell . '),' . $this->curveExpression($curve, $cell, (float) $column['item']['max_points']) . ',0)';
            }
        }

        if ($cells === []) {
            return '=""';
        }

        $possibleSum = '(' . implode('+', $possible) . ')';

        return '=IF(' . $possibleSum . '=0,"",SUM(' . implode(',', $cells) . ')/' . $possibleSum . '*100)';
    }

    /**
     * Period %: weighted average of that period's category-percentage
     * columns that have graded work. Same shape as categoryFormula(), one
     * level up.
     */
    public function periodFormula(array $period, int $row): string
    {
        return $this->weightedRollupFormula(
            static fn (array $column) => $column['type'] === 'category' && (int) ($column['category']['period_id'] ?? 0) === (int) $period['id'],
            static fn (array $column) => (float) $column['category']['weight_percentage'],
            $row
        );
    }

    /**
     * Final %: weighted average of the periods (if the scheme uses them) or
     * else the categories directly, whichever have graded work.
     */
    public function finalFormula(int $row): string
    {
        $curve = $this->finalCurve();

        $wrap = $curve === null
            ? null
            : fn (string $rolled): string => $this->curveExpression($curve, $rolled, 100.0);

        if ($this->usesPeriods()) {
            return $this->weightedRollupFormula(
                static fn (array $column) => $column['type'] === 'period',
                static fn (array $column) => (float) $column['period']['weight_percentage'],
                $row,
                $wrap
            );
        }

        return $this->weightedRollupFormula(
            static fn (array $column) => $column['type'] === 'category',
            static fn (array $column) => (float) $column['category']['weight_percentage'],
            $row,
            $wrap
        );
    }

    /**
     * The shared shape behind category/period/final: a weighted average
     * over whichever columns $matches picks, blank when none of them have a
     * value yet — mirrors GradingEngineService::rollup() exactly.
     */
    private function weightedRollupFormula(callable $matches, callable $weightOf, int $row, ?callable $wrap = null): string
    {
        $numerator = [];
        $denominator = [];

        foreach ($this->columns as $index => $column) {

            if (!$matches($column)) {
                continue;
            }

            $weight = $weightOf($column);

            if ($weight <= 0) {
                continue;
            }

            $cell = self::letter($index + 1) . $row;
            $w = self::number($weight);

            $numerator[] = 'IF(ISNUMBER(' . $cell . '),' . $cell . '*' . $w . ',0)';
            $denominator[] = 'IF(ISNUMBER(' . $cell . '),' . $w . ',0)';

        }

        if ($numerator === []) {
            return '=""';
        }

        $den = '(' . implode('+', $denominator) . ')';

        $rolled = 'ROUND((' . implode('+', $numerator) . ')/' . $den . ',2)';

        return '=IF(' . $den . '=0,"",' . ($wrap !== null ? $wrap($rolled) : $rolled) . ')';
    }

    /*
    |--------------------------------------------------------------------------
    | Curves in force
    |--------------------------------------------------------------------------
    */

    /** @return array{method: string, params: array<string, float>}|null */
    public function curveOf(array $item): ?array
    {
        return $this->context['adjustments']['items'][(int) $item['id']] ?? null;
    }

    /** @return array{method: string, params: array<string, float>}|null */
    public function finalCurve(): ?array
    {
        return $this->context['adjustments']['final'] ?? null;
    }

    /**
     * The spreadsheet form of GradeAdjustmentService::adjust(): the curve
     * applied to `$value` (a cell or a formula), kept between 0 and `$max`
     * and rounded to two decimals, exactly as the grading engine does.
     *
     * @param array{method: string, params: array<string, float>} $curve
     */
    private function curveExpression(array $curve, string $value, float $max): string
    {
        $p = $curve['params'];

        $inner = match ($curve['method']) {
            'add_points' => $value . '+' . self::literal((float) ($p['points'] ?? 0)),
            'scale_to_max' => $value . '*' . self::literal((float) ($p['factor'] ?? 1)),
            'linear' => self::literal((float) ($p['slope'] ?? 1)) . '*' . $value . '+' . self::literal((float) ($p['intercept'] ?? 0)),
            'set_mean' => $value . '+' . self::literal((float) ($p['shift'] ?? 0)),
            default => $value,
        };

        return 'ROUND(MIN(' . self::number($max) . ',MAX(0,' . $inner . ')),2)';
    }

    /** A number written into a formula: enough digits for a factor, no exponent, no locale. */
    private static function literal(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : ($value < 0 ? '(' . $text . ')' : $text);
    }

    public function gradeFormula(int $row): string
    {
        $final = $this->cellOf('final', $row);

        $bands = $this->context['bands'];

        if ($bands === []) {
            return '=""';
        }

        $expression = '"' . str_replace('"', '""', $bands[count($bands) - 1]['name']) . '"';

        foreach (array_reverse(array_slice($bands, 0, -1)) as $band) {
            $expression = 'IF(' . $final . '>=' . self::number((float) $band['minimum_percentage']) . ',"'
                . str_replace('"', '""', $band['name']) . '",' . $expression . ')';
        }

        return '=IF(' . $final . '="","",' . $expression . ')';
    }

    public function remarksFormula(int $row): string
    {
        $final = $this->cellOf('final', $row);

        $passing = self::number((float) $this->context['scheme']['passing_percentage']);

        return '=IF(' . $final . '="","Incomplete",IF(' . $final . '>=' . $passing . ',"Passed","Failed"))';
    }

    public function cellOf(string $key, int $row): string
    {
        $index = array_search($key, $this->keys(), true);

        return self::letter((int) $index + 1) . $row;
    }
}
