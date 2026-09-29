<?php

namespace App\Services\Courses\Grades;

use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

use App\Models\Grades\GradingSchemeModel;

/**
 * Reads an offline grade sheet back in (GradeSheetLayout describes it).
 *
 * preview() never writes. It checks, in order:
 *   1. the file is a Mentora grade sheet for THIS section's current grading
 *      setup (signature) — otherwise it's refused outright;
 *   2. the columns weren't added, removed or reordered;
 *   3. per row: a real, enrolled student, listed once; locked cells
 *      unchanged (row hash; formulas in XLSX);
 *   4. per manual score: a number from 0 to the item's max points (or blank).
 * Rows with a problem are flagged and skipped; the rest can be applied.
 *
 * apply() re-checks everything it's given before writing — the preview is
 * for the instructor, not trusted as input.
 */
class GradeSheetImportService
{
    public const FORMATS = ['xlsx', 'csv'];

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    */

    public function preview(int $offeringId, string $filePath, string $format): array
    {
        (new GradingSchemeService())->forOffering($offeringId);

        $engine = new GradingEngineService();

        $context = $engine->context($offeringId);

        if (!$context) {
            return ['success' => false, 'message' => 'This section has no grading scheme.'];
        }

        $layout = new GradeSheetLayout($context);

        try {
            $grid = $format === 'xlsx' ? $this->readXlsx($filePath) : $this->readCsv($filePath);
        } catch (\Throwable $e) {
            return $this->refuse("That file couldn't be read as a " . strtoupper($format) . ' spreadsheet.');
        }

        /*
        | 1. The right template
        */

        $meta = $grid[0] ?? [];

        if (trim((string) ($meta[0]['value'] ?? '')) !== GradeSheetLayout::META_PREFIX) {
            return $this->refuse("This isn't a Mentora grade sheet. Export the grade sheet from this page, fill it in, and import that file.");
        }

        if (trim((string) ($meta[1]['value'] ?? '')) !== $layout->signature()) {
            return $this->refuse("This file doesn't match this section's current grading setup — the categories, weights, items or points changed since it was exported, or it's from another section. Export a fresh grade sheet.");
        }

        /*
        | 2. Columns intact
        */

        $keys = array_map(static fn ($cell) => trim((string) ($cell['value'] ?? '')), $grid[1] ?? []);

        while ($keys !== [] && end($keys) === '') {
            array_pop($keys);
        }

        if ($keys !== $layout->keys()) {
            return $this->refuse('Columns were added, removed or reordered in this file. Export a fresh grade sheet and only edit the score cells.');
        }

        /*
        | 3–4. Rows
        */

        $columns = $layout->columns();
        $enrolled = [];

        foreach (GradeSheetExportService::roster($offeringId) as $student) {
            $enrolled[(int) $student['student_id']] = $student;
        }

        $rows = [];
        $seen = [];
        $changedStudents = [];

        foreach (array_slice($grid, GradeSheetLayout::FIRST_DATA_ROW - 1, null, true) as $gridIndex => $cells) {

            $rowNumber = $gridIndex + 1;

            if ($this->isBlankRow($cells)) {
                continue;
            }

            $row = $this->checkRow($layout, $context, $columns, $cells, $rowNumber, $format, $enrolled, $seen);

            $rows[] = $row;

            if ($row['rowError'] === null && $row['changes'] !== []) {
                $changedStudents[$row['studentId']] = $row;
            }

        }

        // Final grades before / after, for rows that would change.
        foreach ($rows as &$row) {

            if (!isset($changedStudents[$row['studentId']]) || $row['rowError'] !== null) {
                continue;
            }

            $before = $engine->compute($context, $row['studentId']);

            $overrides = [];
            foreach ($row['changes'] as $change) {
                if ($change['valid']) {
                    $overrides[$change['gradeItemId']] = $change['newValue'];
                }
            }

            $after = $engine->compute($context, $row['studentId'], $overrides);

            $row['finalBefore'] = $before['percentage'];
            $row['finalAfter'] = $after['percentage'];

        }
        unset($row);

        $missing = array_diff(array_keys($enrolled), array_keys($seen));

        $flagged = count(array_filter($rows, static fn ($r) => $r['rowError'] !== null
            || array_filter($r['changes'], static fn ($c) => !$c['valid']) !== []));

        return [
            'success' => true,
            'templateValid' => true,
            'templateError' => null,
            'signature' => $layout->signature(),
            'rows' => $rows,
            'validCount' => array_sum(array_map(
                static fn ($r) => $r['rowError'] === null ? count(array_filter($r['changes'], static fn ($c) => $c['valid'])) : 0,
                $rows
            )),
            'flaggedCount' => $flagged,
            'missingStudents' => array_values(array_map(static fn ($id) => $enrolled[$id]['student_name'], $missing)),
        ];
    }

