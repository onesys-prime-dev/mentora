<?php

namespace App\Services\Courses\Assessments;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Services\Audit\AuditLogger;
use App\Services\Concerns\InTransaction;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Throwable;

/**
 * Reading a quiz file back in.
 *
 * A file is read into a preview (what would be added, changed or removed, and
 * what is wrong with each question), held on the server under a token, and
 * applied only from that stored preview: the browser sends back the token and
 * the teacher's choices, never the questions. Applying is one transaction and
 * can be undone for a few minutes.
 */
class QuestionImportService
{
    use InTransaction;

    /** Largest file read (bytes) and most questions taken from one file. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_QUESTIONS = 300;

    /** Documents, read by their layout or by Mentora's own markers. */
    public const DOCUMENT_EXTENSIONS = ['docx', 'rtf'];

    /** Spreadsheets and plain-text formats from other systems (Excel/CSV, Aiken, GIFT). */
    public const FORMAT_EXTENSIONS = ['csv', 'xlsx', 'txt', 'pdf', 'html', 'htm'];

    public const EXTENSIONS = ['docx', 'rtf', 'csv', 'xlsx', 'txt', 'pdf', 'html', 'htm'];

    // A Short Answer's answer key: "Accepted answers: CPU | Central
    // Processing Unit" (see QuestionExportService::ACCEPTED_ANSWERS_LABEL).
    private const ACCEPTED_ANSWERS_PATTERN = '/^Accepted answers?:\s*(.*?)\s*$/iu';

    // Captures the choice letter too: correctness comes from the metadata
    // line's correct=a,c list, not a per-choice marker in the visible text.
    private const CHOICE_PATTERN = '/^([a-z0-9])\)\s*(.*?)\s*$/iu';

    private const QUESTION_HEADING_PATTERN = '/^Q\d+\.\s*(.*)$/u';

    /** Words in common, as a share of the smaller question, that make two questions "the same". */
    private const DUPLICATE_OVERLAP = 0.85;

    protected AssessmentModel $assessmentModel;
    protected AssessmentQuestionModel $questionModel;
    private ImportPreviewStore $store;

    public function __construct(?ImportPreviewStore $store = null)
    {
        $this->assessmentModel = new AssessmentModel();
        $this->questionModel = new AssessmentQuestionModel();
        $this->store = $store ?? new ImportPreviewStore();
    }

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    */

    /**
     * Reads a Word file into a preview. Never writes to the database.
     */
    public function parseDocx(int $assessmentId, string $filePath): array
    {
        return $this->parseFile($assessmentId, $filePath, 'docx');
    }

    /**
     * Reads a .docx or .rtf, holds the result on the server and returns it
     * with a token to apply or undo it by.
     */
    public function previewFile(User $user, int $assessmentId, string $filePath, string $extension): array
    {
        if (in_array(strtolower($extension), self::FORMAT_EXTENSIONS, true)) {
            return $this->hold($user, $assessmentId, $this->parseFormat($assessmentId, $filePath, strtolower($extension)));
        }

        return $this->hold($user, $assessmentId, $this->parseFile($assessmentId, $filePath, $extension));
    }

    /**
     * A spreadsheet (Excel or CSV), an Aiken file or a GIFT file. Like an
     * ordinary document it only adds questions.
     *
     * @return array<string, mixed>
     */
    /** The text of a PDF with its page markers removed, or null when it has no text layer. */
    private function pdfText(string $filePath): ?string
    {
        try {
            $read = (new \App\Services\AI\PdfTextExtractor())->extract($filePath);
        } catch (Throwable) {
            return null;
        }

        return trim((string) preg_replace('/^\[\[page \d+\]\]$/m', '', $read['text']));
    }

    /** The visible text of an HTML export: cells, paragraphs and line breaks become lines. */
    private function htmlText(string $filePath): string
    {
        $html = (string) file_get_contents($filePath);

        // TestGen writes ISO-8859-1; say so unless the file says otherwise.
        $encoding = preg_match('/charset=([\w-]+)/i', $html, $m) === 1 ? strtoupper($m[1]) : 'ISO-8859-1';
        $html = (string) mb_convert_encoding($html, 'UTF-8', $encoding);
        $html = (string) preg_replace(['~<(script|style)\b.*?</\1>~is', '~</(td|th|tr|p|div|li|h\d)>|<br\s*/?>~i'], ['', "\n"], $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text);
    }

