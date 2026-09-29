<?php

namespace App\Services\Courses\Grades;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use App\Services\Storage\StorageService;

/**
 * Offline grade sheet for a section (GradeSheetLayout describes it).
 *
 * XLSX: live formulas for category %, final %, grade and remarks, so the
 * instructor sees the effect of an edit immediately; the sheet is protected
 * with only manual-item score cells editable.
 * CSV: the same columns with values computed at export time (plain text
 * can't hold formulas) — they're recalculated on import.
 */
class GradeSheetExportService
{
    public function exportToXlsx(int $offeringId): array
    {
        $data = $this->data($offeringId);

        if (!$data) {
            return ['success' => false, 'message' => 'This section has no grading scheme.'];
        }

        [$layout, $students] = $data;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Mentora')
            ->setTitle('Grade sheet')
            ->setDescription('Mentora offline grade sheet — only the white score columns are editable.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Grades');

        $this->writeGrid($sheet, $layout, $students, true);

        $this->writeInstructions($spreadsheet->createSheet(), $layout);

        $spreadsheet->setActiveSheetIndex(0);

        return $this->save($offeringId, 'xlsx', static function (string $path) use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save($path);
        });
    }

    public function exportToCsv(int $offeringId): array
    {
        $data = $this->data($offeringId);

        if (!$data) {
            return ['success' => false, 'message' => 'This section has no grading scheme.'];
        }

        [$layout, $students] = $data;

        $rows = $this->gridRows($layout, $students, false);

        return $this->save($offeringId, 'csv', static function (string $path) use ($rows) {

            $handle = fopen($path, 'w');

            // UTF-8 BOM so Excel opens names with accents correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }

            fclose($handle);

        });
    }

    /*
    |--------------------------------------------------------------------------
    | Grid
    |--------------------------------------------------------------------------
    */

    /**
     * [layout, students] or null when the offering has no scheme.
     */
    private function data(int $offeringId): ?array
    {
        (new GradingSchemeService())->forOffering($offeringId);

        $engine = new GradingEngineService();

        $context = $engine->context($offeringId);

        if (!$context) {
            return null;
        }

        $layout = new GradeSheetLayout($context);

        $students = [];

        foreach (self::roster($offeringId) as $student) {

            $studentId = (int) $student['student_id'];

            $scores = [];

            foreach ($layout->items() as $item) {
                $row = $context['scores'][(int) $item['id']][$studentId] ?? null;
                $scores[(int) $item['id']] = $row && $row['raw_score'] !== null ? (float) $row['raw_score'] : null;
            }

            $students[] = $student + [
                'scores' => $scores,
                'result' => $engine->compute($context, $studentId),
            ];

        }

        return [$layout, $students];
    }

    /**
     * Enrolled students with number and name, by last name.
     */
    public static function roster(int $offeringId): array
    {
        return db_connect()->table('enrollments e')
            ->select([
                'e.student_user_id AS student_id',
                "COALESCE(p.student_number, '') AS student_number",
                "TRIM(CONCAT(COALESCE(p.last_name, ''), ', ', COALESCE(p.first_name, ''))) AS student_name",
            ])
            ->join('user_profiles p', 'p.user_id = e.student_user_id', 'left')
            ->where('e.offering_id', $offeringId)
            ->where('e.status', 'enrolled')
            ->orderBy('p.last_name', 'ASC')
            ->orderBy('p.first_name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * The sheet as rows of plain values. With $formulas, computed cells hold
     * formulas (XLSX); without, their values as of now (CSV).
     */
    private function gridRows(GradeSheetLayout $layout, array $students, bool $formulas): array
    {
        $rows = [
            [GradeSheetLayout::META_PREFIX, $layout->signature()],
            $layout->keys(),
            array_column($layout->columns(), 'header'),
        ];

        foreach ($students as $offset => $student) {

            $rowNumber = GradeSheetLayout::FIRST_DATA_ROW + $offset;

            $studentId = (int) $student['student_id'];

            $categoryPercent = [];

            foreach ($student['result']['categories'] as $category) {
                $categoryPercent[$category['id']] = $category['percentage'];
            }

            $row = [];

            foreach ($layout->columns() as $column) {

                $row[] = match ($column['type']) {
                    'student_id' => $studentId,
                    'student_number' => $student['student_number'],
                    'student_name' => $student['student_name'],
                    'item' => $student['scores'][(int) $column['item']['id']] ?? null,
                    'category' => $formulas
                        ? $layout->categoryFormula($column['category'], $rowNumber)
                        : $categoryPercent[(int) $column['category']['id']] ?? null,
                    'final' => $formulas ? $layout->finalFormula($rowNumber) : $student['result']['percentage'],
                    'grade' => $formulas ? $layout->gradeFormula($rowNumber) : ($student['result']['band']['name'] ?? null),
                    'remarks' => $formulas ? $layout->remarksFormula($rowNumber) : $student['result']['remarks'],
                    'row_hash' => $layout->rowHash($studentId, (string) $student['student_number'], $student['scores']),
                };

            }

            $rows[] = $row;

        }

        return $rows;
    }

    private function writeGrid(Worksheet $sheet, GradeSheetLayout $layout, array $students, bool $formulas): void
    {
        $rows = $this->gridRows($layout, $students, $formulas);

        $columns = $layout->columns();

        foreach ($rows as $rowIndex => $row) {

            foreach ($row as $columnIndex => $value) {

                if ($value === null || $value === '') {
                    continue;
                }

                $cell = GradeSheetLayout::letter($columnIndex + 1) . ($rowIndex + 1);

                // Student numbers stay text ("2026-02000" isn't a date or a sum).
                $type = match (true) {
                    is_string($value) && str_starts_with($value, '=') => DataType::TYPE_FORMULA,
                    is_int($value) || is_float($value) => DataType::TYPE_NUMERIC,
                    default => DataType::TYPE_STRING,
                };

                $sheet->setCellValueExplicit($cell, $value, $type);

            }

        }

        $lastColumn = GradeSheetLayout::letter(count($columns));
        $lastRow = max(GradeSheetLayout::FIRST_DATA_ROW, count($rows));

        // Machine rows / columns hidden; everything locked by default.
        $sheet->getRowDimension(1)->setVisible(false);
        $sheet->getRowDimension(2)->setVisible(false);
        $sheet->getColumnDimension('A')->setVisible(false);
        $sheet->getColumnDimension($lastColumn)->setVisible(false);

        $sheet->getStyle("A3:{$lastColumn}3")->getFont()->setBold(true);
        $sheet->getStyle("A3:{$lastColumn}3")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(3)->setRowHeight(45);

        foreach ($columns as $index => $column) {

            $letter = GradeSheetLayout::letter($index + 1);

            $sheet->getColumnDimension($letter)->setWidth(match ($column['type']) {
                'student_name' => 28,
                'student_number' => 16,
                default => 14,
            });

            $range = "{$letter}" . GradeSheetLayout::FIRST_DATA_ROW . ":{$letter}{$lastRow}";

            if ($column['type'] === 'item' && $layout->isManual($column['item'])) {

                // The only editable cells.
                $sheet->getStyle($range)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                $sheet->getStyle("{$letter}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8E6');

            } else {

                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');

            }

            if (in_array($column['type'], ['category', 'final'], true)) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('0.00');
            }

        }

        $sheet->freezePane('D' . GradeSheetLayout::FIRST_DATA_ROW);

        // A structure guard, not security: no password.
        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setSort(true);
        $sheet->getProtection()->setInsertRows(true);
        $sheet->getProtection()->setDeleteRows(true);
        $sheet->getProtection()->setInsertColumns(true);
        $sheet->getProtection()->setDeleteColumns(true);
    }

    private function writeInstructions(Worksheet $sheet, GradeSheetLayout $layout): void
    {
        $sheet->setTitle('Instructions');

        $lines = [
            'Mentora grade sheet',
            '',
            'Only the columns with a light-yellow header are editable: scores for manual items such as Attendance or Participation.',
            'Quiz and assignment scores are locked — they come from grading in Mentora and are updated there.',
            'Category %, Final %, Grade and Remarks recalculate as you type, using this section\'s grading scheme.',
            'Leave a score blank if it isn\'t graded yet; a blank doesn\'t count against the student.',
            'Don\'t add, remove, reorder or rename columns or rows, and don\'t edit the grey cells — the import checks this and flags any row that was changed.',
            'If the grading scheme changes after you export (weights, categories, items or points), export a fresh sheet — an older one will be refused.',
            '',
            'Template: ' . $layout->signature(),
        ];

        foreach ($lines as $index => $line) {
            $sheet->setCellValueExplicit('A' . ($index + 1), $line, DataType::TYPE_STRING);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(120);
    }

    private function save(int $offeringId, string $extension, callable $write): array
    {
        $directory = (new StorageService())->root() . 'exports';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $filename = 'grade-sheet-' . $offeringId . '-' . date('Ymd-His') . '.' . $extension;

        $path = $directory . '/' . $filename;

        $write($path);

        return ['success' => true, 'file_path' => $path, 'filename' => $filename];
    }
}
