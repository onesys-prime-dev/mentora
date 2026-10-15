<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\ImportPreviewStore;
use App\Services\Courses\Assessments\QuestionImportService;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Bringing in an ordinary exam: a Word file or pasted text in the layout
 * teachers already use, read into a preview that can only add questions, with
 * each question open to correction before it is imported.
 *
 * @internal
 */
final class QuestionImportPlainDocumentTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionImportService $import;
    private string $storeDirectory;
    private int $quiz;
    private User $instructor;

    /** @var list<string> */
    private array $files = [];

    private const EXAM = [
        '1. Which layer routes packets between networks? (2 pts)',
        'a) Transport',
        'b) Network',
        'c) Data link',
        'Answer: b',
        '',
        '2. Select the transport protocols.',
        'a) TCP',
        'b) IP',
        'c) UDP',
        'Answer: a, c',
        '',
        '3. What does CPU stand for?',
        'Answer: Central Processing Unit',
        '',
        '4. Explain why networks are layered.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeDirectory = sys_get_temp_dir() . '/qp-test-' . bin2hex(random_bytes(4));
        $this->import = new QuestionImportService(new ImportPreviewStore($this->storeDirectory));

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

    /** @param list<string|array{0: string, 1: bool}> $lines text, or [text, bold] */
    private function docx(array $lines): string
    {
        $word = new PhpWord();
        $section = $word->addSection();

        foreach ($lines as $line) {
            [$text, $bold] = is_array($line) ? $line : [$line, false];
            $section->addText($text, ['bold' => $bold]);
        }

        $path = sys_get_temp_dir() . '/qp-' . bin2hex(random_bytes(4)) . '.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $this->files[] = $path;
    }

    /** @return list<array<string, mixed>> */
    private function questions(): array
    {
        return db_connect()->table('assessment_questions')->where('assessment_id', $this->quiz)->orderBy('order_index')->get()->getResultArray();
    }

    private function text(array $lines): string
    {
        return implode("\n", $lines);
    }

    public function testAnOrdinaryExamIsReadAsNewQuestionsOfTheRightTypes(): void
    {
        $result = $this->import->previewFile($this->instructor, $this->quiz, $this->docx(self::EXAM), 'docx');

        $this->assertTrue($result['success']);
        $this->assertSame('plain', $result['source']);
        $this->assertSame([], $result['diff']['changed']);
        $this->assertSame([], $result['diff']['removed']);

        $types = array_column($result['diff']['added'], 'question_type');
        $this->assertSame(['Multiple Choice', 'Multiple Choice', 'Short Answer', 'Essay'], $types);
        $this->assertSame(2.0, $result['diff']['added'][0]['points']);
        $this->assertTrue($result['diff']['added'][1]['allow_multiple_answers']);
        $this->assertSame(['Central Processing Unit'], $result['diff']['added'][2]['accepted_answers']);
        $this->assertSame(0, $result['counts']['problems']);
    }

    public function testApplyingAddsThemAndLeavesTheExistingQuestionsAlone(): void
    {
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Already here']);

        $preview = $this->import->previewFile($this->instructor, $this->quiz, $this->docx(self::EXAM), 'docx');
        $result = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertSame('4 added, 0 updated, 0 removed.', $result['message']);
        $this->assertCount(5, $this->questions());
        $this->assertSame('Already here', $this->questions()[0]['question_text'], 'the existing question stays first');

        $multi = db_connect()->table('assessment_questions')->where('question_text', 'Select the transport protocols.')->get()->getRowArray();
        $this->assertSame(2, db_connect()->table('assessment_question_choices')->where('question_id', (int) $multi['id'])->where('is_correct', 1)->countAllResults());
    }

    public function testPastedTextIsReadTheSameWayAsAFile(): void
    {
        $result = $this->import->previewText($this->instructor, $this->quiz, implode("\r\n", self::EXAM));

        $this->assertTrue($result['success']);
        $this->assertCount(4, $result['diff']['added']);
        $this->assertSame('plain', $result['source']);
    }

    public function testAnAnswerKeyAtTheEndOfTheDocumentIsUsed(): void
    {
        $result = $this->import->previewText($this->instructor, $this->quiz, $this->text([
            '1. Pick one', 'a) x', 'b) y', '2. Pick another', 'a) p', 'b) q', 'Answer Key', '1. b', '2. a',
        ]));

        $first = $result['diff']['added'][0]['choices'];
        $second = $result['diff']['added'][1]['choices'];

        $this->assertFalse($first[0]['is_correct']);
        $this->assertTrue($first[1]['is_correct']);
        $this->assertTrue($second[0]['is_correct']);
    }

    public function testBoldChoicesInTheWordFileMarkTheAnswer(): void
    {
        $path = $this->docx(['1. Pick one', 'a) x', ['b) y', true], 'c) z']);

        $result = $this->import->previewFile($this->instructor, $this->quiz, $path, 'docx');
        $question = $result['diff']['added'][0];

        $this->assertSame([false, true, false], array_column($question['choices'], 'is_correct'));
        $this->assertStringContainsString('bold', implode(' ', array_column($question['problems'], 'message')));
    }

    public function testAQuestionWithNoAnswerCanBeCorrectedInTheReviewThenImported(): void
    {
        $lines = ['1. Which?', 'a) x', 'b) y', 'c) z'];

        $preview = $this->import->previewText($this->instructor, $this->quiz, $this->text($lines));

        $this->assertFalse($preview['diff']['added'][0]['importable'], 'no correct answer is marked');

        $skipped = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);
        $this->assertSame(1, $skipped['counts']['skipped']);
        $this->assertCount(0, $this->questions());

        // The same preview, corrected: choice 3 is right and it is worth 3 points.
        $preview = $this->import->previewText($this->instructor, $this->quiz, $this->text($lines));
        $result = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], [], [], false, [
            'a0' => ['correct' => [2], 'points' => 3],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['counts']['added']);
        $this->assertEquals(3, $this->questions()[0]['points']);

        $correct = db_connect()->table('assessment_question_choices')
            ->where('question_id', (int) $this->questions()[0]['id'])->where('is_correct', 1)->get()->getResultArray();
        $this->assertSame(['z'], array_column($correct, 'choice_text'));
    }

    public function testChangingTheTypeInTheReviewIsCheckedAgain(): void
    {
        $lines = ['1. Which?', 'a) x', 'b) y', 'Answer: a'];

        $preview = $this->import->previewText($this->instructor, $this->quiz, $this->text($lines));
        $essay = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], [], [], false, [
            'a0' => ['question_type' => 'Essay'],
        ]);

        $this->assertSame(1, $essay['counts']['added']);
        $this->assertSame('Essay', $this->questions()[0]['question_type']);
        $this->assertSame(0, db_connect()->table('assessment_question_choices')->where('question_id', (int) $this->questions()[0]['id'])->countAllResults());

        $preview = $this->import->previewText($this->instructor, $this->quiz, $this->text($lines));
        $bad = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], [], [], false, [
            'a0' => ['question_type' => 'Short Answer'],
        ]);

        $this->assertSame(1, $bad['counts']['skipped'], 'a short answer with no accepted answer is not imported');
    }

    public function testAnUnknownTypeInAnOverrideIsIgnored(): void
    {
        $preview = $this->import->previewText($this->instructor, $this->quiz, $this->text(['1. Which?', 'a) x', 'b) y', 'Answer: a']));

        $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], [], [], false, [
            'a0' => ['question_type' => 'Hacked', 'points' => 'many'],
        ]);

        $this->assertSame('Multiple Choice', $this->questions()[0]['question_type']);
    }

    public function testADuplicateOfAnExistingQuestionIsFlaggedButStillImportable(): void
    {
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Which layer routes packets between networks?']);

        $result = $this->import->previewFile($this->instructor, $this->quiz, $this->docx(self::EXAM), 'docx');
        $first = $result['diff']['added'][0];

        $this->assertTrue($first['importable']);
        $this->assertStringContainsString('already in the quiz', implode(' ', array_column($first['problems'], 'message')));
    }

    public function testADocumentWithNothingRecognisableGivesTheLayoutGuide(): void
    {
        $result = $this->import->previewText($this->instructor, $this->quiz, "Some notes\nNothing numbered here");

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"1." or "Q1:"', $result['message']);
    }

    public function testAMentoraExportIsStillReadAsAMentoraExport(): void
    {
        $q = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 2, ['question_text' => 'Original']);

        $result = $this->import->previewText($this->instructor, $this->quiz, $this->text([
            'Q1. Edited',
            "[machine-readable — do not edit] id={$q}; type=Essay; points=2; required=yes; correct=",
        ]));

        $this->assertSame('mentora', $result['source']);
        $this->assertCount(1, $result['diff']['changed']);
    }

    public function testTheSampleDocumentIsAValidWordFileThatImportsCleanly(): void
    {
        $path = sys_get_temp_dir() . '/qp-sample-' . bin2hex(random_bytes(3)) . '.docx';
        file_put_contents($path, $this->import->sampleDocument());
        $this->files[] = $path;

        $result = $this->import->previewFile($this->instructor, $this->quiz, $path, 'docx');

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertCount(5, $result['diff']['added']);
        $this->assertSame(0, $result['counts']['problems']);
        $this->assertSame(
            ['Multiple Choice', 'Multiple Choice', 'Multiple Choice', 'Short Answer', 'Essay'],
            array_column($result['diff']['added'], 'question_type')
        );
    }
}