    private function parseFormat(int $assessmentId, string $filePath, string $extension): array
    {
        if (! $this->assessmentModel->find($assessmentId)) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        try {
            if (in_array($extension, ['pdf', 'html', 'htm'], true)) {
                $text = $extension === 'pdf' ? $this->pdfText($filePath) : $this->htmlText($filePath);

                if ($text === null) {
                    return ['success' => false, 'message' => 'This PDF has no selectable text (it looks like a scan), so it cannot be read here. Export the exam as Word, text or Moodle XML instead.'];
                }

                $read = (new PrintedExamParser())->parse($text);

                if ($read['questions'] === []) {
                    return ['success' => false, 'message' => 'No questions were found. A printed exam has numbered questions ("1)") with lettered choices ("A)") and an answer key at the end.'];
                }
            } elseif ($extension === 'txt') {
                $text = (string) file_get_contents($filePath);

                if (PrintedExamParser::looks($text)) {
                    $read = (new PrintedExamParser())->parse($text);
                } elseif (GiftParser::looks($text)) {
                    $read = (new GiftParser())->parse($text);
                } elseif (AikenParser::looks($text)) {
                    $read = (new AikenParser())->parse($text);
                } else {
                    return ['success' => false, 'message' => 'This text file is not in Aiken or GIFT format. Aiken questions end with an "ANSWER: B" line; GIFT answers sit inside { }.'];
                }
            } else {
                $read = (new SpreadsheetQuestions())->parse(SpreadsheetQuestions::rowsOf($filePath, $extension));

                if ($read === null) {
                    return ['success' => false, 'message' => 'No header row was found. The first row needs a "Question" column (and Type, A to H, Answer, Points). Download the template to start from.'];
                }
            }
        } catch (Throwable) {
            return ['success' => false, 'message' => 'This file could not be read. Check that it is not damaged.'];
        }

        return $this->finishFormat($assessmentId, $read);
    }

    /**
     * The preview of questions read from another system's file. Like an
     * ordinary document it only adds questions.
     *
     * @param array{questions: list<array<string, mixed>>, skipped: list<array{line: int, reason: string, severity?: string}>} $read
     *
     * @return array<string, mixed>
     */
    private function finishFormat(int $assessmentId, array $read): array
    {
        if (count($read['questions']) + count($read['skipped']) > self::MAX_QUESTIONS) {
            return ['success' => false, 'message' => 'This file has more than ' . self::MAX_QUESTIONS . ' questions. Import it in smaller parts.'];
        }

        if ($read['questions'] === []) {
            $why = $read['skipped'] !== [] ? ' ' . $read['skipped'][0]['reason'] : '';

            return ['success' => false, 'message' => 'No questions could be imported from this file.' . $why];
        }

        $parsed = array_map(static fn (array $q): array => $q + [
            'id'                => null,
            'is_required'       => true,
            'case_sensitive'    => false,
            'unmatched_correct' => false,
        ], $read['questions']);

        $result = $this->buildDiff($assessmentId, $parsed, false);

        // Rows the reader could not turn into a question are listed, with their line.
        foreach ($read['skipped'] as $row) {
            $severity = ($row['severity'] ?? '') === 'warning' ? QuestionImportValidator::WARNING : QuestionImportValidator::ERROR;

            $result['problems'][] = [
                'severity'      => $severity,
                'message'       => $row['reason'],
                'line'          => $row['line'],
                'question_text' => '',
            ];

            if ($severity === QuestionImportValidator::ERROR) {
                $result['counts']['problems']++;
            }
        }

        $result['success']             = true;
        $result['source']              = 'plain';
        $result['responses_affected']  = 0;

        return $result;
    }

    /**
     * The same preview from text the teacher pasted instead of a file.
     */
    public function previewText(User $user, int $assessmentId, string $text): array
    {
        $paragraphs = array_map(
            static fn (string $line): array => ['text' => $line, 'bold' => false],
            preg_split('/\R/u', $text) ?: []
        );

        return $this->hold($user, $assessmentId, $this->parseParagraphs($assessmentId, $paragraphs));
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function hold(User $user, int $assessmentId, array $result): array
    {
        if (! $result['success']) {
            return $result;
        }

        $token = $this->store->put((int) $user->id, $assessmentId, [
            'diff'    => $result['diff'],
            'order'   => $result['order'],
            'removed' => array_column($result['diff']['removed'], 'id'),
            'source'  => $result['source'],
        ]);

        return $result + ['token' => $token];
    }

    /**
     * @return array{success: bool, message?: string, diff?: array, order?: list<string>, problems?: list<array<string, mixed>>, counts?: array<string, int>, responses_affected?: int}
     */
    private function parseFile(int $assessmentId, string $filePath, string $extension): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        try {
            $paragraphs = $this->extractParagraphs($filePath, strtolower($extension));
        } catch (Throwable) {
            return ['success' => false, 'message' => 'This file could not be read. Check that it is a Word (.docx) or Rich Text (.rtf) file and not damaged.'];
        }

        return $this->parseParagraphs($assessmentId, $paragraphs);
    }

    /**
     * A document's paragraphs become questions in one of two ways: a file
     * exported from Mentora (machine-readable lines, which can update and
     * remove questions of the same quiz), or an ordinary exam layout (numbered
     * questions, lettered choices), which can only add.
     *
     * @param list<array{text: string, bold: bool}> $paragraphs
     *
     * @return array<string, mixed>
     */
    private function parseParagraphs(int $assessmentId, array $paragraphs): array
    {
        if (! $this->assessmentModel->find($assessmentId)) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        $lines = array_column($paragraphs, 'text');
        $blocks = $this->splitIntoBlocks($lines);

        if (empty($blocks)) {
            return $this->parsePlain($assessmentId, $paragraphs);
        }

        if (count($blocks) > self::MAX_QUESTIONS) {
            return ['success' => false, 'message' => 'This file has more than ' . self::MAX_QUESTIONS . ' questions. Import it in smaller parts.'];
        }

        $parsed = array_map(fn (array $block) => $this->parseBlock($block), $blocks);

        $result = $this->buildDiff($assessmentId, $parsed);

        $result['success'] = true;
        $result['source'] = 'mentora';
        $result['responses_affected'] = $this->responsesOn(array_merge(
            array_column($result['diff']['changed'], 'id'),
            array_column($result['diff']['removed'], 'id')
        ));

        return $result;
    }

