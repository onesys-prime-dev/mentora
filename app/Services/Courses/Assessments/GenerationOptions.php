<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * What a teacher can steer about a generation: how hard, what kind of
 * thinking, in which language, about what, and how many choices. Clean here
 * once (never trust the form), then turned into one plain instruction for
 * the writer. Pure.
 */
final class GenerationOptions
{
    public const DIFFICULTY = ['mixed', 'easier', 'harder'];

    public const LEVELS = ['any', 'recall', 'understand', 'apply'];

    /** The question types the builder can write, by the key the form and the worker use. */
    public const TYPE_KEYS = ['multiple_choice', 'short_answer', 'essay', 'file_upload', 'true_false', 'multiple_answer'];

    /** The same types by display name, which older callers send. */
    public const TYPE_LABELS = ['Multiple Choice', 'Short Answer', 'Essay', 'File Upload'];

    public const CHOICES_MIN = 3;
    public const CHOICES_MAX = 5;
    public const CHOICES_DEFAULT = 4;

    private const FOCUS_MAX = 200;
    private const LANGUAGE_MAX = 40;

    /**
     * @return array{difficulty: string, level: string, language: string, focus: string, choices: int}
     */
    public static function normalise(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        $difficulty = (string) ($raw['difficulty'] ?? 'mixed');
        $level      = (string) ($raw['level'] ?? 'any');
        $choices    = is_numeric($raw['choices'] ?? null) ? (int) $raw['choices'] : self::CHOICES_DEFAULT;

        return [
            'difficulty' => in_array($difficulty, self::DIFFICULTY, true) ? $difficulty : 'mixed',
            'level'      => in_array($level, self::LEVELS, true) ? $level : 'any',
            'language'   => self::text($raw['language'] ?? '', self::LANGUAGE_MAX),
            'focus'      => self::text($raw['focus'] ?? '', self::FOCUS_MAX),
            'choices'    => max(self::CHOICES_MIN, min(self::CHOICES_MAX, $choices)),
        ];
    }

    /**
     * The type counts that name a type the builder writes, with counts that
     * are whole numbers above zero; null when nothing usable is left.
     *
     * @return array<string, int>|null
     */
    public static function typeMix(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $mix = [];

        foreach ($raw as $key => $count) {
            if ((in_array($key, self::TYPE_KEYS, true) || in_array($key, self::TYPE_LABELS, true)) && is_numeric($count) && (int) $count > 0) {
                $mix[(string) $key] = (int) $count;
            }
        }

        return $mix === [] ? null : $mix;
    }

    /**
     * What the writer is told, as a short paragraph; an empty string when
     * nothing was steered.
     *
     * @param array{difficulty: string, level: string, language: string, focus: string, choices: int} $options
     */
    public static function instruction(array $options): string
    {
        $parts = [];

        $parts[] = match ($options['difficulty']) {
            'easier' => 'Keep the questions easy: plain recall of one clear fact, with clearly distinct choices.',
            'harder' => 'Make the questions demanding: apply or compare ideas, with plausible close choices.',
            default  => '',
        };

        $parts[] = match ($options['level']) {
            'recall'     => 'Ask the student to recall facts and definitions.',
            'understand' => 'Ask the student to explain or interpret ideas in their own words.',
            'apply'      => 'Ask the student to apply the ideas to a new situation or example.',
            default      => '',
        };

        if ($options['language'] !== '') {
            $parts[] = 'Write the questions and choices in ' . $options['language'] . '.';
        }

        if ($options['focus'] !== '') {
            $parts[] = 'Only ask about: ' . $options['focus'] . '.';
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private static function text(mixed $value, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));

        return mb_substr($text, 0, $max);
    }
}
