<?php

namespace App\Services\Courses\Assessments;

/**
 * Auto-grading for Short Answer questions.
 *
 * A question lists its accepted answers (the first is the main one, the
 * rest equivalent forms such as an acronym and its full term). A response
 * is correct when it equals any of them after trimming and collapsing
 * repeated spaces; letter case is ignored unless the question is marked
 * case sensitive (off by default). No fuzzy matching or partial credit —
 * all-or-nothing, like Multiple Choice.
 *
 * A Short Answer with no accepted answers can't be auto-graded; it's left
 * for the instructor like an essay.
 */
class ShortAnswerGrader
{
    /** Most alternatives a question keeps (the main answer included). */
    public const MAX_ANSWERS = 10;

    /**
     * Accepted answers from a stored value (JSON text or an array): trimmed,
     * blanks dropped, duplicates removed, order kept.
     *
     * @return list<string>
     */
    public static function decode(mixed $stored): array
    {
        $list = is_array($stored)
            ? $stored
            : (is_string($stored) && $stored !== '' ? (json_decode($stored, true) ?? []) : []);

        $answers = [];

        foreach ($list as $answer) {

            if (!is_scalar($answer)) {
                continue;
            }

            $answer = self::tidy((string) $answer);

            if ($answer !== '' && !in_array($answer, $answers, true)) {
                $answers[] = $answer;
            }

        }

        return array_slice($answers, 0, self::MAX_ANSWERS);
    }

    /**
     * JSON to store, or null when there's nothing to store.
     */
    public static function encode(mixed $answers): ?string
    {
        $answers = self::decode($answers);

        return $answers === [] ? null : json_encode($answers, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    public function acceptedAnswers(array $question): array
    {
        return self::decode($question['accepted_answers'] ?? null);
    }

    public function isAutoGradable(array $question): bool
    {
        return ($question['question_type'] ?? '') === 'Short Answer'
            && $this->acceptedAnswers($question) !== [];
    }

    public function isCorrect(?string $response, array $question): bool
    {
        $caseSensitive = !empty($question['case_sensitive']);

        $given = $this->comparable((string) $response, $caseSensitive);

        if ($given === '') {
            return false;
        }

        foreach ($this->acceptedAnswers($question) as $answer) {
            if ($this->comparable($answer, $caseSensitive) === $given) {
                return true;
            }
        }

        return false;
    }

    private function comparable(string $text, bool $caseSensitive): string
    {
        $text = self::tidy($text);

        return $caseSensitive ? $text : mb_strtolower($text, 'UTF-8');
    }

    /** Trim and collapse any run of whitespace to a single space. */
    private static function tidy(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
