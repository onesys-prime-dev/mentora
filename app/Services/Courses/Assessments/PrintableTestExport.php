<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * A quiz as a paper test: the questions to hand out, then, on pages of their
 * own at the end, the answer key. Nothing here is meant to be read back in;
 * the editable export that round-trips through import is
 * QuestionExportService.
 *
 * The test is first described once, as plain data (see model()), and that
 * description is written as Word, RTF or PDF, so the three always say the same
 * thing in the same order.
 */
final class PrintableTestExport
{
    public const FORMATS = ['pdf', 'docx', 'rtf'];

    private const BLANK_LINES_FOR_ESSAY = 6;

    /**
     * @param array<string, mixed>       $assessment
     * @param list<array<string, mixed>> $questions  as listed for the assessment (with choices)
     *
     * @return array{
     *     title: string, instructions: string, total_points: string, question_count: int,
     *     questions: list<array{number: int, type: string, text: string, points: string, note: string, choices: list<array{letter: string, text: string}>}>,
     *     key: list<array{number: int, type: string, points: string, answer: string}>
     * }
     */
    public function model(array $assessment, array $questions): array
    {
        $items = [];
        $key   = [];
        $total = 0.0;

        foreach (array_values($questions) as $index => $question) {
            $number = $index + 1;
            $type   = (string) $question['question_type'];
            $points = (float) ($question['points'] ?? 0);
            $total += $points;

            $choices = [];
            $correct = [];

            foreach (array_values($question['choices'] ?? []) as $c => $choice) {
                $letter    = chr(ord('A') + $c);
                $choices[] = ['letter' => $letter, 'text' => (string) $choice['choice_text']];

                if (! empty($choice['is_correct'])) {
                    $correct[] = $letter . '. ' . $choice['choice_text'];
                }
            }

            $items[] = [
                'number'  => $number,
                'type'    => $type,
                'text'    => (string) $question['question_text'],
                'points'  => self::points($points),
                'note'    => $this->noteFor($question),
                'choices' => $type === 'Multiple Choice' ? $choices : [],
            ];

            $key[] = [
                'number' => $number,
                'type'   => $type,
                'points' => self::points($points),
                'answer' => $this->answerFor($question, $correct),
            ];
        }

        return [
            'title'          => (string) ($assessment['title'] ?? 'Test'),
            'instructions'   => trim((string) ($assessment['instructions'] ?? '')),
            'total_points'   => self::points($total),
            'question_count' => count($items),
            'questions'      => $items,
            'key'            => $key,
        ];
    }

    /**
     * @param array<string, mixed> $model from model()
     */
    public function save(array $model, string $format, string $path): void
    {
        if ($format === 'pdf') {
            file_put_contents($path, $this->pdf($this->html($model)));

            return;
        }

        $writer = $format === 'rtf' ? 'RTF' : 'Word2007';

        IOFactory::createWriter($this->word($model), $writer)->save($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Word and RTF
    |--------------------------------------------------------------------------
    */

    /** @param array<string, mixed> $model */
    public function word(array $model): PhpWord
    {
        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);

        $section = $word->addSection(['marginTop' => 1000, 'marginBottom' => 1000, 'marginLeft' => 1200, 'marginRight' => 1200]);

        $section->addText($model['title'], ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER, 'spaceAfter' => 80]);
        $section->addText(
            $model['question_count'] . ' ' . ($model['question_count'] === 1 ? 'question' : 'questions') . ' · ' . $model['total_points'] . ' points',
            ['size' => 10, 'color' => '555555'],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 240]
        );

        $table = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
        $table->addRow();
        $table->addCell(5000)->addText('Name: ______________________________');
        $table->addCell(4000)->addText('Date: ____________________');
        $table->addRow();
        $table->addCell(5000)->addText('Section: ___________________________');
        $table->addCell(4000)->addText('Score: ________ / ' . $model['total_points']);

        $section->addTextBreak();

