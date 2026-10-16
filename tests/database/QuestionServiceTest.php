<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuestionService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The instructor's question editor: adding, editing, duplicating, reordering
 * and deleting questions, the answer keys (choices, several correct answers,
 * short-answer keys), and the quiz total that follows every change.
 *
 * @internal
 */
final class QuestionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionService $service;
    private int $quiz;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QuestionService();
        $offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
    }

    private function add(array $data, ?int $quiz = null): array
    {
        return $this->service->createQuestion($this->instructor, $quiz ?? $this->quiz, $data);
    }

    private function row(string $table, int $id): ?array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    /** @return list<int> question ids in display order */
    private function order(?int $quiz = null): array
    {
        return array_map('intval', array_column(db_connect()->table('assessment_questions')->where('assessment_id', $quiz ?? $this->quiz)->orderBy('order_index')->orderBy('id')->get()->getResultArray(), 'id'));
    }

    private function total(?int $quiz = null): float
    {
        return (float) $this->row('assessments', $quiz ?? $this->quiz)['total_points'];
    }

    private function choices(int $questionId): array
    {
        return db_connect()->table('assessment_question_choices')->where('question_id', $questionId)->orderBy('order_index')->get()->getResultArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Adding
    |--------------------------------------------------------------------------
    */

    public function testAMultipleChoiceQuestionIsStoredWithItsChoicesInOrder(): void
    {
        $result = $this->add([
            'question_text' => 'Capital of France?',
            'question_type' => 'Multiple Choice',
            'points' => 3,
            'is_required' => true,
            'choices' => [['text' => ' Paris ', 'is_correct' => true], ['text' => 'Lyon'], ['text' => '   ', 'is_correct' => true], ['text' => 'Nice']],
        ]);

        $this->assertTrue($result['success']);
        $question = $result['question'];
        $this->assertSame('Capital of France?', $question['question_text']);
        $this->assertSame('Multiple Choice', $question['question_type']);
        $this->assertEquals(3, $question['points']);
        $this->assertSame(1, (int) $question['is_required']);
        $this->assertSame(1, (int) $question['order_index']);
        $this->assertSame('Active', $question['status']);
        $this->assertSame($this->instructor->id, (int) $question['created_by']);
        $this->assertSame(['Paris', 'Lyon', 'Nice'], array_column($question['choices'], 'choice_text'), 'blank choices are dropped');
        $this->assertSame([1, 0, 0], array_map('intval', array_column($question['choices'], 'is_correct')));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($question['choices'], 'order_index')));
    }

    public function testNewQuestionsGoToTheEndAndTheQuizTotalAddsUp(): void
    {
        $a = $this->add(['question_text' => 'A', 'question_type' => 'Essay', 'points' => 5])['question']['id'];
        $b = $this->add(['question_text' => 'B', 'question_type' => 'Essay', 'points' => 2.5])['question']['id'];

        $this->assertSame([(int) $a, (int) $b], $this->order());
        $this->assertSame(7.5, $this->total());
    }

    public function testQuestionsDefaultToAnEssayWorthNothingAndRequiredOnlyWhenAsked(): void
    {
        $question = $this->add(['question_text' => 'Q'])['question'];

        $this->assertSame('Essay', $question['question_type']);
        $this->assertEquals(0, $question['points']);
        $this->assertSame(0, (int) $question['is_required']);
    }

    public function testAQuestionCannotBeAddedToAnAssessmentThatDoesNotExist(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->add(['question_text' => 'Q'], 999999));
    }

    public function testSeveralCorrectAnswersNeedAtLeastTwoAndSingleAnswerQuestionsKeepNoCount(): void
    {
        $multi = $this->add(['question_text' => 'Pick', 'question_type' => 'Multiple Choice', 'allow_multiple_answers' => true, 'answers_count' => 1])['question'];
        $this->assertSame(1, (int) $multi['allow_multiple_answers']);
        $this->assertSame(2, (int) $multi['answers_count'], 'one is not "multiple"');

        $three = $this->add(['question_text' => 'Pick', 'question_type' => 'Multiple Choice', 'allow_multiple_answers' => true, 'answers_count' => 3])['question'];
        $this->assertSame(3, (int) $three['answers_count']);

        $single = $this->add(['question_text' => 'Pick', 'question_type' => 'Multiple Choice', 'answers_count' => 4])['question'];
        $this->assertSame(0, (int) $single['allow_multiple_answers']);
        $this->assertNull($single['answers_count'], 'a stray count never sticks to a single-answer question');
    }

    public function testAShortAnswerKeepsItsCleanedAnswerKey(): void
    {
        $question = $this->add([
            'question_text' => 'Capital?', 'question_type' => 'Short Answer', 'points' => 2,
            'accepted_answers' => ['  Paris ', 'paris', 'Paris', '', 'City of Light'], 'case_sensitive' => 'true',
        ])['question'];

        $this->assertSame(['Paris', 'paris', 'City of Light'], $question['accepted_answers']);
        $this->assertSame(1, (int) $question['case_sensitive']);
    }

    public function testOtherQuestionTypesNeverKeepAnAnswerKey(): void
    {
        $question = $this->add(['question_text' => 'Essay', 'question_type' => 'Essay', 'accepted_answers' => ['x'], 'case_sensitive' => true])['question'];

        $this->assertSame([], $question['accepted_answers']);
        $this->assertSame(0, (int) $question['case_sensitive']);
    }

    public function testAShortAnswerWithNoKeyIsStoredWithoutOne(): void
    {
        $question = $this->add(['question_text' => 'Open', 'question_type' => 'Short Answer', 'accepted_answers' => []])['question'];

        $this->assertSame([], $question['accepted_answers']);
        $this->assertNull($this->row('assessment_questions', (int) $question['id'])['accepted_answers']);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function testListingReturnsActiveQuestionsInOrderWithTheirChoices(): void
    {
        $mc = $this->add(['question_text' => 'MC', 'question_type' => 'Multiple Choice', 'choices' => [['text' => 'Yes', 'is_correct' => true]]])['question']['id'];
        $essay = $this->add(['question_text' => 'Essay', 'question_type' => 'Essay'])['question']['id'];
        $archived = $this->add(['question_text' => 'Old', 'question_type' => 'Essay'])['question']['id'];
        db_connect()->table('assessment_questions')->where('id', $archived)->update(['status' => 'Archived']);

        $list = $this->service->listQuestions($this->quiz);

        $this->assertTrue($list['success']);
        $this->assertSame([(int) $mc, (int) $essay], array_map('intval', array_column($list['questions'], 'id')));
        $this->assertCount(1, $list['questions'][0]['choices']);
        $this->assertSame([], $list['questions'][1]['choices']);
    }

    public function testGettingOneQuestion(): void
    {
        $id = $this->add(['question_text' => 'Q', 'question_type' => 'Essay'])['question']['id'];

        $this->assertSame('Q', $this->service->getQuestion((int) $id)['question']['question_text']);
        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $this->service->getQuestion(999999));
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    public function testEditingChangesOnlyWhatIsSent(): void
    {
        $id = (int) $this->add(['question_text' => 'Original', 'question_type' => 'Essay', 'points' => 4, 'is_required' => true])['question']['id'];

        $result = $this->service->updateQuestion($this->instructor, $id, ['question_text' => 'Edited']);

        $this->assertTrue($result['success']);
        $row = $this->row('assessment_questions', $id);
        $this->assertSame('Edited', $row['question_text']);
        $this->assertEquals(4, $row['points']);
        $this->assertSame(1, (int) $row['is_required']);
        $this->assertSame($this->instructor->id, (int) $row['updated_by']);
        $this->assertNotNull($row['updated_at']);
    }

    public function testRequiredCanBeSwitchedOffByNamingIt(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Essay', 'is_required' => true])['question']['id'];

        $this->service->updateQuestion($this->instructor, $id, ['is_required' => false]);

        $this->assertSame(0, (int) $this->row('assessment_questions', $id)['is_required']);
    }

    public function testChangingPointsUpdatesTheQuizTotal(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Essay', 'points' => 4])['question']['id'];
        $this->add(['question_text' => 'R', 'question_type' => 'Essay', 'points' => 6]);

        $this->service->updateQuestion($this->instructor, $id, ['points' => 10]);

        $this->assertSame(16.0, $this->total());
    }

    public function testChoicesAreReplacedOnlyWhenSent(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'choices' => [['text' => 'A', 'is_correct' => true], ['text' => 'B']]])['question']['id'];

        $this->service->updateQuestion($this->instructor, $id, ['question_text' => 'Q2']);
        $this->assertSame(['A', 'B'], array_column($this->choices($id), 'choice_text'), 'untouched when no choices are sent');

        $this->service->updateQuestion($this->instructor, $id, ['choices' => [['text' => 'C', 'is_correct' => true]]]);
        $this->assertSame(['C'], array_column($this->choices($id), 'choice_text'));

        $this->service->updateQuestion($this->instructor, $id, ['choices' => []]);
        $this->assertSame([], $this->choices($id));
    }

    public function testChangingTheCorrectChoiceKeepsWhatAStudentAnswered(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'choices' => [
            ['text' => 'A'], ['text' => 'B'], ['text' => 'C'], ['text' => 'D', 'is_correct' => true],
        ]])['question']['id'];

        $choiceA = (int) $this->choices($id)[0]['id'];
        $attempt = AssessmentFactory::attempt($this->quiz, CourseFactory::user('student'), 'Submitted');
        AssessmentFactory::response($attempt, $id, ['selected_choice_id' => $choiceA]);

        $this->service->updateQuestion($this->instructor, $id, ['choices' => [
            ['text' => 'A'], ['text' => 'B', 'is_correct' => true], ['text' => 'C'], ['text' => 'D'],
        ]]);

        $response = db_connect()->table('assessment_question_responses')->where('question_id', $id)->get()->getRowArray();
        $this->assertSame($choiceA, (int) $response['selected_choice_id'], "the student's answer is still on choice A");
        $this->assertSame([0, 1, 0, 0], array_map('intval', array_column($this->choices($id), 'is_correct')));
        $this->assertSame($choiceA, (int) $this->choices($id)[0]['id'], 'choice A is the same row');
    }

    public function testChangingTheCorrectChoiceRegradesAnswersAlreadyHandedIn(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'points' => 2, 'choices' => [
            ['text' => 'A', 'is_correct' => true], ['text' => 'B'], ['text' => 'C'],
        ]])['question']['id'];

        $choiceA = (int) $this->choices($id)[0]['id'];
        $student = CourseFactory::user('student');
        $attempt = AssessmentFactory::attempt($this->quiz, $student, 'Graded', 2.0, ['percentage' => 100]);
        $response = AssessmentFactory::response($attempt, $id, [
            'selected_choice_id' => $choiceA,
            'score' => 2.0,
            'is_auto_graded' => 1,
        ]);

        $this->service->updateQuestion($this->instructor, $id, ['choices' => [
            ['text' => 'A'], ['text' => 'B', 'is_correct' => true], ['text' => 'C'],
        ]]);

        $this->assertEquals(0.0, (float) $this->row('assessment_question_responses', $response)['score'], 'A is no longer the key');
        $submission = $this->row('assessment_submissions', $attempt);
        $this->assertEquals(0.0, (float) $submission['score']);
        $this->assertEquals(0.0, (float) $submission['percentage']);

        // And back again: the same answer is correct once more.
        $this->service->updateQuestion($this->instructor, $id, ['choices' => [
            ['text' => 'A', 'is_correct' => true], ['text' => 'B'], ['text' => 'C'],
        ]]);

        $this->assertEquals(2.0, (float) $this->row('assessment_question_responses', $response)['score']);
        $this->assertEquals(2.0, (float) $this->row('assessment_submissions', $attempt)['score']);
    }

    public function testAnAnswerTheInstructorGradedByHandIsNotRegraded(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'points' => 2, 'choices' => [
            ['text' => 'A', 'is_correct' => true], ['text' => 'B'],
        ]])['question']['id'];

        $choiceB = (int) $this->choices($id)[1]['id'];
        $attempt = AssessmentFactory::attempt($this->quiz, CourseFactory::user('student'), 'Graded', 2.0);
        $response = AssessmentFactory::response($attempt, $id, [
            'selected_choice_id' => $choiceB,
            'score' => 2.0,
            'is_auto_graded' => 0,
        ]);

        $this->service->updateQuestion($this->instructor, $id, ['choices' => [['text' => 'A'], ['text' => 'B', 'is_correct' => false]]]);

        $this->assertEquals(2.0, (float) $this->row('assessment_question_responses', $response)['score'], "the instructor's own mark stands");
    }

    public function testAnEditedChoiceTextAndAReorderKeepTheStudentsPick(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'choices' => [
            ['text' => 'Red', 'is_correct' => true], ['text' => 'Blue'], ['text' => 'Green'],
        ]])['question']['id'];

        $byText = array_column($this->choices($id), 'id', 'choice_text');
        $attempt = AssessmentFactory::attempt($this->quiz, CourseFactory::user('student'), 'Submitted');
        AssessmentFactory::response($attempt, $id, ['selected_choice_id' => (int) $byText['Blue']]);

        // Blue moves to the front (same text), Green gets a typo fix, Yellow is new, Red is removed.
        $this->service->updateQuestion($this->instructor, $id, ['choices' => [
            ['text' => 'Blue'], ['text' => 'Greene', 'is_correct' => true], ['text' => 'Yellow'],
        ]]);

        $rows = $this->choices($id);
        $this->assertSame(['Blue', 'Greene', 'Yellow'], array_column($rows, 'choice_text'));
        $this->assertSame((int) $byText['Blue'], (int) $rows[0]['id']);
        $this->assertSame((int) $byText['Green'], (int) $rows[1]['id'], 'the typo fix stays on the same row');
        $this->assertNotContains((int) $byText['Red'], array_map('intval', array_column($rows, 'id')), 'an unrelated new choice does not reuse a removed row');
        $this->assertSame(3, count($rows));

        $response = db_connect()->table('assessment_question_responses')->where('question_id', $id)->get()->getRowArray();
        $this->assertSame((int) $byText['Blue'], (int) $response['selected_choice_id']);
    }

    public function testAPartialUpdateKeepsTheShortAnswerKeyAndCanFlipJustTheCaseToggle(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Short Answer', 'accepted_answers' => ['Paris', 'paris france']])['question']['id'];

        $this->service->updateQuestion($this->instructor, $id, ['case_sensitive' => true]);

        $row = $this->service->getQuestion($id)['question'];
        $this->assertSame(['Paris', 'paris france'], $row['accepted_answers']);
        $this->assertSame(1, (int) $row['case_sensitive']);

        $this->service->updateQuestion($this->instructor, $id, ['accepted_answers' => ['Lyon']]);
        $row = $this->service->getQuestion($id)['question'];
        $this->assertSame(['Lyon'], $row['accepted_answers']);
        $this->assertSame(1, (int) $row['case_sensitive'], 'still on');
    }

    public function testTurningAShortAnswerIntoAnEssayDropsItsKey(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Short Answer', 'accepted_answers' => ['Paris'], 'case_sensitive' => true])['question']['id'];

        $this->service->updateQuestion($this->instructor, $id, ['question_type' => 'Essay']);

        $row = $this->service->getQuestion($id)['question'];
        $this->assertSame([], $row['accepted_answers']);
        $this->assertSame(0, (int) $row['case_sensitive']);
    }

    public function testMultipleAnswersCanBeTurnedOnAndOffWhileKeepingTheRest(): void
    {
        $id = (int) $this->add(['question_text' => 'Q', 'question_type' => 'Multiple Choice', 'allow_multiple_answers' => true, 'answers_count' => 3])['question']['id'];

        $this->service->updateQuestion($this->instructor, $id, ['question_text' => 'Renamed']);
        $this->assertSame(3, (int) $this->row('assessment_questions', $id)['answers_count'], 'untouched');

        $this->service->updateQuestion($this->instructor, $id, ['allow_multiple_answers' => false]);
        $row = $this->row('assessment_questions', $id);
        $this->assertSame(0, (int) $row['allow_multiple_answers']);
        $this->assertNull($row['answers_count']);
    }

    public function testEditingAMissingQuestionIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $this->service->updateQuestion($this->instructor, 999999, ['points' => 1]));
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicating
    |--------------------------------------------------------------------------
    */

    public function testADuplicateCopiesTheQuestionAndItsChoicesAndSitsRightAfterTheOriginal(): void
    {
        $first = (int) $this->add(['question_text' => 'First', 'question_type' => 'Essay', 'points' => 1])['question']['id'];
        $mc = (int) $this->add(['question_text' => 'MC', 'question_type' => 'Multiple Choice', 'points' => 2, 'choices' => [['text' => 'A', 'is_correct' => true], ['text' => 'B']]])['question']['id'];
        $last = (int) $this->add(['question_text' => 'Last', 'question_type' => 'Essay', 'points' => 3])['question']['id'];

        $result = $this->service->duplicateQuestion($this->instructor, $mc);

        $this->assertTrue($result['success']);
        $copy = (int) $result['question']['id'];
        $this->assertNotSame($mc, $copy);
        $this->assertSame('MC', $result['question']['question_text']);
        $this->assertSame(['A', 'B'], array_column($result['question']['choices'], 'choice_text'));
        $this->assertSame([1, 0], array_map('intval', array_column($result['question']['choices'], 'is_correct')));
        $this->assertNotSame(array_column($this->choices($mc), 'id'), array_column($this->choices($copy), 'id'), 'the choices are copies, not shared');
        $this->assertSame([$first, $mc, $copy, $last], $this->order(), 'right after the original, before the next question');
        $this->assertSame(8.0, $this->total());
    }

    public function testDuplicatingTheLastQuestionAppendsIt(): void
    {
        $a = (int) $this->add(['question_text' => 'A', 'question_type' => 'Essay'])['question']['id'];
        $b = (int) $this->add(['question_text' => 'B', 'question_type' => 'Essay'])['question']['id'];

        $copy = (int) $this->service->duplicateQuestion($this->instructor, $b)['question']['id'];

        $this->assertSame([$a, $b, $copy], $this->order());
    }

    public function testADuplicateKeepsTheShortAnswerKeyAndMultipleAnswerSettings(): void
    {
        $short = (int) $this->add(['question_text' => 'S', 'question_type' => 'Short Answer', 'accepted_answers' => ['Paris', 'paris france'], 'case_sensitive' => true])['question']['id'];
        $multi = (int) $this->add(['question_text' => 'M', 'question_type' => 'Multiple Choice', 'allow_multiple_answers' => true, 'answers_count' => 3])['question']['id'];

        $shortCopy = $this->service->duplicateQuestion($this->instructor, $short)['question'];
        $multiCopy = $this->service->duplicateQuestion($this->instructor, $multi)['question'];

        $this->assertSame(['Paris', 'paris france'], $shortCopy['accepted_answers']);
        $this->assertSame(1, (int) $shortCopy['case_sensitive']);
        $this->assertSame(1, (int) $multiCopy['allow_multiple_answers']);
        $this->assertSame(3, (int) $multiCopy['answers_count']);
    }

    public function testDuplicatingAMissingQuestionIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $this->service->duplicateQuestion($this->instructor, 999999));
    }

    /*
    |--------------------------------------------------------------------------
    | Reordering, deleting, clearing
    |--------------------------------------------------------------------------
    */

    public function testReorderingRenumbersInTheOrderGiven(): void
    {
        $a = (int) $this->add(['question_text' => 'A', 'question_type' => 'Essay'])['question']['id'];
        $b = (int) $this->add(['question_text' => 'B', 'question_type' => 'Essay'])['question']['id'];
        $c = (int) $this->add(['question_text' => 'C', 'question_type' => 'Essay'])['question']['id'];

        $result = $this->service->reorderQuestions($this->quiz, [$c, $a, $b]);

        $this->assertTrue($result['success']);
        $this->assertSame([$c, $a, $b], $this->order());
        $this->assertSame([1, 2, 3], array_map(fn ($id) => (int) $this->row('assessment_questions', $id)['order_index'], [$c, $a, $b]));
    }

    public function testReorderingNeverTouchesQuestionsOfAnotherAssessment(): void
    {
        $mine = (int) $this->add(['question_text' => 'Mine', 'question_type' => 'Essay'])['question']['id'];
        $otherQuiz = CourseFactory::assessment(CourseFactory::offering('B'), $this->instructor, CourseFactory::module(CourseFactory::offering('C')));
        $other = AssessmentFactory::question($otherQuiz, $this->instructor, 'Essay', 1);

        // The foreign id comes second, so it would be renumbered 2 if it were let in.
        $this->service->reorderQuestions($this->quiz, [$mine, $other]);

        $this->assertSame(1, (int) $this->row('assessment_questions', $other)['order_index'], 'the other quiz is untouched');
        $this->assertSame(1, (int) $this->row('assessment_questions', $mine)['order_index']);
        $this->assertSame([$mine], $this->order());
    }

    public function testDeletingAQuestionRemovesItsChoicesCloseTheGapAndUpdatesTheTotal(): void
    {
        $a = (int) $this->add(['question_text' => 'A', 'question_type' => 'Essay', 'points' => 2])['question']['id'];
        $b = (int) $this->add(['question_text' => 'B', 'question_type' => 'Multiple Choice', 'points' => 3, 'choices' => [['text' => 'x', 'is_correct' => true]]])['question']['id'];
        $c = (int) $this->add(['question_text' => 'C', 'question_type' => 'Essay', 'points' => 4])['question']['id'];

        $result = $this->service->deleteQuestion($b);

        $this->assertTrue($result['success']);
        $this->assertNull($this->row('assessment_questions', $b));
        $this->assertSame([], $this->choices($b));
        $this->assertSame([$a, $c], $this->order());
        $this->assertSame([1, 2], array_map(fn ($id) => (int) $this->row('assessment_questions', $id)['order_index'], [$a, $c]));
        $this->assertSame(6.0, $this->total());
    }

    public function testDeletingAQuestionTakesItsStudentAnswersWithIt(): void
    {
        $id = (int) $this->add(['question_text' => 'A', 'question_type' => 'Essay'])['question']['id'];
        $student = CourseFactory::user('student');
        $attempt = AssessmentFactory::attempt($this->quiz, $student, 'Submitted');
        AssessmentFactory::response($attempt, $id, ['response_text' => 'My answer']);

        $this->service->deleteQuestion($id);

        $this->assertSame(0, db_connect()->table('assessment_question_responses')->where('question_id', $id)->countAllResults());
        $this->assertNotNull($this->row('assessment_submissions', $attempt), 'the attempt itself stays');
    }

    public function testDeletingAMissingQuestionIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $this->service->deleteQuestion(999999));
    }

    public function testClearingRemovesEveryQuestionAndZeroesTheTotal(): void
    {
        $this->add(['question_text' => 'A', 'question_type' => 'Multiple Choice', 'points' => 2, 'choices' => [['text' => 'x']]]);
        $this->add(['question_text' => 'B', 'question_type' => 'Essay', 'points' => 3]);
        $otherQuiz = CourseFactory::assessment(CourseFactory::offering('B'), $this->instructor, CourseFactory::module(CourseFactory::offering('C')));
        $keep = AssessmentFactory::question($otherQuiz, $this->instructor, 'Essay', 1);

        $result = $this->service->clearAllQuestions($this->quiz);

        $this->assertTrue($result['success']);
        $this->assertSame([], $this->order());
        $this->assertSame(0.0, $this->total());
        $this->assertSame(0, db_connect()->table('assessment_question_choices')->countAllResults());
        $this->assertNotNull($this->row('assessment_questions', $keep), 'another quiz is untouched');
    }

    public function testClearingAMissingAssessmentIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->service->clearAllQuestions(999999));
    }
}
