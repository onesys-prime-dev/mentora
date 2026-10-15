<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * What is wrong with a question read from a file, before anything is written.
 *
 * Pure: it looks only at the parsed question. An `error` means the question
 * cannot be imported as it stands (the teacher can fix it in the file or skip
 * it); a `warning` is something worth a look that does not stop it. Every
 * message is written for the teacher, not for a developer.
 */
final class QuestionImportValidator
{
    public const TYPES = ['Multiple Choice', 'Short Answer', 'Essay', 'File Upload'];

    public const MAX_POINTS = 1000;

    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @param array<string, mixed> $question as produced by QuestionImportService (question_text,
     *                                       question_type, points, choices, accepted_answers, ...)
     *
     * @return list<array{severity: string, message: string}>
     */
    public static function validate(array $question): array
    {
        $problems = [];
        $type     = (string) ($question['question_type'] ?? '');
        $text     = trim((string) ($question['question_text'] ?? ''));

        if ($text === '') {
            $problems[] = self::error('This question has no text.');
        }

        if (! in_array($type, self::TYPES, true)) {
            $problems[] = self::error(
                $type === 'Discussion'
                    ? 'Discussion questions cannot be imported.'
                    : 'The question type "' . ($type === '' ? 'unknown' : $type) . '" is not supported. Use Multiple Choice, Short Answer, Essay or File Upload.'
            );

            return $problems;
        }

        $points = $question['points'] ?? null;

        if (! is_numeric($points) || (float) $points < 0 || (float) $points > self::MAX_POINTS) {
            $problems[] = self::error('The points must be a number from 0 to ' . self::MAX_POINTS . '.');
        }

        if ($type === 'Multiple Choice') {
            $problems = array_merge($problems, self::multipleChoice($question));
        } elseif ($type === 'Short Answer') {
            $keys = array_filter(array_map('trim', array_map('strval', $question['accepted_answers'] ?? [])), static fn (string $a): bool => $a !== '');

            if ($keys === []) {
                $problems[] = self::error('This short-answer question has no accepted answer.');
            }
        } elseif (! empty($question['choices'])) {
            $problems[] = self::warning('The choices are ignored: ' . $type . ' questions have none.');
        }

        return $problems;
    }

    /** True when none of the problems is an error. */
    public static function importable(array $problems): bool
    {
        foreach ($problems as $problem) {
            if ($problem['severity'] === self::ERROR) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $question
     *
     * @return list<array{severity: string, message: string}>
     */
    private static function multipleChoice(array $question): array
    {
        $problems = [];
        $texts    = [];
        $correct  = 0;

        foreach ((array) ($question['choices'] ?? []) as $choice) {
            $text = trim((string) ($choice['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $texts[] = mb_strtolower($text);
            $correct += ! empty($choice['is_correct']) ? 1 : 0;
        }

        if (count($texts) < 2) {
            $problems[] = self::error('A multiple-choice question needs at least two choices.');

            return $problems;
        }

        if ($correct === 0) {
            $problems[] = self::error('No correct answer is marked.');
        }

        if ($correct > 1 && empty($question['allow_multiple_answers'])) {
            $problems[] = self::warning('More than one choice is marked correct, so students will be asked to select several.');
        }

        if (count($texts) !== count(array_unique($texts))) {
            $problems[] = self::warning('Two choices have the same text.');
        }

        if (! empty($question['unmatched_correct'])) {
            $problems[] = self::warning('A correct-answer letter in the file matches no choice.');
        }

        return $problems;
    }

    /** @return array{severity: string, message: string} */
    private static function error(string $message): array
    {
        return ['severity' => self::ERROR, 'message' => $message];
    }

    /** @return array{severity: string, message: string} */
    private static function warning(string $message): array
    {
        return ['severity' => self::WARNING, 'message' => $message];
    }
}