    /**
     * @param list<array{text: string, bold: bool}> $paragraphs
     *
     * @return array<string, mixed>
     */
    private function parsePlain(int $assessmentId, array $paragraphs): array
    {
        // An exam printed from a test-bank tool has its choices inline: read it as one stream.
        $stream = implode("\n", array_column($paragraphs, 'text'));

        if (PrintedExamParser::looks($stream)) {
            $printed = (new PrintedExamParser())->parse($stream);

            if ($printed['questions'] !== []) {
                return $this->finishFormat($assessmentId, $printed);
            }
        }

        $questions = (new PlainQuestionParser())->parse($paragraphs);

        if ($questions === []) {
            return [
                'success' => false,
                'message' => 'No questions were found. A question should start with a number ("1." or "Q1:") and its '
                    . 'choices with letters ("a)" or "A."), with the correct one given as "Answer: b" or marked with *. '
                    . 'Download the sample to see the layout.',
            ];
        }

        if (count($questions) > self::MAX_QUESTIONS) {
            return ['success' => false, 'message' => 'This file has more than ' . self::MAX_QUESTIONS . ' questions. Import it in smaller parts.'];
        }

        $parsed = array_map(static fn (array $q): array => $q + [
            'id'                => null,
            'is_required'       => true,
            'case_sensitive'    => false,
            'unmatched_correct' => false,
        ], $questions);

        // Importing an ordinary document only ever adds: what is already in
        // the quiz and not in the document is not "missing".
        $result = $this->buildDiff($assessmentId, $parsed, false);

        $result['success'] = true;
        $result['source'] = 'plain';
        $result['responses_affected'] = 0;

        return $result;
    }

