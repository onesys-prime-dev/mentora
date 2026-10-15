<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * The one shape every reader hands to the import preview, whatever the file
 * was: the same keys PlainQuestionParser produces.
 */
final class ParsedQuestion
{
    /**
     * @param list<array{text: string, is_correct: bool}> $choices
     * @param list<string>                                $notes
     *
     * @return array<string, mixed>
     */
    public static function choice(int $line, string $text, array $choices, float $points = 1.0, array $notes = []): array
    {
        $correct = count(array_filter($choices, static fn (array $c): bool => $c['is_correct']));
        $multi   = $correct > 1;

        return [
            'line'                   => $line,
            'question_text'          => $text,
            'question_type'          => 'Multiple Choice',
            'points'                 => $points,
            'choices'                => $choices,
            'accepted_answers'       => [],
            'allow_multiple_answers' => $multi,
            'answers_count'          => $multi ? $correct : null,
            'notes'                  => $notes,
        ];
    }

    /**
     * @param list<string> $accepted
     * @param list<string> $notes
     *
     * @return array<string, mixed>
     */
    public static function shortAnswer(int $line, string $text, array $accepted, float $points = 1.0, array $notes = []): array
    {
        return [
            'line'                   => $line,
            'question_text'          => $text,
            'question_type'          => 'Short Answer',
            'points'                 => $points,
            'choices'                => [],
            'accepted_answers'       => $accepted,
            'allow_multiple_answers' => false,
            'answers_count'          => null,
            'notes'                  => $notes,
        ];
    }

    /** @return array<string, mixed> */
    public static function open(int $line, string $text, string $type = 'Essay', float $points = 1.0): array
    {
        return [
            'line'                   => $line,
            'question_text'          => $text,
            'question_type'          => $type,
            'points'                 => $points,
            'choices'                => [],
            'accepted_answers'       => [],
            'allow_multiple_answers' => false,
            'answers_count'          => null,
            'notes'                  => [],
        ];
    }

    /** @return list<array{text: string, is_correct: bool}> */
    public static function trueFalse(bool $answer): array
    {
        return [
            ['text' => 'True', 'is_correct' => $answer],
            ['text' => 'False', 'is_correct' => ! $answer],
        ];
    }
}
