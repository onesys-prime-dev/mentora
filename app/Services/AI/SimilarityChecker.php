<?php

namespace App\Services\AI;

/**
 * Sliding-window n-gram overlap between a generated question (+ its
 * choices) and reference source material — flags near-verbatim lifts
 * without diluting the score across an entire multi-page document, which
 * a naive whole-document comparison would do.
 */
class SimilarityChecker
{
    public function score(string $questionText, array $choiceTexts, ?string $sourceText): ?float
    {
        if ($sourceText === null || trim($sourceText) === '') {
            return null;
        }

        $combined = trim($questionText . ' ' . implode(' ', $choiceTexts));

        $textTokens = $this->tokenize($combined);

        if (empty($textTokens)) {
            return 0.0;
        }

        $sourceTokens = $this->tokenize($sourceText);

        $windowSize = count($textTokens);

        if (count($sourceTokens) <= $windowSize) {
            return $this->jaccard($textTokens, $sourceTokens);
        }

        $best = 0.0;

        // Slide by half the window width — a full step-of-1 scan is
        // overkill for a similarity *flag* (not an exact match finder) and
        // would be needlessly slow over a multi-page source document.
        $step = max(1, (int) ($windowSize / 2));

        $lastStart = count($sourceTokens) - $windowSize;

        for ($i = 0; $i <= $lastStart; $i += $step) {

            $window = array_slice($sourceTokens, $i, $windowSize);

            $score = $this->jaccard($textTokens, $window);

            if ($score > $best) {
                $best = $score;
            }

        }

        return $best;
    }

    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);

        preg_match_all('/[a-z0-9]+/u', $text, $matches);

        return $matches[0];
    }

    private function jaccard(array $a, array $b): float
    {
        $setA = array_unique($a);
        $setB = array_unique($b);

        $intersection = count(array_intersect($setA, $setB));

        $union = count(array_unique(array_merge($setA, $setB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
