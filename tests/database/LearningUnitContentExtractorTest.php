<?php

declare(strict_types=1);

use App\Services\AI\QuestionGeneratorClientInterface;
use App\Services\AI\QuestionGeneratorUnavailableException;
use App\Services\Courses\Assessments\LearningUnitContentExtractor;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Turning a learning unit's readings and PDFs into the text AI question
 * generation reads: which unit a quiz may draw on, how the editor's blocks
 * become lines, what is skipped, and how a PDF that cannot be read is
 * reported instead of failing the whole run.
 *
 * @internal
 */
final class LearningUnitContentExtractorTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private LearningUnitContentExtractor $extractor;
    private ExtractorClient $client;
    private int $offering;
    private int $module;
    private int $unit;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new LearningUnitContentExtractor();
        $this->client = new ExtractorClient();
        $this->offering = CourseFactory::offering();
        $this->module = CourseFactory::module($this->offering);
        $this->unit = CourseFactory::unit($this->module);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    /** A reading item holding text blocks (each a list of runs) or bullet lists. @return int item id */
    private function reading(string $title, array $blocks, string $status = 'published'): int
    {
        [$id] = CourseFactory::items($this->unit, [['title' => $title, 'type' => 'reading', 'status' => $status]]);
        db_connect()->table('learning_items')->where('id', $id)->update(['content' => json_encode(['version' => 1, 'blocks' => $blocks])]);

        return $id;
    }

    /** @return array{type: string, content: list<array{text: string}>} */
    private function paragraph(string ...$runs): array
    {
        return ['type' => 'paragraph', 'content' => array_map(static fn (string $t) => ['text' => $t], $runs)];
    }

    private function pdf(string $title, ?string $content = 'PDF text', bool $withFile = true): int
    {
        [$id] = CourseFactory::items($this->unit, [['title' => $title, 'type' => 'pdf']]);

        if ($withFile) {
            $file = CourseFactory::file($this->instructor, 'pdf bytes');
            db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active', 'mime_type' => 'application/pdf']);
            CourseFactory::resource($id, 'pdf', null, $file['id']);
            $this->client->text = $content ?? '';
        }

        return $id;
    }

    private function extract(): array
    {
        return $this->extractor->extract($this->unit, $this->instructor, $this->client);
    }

    /*
    |--------------------------------------------------------------------------
    | Which unit a quiz may use
    |--------------------------------------------------------------------------
    */

    public function testAQuizOnlyDrawsOnTheUnitItSitsIn(): void
    {
        $this->reading('Intro', [$this->paragraph('Hello')]);
        $this->reading('Chapter', [$this->paragraph('World')]);
        $this->pdf('Slides');
        CourseFactory::items($this->unit, [['type' => 'video'], ['type' => 'link']]);
        [$quizItem] = CourseFactory::items($this->unit, [['type' => 'quiz']]);
        $quiz = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $quizItem);

        $unit = $this->extractor->unitForAssessment(db_connect()->table('assessments')->where('id', $quiz)->get()->getRowArray(), $this->instructor);

        $this->assertSame($this->unit, $unit['id']);
        $this->assertSame(2, $unit['readings']);
        $this->assertSame(1, $unit['pdfs']);
        $this->assertStringStartsWith('Unit ', $unit['title']);
        $this->assertNotSame('', $unit['module_title']);
    }

    public function testAQuizOutsideAnyUnitHasNoSource(): void
    {
        $loose = CourseFactory::assessment($this->offering, $this->instructor, $this->module);

        $this->assertNull($this->extractor->unitForAssessment(db_connect()->table('assessments')->where('id', $loose)->get()->getRowArray(), $this->instructor));
        $this->assertNull($this->extractor->unitForAssessment(['learning_item_id' => 999999], $this->instructor));
    }

    /*
    |--------------------------------------------------------------------------
    | Readings
    |--------------------------------------------------------------------------
    */

    public function testAReadingBecomesItsTitleThenOneLinePerBlockWithRunsJoinedTogether(): void
    {
        $this->reading('Photosynthesis', [
            $this->paragraph('Plants turn ', 'light', ' into sugar.'),
            $this->paragraph('It happens in leaves.'),
        ]);

        $result = $this->extract();

        $this->assertSame("Photosynthesis\n\nPlants turn light into sugar.\nIt happens in leaves.", $result['text']);
        $this->assertSame([], $result['skipped']);
        $this->assertNull($result['failure']);
    }

    public function testBulletListsBecomeDashedLines(): void
    {
        $this->reading('Steps', [['type' => 'bulletList', 'items' => [[['text' => 'Mix']], [['text' => 'Bake'], ['text' => ' it']]]]]);

        $this->assertSame("Steps\n\n- Mix\n- Bake it", $this->extract()['text']);
    }

    public function testEmptyBlocksAndEmptyBulletsAreDropped(): void
    {
        $this->reading('Sparse', [$this->paragraph(''), $this->paragraph('Real line'), ['type' => 'bulletList', 'items' => [[['text' => '']], [['text' => 'Real bullet']]]], ['type' => 'divider']]);

        $this->assertSame("Sparse\n\nReal line\n- Real bullet", $this->extract()['text']);
    }

    public function testATitleAlreadyAtTheTopOfTheTextIsNotRepeated(): void
    {
        $this->reading('Photosynthesis', [$this->paragraph('photosynthesis is how plants eat.')]);

        $this->assertSame('photosynthesis is how plants eat.', $this->extract()['text']);
    }

    public function testReadingsWithNoUsableContentAreLeftOut(): void
    {
        [$empty, $broken, $none] = CourseFactory::items($this->unit, [['title' => 'Empty', 'type' => 'reading'], ['title' => 'Broken', 'type' => 'reading'], ['title' => 'None', 'type' => 'reading']]);
        db_connect()->table('learning_items')->where('id', $empty)->update(['content' => json_encode(['blocks' => []])]);
        db_connect()->table('learning_items')->where('id', $broken)->update(['content' => 'not json']);
        $this->reading('Good', [$this->paragraph('Content')]);

        $result = $this->extract();

        $this->assertSame("Good\n\nContent", $result['text']);
        $this->assertSame([], $result['skipped'], 'nothing to read is not a failure');
    }

    public function testItemsAreJoinedInOrderWithASeparator(): void
    {
        $this->reading('One', [$this->paragraph('First text')]);
        $this->reading('Two', [$this->paragraph('Second text')]);

        $this->assertSame("One\n\nFirst text\n\n---\n\nTwo\n\nSecond text", $this->extract()['text']);
    }

    public function testOnlyTextItemsAreRead(): void
    {
        CourseFactory::items($this->unit, [['type' => 'video'], ['type' => 'link'], ['type' => 'quiz'], ['type' => 'assignment'], ['type' => 'discussion']]);

        $this->assertSame('', $this->extract()['text']);
    }

    public function testAnEmptyUnitGivesNothing(): void
    {
        $this->assertSame(['text' => '', 'skipped' => [], 'failure' => null, 'items' => [], 'truncated' => false], $this->extract());
    }

    public function testTheTextIsCutAtTheSourceLimitAndLaterItemsAreNotRead(): void
    {
        $long = str_repeat('word ', (int) (\App\Services\Courses\Assessments\LearningUnitContentExtractor::MAX_SOURCE_LENGTH / 5) + 1000);
        $this->reading('Big one', [$this->paragraph($long)]);
        $this->reading('Big two', [$this->paragraph($long)]);
        $this->reading('Never read', [$this->paragraph('late')]);

        $text = $this->extract()['text'];

        $this->assertSame(\App\Services\Courses\Assessments\LearningUnitContentExtractor::MAX_SOURCE_LENGTH, mb_strlen($text));
        $this->assertStringNotContainsString('Never read', $text);
    }

    public function testOnceTheLimitIsReachedLaterPdfsAreNotEvenSentToTheExtractionService(): void
    {
        // More than the source limit in one reading, so nothing after it can be used.
        $long = str_repeat('word ', (int) (\App\Services\Courses\Assessments\LearningUnitContentExtractor::MAX_SOURCE_LENGTH / 5) + 1000);
        $this->reading('Big', [$this->paragraph($long)]);
        $this->pdf('Later slides', 'Never needed');

        $this->extract();

        $this->assertSame([], $this->client->paths, 'no OCR is paid for text that can never be used');
    }

    /*
    |--------------------------------------------------------------------------
    | PDFs
    |--------------------------------------------------------------------------
    */

    public function testAPdfIsReadThroughTheExtractionService(): void
    {
        $this->pdf('Lecture slides', 'Slide one. Slide two.');

        $result = $this->extract();

        $this->assertSame("Lecture slides\n\nSlide one. Slide two.", $result['text']);
        $this->assertSame('application/pdf', $this->client->lastMime);
        $this->assertCount(1, $this->client->paths);
        $this->assertFileExists($this->client->paths[0]);
    }

    public function testAMissingPdfFileIsSkippedAndReportedWhileOtherItemsStillRead(): void
    {
        $this->pdf('Lost slides', null, false);
        $this->reading('Notes', [$this->paragraph('Still here')]);

        $result = $this->extract();

        $this->assertSame("Notes\n\nStill here", $result['text']);
        $this->assertSame(['Lost slides'], $result['skipped']);
        $this->assertSame('Couldn\'t read "Lost slides": the PDF file is missing.', $result['failure']);
    }

    public function testAnItemWithNoResourceRowAtAllCountsAsMissing(): void
    {
        [$id] = CourseFactory::items($this->unit, [['title' => 'Bare', 'type' => 'pdf']]);

        $result = $this->extract();

        $this->assertSame(['Bare'], $result['skipped']);
        $this->assertNotNull($id);
    }

    public function testAPdfTheServiceCannotReadIsSkippedAndOnlyTheFirstFailureIsKept(): void
    {
        $this->pdf('First', 'x');
        $this->pdf('Second', 'x');
        $this->client->failure = new QuestionGeneratorUnavailableException('OCR is down.');

        $result = $this->extract();

        $this->assertSame('', $result['text']);
        $this->assertSame(['First', 'Second'], $result['skipped']);
        $this->assertSame('Couldn\'t read "First": OCR is down.', $result['failure']);
    }

    public function testAPdfWithNoTextIsLeftOutWithoutBeingCalledAFailure(): void
    {
        $this->pdf('Blank scan', '   ');

        $result = $this->extract();

        $this->assertSame('', $result['text']);
        $this->assertSame([], $result['skipped']);
    }

    public function testStudentsDoNotGetDraftItemsButAnInstructorDoes(): void
    {
        $this->reading('Published', [$this->paragraph('Visible')]);
        $this->reading('Draft', [$this->paragraph('Hidden from students')], 'draft');
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $student);

        $asInstructor = $this->extractor->extract($this->unit, $this->instructor, $this->client)['text'];
        $asStudent = $this->extractor->extract($this->unit, $student, $this->client)['text'];

        $this->assertStringContainsString('Hidden from students', $asInstructor);
        $this->assertStringNotContainsString('Hidden from students', $asStudent);
    }
}

