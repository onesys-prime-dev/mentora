<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpWord\IOFactory;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentQuestionModel;

class QuestionImportService
{
    /**
     * Must stay in sync with QuestionExportService::META_PREFIX / the
     * metadata line format it writes.
     */
    private const META_PATTERN =
        '/^\[machine-readable.*?\]\s*id=(\d+|new);\s*type=([^;]+);\s*points=([\d.]+);\s*required=(yes|no);\s*correct=([a-z0-9,]*)(?:;\s*case=(yes|no))?\s*$/iu';

    // A Short Answer's answer key — "Accepted answers: CPU | Central
    // Processing Unit" (see QuestionExportService::ACCEPTED_ANSWERS_LABEL).
    private const ACCEPTED_ANSWERS_PATTERN = '/^Accepted answers?:\s*(.*?)\s*$/iu';

    // Captures the choice letter too — correctness now comes from the
    // metadata line's correct=a,c list, not a per-choice marker in the
    // visible text (see QuestionExportService, which this must stay in
    // sync with).
    private const CHOICE_PATTERN = '/^([a-z0-9])\)\s*(.*?)\s*$/iu';

    private const QUESTION_HEADING_PATTERN = '/^Q\d+\.\s*(.*)$/u';

    protected AssessmentModel $assessmentModel;
    protected AssessmentQuestionModel $questionModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->questionModel = new AssessmentQuestionModel();
    }

    /**
     * Reads a docx and returns either a diff to preview (added/changed/
     * removed against the live question set) or a flag saying this isn't
     * one of this app's own exports, so the caller should route it through
     * AI generation instead. Never writes to the database.
     */
    public function parseDocx(int $assessmentId, string $filePath): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        $lines = $this->extractLines($filePath);

        $blocks = $this->splitIntoBlocks($lines);

        if (empty($blocks)) {

            return [
                'success' => true,
                'needs_ai_fallback' => true,
                'extracted_text' => implode("\n", $lines),
            ];

        }

        $parsedQuestions = array_map(
            fn (array $block) => $this->parseBlock($block),
            $blocks
        );

        return [
            'success' => true,
            'needs_ai_fallback' => false,
            'diff' => $this->buildDiff($assessmentId, $parsedQuestions),
        ];
    }

    /**
     * @param array $changes {
     *     added: array of {question_text, question_type, points, is_required, choices},
     *     changed: array of {id, question_text, question_type, points, is_required, choices},
     *     remove_ids: int[] — only ids explicitly confirmed by the reviewer,
     * }
     */
    public function applyDiff(User $user, int $assessmentId, array $changes): array
    {
        $questionService = new QuestionService();

        $addedCount = 0;
        $changedCount = 0;
        $removedCount = 0;

        foreach (($changes['added'] ?? []) as $item) {

            $result = $questionService->createQuestion($user, $assessmentId, $item);

            if ($result['success']) {
                $addedCount++;
            }

        }

        foreach (($changes['changed'] ?? []) as $item) {

            if (empty($item['id'])) {
                continue;
            }

            $result = $questionService->updateQuestion($user, (int) $item['id'], $item);

            if ($result['success']) {
                $changedCount++;
            }

        }

        foreach (($changes['remove_ids'] ?? []) as $questionId) {

            $result = $questionService->deleteQuestion((int) $questionId);

            if ($result['success']) {
                $removedCount++;
            }

        }

        return [
            'success' => true,
            'message' => "{$addedCount} added, {$changedCount} updated, {$removedCount} removed.",
        ];
    }

    /**
     * Flattened, in-document-order plain-text lines. PHPWord normalizes
     * addText() calls into TextRun elements on Word2007 read-back; every
     * other element type (TextBreak, images, tables) is skipped since our
     * own export never produces them.
     */
    private function extractLines(string $filePath): array
    {
        $reader = IOFactory::createReader('Word2007');

        $phpWord = $reader->load($filePath);

        $lines = [];

        foreach ($phpWord->getSections() as $section) {

            foreach ($section->getElements() as $element) {

                if (!method_exists($element, 'getText')) {
                    continue;
                }

                $text = $element->getText();

                if (!is_string($text)) {
                    continue;
                }

                $lines[] = $text;

            }

        }

        return $lines;
    }

    /**
     * Groups lines into question blocks, each terminated by a metadata
     * line. Lines before the first recognizable block, or after the last
     * one, are treated as a single trailing "new content" block if they
     * contain a question heading — this covers the common case of an
     * instructor typing a brand new question at the end without touching
     * the metadata format. Returns [] if not a single metadata line is
     * found anywhere (caller then falls back to AI generation).
     */
    private function splitIntoBlocks(array $lines): array
    {
        $blocks = [];

        $buffer = [];

        $bufferHasHeading = false;

        $sawAnyMetaLine = false;

        foreach ($lines as $line) {

            if (preg_match(self::META_PATTERN, $line)) {

                $sawAnyMetaLine = true;

                $buffer[] = $line;

                $blocks[] = $buffer;

                $buffer = [];

                $bufferHasHeading = false;

                continue;

            }

            if (trim($line) === '') {
                continue;
            }

            $isHeading = (bool) preg_match(self::QUESTION_HEADING_PATTERN, $line);

            // A second heading arriving before the first one's metadata
            // line means the first question's marker is missing/broken —
            // flush what we have as an orphan block now rather than
            // silently merging it into whichever block closes next
            // (which would overwrite this question's text with the
            // following one's).
            if ($isHeading && $bufferHasHeading) {

                $blocks[] = $buffer;

                $buffer = [];

            }

            if ($isHeading) {
                $bufferHasHeading = true;
            }

            $buffer[] = $line;

        }

        if (!$sawAnyMetaLine) {
            return [];
        }

        // Trailing lines with no metadata line to close them — likely a
        // hand-typed new question. Only keep it if it looks like one.
        if (!empty($buffer)) {

            if ($bufferHasHeading) {
                $blocks[] = $buffer;
            }

        }

        return $blocks;
    }

    private function parseBlock(array $block): array
    {
        $meta = null;

        $contentLines = [];

        foreach ($block as $line) {

            if (preg_match(self::META_PATTERN, $line, $matches)) {

                $meta = [
                    'id' => $matches[1] === 'new' ? null : (int) $matches[1],
                    'type' => trim($matches[2]),
                    'points' => (float) $matches[3],
                    'is_required' => strtolower($matches[4]) === 'yes',
                    'correct_letters' => $matches[5] === ''
                        ? []
                        : array_map('strtolower', explode(',', $matches[5])),
                    'case_sensitive' => strtolower($matches[6] ?? '') === 'yes',
                ];

                continue;

            }

            $contentLines[] = $line;

        }

        $questionText = '';
        $choices = [];
        $acceptedAnswers = [];

        foreach ($contentLines as $index => $line) {

            if (preg_match(self::QUESTION_HEADING_PATTERN, $line, $matches)) {
                $questionText = trim($matches[1]);
                continue;
            }

            if (preg_match(self::ACCEPTED_ANSWERS_PATTERN, $line, $matches)) {
                $acceptedAnswers = ShortAnswerGrader::decode(explode('|', $matches[1]));
                continue;
            }

            if (preg_match(self::CHOICE_PATTERN, $line, $matches)) {

                $letter = strtolower($matches[1]);

                $choices[] = [
                    'text' => trim($matches[2]),
                    'is_correct' => in_array($letter, $meta['correct_letters'] ?? [], true),
                ];

                continue;

            }

            // A content line that's neither a heading nor a choice — most
            // likely the question text on its own line (heading pattern
            // requires a literal "Qn." prefix, which an instructor editing
            // by hand may have removed). Treat the first such line as the
            // question text if we don't have one yet.
            if ($questionText === '' && $index === 0) {
                $questionText = trim($line);
            }

        }

        $questionType = $meta['type'] ?? (empty($choices) ? 'Essay' : 'Multiple Choice');

        $isShortAnswer = $questionType === 'Short Answer';

        return [
            'id' => $meta['id'] ?? null,
            'question_text' => $questionText,
            'question_type' => $questionType,
            'points' => $meta['points'] ?? 0,
            'is_required' => $meta['is_required'] ?? true,
            'choices' => $choices,
            'accepted_answers' => $isShortAnswer ? $acceptedAnswers : [],
            'case_sensitive' => $isShortAnswer && ($meta['case_sensitive'] ?? false),
        ];
    }

    private function buildDiff(int $assessmentId, array $parsedQuestions): array
    {
        $liveQuestions = $this->questionModel->listForAssessment($assessmentId);

        $liveById = [];

        foreach ($liveQuestions as $question) {
            $liveById[(int) $question['id']] = $question;
        }

        $added = [];
        $changed = [];

        $seenIds = [];

        foreach ($parsedQuestions as $parsed) {

            if ($parsed['id'] === null || !isset($liveById[$parsed['id']])) {

                $added[] = [
                    'question_text' => $parsed['question_text'],
                    'question_type' => $parsed['question_type'],
                    'points' => $parsed['points'],
                    'is_required' => $parsed['is_required'],
                    'choices' => $parsed['choices'],
                    'accepted_answers' => $parsed['accepted_answers'],
                    'case_sensitive' => $parsed['case_sensitive'],
                ];

                continue;

            }

            $seenIds[] = $parsed['id'];

            $live = $liveById[$parsed['id']];

            $liveChoices = array_map(
                static fn (array $choice) => [
                    'text' => $choice['choice_text'],
                    'is_correct' => (bool) $choice['is_correct'],
                ],
                $live['choices'] ?? []
            );

            $hasChanged =
                $live['question_text'] !== $parsed['question_text']
                || $live['question_type'] !== $parsed['question_type']
                || (float) $live['points'] !== (float) $parsed['points']
                || (bool) $live['is_required'] !== $parsed['is_required']
                || $liveChoices !== $parsed['choices']
                || ($live['accepted_answers'] ?? []) !== $parsed['accepted_answers']
                || !empty($live['case_sensitive']) !== $parsed['case_sensitive'];

            if ($hasChanged) {

                $changed[] = [
                    'id' => $parsed['id'],
                    'question_text' => $parsed['question_text'],
                    'question_type' => $parsed['question_type'],
                    'points' => $parsed['points'],
                    'is_required' => $parsed['is_required'],
                    'choices' => $parsed['choices'],
                    'accepted_answers' => $parsed['accepted_answers'],
                    'case_sensitive' => $parsed['case_sensitive'],
                    'previous_text' => $live['question_text'],
                ];

            }

        }

        $removed = [];

        foreach ($liveById as $id => $live) {

            if (!in_array($id, $seenIds, true)) {

                $removed[] = [
                    'id' => $id,
                    'question_text' => $live['question_text'],
                ];

            }

        }

        return [
            'added' => $added,
            'changed' => $changed,
            'removed' => $removed,
        ];
    }
}
