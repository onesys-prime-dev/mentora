<?php

namespace App\Services\Courses\Grades;

use App\Models\Assessments\GradebookRecordModel;
use App\Services\Storage\StorageService;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Report-style exports of a section's grades, for reading, printing and
 * filing (the offline grade sheet, GradeSheetExportService, is the template
 * for editing scores and is a different thing).
 *
 * Every number comes from the same grading engine and the same stored grades
 * the screens use, so a report never disagrees with the gradebook:
 *   - the class gradebook, in two forms that are not the same thing:
 *       Excel: the report to read, print and file. Formatted, coloured
 *       standings, notes in words, a Summary sheet.
 *       CSV: the data for other software. Ids, plain numbers, yes/no flags,
 *       ISO dates; no prose, no formatting, one header row.
 *     (The offline grade sheet, GradeSheetExportService, is a third thing:
 *     the template for editing scores and importing them back.)
 *   - the class summary (PDF / print): the class's mean, range, who passes,
 *     the tags and how the grades are spread;
 *   - a student's report (PDF / print): their scores, how their grade is made up
 *     and where they stand. A student gets their own once the grades are final.
 */
class GradeReportService
{
    /** Spread of the grades, lowest first: label and lower bound. */
    private const BUCKETS = [['Below 50', 0], ['50 to 59', 50], ['60 to 69', 60], ['70 to 79', 70], ['80 to 89', 80], ['90 to 100', 90]];

    private GradingEngineService $engine;

    private GradebookRecordModel $records;

    public function __construct()
    {
        $this->engine = new GradingEngineService();
        $this->records = new GradebookRecordModel();
    }

    /*
    |--------------------------------------------------------------------------
    | The data
    |--------------------------------------------------------------------------
    */

    /**
     * The whole class, or null when the section has no grading yet.
     *
     * @return array<string, mixed>|null
     */
    public function classReport(int $offeringId): ?array
    {
        $context = $this->engine->context($offeringId);

        if (!$context) {
            return null;
        }

        $items = [];

        foreach ($context['items'] as $item) {
            $items[] = [
                'id' => (int) $item['id'],
                'name' => (string) ($item['assessment_title'] ?? $item['name']),
                'max' => (float) $item['max_points'],
                'category_id' => $item['category_id'] !== null ? (int) $item['category_id'] : null,
                'curved' => isset($context['adjustments']['items'][(int) $item['id']]),
            ];
        }

        $categories = array_map(static fn (array $c): array => [
            'id' => (int) $c['id'],
            'name' => (string) $c['name'],
            'weight' => (float) $c['weight_percentage'],
        ], $context['categories']);

        $recordsByStudent = [];

        foreach ($this->records->where('course_offering_id', $offeringId)->findAll() as $record) {
            $recordsByStudent[(int) $record['student_id']] = $record;
        }

        $adjusted = $context['adjustments']['items'] !== [] || !empty($context['adjustments']['final']);

        $rows = [];

        foreach (GradeSheetExportService::roster($offeringId) as $student) {
            $rows[] = $this->studentRow($context, $items, $categories, $recordsByStudent, $student, $adjusted);
        }

        $finalization = (new GradeFinalizationService())->current($offeringId);

        return [
            'offering' => $this->offeringInfo($offeringId),
            'generated_at' => date('Y-m-d H:i:s'),
            'scheme' => [
                'name' => (string) $context['scheme']['name'],
                'status' => (string) $context['scheme']['status'],
                'passing' => (float) $context['scheme']['passing_percentage'],
            ],
            'is_published' => $context['scheme']['status'] === 'Published',
            'finalization' => $finalization,
            'curves' => (new GradeAdjustmentService())->describe($offeringId)['live'],
            'items' => $items,
            'categories' => $categories,
            'rows' => $rows,
            'summary' => $this->summary($rows, (float) $context['scheme']['passing_percentage']),
        ];
    }

