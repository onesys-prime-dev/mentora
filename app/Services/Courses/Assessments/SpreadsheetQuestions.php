<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Questions as a spreadsheet, one per row: the layout departments already
 * keep in Excel.
 *
 *     Type | Question | A | B | C | D | E | F | G | H | Answer | Points | Required
 *
 * Answer is a letter (or "A,C") for multiple choice, True or False, or the
 * accepted texts separated by "|" for a short answer. Pure with respect to
 * the database: rows in, questions out (and back).
 */
final class SpreadsheetQuestions
{
    public const CHOICE_COLUMNS = 8;

    private const TYPES = [
        'multiple choice' => 'Multiple Choice', 'mc' => 'Multiple Choice', 'mcq' => 'Multiple Choice', 'multiple' => 'Multiple Choice',
        'true/false' => 'True/False', 'true or false' => 'True/False', 'tf' => 'True/False', 'true false' => 'True/False', 'boolean' => 'True/False',
        'short answer' => 'Short Answer', 'short' => 'Short Answer', 'fill in' => 'Short Answer', 'identification' => 'Short Answer',
        'essay' => 'Essay', 'long answer' => 'Essay',
        'file upload' => 'File Upload', 'upload' => 'File Upload', 'file' => 'File Upload',
    ];

    /** @return list<string> */
    public static function header(): array
    {
        return array_merge(
            ['Type', 'Question'],
            array_map(static fn (int $i): string => chr(ord('A') + $i), range(0, self::CHOICE_COLUMNS - 1)),
            ['Answer', 'Points', 'Required']
        );
    }

    /**
     * Rows of a CSV or Excel file, as text.
     *
     * @return list<list<string>>
     */
    public static function rowsOf(string $path, string $extension): array
    {
        if ($extension === 'csv') {
            $raw = (string) file_get_contents($path);
            $raw = str_replace("\xEF\xBB\xBF", '', $raw);

            $first = strtok($raw, "\n") ?: '';
            $delimiter = ',';

            foreach ([';', "\t"] as $candidate) {
                if (substr_count($first, $candidate) > substr_count($first, $delimiter)) {
                    $delimiter = $candidate;
                }
            }

            $rows   = [];
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $raw);
            rewind($handle);

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                // A leading apostrophe is how an export guards a cell that starts like a formula.
                $rows[] = array_map(static fn ($c): string => (string) preg_replace("/^'(?=[=+\-@])/", '', trim((string) $c)), $row);
            }

            fclose($handle);

