<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * Aiken, the plain-text format many quiz tools share:
 *
 *     Which layer routes packets?
 *     A. Transport
 *     B. Network
 *     ANSWER: B
 *
 * Questions are separated by a blank line. Only multiple choice exists in
 * Aiken. Pure: no database, no AI.
 */
final class AikenParser
{
    private const CHOICE = '/^\(?([A-Ha-h])[.)]\s*(\S.*)$/u';

    private const ANSWER = '/^ANSWER\s*:\s*([A-Ha-h](?:\s*[,;&]\s*[A-Ha-h])*)\s*$/iu';

    /** True when the text looks like Aiken rather than something else. */
    public static function looks(string $text): bool
    {
        return preg_match('/^ANSWER\s*:\s*[A-Ha-h]/imu', $text) === 1;
    }

    /**
     * @return array{questions: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>}
     */
    public function parse(string $text): array
    {
        $questions = [];
        $skipped   = [];
        $block     = [];
        $start     = 1;

        $lines = preg_split('/\R/u', str_replace("\xEF\xBB\xBF", '', $text)) ?: [];
        $lines[] = '';

        foreach ($lines as $index => $raw) {
            $line = trim($raw);

            if ($line !== '') {
                if ($block === []) {
                    $start = $index + 1;
                }

                $block[] = $line;

                continue;
            }

            if ($block !== []) {
                $this->block($block, $start, $questions, $skipped);
                $block = [];
            }
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /**
     * @param list<string>                                       $block
     * @param list<array<string, mixed>>                         $questions
     * @param list<array{line: int, reason: string}>             $skipped
     */
    private function block(array $block, int $start, array &$questions, array &$skipped): void
    {
        $stem    = [];
        $choices = [];
        $letters = null;

        foreach ($block as $line) {
            if (preg_match(self::ANSWER, $line, $m) === 1) {
                $letters = array_map('strtoupper', preg_split('/\s*[,;&]\s*/', trim($m[1])) ?: []);

                continue;
            }

            if (preg_match(self::CHOICE, $line, $m) === 1 && $stem !== []) {
                $choices[] = ['letter' => strtoupper($m[1]), 'text' => trim($m[2])];

                continue;
            }

            if ($choices === []) {
                $stem[] = $line;
            }
        }

        if ($choices === []) {
            $skipped[] = ['line' => $start, 'reason' => 'No choices were found for "' . mb_strimwidth(implode(' ', $stem), 0, 50, '...') . '". Aiken questions have lettered choices and an ANSWER line.'];

            return;
        }

        $notes = [];

        if ($letters === null) {
            $notes[] = 'No ANSWER line was found for this question.';
            $letters = [];
        }

        $known = array_column($choices, 'letter');

        foreach ($letters as $letter) {
            if (! in_array($letter, $known, true)) {
                $notes[] = "The answer {$letter} is not one of the choices.";
            }
        }

        $questions[] = ParsedQuestion::choice(
            $start,
            implode(' ', $stem),
            array_map(static fn (array $c): array => ['text' => $c['text'], 'is_correct' => in_array($c['letter'], $letters, true)], $choices),
            1.0,
            $notes
        );
    }
}
