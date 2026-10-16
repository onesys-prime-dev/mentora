<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\ImportPreviewStore;
use App\Services\Courses\Assessments\QuestionExportService;
use App\Services\Courses\Assessments\QuestionImportService;
use App\Services\Courses\Assessments\QuestionImportValidator;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Importing a quiz file safely: the preview is held by the server and applied
 * from there, problems are listed per question, the apply is one transaction
 * that can be undone, and a damaged or oversized file is a sentence, not an
 * error page.
 *
 * @internal
 */
final class QuestionImportHardeningTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionImportService $import;
    private QuestionExportService $export;
    private ImportPreviewStore $store;
    private string $storeDirectory;
    private int $offering;
    private int $quiz;
    private User $instructor;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeDirectory = sys_get_temp_dir() . '/qi-test-' . bin2hex(random_bytes(4));
        $this->store = new ImportPreviewStore($this->storeDirectory);
        $this->import = new QuestionImportService($this->store);
        $this->export = new QuestionExportService();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering));
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

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** @param list<string> $lines */
    private function docx(array $lines): string
    {
        $word = new PhpWord();
        $section = $word->addSection();

        foreach ($lines as $line) {
            $section->addText($line);
        }

        return $this->save($word);
    }

    private function save(PhpWord $word, string $writer = 'Word2007', string $extension = 'docx'): string
    {
        $path = sys_get_temp_dir() . '/qi-' . bin2hex(random_bytes(4)) . '.' . $extension;
        IOFactory::createWriter($word, $writer)->save($path);

        return $this->files[] = $path;
    }

    private function meta(int|string $id, string $type, string $points = '1', string $required = 'yes', string $correct = '', string $extra = ''): string
    {
        return "[machine-readable — do not edit] id={$id}; type={$type}; points={$points}; required={$required}; correct={$correct}" . ($extra !== '' ? "; {$extra}" : '');
    }

    /** One good multiple-choice question as file lines. @return list<string> */
    private function mc(string $text, string|int $id = 'new', string $correct = 'b', string $extra = ''): array
    {
        return ["Q1. {$text}", 'a) One', 'b) Two', 'c) Three', $this->meta($id, 'Multiple Choice', '2', 'yes', $correct, $extra)];
    }

    private function preview(array $lines, ?int $quiz = null): array
    {
        return $this->import->previewFile($this->instructor, $quiz ?? $this->quiz, $this->docx($lines), 'docx');
    }

    private function questionCount(?int $quiz = null): int
    {
        return db_connect()->table('assessment_questions')->where('assessment_id', $quiz ?? $this->quiz)->countAllResults();
    }

    private function questionsOf(?int $quiz = null): array
    {
        return db_connect()->table('assessment_questions')->where('assessment_id', $quiz ?? $this->quiz)->orderBy('order_index')->get()->getResultArray();
    }

    /*
    |--------------------------------------------------------------------------
    | The validator
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badQuestions(): iterable
    {
        $good = ['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'points' => 1,
            'choices' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => false]]];

        yield 'no text' => [['question_text' => '  '] + $good, 'no text'];
        yield 'unknown type' => [['question_type' => 'Matching'] + $good, 'not supported'];
        yield 'discussion' => [['question_type' => 'Discussion'] + $good, 'Discussion questions cannot'];
        yield 'negative points' => [['points' => -2] + $good, 'points must be'];
        yield 'text points' => [['points' => 'lots'] + $good, 'points must be'];
        yield 'one choice' => [['choices' => [['text' => 'A', 'is_correct' => true]]] + $good, 'at least two choices'];
        yield 'no correct' => [['choices' => [['text' => 'A', 'is_correct' => false], ['text' => 'B', 'is_correct' => false]]] + $good, 'No correct answer'];
        yield 'no key' => [['question_type' => 'Short Answer', 'accepted_answers' => ['  ']] + $good, 'no accepted answer'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badQuestions')]
    public function testEachRuleCatchesItsProblemAsAnError(array $question, string $fragment): void
    {
        $problems = QuestionImportValidator::validate($question);
        $errors = array_filter($problems, static fn (array $p): bool => $p['severity'] === 'error');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($fragment, implode(' ', array_column($errors, 'message')));
        $this->assertFalse(QuestionImportValidator::importable($problems));
    }

    public function testAGoodQuestionHasNoProblemsAndWarningsDoNotBlock(): void
    {
        $good = ['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'points' => 1.5,
            'choices' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => false]]];

        $this->assertSame([], QuestionImportValidator::validate($good));

        $twins = ['choices' => [['text' => 'A', 'is_correct' => true], ['text' => 'a', 'is_correct' => false]]] + $good;
        $problems = QuestionImportValidator::validate($twins);

        $this->assertSame('warning', $problems[0]['severity']);
        $this->assertTrue(QuestionImportValidator::importable($problems));
    }

    /*
    |--------------------------------------------------------------------------
    | The preview store
    |--------------------------------------------------------------------------
    */

    public function testAPreviewBelongsToOnePersonAndOneQuizAndExpires(): void
    {
        $token = $this->store->put(7, 3, ['x' => 1], 1000);

        $this->assertSame(['x' => 1], $this->store->get($token, 7, 3, 1100)['payload']);
        $this->assertNull($this->store->get($token, 8, 3, 1100), 'someone else');
        $this->assertNull($this->store->get($token, 7, 4, 1100), 'another quiz');
        $this->assertNull($this->store->get($token, 7, 3, 1000 + ImportPreviewStore::PREVIEW_SECONDS + 1), 'expired');
        $this->assertNull($this->store->get('../../etc/passwd', 7, 3), 'not a token');
    }

    public function testAnAppliedImportIsKeptOnlyForTheUndoWindow(): void
    {
        $token = $this->store->put(7, 3, [], 1000);
        $entry = $this->store->get($token, 7, 3, 1000);
        $entry['applied_at'] = 2000;
        $this->store->save($token, $entry);

        $this->assertNotNull($this->store->get($token, 7, 3, 2000 + ImportPreviewStore::UNDO_SECONDS));
        $this->assertNull($this->store->get($token, 7, 3, 2000 + ImportPreviewStore::UNDO_SECONDS + 1));
    }

    /*
    |--------------------------------------------------------------------------
    | Previewing
    |--------------------------------------------------------------------------
    */

    public function testPreviewingReadsTheFileHoldsItAndWritesNothing(): void
    {
        $filesBefore = db_connect()->table('files')->countAllResults();

        $result = $this->preview($this->mc('Which is two?'));

        $this->assertTrue($result['success']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['token']);
        $this->assertCount(1, $result['diff']['added']);
        $this->assertSame('a0', $result['diff']['added'][0]['key']);
        $this->assertTrue($result['diff']['added'][0]['importable']);
        $this->assertSame(0, $this->questionCount());
        $this->assertSame($filesBefore, db_connect()->table('files')->countAllResults(), 'the upload is not kept');
    }

    public function testEachProblemIsListedWithTheParagraphItIsNear(): void
    {
        $lines = array_merge(
            $this->mc('Fine question'),
            ['Q2. Broken one', 'a) Only', $this->meta('new', 'Multiple Choice', '1', 'yes', 'a')]
        );

        $result = $this->preview($lines);

        $this->assertSame(1, $result['counts']['problems']);
        $problem = array_values(array_filter($result['problems'], static fn (array $p): bool => $p['severity'] === 'error'))[0];
        $this->assertSame(6, $problem['line'], 'the second question starts at paragraph 6');
        $this->assertStringContainsString('at least two choices', $problem['message']);
        $this->assertFalse($result['diff']['added'][1]['importable']);
        $this->assertTrue($result['diff']['added'][0]['importable']);
    }

    public function testAFileTheSameQuestionTwiceIsAProblemAndALikeQuestionIsFlagged(): void
    {
        $q = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Explain how routers forward packets across networks']);

        $lines = array_merge(
            ["Q1. Explain how routers forward packets across networks", $this->meta('new', 'Essay')],
            ["Q2. Mine", $this->meta($q, 'Essay')],
            ["Q3. Mine again", $this->meta($q, 'Essay')]
        );

        $result = $this->preview($lines);

        $messages = implode(' | ', array_column($result['problems'], 'message'));

        $this->assertStringContainsString('looks like a question already in the quiz', $messages);
        $this->assertStringContainsString('appears twice', $messages);
    }

    public function testTablesAndRichTextAreRead(): void
    {
        $word = new PhpWord();
        $section = $word->addSection();
        $table = $section->addTable();
        $table->addRow();
        $cell = $table->addCell(9000);

        foreach ($this->mc('From a table') as $line) {
            $cell->addText($line);
        }

        $path = $this->save($word);
        $result = $this->import->previewFile($this->instructor, $this->quiz, $path, 'docx');

        $this->assertTrue($result['success']);
        $this->assertSame('From a table', $result['diff']['added'][0]['question_text']);
        $this->assertCount(3, $result['diff']['added'][0]['choices']);
    }

    public function testAnRtfExportComesBackIn(): void
    {
        AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [true, false], 2);
        $exported = $this->export->exportToRtf($this->quiz);
        $this->files[] = $exported['file_path'];

        $result = $this->import->previewFile($this->instructor, $this->quiz, $exported['file_path'], 'rtf');

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame([], $result['diff']['changed']);
        $this->assertSame([], $result['diff']['added']);
    }

    public function testADamagedOrDisguisedFileIsASentenceNotAnError(): void
    {
        $fake = sys_get_temp_dir() . '/qi-fake-' . bin2hex(random_bytes(3)) . '.docx';
        file_put_contents($fake, 'this is just text pretending to be a Word file');
        $this->files[] = $fake;

        $result = $this->import->previewFile($this->instructor, $this->quiz, $fake, 'docx');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('could not be read', $result['message']);

        $this->assertFalse($this->import->previewFile($this->instructor, $this->quiz, $fake, 'exe')['success']);
    }

    public function testMoreQuestionsThanTheLimitAreRefused(): void
    {
        $lines = [];

        for ($i = 0; $i <= QuestionImportService::MAX_QUESTIONS; $i++) {
            $lines[] = "Q{$i}. Question {$i}";
            $lines[] = $this->meta('new', 'Essay');
        }

        $result = $this->preview($lines);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('more than ' . QuestionImportService::MAX_QUESTIONS, $result['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Applying
    |--------------------------------------------------------------------------
    */

    public function testApplyingTheStoredPreviewAddsWhatWasPreviewedAndReportsIt(): void
    {
        $preview = $this->preview(array_merge($this->mc('First'), $this->mc('Second')));

        $result = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertTrue($result['success']);
        $this->assertSame('2 added, 0 updated, 0 removed.', $result['message']);
        $this->assertSame(['First', 'Second'], array_column($this->questionsOf(), 'question_text'));
        $this->assertSame($preview['token'], $result['undo_token']);
    }

    public function testASkippedQuestionAndAQuestionWithAnErrorAreNotImportedAndAreReported(): void
    {
        $lines = array_merge(
            $this->mc('Keep me'),
            $this->mc('Skip me'),
            ['Q3. Broken', 'a) Only', $this->meta('new', 'Multiple Choice', '1', 'yes', 'a')]
        );
        $preview = $this->preview($lines);

        $result = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], ['a1']);

        $this->assertSame(['Keep me'], array_column($this->questionsOf(), 'question_text'));
        $this->assertSame(2, $result['counts']['skipped']);
        $reasons = array_column($result['skipped'], 'reason');
        $this->assertContains('You chose to skip it.', $reasons);
        $this->assertTrue((bool) array_filter($reasons, static fn (string $r): bool => str_contains($r, 'at least two choices')));
    }

    public function testTheOrderOfTheFileIsTheOrderOfTheQuiz(): void
    {
        $a = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Alpha']);
        $b = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Beta']);

        // The file lists Beta before Alpha, and a new question first.
        $lines = array_merge(
            ['Q1. Brand new', $this->meta('new', 'Essay')],
            ['Q2. Beta', $this->meta($b, 'Essay')],
            ['Q3. Alpha', $this->meta($a, 'Essay')]
        );
        $preview = $this->preview($lines);

        $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertSame(['Brand new', 'Beta', 'Alpha'], array_column($this->questionsOf(), 'question_text'));
    }

    public function testAMultipleAnswersQuestionKeepsItsSettingThroughExportAndImport(): void
    {
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [true, true, false], 3);
        db_connect()->table('assessment_questions')->where('id', $mc['question'])->update(['answers_count' => 2, 'question_text' => 'Pick two']);

        $exported = $this->export->exportToDocx($this->quiz);
        $this->files[] = $exported['file_path'];

        $other = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering));
        $preview = $this->import->previewFile($this->instructor, $other, $exported['file_path'], 'docx');
        $this->import->applyPreview($this->instructor, $other, $preview['token']);

        $copy = $this->questionsOf($other)[0];

        $this->assertSame('Pick two', $copy['question_text']);
        $this->assertSame(1, (int) $copy['allow_multiple_answers']);
        $this->assertSame(2, (int) $copy['answers_count']);
    }

    public function testAnOlderFileWithSeveralCorrectLettersIsStillAMultipleAnswersQuestion(): void
    {
        $preview = $this->preview($this->mc('Old file', 'new', 'a,b'));

        $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertSame(1, (int) $this->questionsOf()[0]['allow_multiple_answers']);
    }

    public function testAnotherPersonOrAnotherQuizCannotUseAToken(): void
    {
        $preview = $this->preview($this->mc('Mine'));
        $other = CourseFactory::user('instructor');
        $otherQuiz = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering));

        $this->assertFalse($this->import->applyPreview($other, $this->quiz, $preview['token'])['success']);
        $this->assertFalse($this->import->applyPreview($this->instructor, $otherQuiz, $preview['token'])['success']);
        $this->assertFalse($this->import->applyPreview($this->instructor, $this->quiz, str_repeat('0', 32))['success']);
        $this->assertSame(0, $this->questionCount());
    }

    public function testAnImportCannotBeAppliedTwice(): void
    {
        $preview = $this->preview($this->mc('Once'));

        $this->assertTrue($this->import->applyPreview($this->instructor, $this->quiz, $preview['token'])['success']);
        $this->assertFalse($this->import->applyPreview($this->instructor, $this->quiz, $preview['token'])['success']);
        $this->assertSame(1, $this->questionCount());
    }

    public function testOnlyTheMissingQuestionsTheTeacherTickedAreDeleted(): void
    {
        $a = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Keep']);
        $b = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Delete']);

        $preview = $this->preview(['Q1. Only one', $this->meta('new', 'Essay')]);

        $this->assertEqualsCanonicalizing([$a, $b], array_column($preview['diff']['removed'], 'id'));

        // An id that was not offered as missing is ignored.
        $result = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], [], [$b, 999999]);

        $this->assertSame(1, $result['counts']['removed']);
        $this->assertContains('Keep', array_column($this->questionsOf(), 'question_text'));
        $this->assertNotContains('Delete', array_column($this->questionsOf(), 'question_text'));
    }

    /*
    |--------------------------------------------------------------------------
    | A quiz students have already taken
    |--------------------------------------------------------------------------
    */

    private function answered(int $questionId): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $student);
        $attempt = AssessmentFactory::attempt($this->quiz, $student, 'Graded', 1.0);
        AssessmentFactory::response($attempt, $questionId, ['response_text' => 'x']);
    }

    public function testChangingOrRemovingAnAnsweredQuestionNeedsConfirmation(): void
    {
        $q = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Old wording']);
        $this->answered($q);

        $preview = $this->preview(['Q1. New wording', $this->meta($q, 'Essay')]);

        $this->assertSame(1, $preview['responses_affected']);

        $refused = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertFalse($refused['success']);
        $this->assertTrue($refused['needs_confirmation']);
        $this->assertSame(1, $refused['responses_affected']);
        $this->assertSame('Old wording', $this->questionsOf()[0]['question_text'], 'nothing changed');

        $accepted = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], [], [], true);

        $this->assertTrue($accepted['success']);
        $this->assertSame('New wording', $this->questionsOf()[0]['question_text']);
    }

    public function testAddingQuestionsToATakenQuizNeedsNoConfirmation(): void
    {
        $q = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1, ['question_text' => 'Same']);
        $this->answered($q);

        $preview = $this->preview(array_merge(['Q1. Same', $this->meta($q, 'Essay')], $this->mc('A new one')));

        $this->assertTrue($this->import->applyPreview($this->instructor, $this->quiz, $preview['token'])['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Undo
    |--------------------------------------------------------------------------
    */

    public function testUndoPutsTheQuizBackAsItWas(): void
    {
        $keep = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 2, ['question_text' => 'Original text']);
        $gone = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [true, false], 3);
        db_connect()->table('assessment_questions')->where('id', $gone['question'])->update(['question_text' => 'Will be deleted']);

        $before = array_column($this->questionsOf(), 'question_text');

        $preview = $this->preview(array_merge(
            ['Q1. Edited text', $this->meta($keep, 'Essay', '5')],
            $this->mc('Added one')
        ));

        $applied = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token'], [], [$gone['question']]);

        $this->assertSame(['Edited text', 'Added one'], array_column($this->questionsOf(), 'question_text'));

        $undone = $this->import->undoImport($this->instructor, $this->quiz, $applied['undo_token']);

        $this->assertTrue($undone['success'], json_encode($undone));
        $this->assertSame($before, array_column($this->questionsOf(), 'question_text'));

        $restored = $this->questionsOf();
        $this->assertSame('Original text', $restored[0]['question_text']);
        $this->assertEquals(2, $restored[0]['points']);

        $choices = db_connect()->table('assessment_question_choices')->where('question_id', (int) $restored[1]['id'])->countAllResults();
        $this->assertSame(2, $choices, 'the deleted question came back with its choices');
    }

    public function testUndoIsOnlyOpenForTheWindowAndOnlyForTheSamePerson(): void
    {
        $preview = $this->preview($this->mc('Undo me'));
        $applied = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertFalse($this->import->undoImport(CourseFactory::user('instructor'), $this->quiz, $applied['undo_token'])['success']);

        // Past the window the entry is gone.
        $entryFile = $this->storeDirectory . '/' . $applied['undo_token'] . '.json';
        $entry = json_decode((string) file_get_contents($entryFile), true);
        $entry['applied_at'] = time() - ImportPreviewStore::UNDO_SECONDS - 5;
        file_put_contents($entryFile, json_encode($entry));

        $result = $this->import->undoImport($this->instructor, $this->quiz, $applied['undo_token']);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->questionCount(), 'nothing was undone');
    }

    public function testApplyingAndUndoingLeaveAuditEntries(): void
    {
        $preview = $this->preview($this->mc('Audited'));
        $applied = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);
        $this->import->undoImport($this->instructor, $this->quiz, $applied['undo_token']);

        $actions = array_column(db_connect()->table('audit_logs')->select('action')->get()->getResultArray(), 'action');

        $this->assertContains('quiz.import.applied', $actions);
        $this->assertContains('quiz.import.undone', $actions);
    }
}
