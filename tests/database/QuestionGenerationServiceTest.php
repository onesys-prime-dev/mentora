<?php

declare(strict_types=1);

use App\Services\AI\QuestionGeneratorBusyException;
use App\Services\AI\QuestionGeneratorClientInterface;
use App\Services\AI\QuestionGeneratorUnavailableException;
use App\Services\Courses\Assessments\QuestionGenerationService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * AI question generation without the AI: a fake generator hands back
 * questions, and the service's own job is checked: batches and chunks, the
 * review tray (edit, discard, accept, accept all), what is kept from the
 * generator, and what happens when it is busy or unavailable.
 *
 * @internal
 */
final class QuestionGenerationServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;
    private int $courseId;
    private int $module;
    private int $unit;
    private int $quiz;
    private int $reading;
    private User $instructor;
    private FakeGenerator $generator;
    private QuestionGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $this->courseId = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];
        $this->module = CourseFactory::module($this->offering);
        $this->unit = CourseFactory::unit($this->module);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        // The only source is the unit the quiz sits under: one reading to read, and the quiz's own item.
        [$this->reading, $quizItem] = CourseFactory::items($this->unit, [
            ['title' => 'Planets', 'type' => 'reading'],
            ['title' => 'Quiz', 'type' => 'quiz'],
        ]);
        db_connect()->table('learning_items')->where('id', $this->reading)->update(['content' => json_encode([
            'version' => 1,
            'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Jupiter is the largest planet in the solar system.']]]],
        ])]);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $quizItem);
        $this->generator = new FakeGenerator();
        // Explicit: these are the in-request path's tests, whatever the machine's .env says.
        $this->service = new QuestionGenerationService($this->generator, false);
    }

    private function start(array $data = []): array
    {
        return $this->service->startBatch($this->instructor, $data + [
            'assessment_id' => $this->quiz,
            'course_id' => $this->courseId,
            'offering_id' => $this->offering,
            'count' => 3,
        ]);
    }

    private function batch(int $id): array
    {
        return db_connect()->table('ai_generation_batches')->where('id', $id)->get()->getRowArray();
    }

    private function generated(int $id): array
    {
        return db_connect()->table('ai_generated_questions')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<array> the quiz's real questions */
    private function questions(): array
    {
        return db_connect()->table('assessment_questions')->where('assessment_id', $this->quiz)->orderBy('order_index')->get()->getResultArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Starting a batch
    |--------------------------------------------------------------------------
    */

    public function testABatchNeedsARealAssessment(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->start(['assessment_id' => null]));
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->start(['assessment_id' => 999999]));
        $this->assertSame(0, db_connect()->table('ai_generation_batches')->countAllResults());
    }

    public function testABatchStoresItsRequestAndItsSourceItemsAndGeneratesTheFirstChunk(): void
    {
        $this->generator->chunk = 2;

        $result = $this->start(['count' => 5, 'type_mix' => ['Multiple Choice' => 3, 'Essay' => 2], 'points_per_question' => 2.5]);

        $this->assertTrue($result['success']);
        $batch = $this->batch((int) $result['batch']['id']);
        $this->assertNull($batch['topic']);
        $this->assertSame('learning_unit', $batch['source_type']);
        $this->assertSame($this->unit, (int) $batch['learning_unit_id']);
        $items = json_decode($batch['source_items'], true);
        $this->assertSame([$this->reading], array_column($items, 'id'));
        $this->assertSame('Planets', $items[0]['title']);
        $this->assertStringContainsString('Jupiter is the largest planet', $items[0]['text']);
        $this->assertSame($this->quiz, (int) $batch['assessment_id']);
        $this->assertSame($this->courseId, (int) $batch['course_id']);
        $this->assertSame(5, (int) $batch['requested_count']);
        $this->assertSame(2, (int) $batch['generated_count']);
        $this->assertSame('generating', $batch['status']);
        $this->assertEquals(2.5, $batch['points_per_question']);
        $this->assertEqualsCanonicalizing(['Multiple Choice' => 3, 'Essay' => 2], json_decode($batch['type_mix'], true));
        $this->assertSame($this->instructor->id, (int) $batch['created_by']);
        $this->assertCount(2, $result['questions']);
        $this->assertFalse($result['stopped_early']);
        $this->assertSame([], $result['skipped_sources']);
    }

    public function testTheGeneratorIsGivenTheRequestAndTheNumberStillWanted(): void
    {
        $this->start(['count' => 3, 'type_mix' => ['Essay' => 3], 'points_per_question' => 4]);

        $call = $this->generator->calls[0];
        $this->assertNull($call['topic']);
        $this->assertStringContainsString('Jupiter is the largest planet', $call['source_text']);
        $this->assertSame(3, $call['count']);
        $this->assertSame(['Essay' => 3], $call['type_mix']);
        $this->assertEquals(4, $call['points_per_question']);
        $this->assertSame([], $call['recent_questions']);
    }

    public function testAnAllAtOnceBatchIsCompleted(): void
    {
        $result = $this->start(['count' => 3]);

        $this->assertSame('completed', $result['batch']['status']);
        $this->assertSame(3, (int) $result['batch']['generated_count']);
    }

    public function testEveryGeneratedQuestionIsStagedAsPendingWithItsChecks(): void
    {
        $this->generator->questions = [[
            'question_text' => 'Which planet is largest?', 'question_type' => 'Multiple Choice', 'points' => 2,
            'choices' => [['text' => 'Jupiter', 'is_correct' => true], ['text' => 'Mars', 'is_correct' => false]], 'math_check' => 'verified',
        ]];

        $result = $this->start(['count' => 1]);

        $row = $result['questions'][0];
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Which planet is largest?', $row['question_text']);
        $this->assertEquals(2, $row['points']);
        $this->assertSame(1, (int) $row['is_required'], 'required unless the generator says otherwise');
        $this->assertSame([['text' => 'Jupiter', 'is_correct' => true], ['text' => 'Mars', 'is_correct' => false]], $row['choices']);
        $this->assertSame('verified', $row['math_check']);
        $this->assertNotNull($row['similarity_score'], 'compared with the source text');
        $this->assertSame([], $row['accepted_answers']);
    }

    public function testAQuestionWithNoMathCheckDefaultsToNotApplicable(): void
    {
        $this->generator->questions = [['question_text' => 'Q', 'question_type' => 'Essay', 'points' => 1]];

        $row = $this->start(['count' => 1])['questions'][0];

        $this->assertNotNull($row['similarity_score'], 'always compared with the unit text');
        $this->assertSame('not_applicable', $row['math_check']);
    }

    public function testAShortAnswerKeepsTheGeneratorsAnswerKeyOthersDoNot(): void
    {
        $this->generator->questions = [
            ['question_text' => 'S', 'question_type' => 'Short Answer', 'points' => 1, 'accepted_answers' => ['  Paris ', 'paris', 'City of Light']],
            ['question_text' => 'E', 'question_type' => 'Essay', 'points' => 1, 'accepted_answers' => ['ignored']],
        ];

        $questions = $this->start(['count' => 2])['questions'];

        $this->assertSame(['Paris', 'paris', 'City of Light'], $questions[0]['accepted_answers']);
        $this->assertSame([], $questions[1]['accepted_answers']);
    }

    public function testABusyGeneratorLeavesTheBatchWaitingForTheNextTry(): void
    {
        $this->generator->failure = new QuestionGeneratorBusyException('Another job is running.');

        $result = $this->start();

        $this->assertSame(['success' => false, 'busy' => true, 'message' => 'Another job is running.', 'skipped_sources' => []], $result);
        $batch = db_connect()->table('ai_generation_batches')->get()->getRowArray();
        $this->assertSame('generating', $batch['status'], 'not failed: it can be retried');
        $this->assertSame(0, db_connect()->table('ai_generated_questions')->countAllResults());
    }

    public function testAnUnavailableGeneratorFailsTheBatchWithItsReason(): void
    {
        $this->generator->failure = new QuestionGeneratorUnavailableException('The service is down.');

        $result = $this->start();

        $this->assertFalse($result['success']);
        $this->assertSame('The service is down.', $result['message']);
        $batch = db_connect()->table('ai_generation_batches')->get()->getRowArray();
        $this->assertSame('failed', $batch['status']);
        $this->assertSame('The service is down.', $batch['error_message']);
    }

    private function readingWith(string $title, string $text, string $status = 'published'): int
    {
        [$id] = CourseFactory::items($this->unit, [['title' => $title, 'type' => 'reading', 'status' => $status]]);
        db_connect()->table('learning_items')->where('id', $id)->update(['content' => json_encode([
            'version' => 1,
            'blocks' => [['type' => 'paragraph', 'content' => [['text' => $text]]]],
        ])]);

        return $id;
    }

    /** @return list<int> ids of the items the stored batch was generated from */
    private function sourceIds(array $result): array
    {
        $batch = $this->batch((int) $result['batch']['id']);

        return array_column(json_decode($batch['source_items'], true), 'id');
    }

    public function testOnlyTheChosenItemsAreReadAndStored(): void
    {
        $second = $this->readingWith('Moons', 'Europa is a moon of Jupiter with an ocean under its ice.');
        $this->readingWith('Comets', 'Comets are icy bodies that grow tails near the sun.');

        $result = $this->start(['learning_item_ids' => [$second]]);

        $this->assertTrue($result['success']);
        $this->assertSame([$second], $this->sourceIds($result));
        $batch = $this->batch((int) $result['batch']['id']);
        $this->assertStringContainsString('Europa', $batch['source_text']);
        $this->assertStringNotContainsString('Comets are icy', $batch['source_text']);
        $this->assertStringNotContainsString('Jupiter is the largest', $batch['source_text']);
    }

    public function testNoSelectionMeansEveryReadingAndPdfOfTheUnit(): void
    {
        $second = $this->readingWith('Moons', 'Europa is a moon of Jupiter.');

        $result = $this->start();

        $this->assertSame([$this->reading, $second], $this->sourceIds($result));
    }

    /** 1 to 100 questions at a time, the form's own range, whatever was sent (QA AMB-ASM-10). */
    public function testAskingForTooFewOrTooManyIsRefusedBeforeAnythingIsStarted(): void
    {
        foreach ([0, -3, 101, 5000] as $count) {
            $result = $this->start(['count' => $count]);

            $this->assertFalse($result['success'], (string) $count);
            $this->assertSame('Ask for 1 to 100 questions at a time.', $result['message']);
        }

        $this->assertSame(0, db_connect()->table('ai_generation_batches')->countAllResults());
        $this->assertSame([], $this->generator->calls);

        $this->assertTrue($this->start(['count' => 100])['success']);
        $this->assertTrue($this->start(['count' => 1])['success']);
    }

    public function testAnEmptySelectionIsRefusedBeforeAnythingIsStarted(): void
    {
        $result = $this->start(['learning_item_ids' => []]);

        $this->assertSame(['success' => false, 'message' => 'Choose at least one reading or PDF to generate questions from.'], $result);
        $this->assertSame(0, db_connect()->table('ai_generation_batches')->countAllResults());
        $this->assertSame([], $this->generator->calls);
    }

    public function testAnItemOfAnotherUnitOrAnotherTypeIsNeverRead(): void
    {
        $otherOffering = CourseFactory::offering();
        $otherUnit = CourseFactory::unit(CourseFactory::module($otherOffering));
        [$foreign] = CourseFactory::items($otherUnit, [['title' => 'Secret', 'type' => 'reading']]);
        db_connect()->table('learning_items')->where('id', $foreign)->update(['content' => json_encode([
            'version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Foreign unit text.']]]],
        ])]);
        [$video] = CourseFactory::items($this->unit, [['title' => 'Clip', 'type' => 'video']]);

        $result = $this->start(['learning_item_ids' => [$foreign, $video, $this->reading]]);

        $this->assertSame([$this->reading], $this->sourceIds($result));
        $this->assertStringNotContainsString('Foreign unit text', $this->batch((int) $result['batch']['id'])['source_text']);

        $onlyForeign = $this->start(['learning_item_ids' => [$foreign, $video]]);
        $this->assertFalse($onlyForeign['success']);
    }

    public function testADraftReadingCanBeChosenByItsInstructor(): void
    {
        $draft = $this->readingWith('Work in progress', 'Saturn has rings made of ice and rock.', 'draft');

        $result = $this->start(['learning_item_ids' => [$draft]]);

        $this->assertTrue($result['success']);
        $this->assertSame([$draft], $this->sourceIds($result));
    }

    public function testAQuizOutsideAUnitHasNoSourceToGenerateFrom(): void
    {
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['learning_item_id' => null, 'module_id' => null]);

        $result = $this->start();

        $this->assertSame([
            'success' => false,
            'message' => "This quiz isn't in a learning unit. Place it in a learning unit to generate questions from its readings and PDFs.",
        ], $result);
    }

    public function testAUnitWithNothingToReadCannotBeTheSource(): void
    {
        db_connect()->table('learning_items')->where('id', $this->reading)->update(['item_type' => 'video']);

        $result = $this->start();

        $this->assertFalse($result['success']);
        $this->assertSame('That learning unit has no readings or PDFs yet.', $result['message']);
        $this->assertSame(0, db_connect()->table('ai_generation_batches')->countAllResults());
    }

    public function testChosenItemsThatCannotBeReadAreReportedByName(): void
    {
        [$pdf] = CourseFactory::items($this->unit, [['title' => 'Lost slides', 'type' => 'pdf']]);

        $result = $this->start(['learning_item_ids' => [$this->reading, $pdf]]);

        $this->assertTrue($result['success']);
        $this->assertSame(['Lost slides'], $result['skipped_sources']);
        $this->assertSame([$this->reading], $this->sourceIds($result));
    }

    /*
    |--------------------------------------------------------------------------
    | More chunks
    |--------------------------------------------------------------------------
    */

    public function testNextChunkGeneratesWhatIsStillMissingAndShowsTheRecentQuestionsToAvoidRepeats(): void
    {
        $this->generator->chunk = 2;
        $batchId = (int) $this->start(['count' => 5])['batch']['id'];

        $second = $this->service->nextChunk($batchId);

        $this->assertSame(4, (int) $second['batch']['generated_count']);
        $call = $this->generator->calls[1];
        $this->assertSame(3, $call['count'], 'five wanted, two have');
        $this->assertCount(2, $call['recent_questions']);

        $third = $this->service->nextChunk($batchId);
        $this->assertSame('completed', $third['batch']['status']);
        $this->assertSame(5, (int) $third['batch']['generated_count']);
        $this->assertSame(1, $this->generator->calls[2]['count']);
    }

    public function testDiscardedQuestionsAreNotShownToTheGeneratorAgain(): void
    {
        $this->generator->chunk = 1;
        $first = $this->start(['count' => 3]);
        $discarded = (int) $first['questions'][0]['id'];
        $this->service->discardGeneratedQuestion($discarded);

        $this->service->nextChunk((int) $first['batch']['id']);

        $this->assertNotContains('Generated question 1', $this->generator->calls[1]['recent_questions']);
    }

    public function testOnlyTheThirtyMostRecentQuestionsAreShown(): void
    {
        $this->generator->chunk = 40;
        $batchId = (int) $this->start(['count' => 41])['batch']['id'];

        $this->service->nextChunk($batchId);

        $this->assertCount(30, $this->generator->calls[1]['recent_questions']);
    }

    public function testAFinishedBatchIsNotGeneratedAgain(): void
    {
        $batchId = (int) $this->start(['count' => 3])['batch']['id'];
        $calls = count($this->generator->calls);

        $result = $this->service->nextChunk($batchId);

        $this->assertSame('completed', $result['batch']['status']);
        $this->assertCount($calls, $this->generator->calls);
    }

    public function testAFailedBatchIsNotRetriedByNextChunk(): void
    {
        $this->generator->failure = new QuestionGeneratorUnavailableException('The service is down.');
        $this->start(['count' => 3]);
        $batchId = (int) db_connect()->table('ai_generation_batches')->get()->getRowArray()['id'];
        $this->generator->failure = null;
        $calls = count($this->generator->calls);

        $result = $this->service->nextChunk($batchId);

        $this->assertSame('failed', $result['batch']['status']);
        $this->assertCount($calls, $this->generator->calls, 'the generator is not asked again');
        $this->assertSame([], $result['questions']);
    }

    public function testAChunkWithNothingNewEndsTheBatchEarly(): void
    {
        $this->generator->chunk = 2;
        $batchId = (int) $this->start(['count' => 6])['batch']['id'];
        $this->generator->questions = [];
        $this->generator->chunk = 0;

        $result = $this->service->nextChunk($batchId);

        $this->assertSame('completed', $result['batch']['status']);
        $this->assertTrue($result['stopped_early'], 'the model ran out of distinct content');
        $this->assertSame(2, (int) $result['batch']['generated_count']);
    }

    public function testChunksForAMissingBatchAreRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Batch not found.'], $this->service->nextChunk(999999));
        $this->assertSame(['success' => false, 'message' => 'Batch not found.'], $this->service->getBatch(999999));
    }

    public function testTheBatchSummaryOnlyListsQuestionsStillPending(): void
    {
        $result = $this->start(['count' => 3]);
        $this->service->discardGeneratedQuestion((int) $result['questions'][0]['id']);
        $this->service->acceptGeneratedQuestion($this->instructor, (int) $result['questions'][1]['id']);

        $summary = $this->service->getBatch((int) $result['batch']['id']);

        $this->assertCount(1, $summary['questions']);
        $this->assertSame('Generated question 3', $summary['questions'][0]['question_text']);
    }

    /*
    |--------------------------------------------------------------------------
    | The review tray
    |--------------------------------------------------------------------------
    */

    public function testEditingAStagedQuestionChangesOnlyWhatIsSent(): void
    {
        $id = (int) $this->start(['count' => 1])['questions'][0]['id'];

        $result = $this->service->updateGeneratedQuestion($id, ['question_text' => 'Reworded', 'points' => 5, 'is_required' => false]);

        $this->assertTrue($result['success']);
        $row = $this->generated($id);
        $this->assertSame('Reworded', $row['question_text']);
        $this->assertEquals(5, $row['points']);
        $this->assertSame(0, (int) $row['is_required']);
        $this->assertSame('Multiple Choice', $row['question_type'], 'untouched');
        $this->assertSame('Reworded', $result['question']['question_text']);
        $this->assertSame('pending', $row['status']);
    }

    public function testEditingChoicesAndMultipleAnswers(): void
    {
        $id = (int) $this->start(['count' => 1])['questions'][0]['id'];

        $this->service->updateGeneratedQuestion($id, ['choices' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => true]], 'allow_multiple_answers' => true, 'answers_count' => 1]);

        $row = $this->generated($id);
        $this->assertSame([['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => true]], json_decode($row['choices'], true));
        $this->assertSame(1, (int) $row['allow_multiple_answers']);
        $this->assertSame(2, (int) $row['answers_count'], 'at least two');

        $this->service->updateGeneratedQuestion($id, ['allow_multiple_answers' => false]);
        $this->assertNull($this->generated($id)['answers_count']);
    }

    public function testAShortAnswerKeyIsEditedAndDroppedWhenTheTypeChanges(): void
    {
        $this->generator->questions = [['question_text' => 'S', 'question_type' => 'Short Answer', 'points' => 1, 'accepted_answers' => ['Paris']]];
        $id = (int) $this->start(['count' => 1])['questions'][0]['id'];

        $this->service->updateGeneratedQuestion($id, ['accepted_answers' => [' Lyon ', 'Nice'], 'case_sensitive' => 'true']);
        $row = $this->service->updateGeneratedQuestion($id, [])['question'];
        $this->assertSame(['Lyon', 'Nice'], $row['accepted_answers']);
        $this->assertSame(1, (int) $row['case_sensitive']);

        $row = $this->service->updateGeneratedQuestion($id, ['question_type' => 'Essay'])['question'];
        $this->assertSame([], $row['accepted_answers']);
        $this->assertSame(0, (int) $row['case_sensitive']);
    }

    public function testEditingOrDiscardingAMissingQuestionIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $this->service->updateGeneratedQuestion(999999, []));
        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $this->service->discardGeneratedQuestion(999999));
        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $this->service->acceptGeneratedQuestion($this->instructor, 999999));
    }

    public function testDiscardingMarksItDiscarded(): void
    {
        $id = (int) $this->start(['count' => 1])['questions'][0]['id'];

        $this->assertSame(['success' => true, 'message' => 'Discarded.'], $this->service->discardGeneratedQuestion($id));

        $this->assertSame('discarded', $this->generated($id)['status']);
        $this->assertSame([], $this->questions(), 'nothing reaches the quiz');
    }

    public function testAcceptingAddsItToTheQuizWithItsChoicesAndAnswerKey(): void
    {
        $this->generator->questions = [
            ['question_text' => 'Largest planet?', 'question_type' => 'Multiple Choice', 'points' => 3, 'choices' => [['text' => 'Jupiter', 'is_correct' => true], ['text' => 'Mars']]],
            ['question_text' => 'Capital?', 'question_type' => 'Short Answer', 'points' => 2, 'accepted_answers' => ['Paris', 'paris france']],
        ];
        $staged = $this->start(['count' => 2])['questions'];

        $mc = $this->service->acceptGeneratedQuestion($this->instructor, (int) $staged[0]['id']);
        $short = $this->service->acceptGeneratedQuestion($this->instructor, (int) $staged[1]['id']);

        $this->assertTrue($mc['success']);
        $this->assertSame(['Jupiter', 'Mars'], array_column($mc['question']['choices'], 'choice_text'));
        $this->assertSame([1, 0], array_map('intval', array_column($mc['question']['choices'], 'is_correct')));
        $this->assertSame(['Paris', 'paris france'], $short['question']['accepted_answers']);
        $this->assertSame('accepted', $this->generated((int) $staged[0]['id'])['status']);
        $this->assertSame(['Largest planet?', 'Capital?'], array_column($this->questions(), 'question_text'));
        $this->assertEquals(5, db_connect()->table('assessments')->where('id', $this->quiz)->get()->getRowArray()['total_points']);
    }

    public function testAcceptingTheSameQuestionTwiceDoesNotAddItTwice(): void
    {
        $id = (int) $this->start(['count' => 1])['questions'][0]['id'];
        $this->service->acceptGeneratedQuestion($this->instructor, $id);

        $again = $this->service->acceptGeneratedQuestion($this->instructor, $id);

        $this->assertFalse($again['success']);
        $this->assertCount(1, $this->questions());
    }

    public function testADiscardedQuestionCannotBeAcceptedAnyMore(): void
    {
        $id = (int) $this->start(['count' => 1])['questions'][0]['id'];
        $this->service->discardGeneratedQuestion($id);

        $this->assertFalse($this->service->acceptGeneratedQuestion($this->instructor, $id)['success']);
        $this->assertSame([], $this->questions());
    }

    public function testABatchWithoutAnAssessmentCannotBeAccepted(): void
    {
        $id = (int) $this->start(['count' => 1])['questions'][0]['id'];
        $batchId = (int) $this->generated($id)['batch_id'];
        db_connect()->table('ai_generation_batches')->where('id', $batchId)->update(['assessment_id' => null]);

        $this->assertSame('This batch is not linked to an assessment.', $this->service->acceptGeneratedQuestion($this->instructor, $id)['message']);
        $this->assertSame('This batch is not linked to an assessment.', $this->service->acceptAll($this->instructor, $batchId)['message']);
    }

    public function testAcceptAllAddsEveryPendingQuestionAndSaysHowMany(): void
    {
        $result = $this->start(['count' => 3]);
        $this->service->discardGeneratedQuestion((int) $result['questions'][0]['id']);
        $this->service->acceptGeneratedQuestion($this->instructor, (int) $result['questions'][1]['id']);

        $all = $this->service->acceptAll($this->instructor, (int) $result['batch']['id']);

        $this->assertTrue($all['success']);
        $this->assertSame('1 question(s) accepted.', $all['message']);
        $this->assertSame(1, $all['accepted_count']);
        $this->assertSame(['Generated question 3'], array_column($all['questions'], 'question_text'));
        $this->assertSame(['Generated question 2', 'Generated question 3'], array_column($this->questions(), 'question_text'), 'the discarded one never arrives');
        $this->assertSame(0, db_connect()->table('ai_generated_questions')->where('status', 'pending')->countAllResults());
    }

    public function testAcceptAllOnAMissingBatchIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Batch not found.'], $this->service->acceptAll($this->instructor, 999999));
    }
    /*
    |--------------------------------------------------------------------------
    | Replacing one question
    |--------------------------------------------------------------------------
    */

    public function testAQuestionCanBeReplacedAndTheOldOneIsDiscarded(): void
    {
        $this->generator->chunk = 2;
        $started = $this->start(['count' => 2]);
        $batchId = (int) $started['batch']['id'];
        $first = (int) $started['questions'][0]['id'];

        $result = $this->service->replaceQuestion($this->instructor, $first, 'harder', 'use the planets');

        $this->assertTrue($result['success']);
        $this->assertSame($first, $result['replaces']);
        $this->assertSame('discarded', $this->generated($first)['status']);

        $inBatch = db_connect()->table('ai_generated_questions')->where('batch_id', $batchId)->where('status', 'pending')->countAllResults();
        $this->assertSame(2, $inBatch, 'the new question sits in the original batch beside the other');

        $asked = $this->generator->calls[count($this->generator->calls) - 1];
        $this->assertSame(1, $asked['count']);
        $this->assertStringContainsString('Make it harder', (string) $asked['instruction']);
        $this->assertStringContainsString('use the planets', (string) $asked['instruction']);
    }

    public function testNothingIsDiscardedWhenNoReplacementArrives(): void
    {
        $this->generator->chunk = 2;
        $started = $this->start(['count' => 2]);
        $first = (int) $started['questions'][0]['id'];

        $this->generator->chunk = 0;
        $this->service->replaceQuestion($this->instructor, $first, 'different');

        $this->assertSame('pending', $this->generated($first)['status']);
    }

    public function testAReplacementIsRefusedForABadRequest(): void
    {
        $this->generator->chunk = 1;
        $started = $this->start(['count' => 1]);
        $id = (int) $started['questions'][0]['id'];

        $this->assertFalse($this->service->replaceQuestion($this->instructor, $id, 'bogus')['success']);
        $this->assertFalse($this->service->replaceQuestion($this->instructor, $id, 'easier', str_repeat('x', 201))['success']);
        $this->assertFalse($this->service->replaceQuestion($this->instructor, 999999, 'easier')['success']);

        $this->service->discardGeneratedQuestion($id);
        $this->assertFalse($this->service->replaceQuestion($this->instructor, $id, 'easier')['success'], 'only a waiting question can be replaced');
    }

    public function testTheRequestedTypeIsTheOneWritten(): void
    {
        $this->generator->chunk = 1;
        $started = $this->start(['count' => 1]);
        $id = (int) $started['questions'][0]['id'];

        $this->service->replaceQuestion($this->instructor, $id, 'type', '', 'Essay');

        $asked = $this->generator->calls[count($this->generator->calls) - 1];
        $this->assertSame(['essay' => 1], $asked['type_mix']);
    }
    /*
    |--------------------------------------------------------------------------
    | Asking for more
    |--------------------------------------------------------------------------
    */

    public function testMoreQuestionsAreWrittenIntoTheSameSet(): void
    {
        $this->generator->chunk = 1;
        $started = $this->start(['count' => 3]);
        $batchId = (int) $started['batch']['id'];

        // The set ended short: one of three.
        $this->service->nextChunk($batchId);
        db_connect()->table('ai_generation_batches')->where('id', $batchId)->update(['status' => 'completed']);

        $this->generator->chunk = 2;
        $result = $this->service->topUp($this->instructor, $batchId, 2);

        $this->assertTrue($result['success']);
        $this->assertSame($batchId, $result['extends']);

        $pending = db_connect()->table('ai_generated_questions')->where('batch_id', $batchId)->where('status', 'pending')->countAllResults();
        $this->assertGreaterThanOrEqual(3, $pending, 'the new questions sit in the original set');
    }

    public function testAnUnfinishedOrUnknownSetCannotBeToppedUp(): void
    {
        $this->generator->chunk = 1;
        $started = $this->start(['count' => 3]);

        $this->assertFalse($this->service->topUp($this->instructor, (int) $started['batch']['id'], 2)['success'], 'still generating');
        $this->assertFalse($this->service->topUp($this->instructor, 999999, 2)['success']);
    }

    public function testASetSaysWhatIsMissingAndWhy(): void
    {
        $this->generator->chunk = 1;
        $started = $this->start(['count' => 3]);
        $batchId = (int) $started['batch']['id'];

        db_connect()->table('ai_generation_batches')->where('id', $batchId)->update([
            'status' => 'completed', 'generated_count' => 1,
            'diagnostics' => json_encode([
                'dropped' => ['solver_disagrees' => 1, 'mc_choice_count' => 1],
                'coverage' => [['item_id' => 1, 'title' => 'Planets', 'count' => 1]],
                'stop_reason' => 'exhausted_topic', 'truncated' => true,
            ]),
        ]);

        $view = $this->service->getBatch($batchId);

        $this->assertSame(2, $view['shortfall']['missing']);
        $this->assertTrue($view['shortfall']['not_enough_material']);
        $this->assertTrue($view['truncated']);
        $this->assertSame('Planets', $view['coverage'][0]['title']);

        $texts = array_column($view['shortfall']['reasons'], 'text');
        $this->assertContains('a second check did not agree with the marked answer', $texts);
        $this->assertContains('it did not meet the question rules', $texts);
    }
}