/** A stand-in extraction service for PDFs. */
final class ExtractorClient implements QuestionGeneratorClientInterface
{
    public string $text = '';

    public ?\Throwable $failure = null;

    /** @var list<string> */
    public array $paths = [];

    public ?string $lastMime = null;

    public function generate(array $params): array
    {
        return ['questions' => [], 'model_used' => 'fake'];
    }

    public function extractText(string $filePath, string $mimeType): array
    {
        $this->paths[] = $filePath;
        $this->lastMime = $mimeType;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return ['text' => $this->text, 'used_ocr' => false, 'pages' => 1];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing items
    |--------------------------------------------------------------------------
    */

    public function testTheUnitListsItsReadingsAndPdfsForTheChecklist(): void
    {
        $intro = $this->reading('Intro', [$this->paragraph('Hello')]);
        $draft = $this->reading('Draft notes', [$this->paragraph('Soon')], 'draft');
        $slides = $this->pdf('Slides');
        CourseFactory::items($this->unit, [['type' => 'video'], ['type' => 'quiz']]);
        [$quizItem] = CourseFactory::items($this->unit, [['type' => 'quiz']]);
        $quiz = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $quizItem);

        $unit = $this->extractor->unitForAssessment(db_connect()->table('assessments')->where('id', $quiz)->get()->getRowArray(), $this->instructor);

        $this->assertSame([$intro, $draft, $slides], array_column($unit['items'], 'id'));
        $this->assertSame(['reading', 'reading', 'pdf'], array_column($unit['items'], 'type'));
        $this->assertSame(['published', 'draft', 'published'], array_column($unit['items'], 'status'));
        $this->assertSame(2, $unit['readings']);
        $this->assertSame(1, $unit['pdfs']);
    }

