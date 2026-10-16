<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use DOMElement;

/**
 * Moodle XML: multiple choice (single or several right), true/false, short
 * answer and essay. Other kinds (matching, numerical, cloze, calculated) are
 * listed as not imported, with the question's name, so nothing is dropped
 * silently. Pure: no database, no AI.
 */
final class MoodleXmlParser
{
    public static function looks(string $xml): bool
    {
        return preg_match('/<quiz[\s>]/', substr($xml, 0, 4000)) === 1 && str_contains($xml, '<question');
    }

    /**
     * @return array{questions: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>}|null null when the file is not Moodle XML
     */
    public function parse(string $xml): ?array
    {
        $doc = XmlText::load($xml);

        if ($doc === null || $doc->documentElement === null || $doc->documentElement->localName !== 'quiz') {
            return null;
        }

        $questions = [];
        $skipped = [];
        $line = 0;

        foreach ($doc->documentElement->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->localName !== 'question') {
                continue;
            }

            $type = $node->getAttribute('type');

            // Categories and descriptions are structure, not questions.
            if (in_array($type, ['category', 'description'], true)) {
                continue;
            }

            $line++;
            $name = $this->text($node, 'name');
            $stem = XmlText::plain($this->text($node, 'questiontext'));
            $points = (float) ($this->raw($node, 'defaultgrade') ?: 1);
            $points = $points > 0 ? $points : 1.0;

            if ($stem === '') {
                $skipped[] = ['line' => $line, 'reason' => 'Question "' . $name . '" has no text.'];

                continue;
            }

            $answers = $this->answers($node);

            switch ($type) {
                case 'multichoice':
                    $questions[] = $this->multichoice($line, $stem, $node, $answers, $points);

                    break;

                case 'truefalse':
                    $true = false;

                    foreach ($answers as $a) {
                        if ($a['fraction'] > 0 && strcasecmp(trim($a['text']), 'true') === 0) {
                            $true = true;
                        }
                    }

                    $questions[] = ParsedQuestion::choice($line, $stem, ParsedQuestion::trueFalse($true), $points);

                    break;

                case 'shortanswer':
                    $accepted = array_values(array_filter(
                        array_map(static fn (array $a): string => $a['text'], array_filter($answers, static fn (array $a): bool => $a['fraction'] > 0)),
                        static fn (string $t): bool => $t !== '' && $t !== '*'
                    ));
                    $q = ParsedQuestion::shortAnswer($line, $stem, $accepted, $points);
                    $q['case_sensitive'] = $this->raw($node, 'usecase') === '1';
                    $questions[] = $q;

                    break;

                case 'essay':
                    $questions[] = ParsedQuestion::open($line, $stem, 'Essay', $points);

                    break;

                default:
                    $skipped[] = ['line' => $line, 'reason' => 'Question "' . $name . '" is a ' . $type . ' question, which is not supported.'];
            }
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /**
     * @param list<array{text: string, fraction: float}> $answers
     *
     * @return array<string, mixed>
     */
    private function multichoice(int $line, string $stem, DOMElement $node, array $answers, float $points): array
    {
        $choices = [];

        foreach ($answers as $a) {
            if ($a['text'] !== '') {
                $choices[] = ['text' => $a['text'], 'is_correct' => $a['fraction'] > 0];
            }
        }

        $notes = [];
        $correct = count(array_filter($choices, static fn (array $c): bool => $c['is_correct']));

        // Moodle can mark several right with partial credit; one marked "single" with several positives is the author's slip.
        if ($this->raw($node, 'single') === 'true' && $correct > 1) {
            $best = max(array_column($answers, 'fraction'));
            $choices = array_map(static fn (array $c, array $a): array => ['text' => $c['text'], 'is_correct' => $a['fraction'] >= $best], $choices, array_values(array_filter($answers, static fn (array $a): bool => $a['text'] !== '')));
            $notes[] = 'Several choices had credit; only the highest-scoring is marked correct.';
        }

        return ParsedQuestion::choice($line, $stem, $choices, $points, $notes);
    }

    /** @return list<array{text: string, fraction: float}> */
    private function answers(DOMElement $node): array
    {
        $out = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'answer') {
                $out[] = [
                    'text' => XmlText::plain($this->text($child, null)),
                    'fraction' => (float) $child->getAttribute('fraction'),
                ];
            }
        }

        return $out;
    }

    private function text(DOMElement $node, ?string $child): string
    {
        if ($child === null) {
            foreach ($node->childNodes as $c) {
                if ($c instanceof DOMElement && $c->localName === 'text') {
                    return $c->textContent;
                }
            }

            return '';
        }

        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $child) {
                foreach ($c->childNodes as $t) {
                    if ($t instanceof DOMElement && $t->localName === 'text') {
                        return trim($t->textContent);
                    }
                }

                return trim($c->textContent);
            }
        }

        return '';
    }

    private function raw(DOMElement $node, string $child): string
    {
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $child) {
                return trim($c->textContent);
            }
        }

        return '';
    }
}
