<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * Finds a question that says (nearly) the same as another: most of the words
 * of the shorter one are in the longer one. Used to flag a generated question
 * that repeats one already in the quiz. Pure.
 */
final class QuestionTwins
{
    /** Words in common, as a share of the shorter question, that make two "the same". */
    public const OVERLAP = 0.85;

    /** Shorter questions than this are too thin to call a twin. */
    private const MIN_WORDS = 3;

    /**
     * @param list<string> $others
     *
     * @return string|null the first question that looks the same, or null
     */
    public static function find(string $text, array $others): ?string
    {
        $words = self::words($text);

        if (count($words) < self::MIN_WORDS) {
            return null;
        }

        foreach ($others as $other) {
            $theirs = self::words($other);
            $least  = min(count($words), count($theirs));

            if ($least < self::MIN_WORDS) {
                continue;
            }

            if (count(array_intersect($words, $theirs)) / $least >= self::OVERLAP) {
                return $other;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $m);

        return array_values(array_unique($m[0]));
    }
}