    public function testASelectionKeepsOnlyThisUnitsReadingsAndPdfsInUnitOrder(): void
    {
        $a = $this->reading('A', [$this->paragraph('Alpha')]);
        $b = $this->reading('B', [$this->paragraph('Beta')]);
        [$video] = CourseFactory::items($this->unit, [['type' => 'video']]);
        [$quizItem] = CourseFactory::items($this->unit, [['type' => 'quiz']]);
        $quiz = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $quizItem);
        $unit = $this->extractor->unitForAssessment(db_connect()->table('assessments')->where('id', $quiz)->get()->getRowArray(), $this->instructor);

        $this->assertSame([$a, $b], $this->extractor->selection($unit, null));
        $this->assertSame([$a, $b], $this->extractor->selection($unit, [$b, $a, 999999, $video]));
        $this->assertSame([$b], $this->extractor->selection($unit, ['' . $b]));
        $this->assertSame([], $this->extractor->selection($unit, []));
        $this->assertSame([], $this->extractor->selection($unit, 'not a list'));
    }

    public function testOnlyTheChosenItemsAreReadAndEachComesBackWithItsOwnText(): void
    {
        $a = $this->reading('A', [$this->paragraph('Alpha text')]);
        $this->reading('B', [$this->paragraph('Beta text')]);
        $slides = $this->pdf('Slides', "[[page 1]]\nSlide one text.\n\n[[page 2]]\nSlide two text.");

        $result = $this->extractor->extract($this->unit, $this->instructor, $this->client, [$a, $slides]);

        $this->assertSame([$a, $slides], array_column($result['items'], 'id'));
        $this->assertSame(['reading', 'pdf'], array_column($result['items'], 'type'));
        $this->assertSame('Alpha text', $result['items'][0]['text']);
        $this->assertStringContainsString("[[page 2]]\nSlide two text.", $result['items'][1]['text'], 'a PDF keeps its own page numbers');
        $this->assertStringNotContainsString('Beta', $result['text']);
        $this->assertStringNotContainsString('[[page', $result['text'], 'the combined text drops page markers');
    }

    public function testAnItemNotChosenIsNeverSentToTheExtractionService(): void
    {
        $this->reading('A', [$this->paragraph('Alpha')]);
        $this->pdf('Slides');

        $this->extractor->extract($this->unit, $this->instructor, $this->client, []);

        $this->assertSame([], $this->client->paths);
    }
}
