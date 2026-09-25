<?php

namespace App\Services\AI;

interface QuestionGeneratorClientInterface
{
    /**
     * Generate up to one chunk of questions (the implementation decides the
     * per-call cap; callers loop to reach a larger total).
     *
     * @param array $params {
     *     topic?: string,
     *     source_text?: string,
     *     count: int,
     *     type_mix?: array<string,int>,
     *     points_per_question?: float,
     *     recent_questions?: string[],
     * }
     *
     * @return array{questions: array, model_used: string}
     *
     * @throws QuestionGeneratorBusyException
     * @throws QuestionGeneratorUnavailableException
     */
    public function generate(array $params): array;

    /**
     * Extract plain text from an uploaded PDF or image (OCR fallback for
     * scanned pages, handled by the implementation).
     *
     * @return array{text: string, used_ocr: bool, pages: int}
     */
    public function extractText(string $filePath, string $mimeType): array;

    public function isAvailable(): bool;
}
