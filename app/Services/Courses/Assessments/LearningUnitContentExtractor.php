<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use App\Models\Files\FileModel;
use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningResourceModel;
use App\Services\AI\QuestionGeneratorClientInterface;
use App\Services\AI\QuestionGeneratorUnavailableException;
use App\Services\Storage\StorageService;

/**
 * Turns a learning unit's readings and PDFs into one plain-text source for
 * AI question generation. Runs on demand at generation time — nothing is
 * pre-extracted when content is authored. Other item types (video, link,
 * quiz, assignment) have no text to pull yet and are skipped.
 */
class LearningUnitContentExtractor
{
    // Python's prompt builder already truncates source_text to 12000 chars;
    // this just stops a very large unit from costing more OCR than can
    // ever reach the model.
    // Large enough for whole PDFs: the question builder reads a source in
    // windows and digests only what a batch needs, so there is no reason to
    // cut a unit down to its first few pages.
    public const MAX_SOURCE_LENGTH = 400000;

    private const ITEM_SEPARATOR = "\n\n---\n\n";

    protected LearningItemModel $itemModel;
    protected LearningResourceModel $resourceModel;
    protected FileModel $fileModel;

    public function __construct()
    {
        $this->itemModel = new LearningItemModel();
        $this->resourceModel = new LearningResourceModel();
        $this->fileModel = new FileModel();
    }

    /**
     * The learning unit an assessment sits under — the only unit its AI
     * generation may read — with how many items extract() would read from
     * it. Null when the assessment isn't placed in a unit.
     *
     * `items` is what the person can choose from: the unit's readings and
     * PDFs (in unit order), as {id, title, type, status}.
     *
     * @return array{id: int, title: string, module_title: string, readings: int, pdfs: int, items: list<array{id: int, title: string, type: string, status: string}>}|null
     */
    public function unitForAssessment(array $assessment, User $user): ?array
    {
        if (empty($assessment['learning_item_id'])) {
            return null;
        }

        $unit = db_connect()->table('learning_items')
            ->select('learning_units.id, learning_units.title, course_modules.title AS module_title')
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_items.id', (int) $assessment['learning_item_id'])
            ->get()
            ->getRowArray();

        if (!$unit) {
            return null;
        }

        $items = $this->selectableItems((int) $unit['id'], $user);
        $types = array_count_values(array_column($items, 'type'));

        return [
            'id' => (int) $unit['id'],
            'title' => $unit['title'],
            'module_title' => $unit['module_title'],
            'readings' => $types['reading'] ?? 0,
            'pdfs' => $types['pdf'] ?? 0,
            'items' => $items,
        ];
    }