    /**
     * A small exam in the layout the plain reader understands, to download as
     * a starting point.
     */
    public function sampleDocument(): string
    {
        $word = new PhpWord();
        $section = $word->addSection();

        $section->addText('Sample quiz', ['bold' => true, 'size' => 14]);
        $section->addText('Type or paste your questions like this, then import the file.');
        $section->addTextBreak();

        $lines = [
            '1. Which layer of the network stack routes packets between networks? (2 pts)',
            'a) Transport',
            'b) Network',
            'c) Data link',
            'd) Session',
            'Answer: b',
            '',
            '2. Select all the protocols that run at the transport layer.',
            'a) TCP',
            'b) IP',
            'c) UDP',
            'Answer: a, c',
            '',
            '3. True or false: a router forwards packets using their destination address.',
            'Answer: True',
            '',
            '4. What does CPU stand for?',
            'Answer: Central Processing Unit',
            '',
            '5. Explain why a network is divided into layers.',
            '',
            'Answer Key (optional; use it instead of "Answer:" lines)',
        ];

        foreach ($lines as $line) {
            $section->addText($line);
        }

        $path = tempnam(sys_get_temp_dir(), 'quiz-sample-');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /*
    |--------------------------------------------------------------------------
    | Apply and undo
    |--------------------------------------------------------------------------
    */

    /**
     * Applies a stored preview. All or nothing.
     *
     * @param list<string> $skipKeys  preview items the teacher unticked
     * @param list<int>    $removeIds missing questions the teacher chose to delete
     * @param array<string, array<string, mixed>> $overrides by preview key: what the teacher corrected in the
     *                                                       review (question_type, points, correct choice numbers)
     *
     * @return array<string, mixed>
     */
    public function applyPreview(User $user, int $assessmentId, string $token, array $skipKeys = [], array $removeIds = [], bool $acknowledgeResponses = false, array $overrides = []): array
    {
        $entry = $this->store->get($token, (int) $user->id, $assessmentId);

        if ($entry === null || $entry['applied_at'] !== null) {
            return ['success' => false, 'message' => 'This import has expired. Choose the file again.'];
        }

        $diff    = $entry['payload']['diff'];
        $skip    = array_flip(array_map('strval', $skipKeys));
        $removal = array_values(array_intersect(array_map('intval', $removeIds), array_map('intval', $entry['payload']['removed'])));

        $touched = [];

        foreach ($diff['changed'] as $item) {
            if (! isset($skip[$item['key']]) && $item['importable']) {
                $touched[] = (int) $item['id'];
            }
        }

        $responses = $this->responsesOn(array_merge($touched, $removal));

        if ($responses > 0 && ! $acknowledgeResponses) {
            return [
                'success'            => false,
                'needs_confirmation' => true,
                'responses_affected' => $responses,
                'message'            => "Students have already answered {$responses} of the questions this import changes or removes. Their scores may change.",
            ];
        }

        $before = $this->questionModel->listForAssessment($assessmentId);

        try {
            $outcome = $this->inTransaction(fn (): array => $this->applyItems($user, $assessmentId, $diff, $skip, $removal, $entry['payload']['order'], $before, $overrides, (string) ($entry['payload']['source'] ?? 'mentora')));
        } catch (Throwable $e) {
            log_message('error', 'Question import failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Nothing was changed: the import could not be completed. Please try again.'];
        }

        $entry['applied_at'] = time();
        $entry['payload']['undo'] = $outcome['undo'];
        $this->store->save($token, $entry);

        $counts = $outcome['counts'];

        try {
            (new AuditLogger())->log('quiz.import.applied', $user, ['target_type' => 'assessment', 'target_id' => $assessmentId, 'new' => $counts]);
        } catch (Throwable $e) {
            log_message('error', 'Import audit entry failed: ' . $e->getMessage());
        }

        return [
            'success'    => true,
            'message'    => "{$counts['added']} added, {$counts['changed']} updated, {$counts['removed']} removed"
                . ($counts['skipped'] > 0 ? ", {$counts['skipped']} skipped." : '.'),
            'counts'     => $counts,
            'skipped'    => $outcome['skipped'],
            'undo_token' => $token,
            'undo_until' => $entry['applied_at'] + ImportPreviewStore::UNDO_SECONDS,
        ];
    }

    /**
     * Puts back what an applied import changed, while the undo window is open.
     *
     * @return array<string, mixed>
     */
    public function undoImport(User $user, int $assessmentId, string $token): array
    {
        $entry = $this->store->get($token, (int) $user->id, $assessmentId);

        if ($entry === null || $entry['applied_at'] === null || empty($entry['payload']['undo'])) {
            return ['success' => false, 'message' => 'This import can no longer be undone.'];
        }

        $undo = $entry['payload']['undo'];

        try {
            $this->inTransaction(function () use ($user, $assessmentId, $undo): void {
                $this->restore($user, $assessmentId, $undo);
            });
        } catch (Throwable $e) {
            log_message('error', 'Question import undo failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'The import could not be undone. Nothing was changed.'];
        }

        $this->store->forget($token);

        try {
            (new AuditLogger())->log('quiz.import.undone', $user, ['target_type' => 'assessment', 'target_id' => $assessmentId]);
        } catch (Throwable) {
            // The undo already happened; a missing audit line is logged, not fatal.
        }

        return ['success' => true, 'message' => 'The import was undone.'];
    }

    /**
     * Applies a list of changes directly (no stored preview). Kept for the
     * callers that already hold trusted changes; the browser goes through
     * applyPreview(). All or nothing, and every failure is reported.
     *
     * @param array $changes {
     *     added: array of {question_text, question_type, points, is_required, choices},
     *     changed: array of {id, question_text, question_type, points, is_required, choices},
     *     remove_ids: int[] only ids explicitly confirmed by the reviewer,
     * }
     */
    public function applyDiff(User $user, int $assessmentId, array $changes): array
    {
        $questionService = new QuestionService();

        // Only this assessment's own questions can be changed or removed.
        $own = array_flip(array_map(
            static fn (array $question) => (int) $question['id'],
            $this->questionModel->listForAssessment($assessmentId)
        ));

        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0];

        try {
            $this->inTransaction(function () use ($user, $assessmentId, $changes, $own, $questionService, &$counts): void {
                foreach (($changes['added'] ?? []) as $item) {
                    $this->mustSucceed($questionService->createQuestion($user, $assessmentId, $item));
                    $counts['added']++;
                }

                foreach (($changes['changed'] ?? []) as $item) {
                    if (empty($item['id']) || !isset($own[(int) $item['id']])) {
                        continue;
                    }

                    $this->mustSucceed($questionService->updateQuestion($user, (int) $item['id'], $item));
                    $counts['changed']++;
                }

                foreach (($changes['remove_ids'] ?? []) as $questionId) {
                    if (!isset($own[(int) $questionId])) {
                        continue;
                    }

                    $this->mustSucceed($questionService->deleteQuestion((int) $questionId));
                    $counts['removed']++;
                }
            });
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Nothing was changed: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => "{$counts['added']} added, {$counts['changed']} updated, {$counts['removed']} removed.",
        ];
    }

    /**
     * @param array<string, mixed> $diff
     * @param array<string, int>   $skip
     * @param list<int>            $removal
     * @param list<string>         $order   preview keys in document order
     * @param list<array<string, mixed>> $before the quiz's questions before the import
     * @param array<string, array<string, mixed>> $overrides
     *
     * @return array{counts: array<string, int>, skipped: list<array<string, mixed>>, undo: array<string, mixed>}
     */
    private function applyItems(User $user, int $assessmentId, array $diff, array $skip, array $removal, array $order, array $before, array $overrides = [], string $source = 'mentora'): array
    {
        $service = new QuestionService();
        $counts  = ['added' => 0, 'changed' => 0, 'removed' => 0, 'skipped' => 0];
        $skipped = [];
        $undo    = [
            'order'   => array_map(static fn (array $q): int => (int) $q['id'], $before),
            'added'   => [],
            'changed' => [],
            'removed' => [],
        ];
        $ids     = []; // preview key => question id, for ordering
        $byId    = [];

        foreach ($before as $question) {
            $byId[(int) $question['id']] = $question;
        }

        $items = [];

        foreach ($diff['added'] as $item) {
            // The review lets a teacher correct a new question (its type, points or
            // correct choice); the corrected question is checked again like any other.
            $items[$item['key']] = isset($overrides[$item['key']])
                ? $this->corrected($item, (array) $overrides[$item['key']])
                : $item;
        }

        foreach ($diff['changed'] as $item) {
            $items[$item['key']] = $item;
        }

        foreach ($items as $key => $item) {
            if (isset($skip[$key])) {
                $counts['skipped']++;
                $skipped[] = ['line' => $item['line'], 'question_text' => $item['question_text'], 'reason' => 'You chose to skip it.'];

                continue;
            }

            if (! $item['importable']) {
                $counts['skipped']++;
                $skipped[] = ['line' => $item['line'], 'question_text' => $item['question_text'], 'reason' => $this->firstError($item)];

                continue;
            }

            if (isset($item['id'])) {
                $previous = $byId[(int) $item['id']] ?? null;

                if ($previous === null) {
                    continue; // no longer in the quiz: nothing to change
                }

                $undo['changed'][] = $this->snapshot($previous);
                $this->mustSucceed($service->updateQuestion($user, (int) $item['id'], $this->payload($item)));
                $ids[$key] = (int) $item['id'];
                $counts['changed']++;

                continue;
            }

            $created = $service->createQuestion($user, $assessmentId, $this->payload($item));
            $this->mustSucceed($created);
            $newId = (int) $created['question']['id'];
            $undo['added'][] = $newId;
            $ids[$key] = $newId;
            $counts['added']++;
        }

        foreach ($removal as $questionId) {
            if (! isset($byId[$questionId])) {
                continue;
            }

            $undo['removed'][] = $this->snapshot($byId[$questionId]);
            $this->mustSucceed($service->deleteQuestion($questionId));
            $counts['removed']++;
        }

        // An exported Mentora file's order is the quiz's order; questions it
        // does not mention keep their relative place after the ones it does.
        // An ordinary document only adds, so what is there stays where it is
        // and the new questions follow, in the document's order.
        $final = [];

        if ($source === 'plain') {
            $final = array_values(array_filter($undo['order'], static fn (int $id): bool => ! in_array($id, $removal, true)));
        }

        foreach ($order as $key) {
            if (isset($ids[$key])) {
                $final[] = $ids[$key];

                continue;
            }

            // A question that is in the file but did not change keeps its id.
            if (str_starts_with($key, 'c')) {
                $existing = (int) substr($key, 1);

                if (isset($byId[$existing]) && ! in_array($existing, $removal, true) && ! in_array($existing, $final, true)) {
                    $final[] = $existing;
                }
            }
        }

        foreach ($undo['order'] as $existing) {
            if (! in_array($existing, $final, true) && ! in_array($existing, $removal, true)) {
                $final[] = $existing;
            }
        }

        $service->reorderQuestions($assessmentId, $final);

        return ['counts' => $counts, 'skipped' => $skipped, 'undo' => $undo];
    }

    /**
     * A new question with the teacher's corrections applied, and its problems
     * worked out again.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function corrected(array $item, array $override): array
    {
        if (isset($override['question_type']) && in_array($override['question_type'], QuestionImportValidator::TYPES, true)) {
            $item['question_type'] = $override['question_type'];
        }

        if (isset($override['points']) && is_numeric($override['points'])) {
            $item['points'] = (float) $override['points'];
        }

        if ($item['question_type'] !== 'Multiple Choice') {
            $item['choices'] = [];
            $item['allow_multiple_answers'] = false;
            $item['answers_count'] = null;
        } elseif (isset($override['correct']) && is_array($override['correct'])) {
            $correct = array_flip(array_map('intval', $override['correct']));

            foreach ($item['choices'] as $index => $choice) {
                $item['choices'][$index]['is_correct'] = isset($correct[$index]);
            }

            $item['allow_multiple_answers'] = count($correct) > 1;
            $item['answers_count'] = count($correct) > 1 ? count($correct) : null;
        }

        if ($item['question_type'] !== 'Short Answer') {
            $item['accepted_answers'] = [];
        }

        $problems = QuestionImportValidator::validate($item);

        $item['problems'] = $problems;
        $item['importable'] = QuestionImportValidator::importable($problems);

        return $item;
    }

    /**
     * @param array<string, mixed> $undo
     */
    private function restore(User $user, int $assessmentId, array $undo): void
    {
        $service = new QuestionService();
        $live    = array_flip(array_map(
            static fn (array $q): int => (int) $q['id'],
            $this->questionModel->listForAssessment($assessmentId)
        ));

        foreach ($undo['added'] as $id) {
            if (isset($live[(int) $id])) {
                $this->mustSucceed($service->deleteQuestion((int) $id));
            }
        }

        foreach ($undo['changed'] as $snapshot) {
            if (isset($live[(int) $snapshot['id']])) {
                $this->mustSucceed($service->updateQuestion($user, (int) $snapshot['id'], $snapshot['data']));
            }
        }

        $idMap = [];

        foreach ($undo['removed'] as $snapshot) {
            $created = $service->createQuestion($user, $assessmentId, $snapshot['data']);
            $this->mustSucceed($created);
            $idMap[(int) $snapshot['id']] = (int) $created['question']['id'];
        }

        $order = [];

        foreach ($undo['order'] as $id) {
            $id = (int) $id;
            $order[] = $idMap[$id] ?? $id;
        }

        $service->reorderQuestions($assessmentId, $order);
    }

    /**
     * What a question was, in the shape createQuestion/updateQuestion take.
     *
     * @param array<string, mixed> $question as listed for the assessment
     *
     * @return array{id: int, data: array<string, mixed>}
     */
    private function snapshot(array $question): array
    {
        return [
            'id'   => (int) $question['id'],
            'data' => [
                'question_text'          => $question['question_text'],
                'question_type'          => $question['question_type'],
                'points'                 => $question['points'],
                'is_required'            => (bool) $question['is_required'],
                'allow_multiple_answers' => (bool) ($question['allow_multiple_answers'] ?? false),
                'answers_count'          => $question['answers_count'] ?? null,
                'accepted_answers'       => $question['accepted_answers'] ?? [],
                'case_sensitive'         => (bool) ($question['case_sensitive'] ?? false),
                'choices'                => array_map(
                    static fn (array $c): array => ['text' => $c['choice_text'], 'is_correct' => (bool) $c['is_correct']],
                    $question['choices'] ?? []
                ),
            ],
        ];
    }

    /**
     * What a preview item writes to a question.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function payload(array $item): array
    {
        return [
            'question_text'          => $item['question_text'],
            'question_type'          => $item['question_type'],
            'points'                 => $item['points'],
            'is_required'            => $item['is_required'],
            'choices'                => $item['choices'],
            'accepted_answers'       => $item['accepted_answers'],
            'case_sensitive'         => $item['case_sensitive'],
            'allow_multiple_answers' => $item['allow_multiple_answers'],
            'answers_count'          => $item['answers_count'],
        ];
    }

    /** @param array<string, mixed> $result */
    private function mustSucceed(array $result): void
    {
        if (empty($result['success'])) {
            throw new RuntimeException((string) ($result['message'] ?? 'A question could not be saved.'));
        }
    }

    /** @param array<string, mixed> $item */
    private function firstError(array $item): string
    {
        foreach ($item['problems'] as $problem) {
            if ($problem['severity'] === QuestionImportValidator::ERROR) {
                return $problem['message'];
            }
        }

        return 'It could not be imported.';
    }

    /** @param list<int> $questionIds */
    private function responsesOn(array $questionIds): int
    {
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds))));

        if ($questionIds === []) {
            return 0;
        }

        return (int) db_connect()->table('assessment_question_responses')
            ->whereIn('question_id', $questionIds)
            ->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the file
    |--------------------------------------------------------------------------
    */

    /**
     * The document's paragraphs in order (list items and table-cell
     * paragraphs included), each with whether all of its text is bold.
     *
     * @return list<array{text: string, bold: bool}>
     *
     * @throws Throwable when the file cannot be opened as the type it claims
     */
    private function extractParagraphs(string $filePath, string $extension): array
    {
        if (! in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported file type.');
        }

        $phpWord = IOFactory::createReader($extension === 'rtf' ? 'RTF' : 'Word2007')->load($filePath);

        $paragraphs = [];

        foreach ($phpWord->getSections() as $section) {
            $this->collect($section->getElements(), $paragraphs);
        }

        return $paragraphs;
    }

    /**
     * @param iterable<AbstractElement>            $elements
     * @param list<array{text: string, bold: bool}> $paragraphs
     */
    private function collect(iterable $elements, array &$paragraphs): void
    {
        foreach ($elements as $element) {
            if ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->collect($cell->getElements(), $paragraphs);
                    }
                }

                continue;
            }

            if (method_exists($element, 'getText')) {
                $text = $element->getText();

                if (is_string($text)) {
                    $paragraphs[] = ['text' => $text, 'bold' => $this->isBold($element)];

                    continue;
                }
            }

            if (method_exists($element, 'getElements')) {
                $this->collect($element->getElements(), $paragraphs);
            }
        }
    }

    /** True when every piece of text in the element is bold. */
    private function isBold(object $element): bool
    {
        if (method_exists($element, 'getElements')) {
            $found = false;

            foreach ($element->getElements() as $child) {
                if (! method_exists($child, 'getText') || trim((string) $child->getText()) === '') {
                    continue;
                }

                $found = true;

                if (! $this->isBold($child)) {
                    return false;
                }
            }

            return $found;
        }

        if (! method_exists($element, 'getFontStyle')) {
            return false;
        }

        $style = $element->getFontStyle();

        return is_object($style) && method_exists($style, 'isBold') && $style->isBold() === true;
    }

    /**
     * The `[machine-readable ...] id=..; type=..; points=..; required=..;
     * correct=..` line, as key/value pairs; null when the line is not one.
     * Must stay in sync with QuestionExportService.
     *
     * @return array{id: int|null, type: string, points: string, is_required: bool, correct_letters: list<string>, case_sensitive: bool, multi: bool|null, answers: int|null}|null
     */
    private function parseMeta(string $line): ?array
    {
        if (! preg_match('/^\[machine-readable[^\]]*\]\s*(.+)$/iu', trim($line), $m)) {
            return null;
        }

        $pairs = [];

        foreach (explode(';', $m[1]) as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $part, 2));
            $pairs[strtolower($key)] = $value;
        }

        foreach (['id', 'type', 'points', 'required', 'correct'] as $required) {
            if (! array_key_exists($required, $pairs)) {
                return null;
            }
        }

        if (! preg_match('/^(\d+|new)$/i', $pairs['id']) || ! preg_match('/^(yes|no)$/i', $pairs['required']) || ! preg_match('/^[a-z0-9,]*$/i', $pairs['correct'])) {
            return null;
        }

        return [
            'id'              => strtolower($pairs['id']) === 'new' ? null : (int) $pairs['id'],
            'type'            => $pairs['type'],
            'points'          => str_replace(',', '.', $pairs['points']),
            'is_required'     => strtolower($pairs['required']) === 'yes',
            'correct_letters' => $pairs['correct'] === '' ? [] : array_map('strtolower', explode(',', $pairs['correct'])),
            'case_sensitive'  => strtolower($pairs['case'] ?? '') === 'yes',
            'multi'           => isset($pairs['multi']) ? strtolower($pairs['multi']) === 'yes' : null,
            'answers'         => isset($pairs['answers']) && ctype_digit($pairs['answers']) ? (int) $pairs['answers'] : null,
        ];
    }

    /**
     * Groups lines into question blocks, each terminated by a metadata line.
     * Lines with no metadata line to close them are kept as a block if they
     * contain a question heading (a hand-typed new question). Returns [] when
     * not a single metadata line is found.
     *
     * @param list<string> $lines
     *
     * @return list<array{lines: list<string>, line: int}> `line` is the 1-based paragraph the block starts at
     */
    private function splitIntoBlocks(array $lines): array
    {
        $blocks = [];
        $buffer = [];
        $start = 0;
        $bufferHasHeading = false;
        $sawAnyMetaLine = false;

        foreach ($lines as $index => $line) {

            if ($this->parseMeta($line) !== null) {

                $sawAnyMetaLine = true;

                $buffer[] = $line;
                $blocks[] = ['lines' => $buffer, 'line' => $start ?: $index + 1];

                $buffer = [];
                $start = 0;
                $bufferHasHeading = false;

                continue;

            }

            if (trim($line) === '') {
                continue;
            }

            $isHeading = (bool) preg_match(self::QUESTION_HEADING_PATTERN, $line);

            // A second heading before the first one's metadata line means the
            // first question's marker is missing: flush it as an orphan block
            // instead of merging it into whichever block closes next.
            if ($isHeading && $bufferHasHeading) {
                $blocks[] = ['lines' => $buffer, 'line' => $start];
                $buffer = [];
                $start = 0;
            }

            if ($isHeading) {
                $bufferHasHeading = true;
            }

            if ($buffer === []) {
                $start = $index + 1;
            }

            $buffer[] = $line;

        }

        if (!$sawAnyMetaLine) {
            return [];
        }

        if (!empty($buffer) && $bufferHasHeading) {
            $blocks[] = ['lines' => $buffer, 'line' => $start];
        }

        return $blocks;
    }

    /**
     * @param array{lines: list<string>, line: int} $block
     *
     * @return array<string, mixed>
     */
    private function parseBlock(array $block): array
    {
        $meta = null;
        $contentLines = [];

        foreach ($block['lines'] as $line) {

            $found = $this->parseMeta($line);

            if ($found !== null) {
                $meta = $found;

                continue;
            }

            $contentLines[] = $line;

        }

        $questionText = '';
        $choices = [];
        $letters = [];
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
                $letters[] = $letter;

                $choices[] = [
                    'text' => trim($matches[2]),
                    'is_correct' => in_array($letter, $meta['correct_letters'] ?? [], true),
                ];

                continue;

            }

            // A line that is neither a heading nor a choice, as the first
            // line, is the question text with its "Qn." prefix removed.
            if ($questionText === '' && $index === 0) {
                $questionText = trim($line);
            }

        }

        $questionType = $meta['type'] ?? (empty($choices) ? 'Essay' : 'Multiple Choice');

        $isShortAnswer = $questionType === 'Short Answer';
        $isChoice = $questionType === 'Multiple Choice';
        $correctCount = count(array_filter($choices, static fn (array $c): bool => $c['is_correct']));

        // A file written before the multiple-answers flag existed carries only
        // the correct letters: more than one means several answers.
        $multi = $isChoice ? ($meta['multi'] ?? $correctCount > 1) : false;

        return [
            'id' => $meta['id'] ?? null,
            'line' => $block['line'],
            'question_text' => $questionText,
            'question_type' => $questionType,
            // A number when the line carries one; the raw text otherwise, for the validator to refuse.
            'points' => $meta === null ? 0 : (is_numeric($meta['points']) ? (float) $meta['points'] : $meta['points']),
            'is_required' => $meta['is_required'] ?? true,
            'choices' => $isChoice || $choices !== [] ? $choices : [],
            'accepted_answers' => $isShortAnswer ? $acceptedAnswers : [],
            'case_sensitive' => $isShortAnswer && ($meta['case_sensitive'] ?? false),
            'allow_multiple_answers' => $multi,
            'answers_count' => $multi ? ($meta['answers'] ?? null) : null,
            'unmatched_correct' => array_values(array_diff($meta['correct_letters'] ?? [], $letters)) !== [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $parsedQuestions
     *
     * @return array{diff: array{added: list<array<string, mixed>>, changed: list<array<string, mixed>>, removed: list<array<string, mixed>>}, order: list<string>, problems: list<array<string, mixed>>, counts: array<string, int>}
     */
    private function buildDiff(int $assessmentId, array $parsedQuestions, bool $trackMissing = true): array
    {
        $liveQuestions = $this->questionModel->listForAssessment($assessmentId);

        $liveById = [];

        foreach ($liveQuestions as $question) {
            $liveById[(int) $question['id']] = $question;
        }

        $added = [];
        $changed = [];
        $order = [];
        $problems = [];
        $seenIds = [];

        foreach ($parsedQuestions as $index => $parsed) {

            $itemProblems = QuestionImportValidator::validate($parsed);

            // What the plain reader was unsure of is shown beside the question.
            foreach ($parsed['notes'] ?? [] as $note) {
                if (! $this->alreadyCovers($itemProblems, $note)) {
                    $itemProblems[] = ['severity' => QuestionImportValidator::WARNING, 'message' => $note];
                }
            }

            // A question that repeats one already in the quiz is flagged, not blocked.
            $isNew = $parsed['id'] === null || !isset($liveById[$parsed['id']]);

            if ($isNew) {
                $twin = $this->similarTo($parsed['question_text'], $liveQuestions);

                if ($twin !== null) {
                    $itemProblems[] = ['severity' => QuestionImportValidator::WARNING, 'message' => 'This looks like a question already in the quiz: "' . mb_strimwidth($twin, 0, 60, '...') . '".'];
                }
            }

            if (isset($parsed['id']) && in_array($parsed['id'], $seenIds, true)) {
                $itemProblems[] = ['severity' => QuestionImportValidator::ERROR, 'message' => 'This question appears twice in the file.'];
            }

            $importable = QuestionImportValidator::importable($itemProblems);

            $base = [
                'line' => $parsed['line'],
                'importable' => $importable,
                'problems' => $itemProblems,
                'question_text' => $parsed['question_text'],
                'question_type' => $parsed['question_type'],
                'points' => $parsed['points'],
                'is_required' => $parsed['is_required'],
                'choices' => $parsed['choices'],
                'accepted_answers' => $parsed['accepted_answers'],
                'case_sensitive' => $parsed['case_sensitive'],
                'allow_multiple_answers' => $parsed['allow_multiple_answers'],
                'answers_count' => $parsed['answers_count'],
            ];

            foreach ($itemProblems as $problem) {
                $problems[] = $problem + ['line' => $parsed['line'], 'question_text' => $parsed['question_text']];
            }

            if ($isNew) {

                $key = 'a' . count($added);
                $added[] = ['key' => $key] + $base;
                $order[] = $key;

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

            $isChoice = $parsed['question_type'] === 'Multiple Choice';

            $hasChanged =
                $live['question_text'] !== $parsed['question_text']
                || $live['question_type'] !== $parsed['question_type']
                || (float) $live['points'] !== (float) $parsed['points']
                || (bool) $live['is_required'] !== $parsed['is_required']
                || $liveChoices !== $parsed['choices']
                || ($live['accepted_answers'] ?? []) !== $parsed['accepted_answers']
                || !empty($live['case_sensitive']) !== $parsed['case_sensitive']
                || ($isChoice && !empty($live['allow_multiple_answers']) !== $parsed['allow_multiple_answers']);

            $key = 'c' . $parsed['id'];
            $order[] = $key;

            if ($hasChanged) {

                $changed[] = ['key' => $key, 'id' => $parsed['id'], 'previous_text' => $live['question_text']] + $base;

            }

        }

        $removed = [];

        foreach ($trackMissing ? $liveById : [] as $id => $live) {

            if (!in_array($id, $seenIds, true)) {

                $removed[] = [
                    'id' => $id,
                    'question_text' => $live['question_text'],
                ];

            }

        }

        return [
            'diff' => [
                'added' => $added,
                'changed' => $changed,
                'removed' => $removed,
            ],
            'order' => $order,
            'problems' => $problems,
            'counts' => [
                'added' => count($added),
                'changed' => count($changed),
                'removed' => count($removed),
                'problems' => count(array_filter($problems, static fn (array $p): bool => $p['severity'] === QuestionImportValidator::ERROR)),
            ],
        ];
    }

    /**
     * A note the validator already says in its own words (no correct answer
     * is both) is not listed twice.
     *
     * @param list<array{severity: string, message: string}> $problems
     */
    private function alreadyCovers(array $problems, string $note): bool
    {
        foreach ($problems as $problem) {
            if ($problem['severity'] === QuestionImportValidator::ERROR && str_contains($note, 'No correct answer') && str_contains($problem['message'], 'No correct answer')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text of an existing question that this one repeats, if any.
     *
     * @param list<array<string, mixed>> $live
     */
    private function similarTo(string $text, array $live): ?string
    {
        $words = $this->words($text);

        if ($words === []) {
            return null;
        }

        foreach ($live as $question) {
            $other = $this->words((string) $question['question_text']);

            if ($other === []) {
                continue;
            }

            $shared = count(array_intersect($words, $other));

            if ($shared / min(count($words), count($other)) >= self::DUPLICATE_OVERLAP && min(count($words), count($other)) >= 3) {
                return (string) $question['question_text'];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $m);

        return array_values(array_unique($m[0]));
    }
}
