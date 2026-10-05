<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuestionExportService;
use App\Services\Courses\Assessments\QuestionImportService;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Taking a quiz's questions out to a Word or RTF file and back in: what the
 * export writes (the visible lines and the machine-readable line), reading a
 * file back as a preview of added, changed and removed questions, falling
 * back to AI generation for a file that isn't one of our own exports, and
 * applying a confirmed preview.
 *
 * @internal
 */
final class QuestionImportExportTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionExportService $export;
    private QuestionImportService $import;
    private int $offering;
    private int $quiz;
    private User $instructor;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->export = new QuestionExportService();
        $this->import = new QuestionImportService();
        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering));
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['title' => 'Capitals quiz']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /** A quiz of a multiple choice, a short answer and an essay. @return array{mc: array, short: int, essay: int} */
    private function questions(?int $quiz = null): array
    {
        $quiz ??= $this->quiz;
        $mc = AssessmentFactory::multipleChoice($quiz, $this->instructor, [false, true, true], 4);
        $short = AssessmentFactory::shortAnswer($quiz, $this->instructor, ['CPU', 'Central Processing Unit'], 2, true);
        $essay = AssessmentFactory::question($quiz, $this->instructor, 'Essay', 6, ['is_required' => 0]);
        db_connect()->table('assessment_questions')->where('id', $mc['question'])->update(['question_text' => 'Which are even?']);
        db_connect()->table('assessment_questions')->where('id', $short)->update(['question_text' => 'What does CPU stand for?']);
        db_connect()->table('assessment_questions')->where('id', $essay)->update(['question_text' => 'Discuss.']);

        return ['mc' => $mc, 'short' => $short, 'essay' => $essay];
    }

    private function exported(string $format = 'docx', ?int $quiz = null): array
    {
        $result = $format === 'rtf' ? $this->export->exportToRtf($quiz ?? $this->quiz) : $this->export->exportToDocx($quiz ?? $this->quiz);

        if ($result['success']) {
            $this->files[] = $result['file_path'];
        }

        return $result;
    }

    /** The visible lines of a .docx, one per paragraph. @return list<string> */
    private function lines(string $path): array
    {
        $lines = [];

        foreach (IOFactory::createReader('Word2007')->load($path)->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText') && is_string($element->getText())) {
                    $lines[] = $element->getText();
                }
            }
        }

        return $lines;
    }

    /** Builds a .docx from lines of text (what an instructor's edited file contains). */
    private function docx(array $lines): string
    {
        $word = new PhpWord();
        $section = $word->addSection();

        foreach ($lines as $line) {
            $section->addText($line);
        }

        $path = WRITEPATH . 'uploads/exports/test-' . uniqid() . '.docx';
        @mkdir(dirname($path), 0777, true);
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $this->files[] = $path;

        return $path;
    }

    private function meta(int|string $id, string $type, string $points, string $required = 'yes', string $correct = '', string $case = ''): string
    {
        return "[machine-readable — do not edit] id={$id}; type={$type}; points={$points}; required={$required}; correct={$correct}" . ($case !== '' ? "; case={$case}" : '');
    }

    private function parse(array $lines, ?int $quiz = null): array
    {
        return $this->import->parseDocx($quiz ?? $this->quiz, $this->docx($lines));
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    public function testTheDocxHasATitleNumberedQuestionsLettersedChoicesAndAMachineReadableLine(): void
    {
        $q = $this->questions();

        $result = $this->exported();
        $lines = $this->lines($result['file_path']);

        $this->assertTrue($result['success']);
        $this->assertMatchesRegularExpression('/^assessment-' . $this->quiz . '-\d{8}-\d{6}\.docx$/', $result['filename']);
        $this->assertSame('Capitals quiz', $lines[0]);
        $this->assertStringStartsWith('Exported ', $lines[1]);
        $this->assertContains('Q1. Which are even?', $lines);
        $this->assertContains('a) Choice 1', $lines);
        $this->assertContains('b) Choice 2', $lines);
        $this->assertContains('c) Choice 3', $lines);
        $this->assertContains('Q2. What does CPU stand for?', $lines);
        $this->assertContains('Accepted answers: CPU | Central Processing Unit', $lines);
        $this->assertContains('Q3. Discuss.', $lines);
        $this->assertContains("[machine-readable — do not edit] id={$q['mc']['question']}; type=Multiple Choice; points=4; required=yes; correct=b,c", $lines);
        $this->assertContains("[machine-readable — do not edit] id={$q['short']}; type=Short Answer; points=2; required=yes; correct=; case=yes", $lines);
        $this->assertContains("[machine-readable — do not edit] id={$q['essay']}; type=Essay; points=6; required=no; correct=", $lines);
    }

    public function testFractionalPointsAreWrittenWithoutTrailingZeros(): void
    {
        $essay = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 2.5);

        $lines = $this->lines($this->exported()['file_path']);

        $this->assertContains("[machine-readable — do not edit] id={$essay}; type=Essay; points=2.5; required=yes; correct=", $lines);
    }

    public function testAnEmptyQuizSaysSo(): void
    {
        $lines = $this->lines($this->exported()['file_path']);

        $this->assertContains('This assessment has no questions yet.', $lines);
    }

    public function testArchivedQuestionsAreNotExported(): void
    {
        $q = $this->questions();
        db_connect()->table('assessment_questions')->where('id', $q['essay'])->update(['status' => 'Archived']);

        $lines = $this->lines($this->exported()['file_path']);

        $this->assertNotContains('Q3. Discuss.', $lines);
        $this->assertCount(2, array_filter($lines, static fn ($l) => str_starts_with($l, '[machine-readable')));
    }

    public function testTheRtfExportIsAnRtfFileWithTheQuestions(): void
    {
        $this->questions();

        $result = $this->exported('rtf');

        $this->assertTrue($result['success']);
        $this->assertStringEndsWith('.rtf', $result['filename']);
        $body = (string) file_get_contents($result['file_path']);
        $this->assertStringStartsWith('{\rtf', $body);
        $this->assertStringContainsString('Which are even?', $body);
    }

    public function testExportingAMissingAssessmentIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->export->exportToDocx(999999));
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->export->exportToRtf(999999));
    }

    /*
    |--------------------------------------------------------------------------
    | Reading a file back (preview)
    |--------------------------------------------------------------------------
    */

    public function testReImportingAnUntouchedExportShowsNothingToChange(): void
    {
        $this->questions();

        $result = $this->import->parseDocx($this->quiz, $this->exported()['file_path']);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['needs_ai_fallback']);
        $this->assertSame(['added' => [], 'changed' => [], 'removed' => []], $result['diff']);
    }

    public function testEditedTextPointsAndChoicesAreReportedAsChanged(): void
    {
        $q = $this->questions();
        $lines = $this->lines($this->exported()['file_path']);
        $lines = array_map(static fn ($l) => match (true) {
            $l === 'Q1. Which are even?' => 'Q1. Which of these are even numbers?',
            $l === 'b) Choice 2' => 'b) Two',
            str_starts_with($l, "[machine-readable — do not edit] id={$q['mc']['question']};") => str_replace('points=4', 'points=5', $l),
            default => $l,
        }, $lines);

        $diff = $this->parse($lines)['diff'];

        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['changed']);
        $changed = $diff['changed'][0];
        $this->assertSame($q['mc']['question'], $changed['id']);
        $this->assertSame('Which of these are even numbers?', $changed['question_text']);
        $this->assertSame('Which are even?', $changed['previous_text']);
        $this->assertSame(5.0, $changed['points']);
        $this->assertSame(['Choice 1', 'Two', 'Choice 3'], array_column($changed['choices'], 'text'));
        $this->assertSame([false, true, true], array_column($changed['choices'], 'is_correct'));
    }

    public function testChangingWhichChoicesAreCorrectIsSeen(): void
    {
        $q = $this->questions();
        $lines = array_map(
            static fn ($l) => str_starts_with($l, "[machine-readable — do not edit] id={$q['mc']['question']};") ? str_replace('correct=b,c', 'correct=a', $l) : $l,
            $this->lines($this->exported()['file_path'])
        );

        $changed = $this->parse($lines)['diff']['changed'][0];

        $this->assertSame([true, false, false], array_column($changed['choices'], 'is_correct'));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function singleEdits(): iterable
    {
        yield 'only the points' => ['points=4', 'points=9', 'points'];
        yield 'only the required flag' => ['required=yes', 'required=no', 'required'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('singleEdits')]
    public function testChangingJustOneSettingOfAMultipleChoiceIsEnoughToShowAsChanged(string $from, string $to): void
    {
        $q = $this->questions();
        $lines = array_map(
            static fn ($l) => str_starts_with($l, "[machine-readable — do not edit] id={$q['mc']['question']};") ? str_replace($from, $to, $l) : $l,
            $this->lines($this->exported()['file_path'])
        );

        $diff = $this->parse($lines)['diff'];

        $this->assertSame([$q['mc']['question']], array_column($diff['changed'], 'id'));
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
    }

    public function testChangingJustTheCaseSettingOfAShortAnswerShowsAsChanged(): void
    {
        $q = $this->questions();
        $lines = array_map(
            static fn ($l) => str_starts_with($l, "[machine-readable — do not edit] id={$q['short']};") ? str_replace('case=yes', 'case=no', $l) : $l,
            $this->lines($this->exported()['file_path'])
        );

        $diff = $this->parse($lines)['diff'];

        $this->assertSame([$q['short']], array_column($diff['changed'], 'id'));
    }

    public function testChangingJustTheQuestionTypeShowsAsChanged(): void
    {
        $q = $this->questions();
        $lines = array_map(
            static fn ($l) => str_starts_with($l, "[machine-readable — do not edit] id={$q['essay']};") ? str_replace('type=Essay', 'type=File Upload', $l) : $l,
            $this->lines($this->exported()['file_path'])
        );

        $changed = $this->parse($lines)['diff']['changed'];

        $this->assertSame([$q['essay']], array_column($changed, 'id'));
        $this->assertSame('File Upload', $changed[0]['question_type']);
    }

    public function testAShortAnswerKeyAndItsCaseSettingAreReadBack(): void
    {
        $q = $this->questions();
        $lines = array_map(static fn ($l) => match (true) {
            $l === 'Accepted answers: CPU | Central Processing Unit' => 'Accepted answers:  cpu |  Processor | cpu ',
            str_starts_with($l, "[machine-readable — do not edit] id={$q['short']};") => str_replace('case=yes', 'case=no', $l),
            default => $l,
        }, $this->lines($this->exported()['file_path']));

        $changed = $this->parse($lines)['diff']['changed'][0];

        $this->assertSame($q['short'], $changed['id']);
        $this->assertSame(['cpu', 'Processor'], $changed['accepted_answers'], 'trimmed, duplicates dropped');
        $this->assertFalse($changed['case_sensitive']);
    }

    public function testAQuestionLeftOutOfTheFileIsListedAsRemoved(): void
    {
        $q = $this->questions();
        $lines = $this->lines($this->exported()['file_path']);
        $keep = array_values(array_filter($lines, static fn ($l) => ! in_array($l, ['Q3. Discuss.'], true) && ! str_contains($l, "id={$q['essay']};")));

        $diff = $this->parse($keep)['diff'];

        $this->assertSame([['id' => $q['essay'], 'question_text' => 'Discuss.']], $diff['removed']);
        $this->assertSame([], $diff['changed']);
    }

    public function testANewQuestionWithANewIdIsAdded(): void
    {
        $this->questions();
        $lines = $this->lines($this->exported()['file_path']);
        array_push($lines, 'Q4. Name a prime number.', 'a) 4', 'b) 7', $this->meta('new', 'Multiple Choice', '1', 'yes', 'b'));

        $diff = $this->parse($lines)['diff'];

        $this->assertCount(1, $diff['added']);
        $added = $diff['added'][0];
        $this->assertSame('Name a prime number.', $added['question_text']);
        $this->assertSame('Multiple Choice', $added['question_type']);
        $this->assertSame(1.0, $added['points']);
        $this->assertSame([['text' => '4', 'is_correct' => false], ['text' => '7', 'is_correct' => true]], $added['choices']);
        $this->assertSame([], $diff['changed']);
        $this->assertSame([], $diff['removed']);
    }

    public function testAHandTypedQuestionAtTheEndWithoutAMachineLineIsAdded(): void
    {
        $this->questions();
        $lines = $this->lines($this->exported()['file_path']);
        array_push($lines, 'Q4. Typed by hand', 'a) Yes', 'b) No');

        $diff = $this->parse($lines)['diff'];

        $this->assertCount(1, $diff['added']);
        $this->assertSame('Typed by hand', $diff['added'][0]['question_text']);
        $this->assertSame('Multiple Choice', $diff['added'][0]['question_type'], 'choices make it multiple choice');
        $this->assertSame(0, $diff['added'][0]['points']);
    }

    public function testATrailingLineThatIsNotAQuestionIsIgnored(): void
    {
        $this->questions();
        $lines = $this->lines($this->exported()['file_path']);
        $lines[] = 'Some stray note at the end';

        $this->assertSame(['added' => [], 'changed' => [], 'removed' => []], $this->parse($lines)['diff']);
    }

    public function testAQuestionWithAnUnknownIdIsTreatedAsNew(): void
    {
        $this->questions();
        $otherQuiz = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering, 'published', 2));
        $foreign = AssessmentFactory::question($otherQuiz, $this->instructor, 'Essay', 3);

        $diff = $this->parse(['Q1. From another quiz', $this->meta($foreign, 'Essay', '3')], $this->quiz)['diff'];

        $this->assertCount(1, $diff['added'], "another quiz's id never matches this quiz's questions");
        $this->assertSame([], $diff['changed']);
    }

    public function testAMissingMachineLineBetweenTwoQuestionsDoesNotMergeThem(): void
    {
        $diff = $this->parse([
            'Q1. First without its line',
            'Q2. Second',
            $this->meta('new', 'Essay', '2'),
        ])['diff'];

        $this->assertSame(['First without its line', 'Second'], array_column($diff['added'], 'question_text'));
    }

    public function testTheHeadingPrefixMayBeLeftOutOfAQuestion(): void
    {
        $diff = $this->parse(['Plain question text', $this->meta('new', 'Essay', '2')])['diff'];

        $this->assertSame('Plain question text', $diff['added'][0]['question_text']);
    }

    public function testAFileThatIsNotOneOfOurExportsFallsBackToAiGeneration(): void
    {
        $result = $this->parse(['Chapter 3 notes', 'Photosynthesis turns light into sugar.', '']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['needs_ai_fallback']);
        $this->assertStringContainsString('Photosynthesis turns light into sugar.', $result['extracted_text']);
        $this->assertArrayNotHasKey('diff', $result);
    }

    public function testAMissingAssessmentIsRefusedWhenReading(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->import->parseDocx(999999, $this->docx(['x'])));
    }

    public function testPreviewingNeverWritesToTheDatabase(): void
    {
        $q = $this->questions();
        $before = db_connect()->table('assessment_questions')->orderBy('id')->get()->getResultArray();
        $lines = array_map(static fn ($l) => $l === 'Q3. Discuss.' ? 'Q3. Changed' : $l, $this->lines($this->exported()['file_path']));
        $lines[] = 'Q9. Brand new';

        $this->parse($lines);

        $this->assertSame($before, db_connect()->table('assessment_questions')->orderBy('id')->get()->getResultArray());
        $this->assertNotEmpty($q);
    }

    /*
    |--------------------------------------------------------------------------
    | Applying a confirmed preview
    |--------------------------------------------------------------------------
    */

    public function testApplyingAddsChangesAndRemovesAndSaysHowMany(): void
    {
        $q = $this->questions();

        $result = $this->import->applyDiff($this->instructor, $this->quiz, [
            'added' => [['question_text' => 'New one', 'question_type' => 'Essay', 'points' => 3, 'is_required' => true]],
            'changed' => [['id' => $q['essay'], 'question_text' => 'Discuss at length.', 'points' => 8]],
            'remove_ids' => [$q['short']],
        ]);

        $this->assertSame(['success' => true, 'message' => '1 added, 1 updated, 1 removed.'], $result);
        $texts = array_column(db_connect()->table('assessment_questions')->where('assessment_id', $this->quiz)->orderBy('order_index')->get()->getResultArray(), 'question_text');
        $this->assertSame(['Which are even?', 'Discuss at length.', 'New one'], $texts);
        $this->assertSame(15.0, (float) db_connect()->table('assessments')->where('id', $this->quiz)->get()->getRowArray()['total_points'], '4 + 8 + 3 (the quiz total follows)');
    }

    public function testApplyingChangedChoicesAndAnswerKeys(): void
    {
        $q = $this->questions();

        $this->import->applyDiff($this->instructor, $this->quiz, ['changed' => [
            ['id' => $q['mc']['question'], 'choices' => [['text' => 'Only', 'is_correct' => true]]],
            ['id' => $q['short'], 'accepted_answers' => ['processor'], 'case_sensitive' => false],
        ]]);

        $model = new App\Models\Assessments\AssessmentQuestionModel();
        $this->assertSame(['Only'], array_column($model->getWithChoices($q['mc']['question'])['choices'], 'choice_text'));
        $short = $model->getWithChoices($q['short']);
        $this->assertSame(['processor'], $short['accepted_answers']);
        $this->assertSame(0, (int) $short['case_sensitive']);
    }

    public function testApplyingNothingChangesNothing(): void
    {
        $this->questions();

        $this->assertSame('0 added, 0 updated, 0 removed.', $this->import->applyDiff($this->instructor, $this->quiz, [])['message']);
        $this->assertSame(3, db_connect()->table('assessment_questions')->where('assessment_id', $this->quiz)->countAllResults());
    }

    public function testAChangedEntryWithoutAnIdIsSkipped(): void
    {
        $this->questions();

        $result = $this->import->applyDiff($this->instructor, $this->quiz, ['changed' => [['question_text' => 'No id']]]);

        $this->assertSame('0 added, 0 updated, 0 removed.', $result['message']);
    }

    public function testApplyingCanNeverTouchAnotherQuizsQuestions(): void
    {
        $this->questions();
        $otherQuiz = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering, 'published', 2));
        $foreign = AssessmentFactory::question($otherQuiz, $this->instructor, 'Essay', 3);
        db_connect()->table('assessment_questions')->where('id', $foreign)->update(['question_text' => 'Not yours']);

        $result = $this->import->applyDiff($this->instructor, $this->quiz, [
            'changed' => [['id' => $foreign, 'question_text' => 'HACKED', 'points' => 99]],
            'remove_ids' => [$foreign],
        ]);

        $this->assertSame('0 added, 0 updated, 0 removed.', $result['message']);
        $row = db_connect()->table('assessment_questions')->where('id', $foreign)->get()->getRowArray();
        $this->assertNotNull($row, 'not deleted');
        $this->assertSame('Not yours', $row['question_text']);
        $this->assertEquals(3, $row['points']);
    }

    public function testAnExportEditedAndAppliedRoundTripsTheQuiz(): void
    {
        $q = $this->questions();
        $lines = array_map(static fn ($l) => $l === 'Q3. Discuss.' ? 'Q3. Discuss in detail.' : $l, $this->lines($this->exported()['file_path']));
        $diff = $this->parse($lines)['diff'];

        $result = $this->import->applyDiff($this->instructor, $this->quiz, ['added' => $diff['added'], 'changed' => $diff['changed'], 'remove_ids' => array_column($diff['removed'], 'id')]);

        $this->assertSame('0 added, 1 updated, 0 removed.', $result['message']);
        $this->assertSame('Discuss in detail.', db_connect()->table('assessment_questions')->where('id', $q['essay'])->get()->getRowArray()['question_text']);
        $again = $this->import->parseDocx($this->quiz, $this->exported()['file_path']);
        $this->assertSame(['added' => [], 'changed' => [], 'removed' => []], $again['diff'], 'and the next export matches the quiz again');
    }
}