    /**
     * The readings and PDFs of a unit that this person can see, in unit
     * order. A light query: it does not load the items' content.
     *
     * @return list<array{id: int, title: string, type: string, status: string}>
     */
    public function selectableItems(int $learningUnitId, User $user): array
    {
        $builder = db_connect()->table('learning_items')
            ->select('id, title, item_type, status')
            ->where('learning_unit_id', $learningUnitId)
            ->whereIn('item_type', ['reading', 'pdf']);

        if (! \App\Services\Courses\CourseViewer::seesInstructorView($user)) {
            $builder->where('status', 'published');
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'type' => (string) $row['item_type'],
                'status' => (string) $row['status'],
            ],
            $builder->orderBy('order_index')->get()->getResultArray()
        );
    }

    /**
     * What a request may select: only ids that are readings or PDFs of this
     * quiz's own unit and visible to the person, in unit order. A foreign or
     * unknown id is dropped, never trusted. Null means "everything".
     *
     * @param array{id: int, items: list<array{id: int}>} $unit from unitForAssessment()
     * @param mixed                                      $requested ids from the request
     *
     * @return list<int>
     */
    public function selection(array $unit, mixed $requested): array
    {
        $available = array_column($unit['items'], 'id');

        if ($requested === null) {
            return $available;
        }

        $wanted = array_map('intval', is_array($requested) ? $requested : []);

        return array_values(array_filter($available, static fn (int $id): bool => in_array($id, $wanted, true)));
    }

    /**
     * @param list<int>|null $itemIds read only these items (null: every reading and PDF)
     *
     * @return array{text: string, skipped: string[], failure: ?string, items: list<array{id: int, title: string, type: string, text: string}>}
     *   `items` is each item that gave text, with its own text (PDF pages keep
     *   their `[[page N]]` lines), so each can be digested on its own;
     *   `text` is the same joined for the single-source path. `skipped` names
     *   items that exist but couldn't be read (so the instructor knows the
     *   questions don't cover them); `failure` is the first read error, for
     *   when nothing at all could be extracted.
     */
    public function extract(int $learningUnitId, User $user, QuestionGeneratorClientInterface $client, ?array $itemIds = null): array
    {
        $sections = [];
        $readItems = [];
        $skipped = [];
        $failure = null;

        $length = 0;

        foreach ($this->itemModel->retrieveLearningItems($learningUnitId, $user) as $item) {

            if ($itemIds !== null && ! in_array((int) $item['id'], $itemIds, true)) {
                continue;
            }

            if ($length >= self::MAX_SOURCE_LENGTH) {
                break;
            }

            if ($item['item_type'] === 'reading') {

                $text = $this->readingText($item['content']);

            } elseif ($item['item_type'] === 'pdf') {

                try {

                    $text = $this->pdfText((int) $item['id'], $client, true);

                } catch (QuestionGeneratorUnavailableException $e) {

                    $skipped[] = $item['title'];
                    $failure ??= "Couldn't read \"{$item['title']}\": " . $e->getMessage();

                    continue;

                }

            } else {

                continue;

            }

            $text = trim($text);

            if ($text === '') {
                continue;
            }

            // Readings usually open with their own title as a heading.
            $section = str_starts_with(mb_strtolower($text), mb_strtolower(trim($item['title'])))
                ? $text
                : $item['title'] . "\n\n" . $text;

            $sections[] = $section;
            $readItems[] = [
                'id' => (int) $item['id'],
                'title' => (string) $item['title'],
                'type' => (string) $item['item_type'],
                // Each item is digested on its own, so its own text, without the unit's separators.
                'text' => mb_substr($text, 0, self::MAX_SOURCE_LENGTH),
            ];

            $length += strlen($section);

        }

        return [
            'text' => mb_substr(implode(self::ITEM_SEPARATOR, $sections), 0, self::MAX_SOURCE_LENGTH),
            'skipped' => $skipped,
            'failure' => $failure,
            'items' => $readItems,
        ];
    }

    /**
     * Reading content is the editor's block JSON: text blocks carry inline
     * runs in `content`, list blocks carry one run-array per bullet in
     * `items`. One line per block/bullet, runs joined without separators
     * (they're fragments of the same sentence split only by formatting).
     */
    private function readingText(?string $content): string
    {
        $document = json_decode($content ?? '', true);

        if (!is_array($document)) {
            return '';
        }

        $lines = [];

        foreach ($document['blocks'] ?? [] as $block) {

            if (isset($block['items']) && is_array($block['items'])) {

                foreach ($block['items'] as $runs) {
                    $lines[] = '- ' . $this->joinRuns($runs);
                }

            } else {

                $lines[] = $this->joinRuns($block['content'] ?? []);

            }

        }

        return implode("\n", array_filter($lines, static fn (string $line) => trim($line, "- \t") !== ''));
    }

    private function joinRuns($runs): string
    {
        if (!is_array($runs)) {
            return '';
        }

        return implode('', array_map(
            static fn ($run) => is_array($run) ? (string) ($run['text'] ?? '') : '',
            $runs
        ));
    }

    /**
     * @throws QuestionGeneratorUnavailableException when the file is missing
     *   or the extraction service can't read it.
     */
    private function pdfText(int $learningItemId, QuestionGeneratorClientInterface $client, bool $keepPageMarkers = false): string
    {
        $resource = $this->resourceModel->retrieveLearningResource($learningItemId);

        $file = !empty($resource['file_id'])
            ? $this->fileModel->retrieveFile((int) $resource['file_id'])
            : null;

        $path = $file ? (new StorageService())->path($file['storage_key']) : null;

        if (!$path || !is_file($path)) {
            throw new QuestionGeneratorUnavailableException('the PDF file is missing.');
        }

        $text = $client->extractText($path, $file['mime_type'])['text'] ?? '';

        // Each item is digested on its own, so a PDF keeps its page numbers.
        // The combined single-source text joins several documents, whose page
        // numbers would collide, so there the markers are dropped.
        return $keepPageMarkers ? $text : (string) preg_replace('/^\[\[page \d+\]\]\R?/m', '', $text);
    }
}
