<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * Reads the questions of an ordinary exam document: the layout teachers
 * already use, with none of Mentora's markers.
 *
 *     1. Which layer routes packets? (2 pts)
 *     a) Transport
 *     b) Network
 *     Answer: b
 *
 * A question starts with a number (`1.`, `1)`, `Q1:`, `Question 1`), choices
 * are lettered (`a)`, `A.`, `(a)`), and the answer comes from an `Answer:`
 * line, a mark on the choice (`*`, `(correct)`, a tick, or bold when only some
 * choices are bold) or an answer key at the end of the document. The type
 * follows from the shape: choices make a multiple-choice question,
 * true-or-false is recognised, an answer without choices is a short answer,
 * and anything else is an essay.
 *
 * Pure and deterministic: no AI, no database. What it is unsure of is said in
 * `notes`, so the teacher can check it in the preview.
 */
final class PlainQuestionParser
{
    private const QUESTION_START = '/^(?:(?:question|q|item|no\.?)\s*)?#?(\d{1,3})\s*[.):\-]\s+(\S.*)$|^\((\d{1,3})\)\s+(\S.*)$/iu';

    private const CHOICE = '/^(\*?)\s*\(?([A-Ha-h])[.)\]:]\s+(\S.*)$/u';

    private const ANSWER = '/^(?:correct\s+)?(?:answer|ans|key|correct)s?(?:\s+answer)?\s*[:=\-–]\s*(.+)$/iu';

    private const POINTS_INLINE = '/[\(\[]\s*(\d+(?:[.,]\d+)?)\s*(?:pts?|points?|marks?|score)\s*[\)\]]/iu';

    private const POINTS_LINE = '/^(?:points?|marks?|score)\s*[:=]\s*(\d+(?:[.,]\d+)?)\s*$/iu';

    private const KEY_HEADER = '/^(?:answer\s*key|answers?|key|answer\s*sheet)\s*:?\s*$/iu';

    private const KEY_ENTRY = '/^(\d{1,3})\s*[.):\-=]?\s*(.+)$/u';

    private const LETTER_LIST = '/^[A-Ha-h](?:\s*(?:,|&|\/|and|or)\s*[A-Ha-h])*\.?$/iu';

    private const CORRECT_MARK = '/\s*(?:\*+|\((?:correct|answer)\)|\[(?:correct|answer)\]|✓|✔|✅)\s*$|^\s*\*+\s*/iu';

