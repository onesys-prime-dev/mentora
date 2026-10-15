<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * IMS QTI 1.2 items as exported by Blackboard (pool .dat), D2L, Sakai and
 * WebCT/Vista (and other LMSs that share the standard). Flavour differences
 * are only in the metadata names and the wrapping of text; the question
 * shape is the same: a stem, the response labels, and response conditions
 * that award a score to the right ident. The right answer is the one whose
 * condition sets a score above zero. Pure: no database, no AI.
 */
final class QtiParser
{
    public static function looks(string $xml): bool
    {
        return stripos(substr($xml, 0, 6000), 'questestinterop') !== false;
    }

    /**
     * Items in document order. `skipped` lists those that could not be used.
     *
     * @return array{questions: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>}|null null when there is no QTI here
     */
    public function parse(string $xml, int $firstLine = 0): ?array
    {
        $doc = XmlText::load($xml);

        if ($doc === null || $doc->documentElement === null || $doc->documentElement->localName !== 'questestinterop') {
            return null;
        }

        $xp = new DOMXPath($doc);
        $items = $xp->query("//*[local-name()='item']");

        $questions = [];
        $skipped = [];
        $line = $firstLine;

        foreach ($items === false ? [] : $items as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $line++;
            $one = $this->item($xp, $item, $line);

            if (isset($one['skip'])) {
                $skipped[] = ['line' => $line, 'reason' => $one['skip']];
            } else {
                $questions[] = $one;
            }
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /** @return array<string, mixed> a ParsedQuestion, or ['skip' => reason] */
    private function item(DOMXPath $xp, DOMElement $item, int $line): array
    {
        $title = $item->getAttribute('title');
        $meta = $this->metadata($xp, $item);
        $kind = strtolower(implode(' ', [
            $meta['bbmd_questiontype'] ?? '', $meta['qmd_questiontype'] ?? '', $meta['qmd_itemtype'] ?? '', $meta['wct_questiontype'] ?? '',
        ]));

        $points = (float) ($meta['qmd_weighting'] ?? $meta['qmd_absolutescore_max'] ?? 1);
        $points = $points > 0 ? $points : 1.0;

        $stem = $this->stem($xp, $item);

        if ($stem === '') {
            return ['skip' => 'Question "' . $title . '" has no text.'];
        }

        $labels = $xp->query(".//*[local-name()='response_lid']//*[local-name()='response_label']", $item);
        $cardinality = strtolower((string) $xp->evaluate("string(.//*[local-name()='response_lid']/@rcardinality)", $item));

        // Choice questions.
        if ($labels !== false && $labels->length >= 2) {
            return $this->choices($xp, $item, $line, $stem, $labels, $kind, $cardinality, $points);
        }

        // Open questions: essay, paragraph, short response, fill in the blank.
        if (preg_match('/essay|paragraph|long|extended/', $kind) === 1) {
            return ParsedQuestion::open($line, $stem, 'Essay', $points);
        }

        if (preg_match('/short|fill|blank|text|string|numeric|calculated/', $kind) === 1 || $xp->query(".//*[local-name()='response_str']", $item)->length > 0) {
            $accepted = $this->positiveIdents($xp, $item, true);

            return ParsedQuestion::shortAnswer($line, $stem, $accepted, $points);
        }

        if (preg_match('/true.?false|boolean/', $kind) === 1) {
            return ['skip' => 'True/False question "' . $title . '" has no choices to read.'];
        }

        return ['skip' => 'Question "' . $title . '" is a kind this importer does not read (' . trim($kind) . ').'];
    }

    /**
     * @param \DOMNodeList<DOMNode> $labels
     *
     * @return array<string, mixed>
     */
    private function choices(DOMXPath $xp, DOMElement $item, int $line, string $stem, \DOMNodeList $labels, string $kind, string $cardinality, float $points): array
    {
        $right = $this->positiveIdents($xp, $item, false);
        $choices = [];

        foreach ($labels as $label) {
            if (! $label instanceof DOMElement) {
                continue;
            }

            $text = $this->texts($xp, $label);

            // Some exports (Sakai) always write eight slots; the unused ones are empty.
            if ($text === '') {
                continue;
            }

            $choices[] = ['text' => $text, 'is_correct' => in_array($label->getAttribute('ident'), $right, true)];
        }

        $notes = [];

        if (array_filter($choices, static fn (array $c): bool => $c['is_correct']) === []) {
            $notes[] = 'No correct answer could be read from this question: choose it.';
        }

        // A true/false question exported as two labelled choices keeps its labels (True, False).
        $q = ParsedQuestion::choice($line, $stem, $choices, $points, $notes);

        if ($cardinality === 'multiple' || preg_match('/multiple (answer|response)/', $kind) === 1) {
            $q['allow_multiple_answers'] = count(array_filter($choices, static fn (array $c): bool => $c['is_correct'])) > 1;
        }

        return $q;
    }

    /**
     * The idents (or, for open answers, the values) a response condition
     * rewards: its varequal, where the same condition sets a score above zero.
     *
     * @return list<string>
     */
    private function positiveIdents(DOMXPath $xp, DOMElement $item, bool $values): array
    {
        $out = [];
        $conditions = $xp->query(".//*[local-name()='respcondition']", $item);

        foreach ($conditions === false ? [] : $conditions as $condition) {
            if (! $condition instanceof DOMElement || ! $this->awards($xp, $condition)) {
                continue;
            }

            $title = strtolower($condition->getAttribute('title'));

            if ($title === 'incorrect') {
                continue;
            }

            // Only equalities that are not negated.
            foreach ($xp->query(".//*[local-name()='varequal'][not(ancestor::*[local-name()='not'])]", $condition) ?: [] as $eq) {
                $text = trim($eq->textContent);

                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether the condition sets a score above zero ("1", "100", "SCORE.max" ...). */
    private function awards(DOMXPath $xp, DOMElement $condition): bool
    {
        $sets = $xp->query(".//*[local-name()='setvar']", $condition);

        foreach ($sets === false ? [] : $sets as $set) {
            $value = trim($set->textContent);
            $action = strtolower(($set instanceof DOMElement) ? $set->getAttribute('action') : '');

            if (stripos($value, 'max') !== false) {
                return true;
            }

            if (is_numeric($value) && (float) $value > 0 && $action !== 'subtract') {
                return true;
            }
        }

        return false;
    }

    /** The question's own text: the first material before the response block. */
    private function stem(DOMXPath $xp, DOMElement $item): string
    {
        $nodes = $xp->query(".//*[local-name()='presentation']//*[local-name()='mattext' or local-name()='mat_formattedtext'][not(ancestor::*[local-name()='response_lid' or local-name()='response_str' or local-name()='response_label' or local-name()='render_choice'])]", $item);
        $parts = [];

        foreach ($nodes === false ? [] : $nodes as $node) {
            $text = XmlText::plain($node->textContent);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode("\n", $parts));
    }

    private function texts(DOMXPath $xp, DOMElement $label): string
    {
        $parts = [];

        foreach ($xp->query(".//*[local-name()='mattext' or local-name()='mat_formattedtext']", $label) ?: [] as $node) {
            $text = XmlText::plain($node->textContent);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Flat map of the item's metadata fields (both qtimetadatafield and
     * Blackboard's bbmd and qmd elements).
     *
     * @return array<string, string>
     */
    private function metadata(DOMXPath $xp, DOMElement $item): array
    {
        $out = [];

        foreach ($xp->query(".//*[local-name()='itemmetadata']/*", $item) ?: [] as $node) {
            if ($node instanceof DOMElement && $node->childElementCount === 0) {
                $out[strtolower($node->localName)] = trim($node->textContent);
            }
        }

        foreach ($xp->query(".//*[local-name()='qtimetadatafield' or local-name()='qti_metadatafield']", $item) ?: [] as $field) {
            $label = strtolower(trim((string) $xp->evaluate("string(*[local-name()='fieldlabel'])", $field)));
            $entry = trim((string) $xp->evaluate("string(*[local-name()='fieldentry'])", $field));

            if ($label !== '') {
                $out[$label] = $entry;
            }
        }

        return $out;
    }
}