    private function checkRow(GradeSheetLayout $layout, array $context, array $columns, array $cells, int $rowNumber, string $format, array $enrolled, array &$seen): array
    {
        $value = static fn (int $index) => $cells[$index]['value'] ?? null;

        $studentId = (int) trim((string) $value(0));

        $row = [
            'rowNumber' => $rowNumber,
            'studentId' => $studentId,
            'studentName' => trim((string) $value(2)),
            'studentNumber' => trim((string) $value(1)),
            'changes' => [],
            'rowError' => null,
            'finalBefore' => null,
            'finalAfter' => null,
        ];

        if (!isset($enrolled[$studentId])) {
            $row['rowError'] = "Row {$rowNumber}: this student isn't enrolled in this section.";
            return $row;
        }

        if (isset($seen[$studentId])) {
            $row['rowError'] = "Row {$rowNumber}: this student is listed more than once.";
            return $row;
        }

        $seen[$studentId] = true;

        $row['studentName'] = $enrolled[$studentId]['student_name'];

        /*
        | Locked cells: the row hash covers the student and quiz/assignment
        | scores as exported; XLSX formula cells must still be formulas.
        */

        $lockedScores = [];

        foreach ($columns as $index => $column) {

            if ($column['type'] === 'item' && !$layout->isManual($column['item'])) {
                $parsed = $this->parseNumber($value($index));
                $lockedScores[(int) $column['item']['id']] = is_float($parsed) ? $parsed : null;
            }

        }

        $hashIndex = count($columns) - 1;

        if (trim((string) $value($hashIndex)) !== $layout->rowHash($studentId, (string) $value(1), $lockedScores)) {
            $row['rowError'] = "Row {$rowNumber}: locked cells were changed (student details or quiz/assignment scores).";
            return $row;
        }

        if ($format === 'xlsx') {

            foreach ($columns as $index => $column) {

                $expected = match ($column['type']) {
                    'category' => $layout->categoryFormula($column['category'], $rowNumber),
                    'final' => $layout->finalFormula($rowNumber),
                    'grade' => $layout->gradeFormula($rowNumber),
                    'remarks' => $layout->remarksFormula($rowNumber),
                    default => null,
                };

                if ($expected !== null && $this->normalizeFormula((string) $value($index)) !== $this->normalizeFormula($expected)) {
                    $row['rowError'] = "Row {$rowNumber}: a calculated cell ({$column['header']}) was overwritten.";
                    return $row;
                }

            }

        }

        /*
        | Manual scores
        */

        foreach ($columns as $index => $column) {

            if ($column['type'] !== 'item' || !$layout->isManual($column['item'])) {
                continue;
            }

            $item = $column['item'];
            $itemId = (int) $item['id'];
            $max = (float) $item['max_points'];

            $current = $context['scores'][$itemId][$studentId]['raw_score'] ?? null;
            $current = $current === null ? null : (float) $current;

            $parsed = $this->parseNumber($value($index));

            $change = [
                'gradeItemId' => $itemId,
                'itemName' => $item['name'],
                'oldValue' => $current,
                'newValue' => null,
                'valid' => true,
                'error' => null,
            ];

            if ($parsed === false) {
                $change['valid'] = false;
                $change['error'] = "“{$value($index)}” isn't a number.";
                $row['changes'][] = $change;
                continue;
            }

            if ($parsed !== null && ($parsed < 0 || $parsed > $max)) {
                $change['newValue'] = $parsed;
                $change['valid'] = false;
                $change['error'] = GradeSheetLayout::number($parsed) . ' is outside 0–' . GradeSheetLayout::number($max) . '.';
                $row['changes'][] = $change;
                continue;
            }

            $unchanged = $parsed === null
                ? $current === null
                : $current !== null && abs($parsed - $current) < 0.001;

            if ($unchanged) {
                continue;
            }

            $change['newValue'] = $parsed;
            $row['changes'][] = $change;

        }

        return $row;
    }