        if ($model['instructions'] !== '') {
            $section->addText('Instructions', ['bold' => true]);
            $section->addText($model['instructions'], [], ['spaceAfter' => 200]);
        }

        foreach ($model['questions'] as $q) {
            $section->addText(
                $q['number'] . '. ' . $q['text'] . '  (' . $q['points'] . ' ' . ($q['points'] === '1' ? 'pt' : 'pts') . ')',
                ['bold' => true],
                ['spaceBefore' => 160, 'spaceAfter' => 60, 'keepNext' => true]
            );

            if ($q['note'] !== '') {
                $section->addText($q['note'], ['italic' => true, 'size' => 9, 'color' => '555555'], ['spaceAfter' => 40, 'keepNext' => true]);
            }

            foreach ($q['choices'] as $choice) {
                $section->addText($choice['letter'] . '.  ' . $choice['text'], [], ['indentation' => ['left' => 500], 'spaceAfter' => 30]);
            }

            if ($q['type'] === 'Short Answer') {
                $section->addText('Answer: ______________________________________________', [], ['indentation' => ['left' => 500], 'spaceBefore' => 80]);
            } elseif ($q['type'] === 'Essay') {
                for ($i = 0; $i < self::BLANK_LINES_FOR_ESSAY; $i++) {
                    $section->addText(str_repeat('_', 78), [], ['spaceBefore' => 80]);
                }
            } elseif ($q['type'] === 'File Upload') {
                $section->addText('Submit your work as instructed.', ['italic' => true, 'size' => 10], ['indentation' => ['left' => 500]]);
            }
        }

        if ($model['questions'] === []) {
            $section->addText('This test has no questions yet.', ['italic' => true]);
        }

        // The key starts on a page of its own, so the questions can be printed
        // and handed out without it.
        $section->addPageBreak();
        $section->addText('Answer Key', ['bold' => true, 'size' => 16], ['spaceAfter' => 60]);
        $section->addText($model['title'], ['size' => 10, 'color' => '555555'], ['spaceAfter' => 200]);

        foreach ($model['key'] as $row) {
            $section->addText(
                $row['number'] . '. ' . $row['answer'] . '  (' . $row['points'] . ' ' . ($row['points'] === '1' ? 'pt' : 'pts') . ')',
                [],
                ['spaceAfter' => 80]
            );
        }

