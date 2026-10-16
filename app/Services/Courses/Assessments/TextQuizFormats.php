<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * A quiz written out as Aiken or GIFT, the two plain-text formats other quiz
 * systems read. Aiken carries single-answer multiple choice only; GIFT also
 * carries several right answers, true/false, short answer and essay. A
 * question a format cannot hold is left out.
 */
final class TextQuizFormats
{
    /**
     * @param list<array<string, mixed>> $questions as listed for the assessment (with choices)
     */
    public static function aiken(array $questions): string
    {
        $out = [];

        foreach ($questions as $question) {
            $choices = array_values($question['choices'] ?? []);
            $right   = [];

            foreach ($choices as $i => $choice) {
                if (! empty($choice['is_correct'])) {
                    $right[] = chr(ord('A') + $i);
                }
            }

            if (($question['question_type'] ?? '') !== 'Multiple Choice' || count($right) !== 1 || count($choices) < 2 || count($choices) > 8) {
                continue;
            }

            $lines = [self::oneLine((string) $question['question_text'])];

            foreach ($choices as $i => $choice) {
                $lines[] = chr(ord('A') + $i) . '. ' . self::oneLine((string) $choice['choice_text']);
            }

            $lines[] = 'ANSWER: ' . $right[0];
            $out[]   = implode("\n", $lines);
        }

        return implode("\n\n", $out) . ($out === [] ? '' : "\n");
    }

    /**
     * @param list<array<string, mixed>> $questions
     */
    public static function gift(array $questions): string
    {
        $out = [];

        foreach ($questions as $i => $question) {
            $type   = (string) ($question['question_type'] ?? '');
            $text   = self::escape(self::oneLine((string) $question['question_text']));
            $title  = '::Q' . ($i + 1) . '::';
            $choices = array_values($question['choices'] ?? []);

            if ($type === 'Multiple Choice' && $choices !== []) {
                $right = array_values(array_filter($choices, static fn (array $c): bool => ! empty($c['is_correct'])));
                $texts = array_map(static fn (array $c): string => strtolower(trim((string) $c['choice_text'])), $choices);

                if (count($choices) === 2 && count($right) === 1 && $texts === ['true', 'false']) {
                    $out[] = $title . $text . ' {' . (strtolower(trim((string) $right[0]['choice_text'])) === 'true' ? 'TRUE' : 'FALSE') . '}';

                    continue;
                }

                if ($right === []) {
                    continue;
                }

                $lines = [];
                $share = count($right) > 1 ? round(100 / count($right), 5) : 100;

                foreach ($choices as $choice) {
                    $label = self::escape(self::oneLine((string) $choice['choice_text']));
                    $isRight = ! empty($choice['is_correct']);

                    if (count($right) === 1) {
                        $lines[] = ($isRight ? '=' : '~') . $label;
                    } else {
                        $lines[] = '~%' . ($isRight ? $share : '-100') . '%' . $label;
                    }
                }

                $out[] = $title . $text . " {\n" . implode("\n", $lines) . "\n}";

                continue;
            }

            if ($type === 'Short Answer') {
                $answers = array_values(array_filter(array_map('strval', $question['accepted_answers'] ?? []), static fn (string $a): bool => trim($a) !== ''));

                if ($answers === []) {
                    continue;
                }

                $out[] = $title . $text . ' {' . implode(' ', array_map(static fn (string $a): string => '=' . self::escape(self::oneLine($a)), $answers)) . '}';

                continue;
            }

            if ($type === 'Essay') {
                $out[] = $title . $text . ' {}';
            }
        }

        return implode("\n\n", $out) . ($out === [] ? '' : "\n");
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([~=#{}:\\\\])/u', '\\\\$1', $text);
    }
}
