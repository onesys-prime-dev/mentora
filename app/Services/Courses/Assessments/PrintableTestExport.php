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

    /** Paper sizes offered, as PhpWord and Dompdf both name them. */
    public const PAPERS = ['A4' => 'A4', 'Letter' => 'Letter', 'Legal' => 'Legal'];

    public const MAX_VERSIONS = 4;

    private const BLANK_LINES_FOR_ESSAY = 6;

    /**
     * The paper to print: one model per version. A single version keeps the
     * quiz's own order unless shuffling is asked for; with several, version A
     * is the quiz as written and the others are shuffled, each the same every
     * time it is printed (the shuffle is seeded by quiz, version and position).
     *
     * @param array<string, mixed>       $assessment
     * @param list<array<string, mixed>> $questions
     * @param array{versions?: int, shuffle_questions?: bool, shuffle_choices?: bool} $options
     *
     * @return list<array<string, mixed>> models from model(), each with 'version' ('' when there is only one)
     */
    public function versions(array $assessment, array $questions, array $options = []): array
    {
        $count    = max(1, min(self::MAX_VERSIONS, (int) ($options['versions'] ?? 1)));
        $shuffleQ = ! empty($options['shuffle_questions']);
        $shuffleC = ! empty($options['shuffle_choices']);
        $seed     = (string) ($assessment['id'] ?? '0');
        $models   = [];

        for ($v = 0; $v < $count; $v++) {
            $letter  = $count === 1 ? '' : chr(ord('A') + $v);
            $shuffle = $count === 1 || $v > 0;
            $set     = array_values($questions);

            if ($shuffle && $shuffleC) {
                foreach ($set as $i => $question) {
                    $set[$i] = $this->withShuffledChoices($question, $seed . '|' . $v . '|c' . $i);
                }
            }

            if ($shuffle && $shuffleQ) {
                $set = $this->seededOrder($set, $seed . '|' . $v . '|q');
            }

            $model            = $this->model($assessment, $set);
            $model['version'] = $letter;
            $models[]         = $model;
        }

        return $models;
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    private function seededOrder(array $items, string $seed): array
    {
        $keyed = [];

        foreach ($items as $i => $item) {
            $keyed[] = [md5($seed . '|' . $i), $item];
        }

        usort($keyed, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return array_column($keyed, 1);
    }

    /**
     * Choices that refer to each other ("all of the above", "both A and B")
     * keep their order.
     *
     * @param array<string, mixed> $question
     *
     * @return array<string, mixed>
     */
    private function withShuffledChoices(array $question, string $seed): array
    {
        $choices = array_values($question['choices'] ?? []);

        if (($question['question_type'] ?? '') !== 'Multiple Choice' || count($choices) < 2) {
            return $question;
        }

        foreach ($choices as $choice) {
            if (preg_match('/\b(all|none) of the above\b|\b(both|neither)\b.*\b[a-d]\b|\b[a-d] and [a-d]\b/i', (string) $choice['choice_text']) === 1) {
                return $question;
            }
        }

        $question['choices'] = $this->seededOrder($choices, $seed);

        return $question;
    }

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
        $this->saveMany([$model], $format, $path);
    }

    /**
     * @param list<array<string, mixed>>             $models  from versions()
     * @param array{key_only?: bool, paper?: string} $options
     */
    public function saveMany(array $models, string $format, string $path, array $options = []): void
    {
        $paper   = self::PAPERS[$options['paper'] ?? 'A4'] ?? 'A4';
        $keyOnly = ! empty($options['key_only']);

        if ($format === 'pdf') {
            file_put_contents($path, $this->pdf($this->htmlMany($models, $keyOnly), $paper));

            return;
        }

        $writer = $format === 'rtf' ? 'RTF' : 'Word2007';

        IOFactory::createWriter($this->wordMany($models, $keyOnly, $paper), $writer)->save($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Word and RTF
    |--------------------------------------------------------------------------
    */

    /** @param array<string, mixed> $model */
    public function word(array $model): PhpWord
    {
        return $this->wordMany([$model], false, 'A4');
    }

    /**
     * Every version's test, each on pages of its own, then every key together.
     *
     * @param list<array<string, mixed>> $models
     */
    public function wordMany(array $models, bool $keyOnly, string $paper): PhpWord
    {
        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);

        $settings = ['marginTop' => 1000, 'marginBottom' => 1000, 'marginLeft' => 1200, 'marginRight' => 1200, 'paperSize' => $paper];

        if (! $keyOnly) {
            foreach ($models as $model) {
                $section = $word->addSection($settings);
                $label   = ($model['version'] ?? '') !== '' ? 'Version ' . $model['version'] . ' · ' : '';

                $section->addFooter()->addPreserveText(
                    $label . $model['title'] . ' · Page {PAGE} of {NUMPAGES}',
                    ['size' => 8, 'color' => '777777'],
                    ['alignment' => Jc::CENTER]
                );

                $this->wordTest($section, $model);
            }
        }

        $section = $word->addSection($settings);

        $section->addFooter()->addPreserveText('Answer key · Page {PAGE} of {NUMPAGES}', ['size' => 8, 'color' => '777777'], ['alignment' => Jc::CENTER]);

        foreach (array_values($models) as $i => $model) {
            $this->wordKey($section, $model, $i > 0);
        }

        return $word;
    }

    /** @param array<string, mixed> $model */
    private function wordTest(\PhpOffice\PhpWord\Element\Section $section, array $model): void
    {
        $heading = ($model['version'] ?? '') !== '' ? $model['title'] . ' — Version ' . $model['version'] : $model['title'];

        $section->addText($heading, ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER, 'spaceAfter' => 80]);
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

    }

    /** @param array<string, mixed> $model */
    private function wordKey(\PhpOffice\PhpWord\Element\Section $section, array $model, bool $newPage): void
    {
        // The keys sit in a section of their own, so the questions can be
        // printed and handed out without them.
        if ($newPage) {
            $section->addPageBreak();
        }

        $heading = ($model['version'] ?? '') !== '' ? 'Answer Key — Version ' . $model['version'] : 'Answer Key';

        $section->addText($heading, ['bold' => true, 'size' => 16], ['spaceAfter' => 60]);
        $section->addText($model['title'], ['size' => 10, 'color' => '555555'], ['spaceAfter' => 200]);

        foreach ($model['key'] as $row) {
            $section->addText(
                $row['number'] . '. ' . $row['answer'] . '  (' . $row['points'] . ' ' . ($row['points'] === '1' ? 'pt' : 'pts') . ')',
                [],
                ['spaceAfter' => 80]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PDF
    |--------------------------------------------------------------------------
    */

    /** @param array<string, mixed> $model */
    public function html(array $model): string
    {
        return $this->htmlMany([$model], false);
    }

    /** @param list<array<string, mixed>> $models */
    public function htmlMany(array $models, bool $keyOnly): string
    {
        $body = '';

        if (! $keyOnly) {
            foreach (array_values($models) as $i => $model) {
                $body .= '<div class="test' . ($i > 0 ? ' next' : '') . '">' . $this->htmlTest($model) . '</div>';
            }
        }

        foreach (array_values($models) as $i => $model) {
            $body .= $this->htmlKey($model, $i > 0 || ! $keyOnly);
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $this->css() . '</style></head><body>'
            . '<div class="footer"></div>' . $body . '</body></html>';
    }

    /** @param array<string, mixed> $model */
    private function htmlTest(array $model): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $heading = ($model['version'] ?? '') !== '' ? $model['title'] . ' — Version ' . $model['version'] : $model['title'];

        $body  = '<h1>' . $e($heading) . '</h1>';
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

        return $body;
    }

    /** @param array<string, mixed> $model */
    private function htmlKey(array $model, bool $newPage): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $heading = ($model['version'] ?? '') !== '' ? 'Answer Key — Version ' . $model['version'] : 'Answer Key';

        $body = '<div class="key' . ($newPage ? ' next' : '') . '"><h1>' . $e($heading) . '</h1><p class="sub">' . $e($model['title']) . '</p>';

        foreach ($model['key'] as $row) {
            $body .= '<p class="answer"><strong>' . $row['number'] . '.</strong> ' . nl2br($e($row['answer']))
                . ' <span class="pts">(' . $e($row['points']) . ' ' . ($row['points'] === '1' ? 'pt' : 'pts') . ')</span></p>';
        }

        return $body . '</div>';
    }

    private function css(): string
    {
        return <<<'CSS'
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
            .next { page-break-before: always; }
            .answer { margin: 0 0 7pt; }
            .footer { position: fixed; bottom: -12mm; left: 0; right: 0; text-align: center; color: #777; font-size: 8pt; }
            .footer:after { content: "Page " counter(page); }
            CSS;
    }

    private function pdf(string $html, string $paper = 'A4'): string
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
        $dompdf->setPaper($paper);
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