    /*
    |--------------------------------------------------------------------------
    | Apply
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{signature?: string, changes?: list<array{student_id: int, grade_item_id: int, value: ?float}>} $payload
     */
    public function apply(User $user, int $offeringId, array $payload): array
    {
        $scheme = (new GradingSchemeService())->forOffering($offeringId);

        $engine = new GradingEngineService();

        $context = $engine->context($offeringId);

        $layout = new GradeSheetLayout($context);

        if (($payload['signature'] ?? '') !== $layout->signature()) {
            return ['success' => false, 'message' => 'The grading setup changed since this file was previewed. Export a fresh grade sheet.'];
        }

        $manualItems = [];

        foreach ($layout->items() as $item) {
            if ($layout->isManual($item)) {
                $manualItems[(int) $item['id']] = $item;
            }
        }

        $enrolled = array_flip($context['studentIds']);

        $valid = [];

        foreach ($payload['changes'] ?? [] as $change) {

            $studentId = (int) ($change['student_id'] ?? 0);
            $itemId = (int) ($change['grade_item_id'] ?? 0);
            $value = $change['value'] ?? null;

            if (!isset($enrolled[$studentId], $manualItems[$itemId])) {
                continue;
            }

            if ($value !== null) {

                if (!is_numeric($value)) {
                    continue;
                }

                $value = (float) $value;

                if ($value < 0 || $value > (float) $manualItems[$itemId]['max_points']) {
                    continue;
                }

            }

            $valid[] = [$studentId, $itemId, $value];

        }

        if ($valid === []) {
            return ['success' => false, 'message' => 'There were no valid changes to apply.'];
        }

        $sync = new GradebookSyncService();

        $db = db_connect();

        $db->transStart();

        foreach ($valid as [$studentId, $itemId, $value]) {
            $sync->setManualScore($offeringId, $itemId, $studentId, $value, (int) $user->id);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'The scores could not be saved.'];
        }

        // Same rule as live grading: final grades follow while the scheme is
        // published and not waiting on a recalculation.
        $scheme = (new GradingSchemeModel())->find($scheme['id']);

        $refreshed = $scheme['status'] === 'Published' && empty($scheme['needs_recalculation']);

        if ($refreshed) {
            foreach (array_unique(array_column($valid, 0)) as $studentId) {
                $engine->recalculateStudent($offeringId, (int) $studentId);
            }
        }

        $count = count($valid);

        return [
            'success' => true,
            'message' => "{$count} " . ($count === 1 ? 'score' : 'scores') . ' imported.'
                . ($refreshed ? '' : ' Recalculate to update final grades.'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading files → [row][col] = ['value' => raw (formula text for formulas)]
    |--------------------------------------------------------------------------
    */

    private function readXlsx(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Grades']);

        $sheet = $reader->load($path)->getSheetByName('Grades');

        if (!$sheet) {
            throw new \RuntimeException('No Grades sheet.');
        }

        $grid = [];

        foreach ($sheet->getRowIterator() as $row) {

            $cells = [];

            foreach ($row->getCellIterator() as $cell) {
                // getValue() keeps formulas as their text ("=IF(...)").
                $cells[] = ['value' => $cell->getValue()];
            }

            $grid[$row->getRowIndex() - 1] = $cells;

        }

        ksort($grid);

        return $this->fillGaps($grid);
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            throw new \RuntimeException('Unreadable.');
        }

        // Skip a UTF-8 BOM (the export writes one; Excel keeps it) before
        // parsing — left in, it hides the first field's opening quote.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $grid = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $grid[] = array_map(static fn ($value) => ['value' => $value], $row);
        }

        fclose($handle);

        return $grid;
    }

    private function fillGaps(array $grid): array
    {
        $filled = [];
        $last = $grid === [] ? -1 : max(array_keys($grid));

        for ($i = 0; $i <= $last; $i++) {
            $filled[$i] = $grid[$i] ?? [];
        }

        return $filled;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Blank → null, a number → float, anything else → false.
     */
    private function parseNumber(mixed $value): float|null|false
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return is_numeric($text) ? (float) $text : false;
    }

    private function normalizeFormula(string $formula): string
    {
        return strtoupper(preg_replace('/\s+/', '', ltrim($formula, '=')));
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) ($cell['value'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function refuse(string $message): array
    {
        return [
            'success' => true,
            'templateValid' => false,
            'templateError' => $message,
            'rows' => [],
            'validCount' => 0,
            'flaggedCount' => 0,
            'missingStudents' => [],
        ];
    }
}