    /**
     * @param list<array{text: string, bold?: bool}> $lines the document's paragraphs, in order
     *
     * @return list<array{line: int, question_text: string, question_type: string, points: float, choices: list<array{text: string, is_correct: bool}>, accepted_answers: list<string>, allow_multiple_answers: bool, answers_count: int|null, notes: list<string>}>
     */
    public function parse(array $lines): array
    {
        $entries = [];

        foreach ($lines as $index => $line) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($line['text'] ?? '')));

            if ($text !== '') {
                $entries[] = ['text' => $text, 'bold' => ! empty($line['bold']), 'line' => $index + 1];
            }
        }

        [$entries, $key] = $this->splitKey($entries);

        $blocks = $this->blocks($entries);

        $questions = [];

        foreach ($blocks as $block) {
            $questions[] = $this->question($block, $key);
        }

        return $questions;
    }

    /**
     * An answer key at the end ("Answer Key" then "1. b", "2. c") is taken out
     * of the stream and returned as number => answer.
     *
     * @param list<array{text: string, bold: bool, line: int}> $entries
     *
     * @return array{0: list<array{text: string, bold: bool, line: int}>, 1: array<int, string>}
     */
    private function splitKey(array $entries): array
    {
        $firstQuestion = null;

        foreach ($entries as $i => $entry) {
            if ($this->questionStart($entry['text']) !== null) {
                $firstQuestion = $i;
                break;
            }
        }

        if ($firstQuestion === null) {
            return [$entries, []];
        }

        for ($i = count($entries) - 1; $i > $firstQuestion; $i--) {
            if (! preg_match(self::KEY_HEADER, $entries[$i]['text'])) {
                continue;
            }

            $key = [];

            foreach (array_slice($entries, $i + 1) as $entry) {
                if (preg_match(self::KEY_ENTRY, $entry['text'], $m)) {
                    $key[(int) $m[1]] = trim($m[2]);
                }
            }

            if ($key !== []) {
                return [array_slice($entries, 0, $i), $key];
            }
        }

        return [$entries, []];
    }

    /**
     * @param list<array{text: string, bold: bool, line: int}> $entries
     *
     * @return list<array{number: int, line: int, lines: list<array{text: string, bold: bool, line: int}>}>
     */
    private function blocks(array $entries): array
    {
        $blocks = [];
        $current = null;

        foreach ($entries as $entry) {
            $start = $this->questionStart($entry['text']);

            // A lettered choice is never a new question, even if it looks numbered.
            if ($start !== null && ! preg_match(self::CHOICE, $entry['text'])) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = [
                    'number' => $start['number'],
                    'line'   => $entry['line'],
                    'lines'  => [['text' => $start['text'], 'bold' => $entry['bold'], 'line' => $entry['line']]],
                ];

                continue;
            }

            if ($current !== null) {
                $current['lines'][] = $entry;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /** @return array{number: int, text: string}|null */
    private function questionStart(string $text): ?array
    {
        if (! preg_match(self::QUESTION_START, $text, $m)) {
            return null;
        }

        // The first alternative (Q1. / 1. / 1)) fills groups 1 and 2; the
        // parenthesised form "(1)" fills groups 3 and 4.
        if ($m[1] !== '') {
            return ['number' => (int) $m[1], 'text' => trim($m[2])];
        }

        return ['number' => (int) $m[3], 'text' => trim($m[4])];
    }

    /**
     * @param array{number: int, line: int, lines: list<array{text: string, bold: bool, line: int}>} $block
     * @param array<int, string>                                                                   $key
     *
     * @return array<string, mixed>
     */
    private function question(array $block, array $key): array
    {
        $textLines = [];
        $choices = [];
        $answer = null;
        $points = null;
        $notes = [];

        foreach ($block['lines'] as $i => $line) {
            $text = $line['text'];

            if ($i > 0 && preg_match(self::ANSWER, $text, $m)) {
                $answer = trim($m[1]);

                continue;
            }

            if ($i > 0 && preg_match(self::POINTS_LINE, $text, $m)) {
                $points = (float) str_replace(',', '.', $m[1]);

                continue;
            }

            if ($i > 0 && preg_match(self::CHOICE, $text, $m)) {
                $marked = $m[1] === '*' || (bool) preg_match(self::CORRECT_MARK, $m[3]);

                $choices[] = [
                    'letter'  => strtoupper($m[2]),
                    'text'    => trim((string) preg_replace(self::CORRECT_MARK, '', $m[3])),
                    'marked'  => $marked,
                    'bold'    => $line['bold'],
                ];

                continue;
            }

            if ($choices !== [] && $i > 0) {
                // A long choice that wrapped onto the next paragraph.
                $choices[array_key_last($choices)]['text'] .= ' ' . $text;

                continue;
            }

            $textLines[] = $text;
        }

        $questionText = trim(implode("\n", $textLines));

        if ($points === null && preg_match(self::POINTS_INLINE, $questionText, $m)) {
            $points = (float) str_replace(',', '.', $m[1]);
            $questionText = trim((string) preg_replace(self::POINTS_INLINE, '', $questionText));
        }

        $answer ??= $key[$block['number']] ?? null;

        return $this->typed($block, $questionText, $choices, $answer, $points ?? 1.0, $notes);
    }

    /**
     * @param array<string, mixed>       $block
     * @param list<array<string, mixed>> $choices
     * @param list<string>               $notes
     *
     * @return array<string, mixed>
     */
    private function typed(array $block, string $text, array $choices, ?string $answer, float $points, array $notes): array
    {
        $base = ['line' => $block['line'], 'question_text' => $text, 'points' => $points];
        $answer = $answer === null ? null : trim($answer, " \t.");

        if (count($choices) >= 2) {
            return $base + $this->multipleChoice($choices, $answer, $notes);
        }

        $isTrueFalse = $answer !== null && preg_match('/^(true|false|t|f)$/i', $answer)
            || preg_match('/\btrue\s+or\s+false\b/i', $text) === 1
            || preg_match('/^\s*(true|false)\s*[:.]?\s*$/i', $text) === 1;

        if ($choices === [] && $isTrueFalse) {
            $says = $answer !== null && preg_match('/^(true|t)$/i', $answer) ? 'True' : ($answer !== null && preg_match('/^(false|f)$/i', $answer) ? 'False' : null);

            if ($says === null) {
                $notes[] = 'True or false: choose which is correct.';
            }

            return $base + [
                'question_type'          => 'Multiple Choice',
                'choices'                => [
                    ['text' => 'True', 'is_correct' => $says === 'True'],
                    ['text' => 'False', 'is_correct' => $says === 'False'],
                ],
                'accepted_answers'       => [],
                'allow_multiple_answers' => false,
                'answers_count'          => null,
                'notes'                  => $notes,
            ];
        }

        if ($answer !== null && $answer !== '') {
            $keys = array_values(array_filter(array_map('trim', preg_split('/\s*[|;]\s*/u', $answer) ?: []), static fn (string $a): bool => $a !== ''));

            return $base + [
                'question_type'          => 'Short Answer',
                'choices'                => [],
                'accepted_answers'       => $keys,
                'allow_multiple_answers' => false,
                'answers_count'          => null,
                'notes'                  => $notes,
            ];
        }

        if (preg_match('/_{3,}/u', $text)) {
            $notes[] = 'This looks like a fill-in-the-blank, but no answer was found: add the accepted answer.';

            return $base + [
                'question_type'          => 'Short Answer',
                'choices'                => [],
                'accepted_answers'       => [],
                'allow_multiple_answers' => false,
                'answers_count'          => null,
                'notes'                  => $notes,
            ];
        }

        if (count($choices) === 1) {
            $notes[] = 'Only one choice was found, so this was read as an essay.';
        }

        return $base + [
            'question_type'          => 'Essay',
            'choices'                => [],
            'accepted_answers'       => [],
            'allow_multiple_answers' => false,
            'answers_count'          => null,
            'notes'                  => $notes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $choices
     * @param list<string>               $notes
     *
     * @return array<string, mixed>
     */
    private function multipleChoice(array $choices, ?string $answer, array $notes): array
    {
        $correct = [];

        foreach ($choices as $i => $choice) {
            if ($choice['marked']) {
                $correct[$i] = true;
            }
        }

        if ($answer !== null && $answer !== '') {
            if (preg_match(self::LETTER_LIST, $answer)) {
                preg_match_all('/[A-Ha-h]/', $answer, $letters);

                foreach ($choices as $i => $choice) {
                    if (in_array($choice['letter'], array_map('strtoupper', $letters[0]), true)) {
                        $correct[$i] = true;
                    }
                }
            } else {
                // The answer may be written out in full rather than as a letter.
                foreach ($choices as $i => $choice) {
                    if (mb_strtolower(trim((string) $choice['text'], ' .')) === mb_strtolower($answer)) {
                        $correct[$i] = true;
                    }
                }
            }
        }

        if ($correct === []) {
            $bold = array_keys(array_filter($choices, static fn (array $c): bool => $c['bold']));

            // Bold only means "the answer" when some, but not all, choices are bold.
            if ($bold !== [] && count($bold) < count($choices)) {
                foreach ($bold as $i) {
                    $correct[$i] = true;
                }

                $notes[] = 'The correct answer was taken from the bold choice: check it.';
            }
        }

        if ($correct === []) {
            $notes[] = 'No correct answer was found: tick the right choice.';
        }

        $multi = count($correct) > 1;

        return [
            'question_type'          => 'Multiple Choice',
            'choices'                => array_map(
                static fn (array $c, int $i): array => ['text' => (string) $c['text'], 'is_correct' => isset($correct[$i])],
                $choices,
                array_keys($choices)
            ),
            'accepted_answers'       => [],
            'allow_multiple_answers' => $multi,
            'answers_count'          => $multi ? count($correct) : null,
            'notes'                  => $notes,
        ];
    }
}