            return $rows;
        }

        $sheet = IOFactory::load($path)->getActiveSheet();

        return array_map(
            static fn (array $row): array => array_map(static fn ($c): string => trim((string) $c), $row),
            $sheet->toArray(null, true, true, false)
        );
    }

    /**
     * @param list<list<string>> $rows the header row first
     *
     * @return array{questions: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>}|null null when there is no recognisable header
     */
    public function parse(array $rows): ?array
    {
        $headerAt = null;
        $columns  = [];

        foreach ($rows as $index => $row) {
            $names = array_map(static fn (string $c): string => strtolower(trim($c)), $row);

            if (in_array('question', $names, true)) {
                $headerAt = $index;
                $columns  = array_flip($names);

                break;
            }
        }

        if ($headerAt === null) {
            return null;
        }

        $questions = [];
        $skipped   = [];

        foreach (array_slice($rows, $headerAt + 1, null, true) as $index => $row) {
            $cell = static fn (string $name): string => isset($columns[$name]) ? trim((string) ($row[$columns[$name]] ?? '')) : '';
            $line = $index + 1;
            $text = $cell('question');

            if ($text === '' && implode('', $row) === '') {
                continue;
            }

            if ($text === '') {
                $skipped[] = ['line' => $line, 'reason' => 'This row has no question text.'];

                continue;
            }

            $typeLabel = strtolower($cell('type'));
            $choices   = [];

            for ($i = 0; $i < self::CHOICE_COLUMNS; $i++) {
                $value = $cell(strtolower(chr(ord('A') + $i)));

                if ($value !== '') {
                    $choices[chr(ord('A') + $i)] = $value;
                }
            }

            $type = self::TYPES[$typeLabel] ?? null;

            if ($typeLabel !== '' && $type === null) {
                $skipped[] = ['line' => $line, 'reason' => 'The type "' . $cell('type') . '" is not known. Use Multiple Choice, True/False, Short Answer, Essay or File Upload.'];

                continue;
            }

            $type ??= $choices !== [] ? 'Multiple Choice' : 'Short Answer';

            $points = str_replace(',', '.', $cell('points'));

            if ($points !== '' && ! is_numeric($points)) {
                $skipped[] = ['line' => $line, 'reason' => 'Points "' . $cell('points') . '" is not a number.'];

                continue;
            }

            $pointValue = $points === '' ? 1.0 : (float) $points;
            $answer     = $cell('answer');
            $question   = $this->build($line, $type, $text, $choices, $answer, $pointValue);

            $required = strtolower($cell('required'));
            $question['is_required'] = ! in_array($required, ['no', 'n', 'false', '0', 'optional'], true);

            $questions[] = $question;
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /**
     * @param array<string, string> $choices letter => text
     *
     * @return array<string, mixed>
     */
    private function build(int $line, string $type, string $text, array $choices, string $answer, float $points): array
    {
        if ($type === 'True/False') {
            return ParsedQuestion::choice($line, $text, ParsedQuestion::trueFalse(preg_match('/^(true|t|yes)$/i', $answer) === 1), $points,
                preg_match('/^(true|t|yes|false|f|no)$/i', $answer) === 1 ? [] : ['The answer should be True or False.']);
        }

        if ($type === 'Multiple Choice') {
            $letters = array_map('strtoupper', preg_split('/\s*[,;&|]\s*|\s+/', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $notes   = [];

            foreach ($letters as $letter) {
                if (! isset($choices[$letter])) {
                    $notes[] = "The answer {$letter} is not one of the choices.";
                }
            }

            $list = [];

            foreach ($choices as $letter => $value) {
                $list[] = ['text' => $value, 'is_correct' => in_array($letter, $letters, true)];
            }

            return ParsedQuestion::choice($line, $text, $list, $points, $notes);
        }

        if ($type === 'Short Answer') {
            $accepted = array_values(array_filter(array_map('trim', explode('|', $answer)), static fn (string $a): bool => $a !== ''));

            return ParsedQuestion::shortAnswer($line, $text, $accepted, $points);
        }

        return ParsedQuestion::open($line, $text, $type, $points);
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<array<string, mixed>> $questions as listed for the assessment (with choices)
     *
     * @return list<list<string>> the header, then one row per question
     */
    public static function rowsFor(array $questions): array
    {
        $rows = [self::header()];

        foreach ($questions as $question) {
            $type    = (string) $question['question_type'];
            $choices = array_values($question['choices'] ?? []);
            $answer  = '';
            $cells   = array_fill(0, self::CHOICE_COLUMNS, '');

            if ($type === 'Multiple Choice') {
                $letters = [];

                foreach (array_slice($choices, 0, self::CHOICE_COLUMNS) as $i => $choice) {
                    $cells[$i] = (string) $choice['choice_text'];

                    if (! empty($choice['is_correct'])) {
                        $letters[] = chr(ord('A') + $i);
                    }
                }

                $answer = implode(',', $letters);
            } elseif ($type === 'Short Answer') {
                $answer = implode(' | ', array_map('strval', $question['accepted_answers'] ?? []));
            }

            $points = rtrim(rtrim(number_format((float) ($question['points'] ?? 0), 2, '.', ''), '0'), '.');

            $rows[] = array_merge(
                [$type, (string) $question['question_text']],
                $cells,
                [$answer, $points === '' ? '0' : $points, ! empty($question['is_required']) ? 'yes' : 'no']
            );
        }

        return $rows;
    }

    /** A starting file: the header and two example questions. */
    public static function templateRows(): array
    {
        return [
            self::header(),
            ['Multiple Choice', 'Which layer routes packets?', 'Transport', 'Network', 'Session', 'Data link', '', '', '', '', 'B', '2', 'yes'],
            ['Short Answer', 'What does CPU stand for?', '', '', '', '', '', '', '', '', 'Central Processing Unit | CPU', '1', 'yes'],
            ['True/False', 'A switch works at the network layer.', '', '', '', '', '', '', '', '', 'False', '1', 'yes'],
            ['Essay', 'Explain how a packet crosses a router.', '', '', '', '', '', '', '', '', '', '5', 'yes'],
        ];
    }

    /**
     * @param list<list<string>> $rows
     */
    public static function write(array $rows, string $format, string $path): void
    {
        if ($format === 'csv') {
            // Opened in Excel, a cell that starts with = + - or @ would run as a formula.
            $rows = array_map(static fn (array $row): array => array_map(
                static fn (string $c): string => preg_match('/^[=+\-@]/', $c) === 1 ? "'" . $c : $c,
                $row
            ), $rows);
        }

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Questions');
        $sheet->fromArray($rows, null, 'A1', true);

        // Everything is text, so "1." or a leading "=" is never turned into a formula or a number.
        $last = count($rows);

        foreach (range(1, $last) as $r) {
            foreach (range(1, count($rows[0])) as $c) {
                $sheet->getCell([$c, $r])->setValueExplicit((string) ($rows[$r - 1][$c - 1] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        $sheet->getColumnDimension('B')->setWidth(60);

        $writer = $format === 'csv' ? new Csv($book) : new Xlsx($book);

        if ($writer instanceof Csv) {
            $writer->setUseBOM(true);
        }

        $writer->save($path);
    }
}
