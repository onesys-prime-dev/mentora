<?php

declare(strict_types=1);

use App\Models\Assessments\AssessmentQuestionModel;
use App\Services\Courses\Assessments\ImportPreviewStore;
use App\Services\Courses\Assessments\QuestionExportService;
use App\Services\Courses\Assessments\QuestionImportService;
use App\Services\Courses\Assessments\SpreadsheetQuestions;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Other systems' files (Excel, CSV, Aiken, GIFT): read into the same preview
 * as a Word exam, only ever adding, and a quiz written out in those formats
 * comes back in as the same questions.
 *
 * @internal
 */
final class QuestionImportFormatsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionImportService $import;
    private QuestionExportService $export;
    private string $storeDirectory;
    private int $quiz;
    private User $instructor;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeDirectory = sys_get_temp_dir() . '/qf-test-' . bin2hex(random_bytes(4));
        $this->import = new QuestionImportService(new ImportPreviewStore($this->storeDirectory));
        $this->export = new QuestionExportService();

        $offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        foreach (glob($this->storeDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->storeDirectory);

        parent::tearDown();
    }

    private function file(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/qf-' . bin2hex(random_bytes(4)) . '.' . $extension;
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }

    /** @return list<array<string, mixed>> */
    private function questions(): array
    {
        return (new AssessmentQuestionModel())->listForAssessment($this->quiz);
    }

    private function seedQuiz(): void
    {
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true, false], 2);
        $short = AssessmentFactory::shortAnswer($this->quiz, $this->instructor, ['CPU', 'Central Processing Unit'], 1, false);
        $essay = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 5);
        $db = db_connect();
        $db->table('assessment_questions')->where('id', $mc['question'])->update(['question_text' => 'Which layer routes packets?']);
        $db->table('assessment_questions')->where('id', $short)->update(['question_text' => 'What does CPU stand for?']);
        $db->table('assessment_questions')->where('id', $essay)->update(['question_text' => 'Discuss routing.']);
    }

    public function testACsvIsPreviewedAndAddsQuestionsAtTheEnd(): void
    {
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Already here']);

        $path = $this->file('csv', "Type,Question,A,B,Answer,Points\nMC,Which is even?,3,4,B,2\nShort Answer,Capital of France?,,,Paris,1\n");
        $preview = $this->import->previewFile($this->instructor, $this->quiz, $path, 'csv');

        $this->assertTrue($preview['success']);
        $this->assertSame(2, $preview['counts']['added']);
        $this->assertSame(0, $preview['counts']['removed'], 'a spreadsheet only adds');

        $applied = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertTrue($applied['success']);
        $this->assertSame(['Already here', 'Which is even?', 'Capital of France?'], array_column($this->questions(), 'question_text'));
    }

    public function testRowsThatCannotBeUsedAreListedWithTheirLineAndTheGoodOnesStillImport(): void
    {
        $path = $this->file('csv', "Type,Question,A,B,Answer\nMC,Fine,x,y,A\nRiddle,Odd one,,,\n");
        $preview = $this->import->previewFile($this->instructor, $this->quiz, $path, 'csv');

        $this->assertTrue($preview['success']);
        $this->assertSame(1, $preview['counts']['added']);
        $this->assertSame(1, $preview['counts']['problems']);

        $problem = array_values(array_filter($preview['problems'], static fn (array $p): bool => $p['line'] === 3));

        $this->assertCount(1, $problem);
        $this->assertStringContainsString('Riddle', $problem[0]['message']);
    }

    public function testAnAikenFileImports(): void
    {
        $path = $this->file('txt', "Which layer routes packets?\nA. Transport\nB. Network\nANSWER: B\n");
        $preview = $this->import->previewFile($this->instructor, $this->quiz, $path, 'txt');

        $this->assertTrue($preview['success']);

        $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $questions = $this->questions();

        $this->assertCount(1, $questions);
        $this->assertSame([0, 1], array_map(static fn (array $c): int => (int) $c['is_correct'], $questions[0]['choices']));
    }

    public function testAGiftFileImportsAndNamesTheLineOfWhatItCannotHold(): void
    {
        $path = $this->file('txt', "What does CPU stand for? {=CPU =Central Processing Unit}\n\nMatch. {\n=a -> b\n}\n");
        $preview = $this->import->previewFile($this->instructor, $this->quiz, $path, 'txt');

        $this->assertTrue($preview['success']);
        $this->assertSame(1, $preview['counts']['added']);
        $this->assertSame(1, $preview['counts']['problems']);
        $this->assertSame(3, array_values(array_filter($preview['problems'], static fn (array $p): bool => $p['severity'] === 'error'))[0]['line']);
    }

    public function testATextFileInNeitherFormatIsRefusedInPlainWords(): void
    {
        $preview = $this->import->previewFile($this->instructor, $this->quiz, $this->file('txt', "Meeting notes\nbuy chalk\n"), 'txt');

        $this->assertFalse($preview['success']);
        $this->assertStringContainsString('Aiken or GIFT', $preview['message']);
    }

    public function testASpreadsheetWithoutAHeaderIsRefusedAndPointsToTheTemplate(): void
    {
        $preview = $this->import->previewFile($this->instructor, $this->quiz, $this->file('csv', "a,b\n1,2\n"), 'csv');

        $this->assertFalse($preview['success']);
        $this->assertStringContainsString('template', $preview['message']);
    }

    public function testAnExcelWorkbookRoundTrips(): void
    {
        $this->seedQuiz();

        $result = $this->export->exportFormat($this->quiz, 'xlsx');
        $this->assertTrue($result['success']);
        $this->files[] = $result['file_path'];

        $offering = CourseFactory::offering();
        $other = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));

        $preview = $this->import->previewFile($this->instructor, $other, $result['file_path'], 'xlsx');

        $this->assertTrue($preview['success']);
        $this->assertSame(3, $preview['counts']['added']);
        $this->assertSame(0, $preview['counts']['problems']);

        $this->import->applyPreview($this->instructor, $other, $preview['token']);

        $copied = (new AssessmentQuestionModel())->listForAssessment($other);

        $this->assertSame(['Which layer routes packets?', 'What does CPU stand for?', 'Discuss routing.'], array_column($copied, 'question_text'));
        $this->assertSame([0, 1, 0], array_map(static fn (array $c): int => (int) $c['is_correct'], $copied[0]['choices']));
        $this->assertSame(['CPU', 'Central Processing Unit'], $copied[1]['accepted_answers']);
        $this->assertEquals(5, $copied[2]['points']);
    }

    public function testGiftAndAikenExportsAreReadBackByTheImport(): void
    {
        $this->seedQuiz();

        $gift = $this->export->exportFormat($this->quiz, 'gift');
        $this->assertTrue($gift['success']);
        $this->assertSame('assessment-' . $this->quiz . '-gift.txt', $gift['filename']);
        $this->files[] = $gift['file_path'];

        $preview = $this->import->previewFile($this->instructor, $this->quiz, $gift['file_path'], 'txt');

        $this->assertSame(3, $preview['counts']['added']);

        $aiken = $this->export->exportFormat($this->quiz, 'aiken');
        $this->files[] = $aiken['file_path'];

        $this->assertSame(1, substr_count((string) file_get_contents($aiken['file_path']), 'ANSWER:'));
    }

    public function testAnExportWithNothingWritableSaysSo(): void
    {
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1);

        $result = $this->export->exportFormat($this->quiz, 'aiken');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Aiken', $result['message']);
    }

    public function testTheTemplateIsAnExcelFileThatImportsCleanly(): void
    {
        $result = $this->export->exportFormat($this->quiz, 'template-xlsx');

        $this->assertTrue($result['success']);
        $this->assertSame('quiz-questions-template.xlsx', $result['filename']);
        $this->files[] = $result['file_path'];

        $preview = $this->import->previewFile($this->instructor, $this->quiz, $result['file_path'], 'xlsx');

        $this->assertSame(count(SpreadsheetQuestions::templateRows()) - 1, $preview['counts']['added']);
        $this->assertSame(0, $preview['counts']['problems']);
    }
}
