<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * Reads an exam printed out of a test-bank tool (Pearson TestGen and the
 * like), however the file reached us: text, RTF, a PDF's text layer or the
 * text of an HTML export.
 *
 *     MULTIPLE CHOICE.  Choose the one alternative that best completes ...
 *     1) The term ________ can be used to describe anything that uses binary numbers.      1) _______
 *     A) mnemonic      B) processor      C) computer      D) digital
 *     ...
 *     1) D   2) A   3) B ...
 *
 * What these files share, and what makes them unlike a hand-typed exam:
 * choices sit on one line (or wrap), the question number is repeated before a
 * blank on the right ("N) ______"), the key is a list of "N) X" at the end
 * (with or without an "Answer Key" label), and, depending on the file type,
 * the spaces or line breaks have been lost. So the text is read as one stream:
 * questions are found by counting 1, 2, 3 ..., choices by their letters in
 * order, and a key by its numbers restarting at 1.
 *
 * Pure and deterministic: no AI, no database.
 */
final class PrintedExamParser
{
    private const MAX_CHOICES = 6;

    /** Section headings and the type of the questions that follow them. */
    private const HEADERS = [
        'MULTIPLE CHOICE' => 'choice',
        'TRUE/FALSE' => 'tf',
        'TRUE OR FALSE' => 'tf',
        'SHORT ANSWER' => 'short',
        'VOCABULARY' => 'short',
        'FILL IN THE BLANK' => 'short',
        'FILL-IN-THE-BLANK' => 'short',
        'COMPLETION' => 'short',
        'ESSAY' => 'essay',
    ];

    /** True when the text looks like a printed exam: numbered stems repeated before a blank, then lettered choices. */
    public static function looks(string $text): bool
    {
        $hits = preg_match_all('/(?<!\S)(\d{1,3})\)\s+\S[^\n]{3,}?(?<!\d)\1\)\s*(?:_{3,}|\n\s*A\)|A\))/u', $text);

        return $hits !== false && $hits >= 3;
    }