        return $word;
    }

    /*
    |--------------------------------------------------------------------------
    | PDF
    |--------------------------------------------------------------------------
    */

    /** @param array<string, mixed> $model */
    public function html(array $model): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body  = '<h1>' . $e($model['title']) . '</h1>';
        $body .= '<p class="sub">' . $model['question_count'] . ' ' . ($model['question_count'] === 1 ? 'question' : 'questions')
            . ' &middot; ' . $e($model['total_points']) . ' points</p>';
        $body .= '<table class="who"><tr><td>Name: <span class="fill">&nbsp;</span></td><td>Date: <span class="fill">&nbsp;</span></td></tr>'
            . '<tr><td>Section: <span class="fill">&nbsp;</span></td><td>Score: <span class="fill short">&nbsp;</span> / ' . $e($model['total_points']) . '</td></tr></table>';

        if ($model['instructions'] !== '') {
            $body .= '<p class="instructions"><strong>Instructions</strong><br>' . nl2br($e($model['instructions'])) . '</p>';
        }

        foreach ($model['questions'] as $q) {
            $body .= '<div class="q"><p class="qtext"><strong>' . $q['number'] . '. ' . nl2br($e($q['text'])) . '</strong> '
                . '<span class="pts">(' . $e($q['points']) . ' ' . ($q['points'] === '1' ? 'pt' : 'pts') . ')</span></p>';

            if ($q['note'] !== '') {
                $body .= '<p class="note">' . $e($q['note']) . '</p>';
            }

            foreach ($q['choices'] as $choice) {
                $body .= '<p class="choice">' . $e($choice['letter']) . '.&nbsp; ' . $e($choice['text']) . '</p>';
            }

            if ($q['type'] === 'Short Answer') {
                $body .= '<p class="choice">Answer: <span class="fill long">&nbsp;</span></p>';
            } elseif ($q['type'] === 'Essay') {
                $body .= str_repeat('<div class="line"></div>', self::BLANK_LINES_FOR_ESSAY);
            } elseif ($q['type'] === 'File Upload') {
                $body .= '<p class="note">Submit your work as instructed.</p>';
            }

            $body .= '</div>';
        }

        if ($model['questions'] === []) {
            $body .= '<p class="note">This test has no questions yet.</p>';
        }

        $body .= '<div class="key"><h1>Answer Key</h1><p class="sub">' . $e($model['title']) . '</p>';

        foreach ($model['key'] as $row) {
            $body .= '<p class="answer"><strong>' . $row['number'] . '.</strong> ' . nl2br($e($row['answer']))
                . ' <span class="pts">(' . $e($row['points']) . ' ' . ($row['points'] === '1' ? 'pt' : 'pts') . ')</span></p>';
        }

        $body .= '</div>';

        $css = <<<'CSS'
            @page { margin: 22mm 18mm; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #111; }
            h1 { font-size: 17pt; text-align: center; margin: 0 0 4pt; }
            .sub { text-align: center; color: #555; font-size: 9.5pt; margin: 0 0 14pt; }
            .who { width: 100%; margin-bottom: 14pt; }
            .who td { width: 50%; padding: 5pt 0; }
            .fill { display: inline-block; border-bottom: 1px solid #000; width: 62%; }
            .fill.short { width: 18%; }
            .fill.long { width: 80%; }
            .instructions { margin: 0 0 14pt; }
            .q { page-break-inside: avoid; margin: 0 0 12pt; }
            .qtext { margin: 0 0 4pt; }
            .pts { color: #555; font-size: 9pt; }
            .note { color: #555; font-style: italic; font-size: 9pt; margin: 0 0 3pt; }
            .choice { margin: 0 0 3pt 18pt; }
            .line { border-bottom: 1px solid #000; height: 20pt; }
            .key { page-break-before: always; }
            .answer { margin: 0 0 7pt; }
            CSS;

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>' . $body . '</body></html>';
    }

    private function pdf(string $html): string
    {
        $cache = WRITEPATH . 'cache/dompdf';

        if (! is_dir($cache)) {
            mkdir($cache, 0777, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $options->set('chroot', WRITEPATH);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /*
    |--------------------------------------------------------------------------
    | Wording
    |--------------------------------------------------------------------------
    */

    /** @param array<string, mixed> $question */
    private function noteFor(array $question): string
    {
        if (($question['question_type'] ?? '') !== 'Multiple Choice' || empty($question['allow_multiple_answers'])) {
            return '';
        }

        $count = (int) ($question['answers_count'] ?? 0);

        return $count >= 2 ? "Choose {$count} answers." : 'Select all that apply.';
    }

    /**
     * @param array<string, mixed> $question
     * @param list<string>         $correct  the correct choices, as "B. text"
     */
    private function answerFor(array $question, array $correct): string
    {
        switch ($question['question_type']) {
            case 'Multiple Choice':
                return $correct === [] ? 'No correct answer is set.' : implode('; ', $correct);

            case 'Short Answer':
                $answers = array_values(array_filter(array_map('strval', $question['accepted_answers'] ?? []), static fn (string $a): bool => trim($a) !== ''));

                if ($answers === []) {
                    return 'No answer key is set.';
                }

                return implode(' / ', $answers) . (! empty($question['case_sensitive']) ? ' (case-sensitive)' : '');

            case 'Essay':
                return 'Open-ended: graded by the instructor.';

            default:
                return 'File submission: graded by the instructor.';
        }
    }

    private static function points(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
