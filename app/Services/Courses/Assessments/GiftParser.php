<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * GIFT, Moodle's plain-text quiz format:
 *
 *     ::Routing::Which layer routes packets? {
 *     =Network
 *     ~Transport
 *     ~Session
 *     }
 *
 * Read: multiple choice (one or several right answers), true/false, short
 * answer and essay. Matching, numeric and fill-in-the-blank questions are
 * listed as not imported, with their line. Pure: no database, no AI.
 */
final class GiftParser
{
    /** True when the text looks like GIFT: a question followed by an answer block. */
    public static function looks(string $text): bool
    {
        return preg_match('/^[^\n{]*\{\s*(?:[=~#]|TRUE|FALSE|T|F|%|\})/imu', $text) === 1;
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

        $lines   = preg_split('/\R/u', str_replace("\xEF\xBB\xBF", '', $text)) ?: [];
        $lines[] = '';

        foreach ($lines as $index => $raw) {
            $line = rtrim($raw);

            if (str_starts_with(ltrim($line), '//') || str_starts_with(ltrim($line), '$CATEGORY') || str_starts_with(ltrim($line), '$course$')) {
                continue;
            }

            if (trim($line) !== '') {
                if ($block === []) {
                    $start = $index + 1;
                }

                $block[] = trim($line);

                continue;
            }

            if ($block !== []) {
                $this->question(implode("\n", $block), $start, $questions, $skipped);
                $block = [];
            }
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /**
     * @param list<array<string, mixed>>             $questions
     * @param list<array{line: int, reason: string}> $skipped
     */
    private function question(string $block, int $line, array &$questions, array &$skipped): void
    {
        // The answer block is the last unescaped { ... }.
        if (preg_match('/^(.*?)(?<!\\\\)\{(.*)(?<!\\\\)\}\s*(.*)$/su', $block, $m) !== 1) {
            $skipped[] = ['line' => $line, 'reason' => 'This is not a GIFT question: there is no { } answer block.'];

            return;
        }

        $stem    = $this->stem($m[1]);
        $answers = trim($m[2]);
        $tail    = trim($m[3]);

        if ($stem === '') {
            $skipped[] = ['line' => $line, 'reason' => 'This question has no text.'];

            return;
        }

        if ($tail !== '') {
            $skipped[] = ['line' => $line, 'reason' => 'Fill-in-the-blank questions are not supported: "' . mb_strimwidth($stem, 0, 40, '...') . '".'];

            return;
        }

        if ($answers === '') {
            $questions[] = ParsedQuestion::open($line, $stem);

            return;
        }

        if (preg_match('/^(?:TRUE|T)$/i', $answers) === 1 || preg_match('/^(?:FALSE|F)$/i', $answers) === 1) {
            $questions[] = ParsedQuestion::choice($line, $stem, ParsedQuestion::trueFalse(strtoupper($answers[0]) === 'T'));

            return;
        }

        if (preg_match('/^TRUE|^FALSE/i', $answers) === 1 && preg_match('/[=~]/', $answers) !== 1) {
            $questions[] = ParsedQuestion::choice($line, $stem, ParsedQuestion::trueFalse(stripos($answers, 'TRUE') === 0));

            return;
        }

        if (str_starts_with($answers, '#')) {
            $skipped[] = ['line' => $line, 'reason' => 'Numeric questions are not supported: "' . mb_strimwidth($stem, 0, 40, '...') . '".'];

            return;
        }

        if (str_contains($answers, '->')) {
            $skipped[] = ['line' => $line, 'reason' => 'Matching questions are not supported: "' . mb_strimwidth($stem, 0, 40, '...') . '".'];

            return;
        }

        $this->options($answers, $stem, $line, $questions);
    }

    /**
     * @param list<array<string, mixed>> $questions
     */
    private function options(string $answers, string $stem, int $line, array &$questions): void
    {
        $parts = preg_split('/(?<!\\\\)(?=[=~])/u', $answers, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $choices  = [];
        $accepted = [];
        $hasWrong = false;

        foreach ($parts as $part) {
            $sign = $part[0];
            $body = substr($part, 1);

            // Feedback after # is not carried.
            $body = (string) preg_replace('/(?<!\\\\)#.*$/su', '', $body);
            $correct = $sign === '=';

            if ($sign === '~') {
                $hasWrong = true;

                if (preg_match('/^%(-?\d+(?:\.\d+)?)%/', $body, $w) === 1) {
                    $correct = (float) $w[1] > 0;
                    $body = (string) substr($body, strlen($w[0]));
                }
            }

            $text = $this->unescape($body);

            if ($text === '') {
                continue;
            }

            $choices[]  = ['text' => $text, 'is_correct' => $correct];

            if ($correct) {
                $accepted[] = $text;
            }
        }

        if ($choices === []) {
            $questions[] = ParsedQuestion::open($line, $stem);

            return;
        }

        // Only "=" answers: any of them is accepted, typed in.
        if (! $hasWrong) {
            $questions[] = ParsedQuestion::shortAnswer($line, $stem, $accepted);

            return;
        }

        $questions[] = ParsedQuestion::choice($line, $stem, $choices);
    }

    private function stem(string $raw): string
    {
        // ::title:: and a [html] / [markdown] format tag are not part of the text.
        $raw = (string) preg_replace('/^\s*::.*?::/su', '', $raw);
        $raw = (string) preg_replace('/^\s*\[(?:html|markdown|moodle|plain)\]\s*/i', '', $raw);

        return $this->unescape(trim((string) preg_replace('/\s+/u', ' ', $raw)));
    }

    private function unescape(string $text): string
    {
        return trim((string) preg_replace('/\\\\([:~=#{}\\\\])/u', '$1', $text));
    }
}