    /**
     * @return array{questions: list<array<string, mixed>>, skipped: list<array{line: int, reason: string, severity?: string}>}
     */
    public function parse(string $text): array
    {
        $text = $this->normalise($text);
        [$body, $key] = $this->splitKey($text);

        $questions = [];
        $skipped = [];
        $mode = 'choice';
        $cursor = 0;
        $n = 1;

        while (true) {
            $marker = $this->find($body, $n, $cursor);

            if ($marker === null) {
                break;
            }

            $next = $this->find($body, $n + 1, $this->afterChoices($body, $marker['end']));
            $segment = substr($body, $marker['end'], ($next['start'] ?? strlen($body)) - $marker['end']);
            $cursor = $next['start'] ?? strlen($body);

            // A section heading closes the previous question's text and sets the type that follows.
            $nextMode = $mode;

            if (preg_match($this->headerPattern(), $segment, $h, PREG_OFFSET_CAPTURE) === 1) {
                $nextMode = self::HEADERS[strtoupper($h[1][0])] ?? $mode;
                $segment = substr($segment, 0, $h[0][1]);
            }

            $built = $this->build($n, $segment, $mode, $key[$n] ?? null, $skipped);

            if ($built !== null) {
                $questions[] = $built;
            }

            $mode = $nextMode;
            $n++;

            if ($next === null) {
                break;
            }
        }

        if ($key !== [] && $questions !== [] && count($key) !== $n - 1) {
            $skipped[] = ['line' => 0, 'severity' => 'warning', 'reason' => 'The answer key lists ' . count($key) . ' answers but ' . ($n - 1) . ' questions were found. Check the answers in the review.'];
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /*
    |--------------------------------------------------------------------------
    | Normalising
    |--------------------------------------------------------------------------
    */

    private function normalise(string $text): string
    {
        $text = str_replace("\xEF\xBB\xBF", '', $text);
        $text = str_replace(["\r\n", "\r", "\xC2\xA0", "\u{00A0}"], ["\n", "\n", ' ', ' '], $text);
        // Layout whitespace between words; keep line breaks, which still help.
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * The answer key is a run of "1) D 2) A ..." whose numbering restarts at
     * 1 after the last question, with or without an "Answer Key" label.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function splitKey(string $text): array
    {
        // A "1) X 2) Y" run: the last place where answer 1 is followed by answer 2.
        if (preg_match_all('/(?<!\S)1\)\s*(?:[A-F]|T|F|TRUE|FALSE)\b\s*2\)\s*\S/iu', $text, $m, PREG_OFFSET_CAPTURE) === false || $m[0] === []) {
            return [$text, []];
        }

        $start = (int) end($m[0])[1];
        $body = rtrim(substr($text, 0, $start));
        // The label (and "Testname: ...") belongs to the key, not to the last choice.
        $body = (string) preg_replace('/\s*Answer\s+Key\b.*$/isu', '', $body);
        $tail = substr($text, $start);

        $key = [];

        if (preg_match_all('/(?<!\S)(\d{1,3})\)\s*(.+?)(?=\s+\d{1,3}\)\s|\s*$)/su', $tail, $e, PREG_SET_ORDER) !== false) {
            foreach ($e as $entry) {
                $key[(int) $entry[1]] ??= trim((string) preg_replace('/\s+/u', ' ', $entry[2]));
            }
        }

        return [$body, $key];
    }

    /*
    |--------------------------------------------------------------------------
    | Finding things
    |--------------------------------------------------------------------------
    */

    /**
     * The next occurrence, at or after $from, of question number $n as the
     * start of a stem ("n) text"), not as the blank repeated after a stem.
     *
     * @return array{start: int, end: int}|null
     */
    private function find(string $body, int $n, int $from): ?array
    {
        if (preg_match_all('/(?<![\w.])' . $n . '\)\s*(?=\S)/u', $body, $m, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        foreach ($m[0] as [$hit, $pos]) {
            if ($pos >= $from) {
                // Not the "n) A)" artifact of a flattened PDF.
                if (preg_match('/^' . $n . '\)\s*A\)/u', substr($body, $pos, 12)) === 1) {
                    continue;
                }

                return ['start' => $pos, 'end' => $pos + strlen($hit)];
            }
        }

        return null;
    }

    /** Where to start looking for the next question: past the first "A)" after this stem. */
    private function afterChoices(string $body, int $from): int
    {
        if (preg_match('/(?<![A-Za-z0-9])A\)\s*/u', $body, $m, PREG_OFFSET_CAPTURE, $from) === 1) {
            return (int) $m[0][1] + strlen($m[0][0]);
        }

        return $from;
    }

    private function headerPattern(): string
    {
        $names = array_map(static fn (string $h): string => preg_quote($h, '/'), array_keys(self::HEADERS));

        return '/(?<![A-Za-z])(' . implode('|', $names) . ')\.\s/iu';
    }

    /*
    |--------------------------------------------------------------------------
    | Building a question
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<array{line: int, reason: string}> $skipped
     *
     * @return array<string, mixed>|null
     */
    private function build(int $number, string $segment, string $mode, ?string $answer, array &$skipped): ?array
    {
        // The blank repeated on the right: "1) ______" or, in a flattened PDF, "1) A) ...".
        // Without separators (RTF with its spaces lost) the number can be glued to the stem's last
        // digit ("... * 234) ___"), so the last such blank in the segment is the one taken.
        $stem = $this->clean($segment);
        $rest = '';

        if (preg_match_all('/' . $number . '\)\s*(?:_{2,}|(?=A\)))/u', $segment, $b, PREG_OFFSET_CAPTURE) !== false && $b[0] !== []) {
            [$hit, $at] = end($b[0]);
            $stem = $this->clean(substr($segment, 0, (int) $at));
            $rest = substr($segment, (int) $at + strlen($hit));
        }

        if ($rest === '' && preg_match('/(?<![A-Za-z0-9])A\)\s/u', $segment, $a, PREG_OFFSET_CAPTURE) === 1) {
            // No blank at all: the stem ends where the first choice starts.
            $stem = $this->clean(substr($segment, 0, (int) $a[0][1]));
            $rest = substr($segment, (int) $a[0][1]);
        }

        $choices = $this->choices($rest);

        if ($stem === '') {
            $skipped[] = ['line' => $number, 'reason' => 'Question ' . $number . ' has no text.'];

            return null;
        }

        if (count($choices) >= 2) {
            return $this->choiceQuestion($number, $stem, $choices, $answer);
        }

        if ($mode === 'tf') {
            $value = $answer !== null && preg_match('/^(t|true)\b/i', $answer) === 1;
            $known = $answer !== null && preg_match('/^(t|f|true|false)\b/i', $answer) === 1;

            return ParsedQuestion::choice($number, $stem, ParsedQuestion::trueFalse($value), 1.0, $known ? [] : ['The answer is not given: choose True or False.']);
        }

        if ($mode === 'essay') {
            return ParsedQuestion::open($number, $stem, 'Essay');
        }

        $keys = $answer !== null && trim($answer) !== '' ? [$answer] : [];

        return ParsedQuestion::shortAnswer($number, $stem, $keys, 1.0, $keys === [] ? ['No answer was given for this question.'] : []);
    }

    /**
     * Choices A, B, C ... found in order, so a letter inside a choice's own
     * text (a "C)" in a sentence) is not taken for the next choice.
     *
     * @return list<string>
     */
    private function choices(string $rest): array
    {
        $rest = ltrim($rest);

        if (preg_match('/^A\)\s*/u', $rest, $m) !== 1) {
            return [];
        }

        $pos = strlen($m[0]);
        $texts = [];
        $letters = range('A', chr(ord('A') + self::MAX_CHOICES - 1));

        for ($i = 0; $i < count($letters); $i++) {
            $next = $letters[$i + 1] ?? null;
            $end = strlen($rest);

            if ($next !== null && preg_match('/(?<!\()' . $next . '\)\s*/u', $rest, $n, PREG_OFFSET_CAPTURE, $pos) === 1) {
                $end = (int) $n[0][1];
                $texts[] = $this->clean(substr($rest, $pos, $end - $pos));
                $pos = $end + strlen($n[0][0]);

                continue;
            }

            $texts[] = $this->clean(substr($rest, $pos));

            break;
        }

        return array_values(array_filter($texts, static fn (string $t): bool => $t !== ''));
    }

    /**
     * @param list<string> $choices
     *
     * @return array<string, mixed>
     */
    private function choiceQuestion(int $number, string $stem, array $choices, ?string $answer): array
    {
        $letters = $answer !== null && preg_match_all('/\b([A-F])\b/', strtoupper($answer), $m) ? array_values(array_unique($m[1])) : [];
        $notes = [];
        $list = [];

        foreach ($choices as $i => $text) {
            $list[] = ['text' => $text, 'is_correct' => in_array(chr(ord('A') + $i), $letters, true)];
        }

        if ($answer === null) {
            $notes[] = 'No answer key was found: choose the correct answer.';
        } elseif ($letters === []) {
            $notes[] = 'The answer "' . $answer . '" is not a choice letter: choose the correct answer.';
        } else {
            foreach ($letters as $letter) {
                if (ord($letter) - ord('A') >= count($choices)) {
                    $notes[] = "The key names {$letter}, which is not one of the choices.";
                }
            }
        }

        return ParsedQuestion::choice($number, $stem, $list, 1.0, $notes);
    }

    private function clean(string $text): string
    {
        $text = (string) preg_replace('/_{3,}\s*$/u', '', $text);
        $text = (string) preg_replace('/[ \t]*\n[ \t]*/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