/** A stand-in for the question generator that hands back canned questions. */
final class FakeGenerator implements QuestionGeneratorClientInterface
{
    /** @var list<array> what generate() was asked */
    public array $calls = [];

    /** @var list<array>|null fixed questions to return (otherwise made up) */
    public ?array $questions = null;

    /** How many made-up questions per call (at most what was asked for); 0 with no fixed list means none. */
    public ?int $chunk = null;

    public ?\Throwable $failure = null;

    private int $made = 0;

    public function generate(array $params): array
    {
        $this->calls[] = $params;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->questions !== null) {
            return ['questions' => array_slice($this->questions, 0, $params['count']), 'model_used' => 'fake'];
        }

        $count = min($params['count'], $this->chunk ?? $params['count']);
        $questions = [];

        for ($i = 0; $i < $count; $i++) {
            $this->made++;
            $questions[] = [
                'question_text' => 'Generated question ' . $this->made,
                'question_type' => 'Multiple Choice',
                'points' => $params['points_per_question'] ?? 1,
                'choices' => [['text' => 'Yes', 'is_correct' => true], ['text' => 'No', 'is_correct' => false]],
            ];
        }

        return ['questions' => $questions, 'model_used' => 'fake'];
    }

    public function extractText(string $filePath, string $mimeType): array
    {
        return ['text' => 'extracted', 'used_ocr' => false, 'pages' => 1];
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