    /**
     * One student's report, or null when there is no such student in the
     * section (or, with `$finalOnly`, the grades are not final yet).
     *
     * @return array<string, mixed>|null
     */
    public function studentReport(int $offeringId, int $studentId, bool $finalOnly = false): ?array
    {
        $class = $this->classReport($offeringId);

        if (!$class) {
            return null;
        }

        $row = null;

        foreach ($class['rows'] as $candidate) {
            if ($candidate['student_id'] === $studentId) {
                $row = $candidate;
            }
        }

        if ($row === null || ($finalOnly && !$row['is_final'])) {
            return null;
        }

        $context = $this->engine->context($offeringId, [$studentId]);

        $result = $this->engine->compute($context, $studentId);

        $categoryLines = [];

        foreach ($result['categories'] as $category) {
            $categoryLines[] = [
                'name' => $category['name'],
                'weight' => $category['weight'],
                'percentage' => $category['percentage'],
                'earned' => $category['earned'],
                'possible' => $category['possible'],
            ];
        }

        $categoryNames = array_column($class['categories'], 'name', 'id');

        $itemLines = [];

        foreach ($class['items'] as $item) {
            $itemLines[] = [
                'name' => $item['name'],
                'category' => $categoryNames[$item['category_id']] ?? 'Not counted',
                'score' => $row['scores'][$item['id']] ?? null,
                'max' => $item['max'],
                'curved' => $item['curved'],
            ];
        }

        return [
            'offering' => $class['offering'],
            'generated_at' => $class['generated_at'],
            'scheme' => $class['scheme'],
            'is_published' => $class['is_published'],
            'student' => $row,
            'categories' => $categoryLines,
            'items' => $itemLines,
            'class_mean' => $class['summary']['mean'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    /**
     * The class gradebook as a file: Excel (the report) or CSV (the data).
     *
     * @return array{success: true, file_path: string, filename: string}
     */
    public function classSheet(array $report, string $format): array
    {
        if ($format === 'csv') {
            return $this->writeCsv($report, $this->dataLines($report));
        }

        $header = ['Student number', 'Student'];

        foreach ($report['items'] as $item) {
            $header[] = $item['name'] . ' (/' . $this->number($item['max']) . ')' . ($item['curved'] ? ' [curved]' : '');
        }

        foreach ($report['categories'] as $category) {
            $header[] = $category['name'] . ' % (weight ' . $this->number($category['weight']) . '%)';
        }

        array_push($header, 'Grade %', 'Grade', 'Standing', 'Notes');

        $lines = [$header];

        foreach ($report['rows'] as $row) {

            $line = [$row['student_number'], $row['student_name']];

            foreach ($report['items'] as $item) {
                $line[] = $row['scores'][$item['id']] ?? '';
            }

            foreach ($report['categories'] as $category) {
                $line[] = $row['categories'][$category['id']] ?? '';
            }

            array_push($line, $row['percentage'] ?? '', $row['band'] ?? '', $row['tag'], implode('; ', $row['notes']));

            $lines[] = $line;

        }

        return $this->writeXlsx($report, $lines);
    }

    /**
     * The class gradebook as data, for a spreadsheet formula, a script, a
     * database import or another gradebook: stable ids, plain numbers, ISO
     * dates and yes/no flags, and nothing written for a reader (no notes,
     * no markers in the headers, no "EX": an excused item is a blank score
     * and is named in `excused_items`).
     *
     * @return list<list<mixed>>
     */
    private function dataLines(array $report): array
    {
        $header = ['student_id', 'student_number', 'student_name'];

        foreach ($report['items'] as $item) {
            $header[] = 'score: ' . $item['name'] . ' (max ' . $this->number($item['max']) . ')';
        }

        foreach ($report['categories'] as $category) {
            $header[] = 'category_percent: ' . $category['name'];
        }

        array_push($header, 'final_percent', 'grade', 'standing', 'is_final', 'is_overridden', 'curve_in_force', 'excused_items', 'incomplete_until');

        $curved = $report['curves'] !== [];

        $names = array_column($report['items'], 'name', 'id');

        $lines = [$header];

        foreach ($report['rows'] as $row) {

            $line = [$row['student_id'], $row['student_number'], $row['student_name']];

            $excused = [];

            foreach ($report['items'] as $item) {

                $score = $row['scores'][$item['id']] ?? null;

                if ($score === 'EX') {
                    $excused[] = $names[$item['id']];
                    $score = null;
                }

                $line[] = $score ?? '';

            }

            foreach ($report['categories'] as $category) {
                $line[] = $row['categories'][$category['id']] ?? '';
            }

            array_push(
                $line,
                $row['percentage'] ?? '',
                $row['band'] ?? '',
                $row['tag'],
                $row['is_final'] ? 'yes' : 'no',
                $row['is_override'] ? 'yes' : 'no',
                $curved ? 'yes' : 'no',
                implode('; ', $excused),
                $row['inc_until'] ?? ''
            );

            $lines[] = $line;

        }

        return $lines;
    }

    /** A report page as a PDF. */
    public function pdf(string $html): string
    {
        $cache = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'dompdf';

        if (!is_dir($cache)) {
            mkdir($cache, 0775, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $options->set('chroot', WRITEPATH);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** A short, safe file name part from a course code and section. */
    public function fileStem(array $report, string $kind): string
    {
        $code = preg_replace('/[^A-Za-z0-9]+/', '-', trim($report['offering']['course_code'] . '-' . $report['offering']['section'])) ?? 'section';

        return $kind . '-' . trim($code, '-') . '-' . date('Ymd');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function studentRow(array $context, array $items, array $categories, array $recordsByStudent, array $student, bool $adjusted): array
    {
        $studentId = (int) $student['student_id'];

        $record = $recordsByStudent[$studentId] ?? null;

        $result = $this->engine->compute($context, $studentId);

        $scores = [];
        $excused = 0;

        foreach ($items as $item) {

            $cell = $context['scores'][$item['id']][$studentId] ?? null;

            if (!empty($cell['is_missing'])) {
                $scores[$item['id']] = 'EX';
                $excused++;
            } else {
                $scores[$item['id']] = $cell && $cell['raw_score'] !== null ? (float) $cell['raw_score'] : null;
            }

        }

        $categoryPercentages = [];

        foreach ($result['categories'] as $category) {
            $categoryPercentages[$category['id']] = $category['percentage'];
        }

        // The grade that counts is what the screens show: the stored one (override, final, or calculated).
        $percentage = $record ? GradebookRecordModel::effectivePercentage($record) : $result['percentage'];

        $isFinal = !empty($record['finalized_at']);

        $remarks = (string) ($record['remarks'] ?? 'Incomplete');

        $notes = [];

        if (!empty($record['is_override'])) {
            $notes[] = 'Grade set by the instructor';
        }

        if ($adjusted) {
            $notes[] = 'Curve in force';
        }

        if ($excused > 0) {
            $notes[] = $excused . ($excused === 1 ? ' item excused' : ' items excused');
        }

        if ($remarks === 'INC') {
            $notes[] = 'Incomplete: ' . trim((string) ($record['inc_reason'] ?? ''));
        }

        $band = $percentage !== null ? ($this->engine->bandFor($context['bands'], $percentage)['name'] ?? null) : null;

        return [
            'student_id' => $studentId,
            'student_number' => (string) $student['student_number'],
            'student_name' => (string) $student['student_name'],
            'scores' => $scores,
            'categories' => $categoryPercentages,
            'percentage' => $percentage,
            'band' => $band,
            'remarks' => $remarks,
            'tag' => GradingEngineService::displayTag($remarks, $isFinal),
            'is_final' => $isFinal,
            'is_override' => !empty($record['is_override']),
            'inc_reason' => $record['inc_reason'] ?? null,
            'inc_until' => $record['inc_until'] ?? null,
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(array $rows, float $passing): array
    {
        $stats = GradeStatistics::of(array_map(static fn (array $r): ?float => $r['percentage'], $rows), $passing);

        $graded = array_values(array_filter(array_map(static fn (array $r): ?float => $r['percentage'], $rows), static fn ($p): bool => $p !== null));

        sort($graded);

        $tags = [];

        foreach ($rows as $row) {
            $tags[$row['tag']] = ($tags[$row['tag']] ?? 0) + 1;
        }

        $spread = [];

        foreach (self::BUCKETS as $index => [$label, $from]) {

            $to = self::BUCKETS[$index + 1][1] ?? 100.01;

            $spread[] = [
                'label' => $label,
                'count' => count(array_filter($graded, static fn (float $p): bool => $p >= $from && $p < $to)),
            ];

        }

        return $stats + ['tags' => $tags, 'spread' => $spread];
    }

    /** @return array{course_code: string, course_title: string, section: string, term: string} */
    private function offeringInfo(int $offeringId): array
    {
        $row = db_connect()->table('course_offerings o')
            ->select('c.course_code, c.course_title, o.section_code, t.name AS term_name')
            ->join('courses c', 'c.id = o.course_id', 'left')
            ->join('academic_terms t', 't.id = o.term_id', 'left')
            ->where('o.id', $offeringId)
            ->get()->getRowArray() ?: [];

        return [
            'course_code' => (string) ($row['course_code'] ?? ''),
            'course_title' => (string) ($row['course_title'] ?? ''),
            'section' => (string) ($row['section_code'] ?? ''),
            'term' => (string) ($row['term_name'] ?? ''),
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** A cell that a spreadsheet could read as a formula is made plain text. */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /** @param list<list<mixed>> $lines */
    private function writeCsv(array $report, array $lines): array
    {
        return $this->save($report, 'csv', function (string $path) use ($lines): void {

            $handle = fopen($path, 'wb');

            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($lines as $line) {
                fputcsv($handle, array_map(fn ($cell) => $this->safeCell($cell), $line), ',', '"', '');
            }

            fclose($handle);

        });
    }

    /** @param list<list<mixed>> $lines */
    private function writeXlsx(array $report, array $lines): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Mentora')->setTitle('Class gradebook');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Gradebook');

        foreach ($lines as $r => $line) {
            foreach ($line as $c => $cell) {

                // Numbers stay numbers so they can be sorted and summed; text is never read as a formula.
                if (is_int($cell) || is_float($cell)) {
                    $sheet->setCellValue([$c + 1, $r + 1], $cell);
                } else {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) $this->safeCell($cell), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }

            }
        }

        $last = count($lines[0]);

        $sheet->getStyle([1, 1, $last, 1])->getFont()->setBold(true);
        $sheet->getStyle([1, 1, $last, 1])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6EEFB');
        $sheet->freezePane('C2');

        $standing = $last - 1;
        $percentFrom = 2 + count($report['items']) + 1;
        $percentTo = $percentFrom + count($report['categories']);

        $colours = ['Passed' => '2E7D4F', 'On track' => '2E7D4F', 'Failed' => 'C33939', 'At risk' => '946200', 'INC' => '946200'];

        foreach ($report['rows'] as $offset => $row) {

            $rowNumber = $offset + 2;

            if (isset($colours[$row['tag']])) {
                $sheet->getStyle([$standing, $rowNumber, $standing, $rowNumber])->getFont()->setBold(true)->getColor()->setRGB($colours[$row['tag']]);
            }

        }

        if ($report['rows'] !== []) {
            $sheet->getStyle([$percentFrom, 2, $percentTo, count($report['rows']) + 1])->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle([$last, 2, $last, count($report['rows']) + 1])->getAlignment()->setWrapText(true);
        }

        for ($c = 1; $c <= $last; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        // The notes are prose: a fixed width, wrapped, instead of one very long line.
        $sheet->getColumnDimensionByColumn($last)->setAutoSize(false)->setWidth(48);

        $summary = $spreadsheet->createSheet();
        $summary->setTitle('Summary');

        $facts = [
            ['Course', $report['offering']['course_code'] . ' ' . $report['offering']['course_title']],
            ['Section', $report['offering']['section']],
            ['Term', $report['offering']['term']],
            ['Grading', $report['scheme']['name'] . ($report['is_published'] ? '' : ' (draft)')],
            ['Grades', $report['finalization'] ? 'Final, ' . $report['finalization']['finalized_at'] : 'Running (not final)'],
            ['Students', $report['summary']['students']],
            ['Graded', $report['summary']['graded']],
            ['Class average %', $report['summary']['mean']],
            ['Median %', $report['summary']['median']],
            ['Highest %', $report['summary']['high']],
            ['Lowest %', $report['summary']['low']],
            ['Passing mark %', $report['summary']['passing_mark']],
            ['Generated', $report['generated_at']],
        ];

        foreach ($report['curves'] as $curve) {
            $facts[] = ['Curve', $curve['target'] . ': ' . $curve['label']];
        }

        foreach ($facts as $r => [$label, $value]) {
            $summary->setCellValue([1, $r + 1], $label);
            $summary->setCellValue([2, $r + 1], $value);
        }

        $summary->getStyle([1, 1, 1, count($facts)])->getFont()->setBold(true);
        $summary->getColumnDimensionByColumn(1)->setAutoSize(true);
        $summary->getColumnDimensionByColumn(2)->setAutoSize(true);

        $spreadsheet->setActiveSheetIndex(0);

        return $this->save($report, 'xlsx', static function (string $path) use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save($path);
        });
    }

    private function save(array $report, string $extension, callable $write): array
    {
        $directory = (new StorageService())->root() . 'exports';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        // The report and the data are different files; the name says which.
        $filename = $this->fileStem($report, $extension === 'csv' ? 'gradebook-data' : 'gradebook') . '.' . $extension;

        $path = $directory . '/' . date('His') . '-' . bin2hex(random_bytes(3)) . '-' . $filename;

        $write($path);

        return ['success' => true, 'file_path' => $path, 'filename' => $filename];
    }
}
