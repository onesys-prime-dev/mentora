<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Taking a quiz: starting (or resuming) an attempt, the attempt limit and the
 * availability window, saving answers, and submitting: what grades itself
 * (multiple choice, short answers with a key), what waits for the
 * instructor, the status that results, lateness, and the gradebook.
 *
 * @internal
 */
final class QuizAttemptServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuizAttemptService $service;
    private int $offeringId;
    private int $moduleId;
    private int $quiz;
    private User $instructor;
    private User $ada;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QuizAttemptService();
        $this->offeringId = CourseFactory::offering();
        $this->moduleId = CourseFactory::module($this->offeringId);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
        $this->ada = CourseFactory::user('student');
        $this->bob = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $this->ada);
        CourseFactory::enroll($this->offeringId, $this->bob);

        $this->quiz = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId);
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 2]);
    }

    private function row(string $table, int $id): ?array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function attemptsOf(User $student, ?int $quiz = null): array
    {
        return db_connect()->table('assessment_submissions')
            ->where('assessment_id', $quiz ?? $this->quiz)->where('student_id', $student->id)
            ->orderBy('id')->get()->getResultArray();
    }

    private function start(User $student, ?int $quiz = null): array
    {
        return $this->service->startAttempt($student, $quiz ?? $this->quiz);
    }

    /*
    |--------------------------------------------------------------------------
    | Starting
    |--------------------------------------------------------------------------
    */

    public function testStartingCreatesAnAttemptInProgress(): void
    {
        $result = $this->start($this->ada);

        $this->assertIsString($result['html']);
        $attempts = $this->attemptsOf($this->ada);
        $this->assertCount(1, $attempts);
        $this->assertSame('In Progress', $attempts[0]['status']);
        $this->assertSame(1, (int) $attempts[0]['attempt_number']);
        $this->assertNotNull($attempts[0]['started_at']);
    }

    public function testOpeningTheQuizAgainResumesTheRunningAttempt(): void
    {
        $this->start($this->ada);
        $this->start($this->ada);

        $this->assertCount(1, $this->attemptsOf($this->ada));
    }

    public function testAttemptsAreNumberedAfterTheFinishedOnes(): void
    {
        AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 3);

        $this->start($this->ada);

        $attempts = $this->attemptsOf($this->ada);
        $this->assertCount(2, $attempts);
        $this->assertSame(2, (int) $attempts[1]['attempt_number']);
    }

    public function testTheAttemptLimitStopsANewAttempt(): void
    {
        AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 3);
        AssessmentFactory::attempt($this->quiz, $this->ada, 'Submitted', 0);

        $this->start($this->ada);

        $this->assertCount(2, $this->attemptsOf($this->ada), 'no third attempt');
    }

    public function testACancelledAttemptStillUsesUpAChance(): void
    {
        AssessmentFactory::attempt($this->quiz, $this->ada, 'Cancelled');
        AssessmentFactory::attempt($this->quiz, $this->ada, 'Graded', 3);

        $this->start($this->ada);

        $this->assertCount(2, $this->attemptsOf($this->ada));
    }

    public function testTheDefaultLimitIsOneAttempt(): void
    {
        $single = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId);
        AssessmentFactory::open($single, ['attempt_limit' => null]);
        AssessmentFactory::attempt($single, $this->ada, 'Graded', 1);

        $this->start($this->ada, $single);

        $this->assertCount(1, $this->attemptsOf($this->ada, $single));
    }

    public function testAClosedQuizDoesNotStartANewAttempt(): void
    {
        AssessmentFactory::open($this->quiz, ['status' => 'Closed']);

        $this->start($this->ada);

        $this->assertCount(0, $this->attemptsOf($this->ada));
    }

    public function testAQuizThatHasNotOpenedYetDoesNotStart(): void
    {
        AssessmentFactory::open($this->quiz, ['available_from' => date('Y-m-d H:i:s', strtotime('+2 days'))]);

        $this->start($this->ada);

        $this->assertCount(0, $this->attemptsOf($this->ada));
    }

    public function testAQuizPastItsAvailabilityWindowDoesNotStart(): void
    {
        AssessmentFactory::open($this->quiz, ['available_until' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);

        $this->start($this->ada);

        $this->assertCount(0, $this->attemptsOf($this->ada));
    }

    public function testAfterTheDueDateOnlyAQuizAllowingLateAttemptsStarts(): void
    {
        AssessmentFactory::open($this->quiz, ['due_date' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);

        $this->start($this->ada);
        $this->assertCount(0, $this->attemptsOf($this->ada), 'late attempts are off');

        db_connect()->table('assessment_settings')->insert([
            'assessment_id' => $this->quiz, 'allow_late_submission' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        db_connect()->table('assessment_settings')->where('assessment_id', $this->quiz)->update(['allow_late_submission' => 1]);

        $this->start($this->ada);
        $this->assertCount(1, $this->attemptsOf($this->ada));
    }

    public function testARunningAttemptCanBeResumedAfterTheQuizCloses(): void
    {
        $this->start($this->ada);
        AssessmentFactory::open($this->quiz, ['status' => 'Closed']);

        $this->start($this->ada);

        $this->assertCount(1, $this->attemptsOf($this->ada));
    }

    public function testStudentsCannotOpenADraftQuiz(): void
    {
        AssessmentFactory::open($this->quiz, ['status' => 'Draft']);

        $this->expectException(PageNotFoundException::class);

        $this->start($this->ada);
    }

    public function testAQuizThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->startAttempt($this->ada, 999999);
    }

    public function testAnItemBehindAnUnfinishedPrerequisiteDoesNotStart(): void
    {
        $unit = CourseFactory::unit($this->moduleId);
        [$first, $second] = CourseFactory::items($unit, [[], ['type' => 'quiz', 'mode' => 'previous']]);
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['learning_item_id' => $second]);

        $this->start($this->ada);
        $this->assertCount(0, $this->attemptsOf($this->ada), 'locked');

        CourseFactory::progress($first, $this->ada);
        $this->start($this->ada);
        $this->assertCount(1, $this->attemptsOf($this->ada), 'unlocked once the first item is done');
    }

    public function testTheInstructorCanPreviewAnyQuizWhateverItsState(): void
    {
        AssessmentFactory::open($this->quiz, ['status' => 'Draft']);

        $result = $this->start($this->instructor);

        $this->assertIsString($result['html']);
    }

    public function testStudentsOnlyGetTheirOwnAttempts(): void
    {
        $this->start($this->ada);

        $this->assertCount(0, $this->attemptsOf($this->bob));
    }

    public function testAStudentNotEnrolledInTheQuizsSectionCannotStartIt(): void
    {
        $outsider = CourseFactory::user('student');
        CourseFactory::enroll(CourseFactory::offering('B'), $outsider);

        try {
            $this->start($outsider);
            $this->fail('an outsider started the quiz');
        } catch (PageNotFoundException) {
            $this->assertCount(0, $this->attemptsOf($outsider));
        }
    }

    public function testAStudentWhoDroppedTheCourseCannotStartItsQuiz(): void
    {
        db_connect()->table('enrollments')->where('student_user_id', $this->bob->id)->update(['status' => 'dropped']);

        $this->expectException(PageNotFoundException::class);

        $this->start($this->bob);
    }

    public function testTheQuizMustBelongToTheCourseItIsOpenedFrom(): void
    {
        $other = CourseFactory::offering('B');
        $this->expectException(PageNotFoundException::class);

        $this->service->startAttempt($this->ada, $this->quiz, $other);
    }

    public function testTheQuizOpensFromItsOwnCourse(): void
    {
        $this->assertIsString($this->service->startAttempt($this->ada, $this->quiz, $this->offeringId)['html']);
    }

    /*
    |--------------------------------------------------------------------------
    | Saving answers
    |--------------------------------------------------------------------------
    */

    /** An in-progress attempt of Ada's with a question of each kind. @return array{attempt: int, mc: array, multi: array, short: int, essay: int} */
    private function running(): array
    {
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true, false], 2);
        $multi = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [true, true, false], 3);
        $short = AssessmentFactory::shortAnswer($this->quiz, $this->instructor, ['Paris', 'paris france'], 2);
        $essay = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 5);

        return ['attempt' => AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress'), 'mc' => $mc, 'multi' => $multi, 'short' => $short, 'essay' => $essay];
    }

    private function response(int $attempt, int $question): ?array
    {
        return db_connect()->table('assessment_question_responses')
            ->where('submission_id', $attempt)->where('question_id', $question)->get()->getRowArray();
    }

    public function testAChoiceIsSaved(): void
    {
        $q = $this->running();

        $result = $this->service->saveAnswer($this->ada, $q['attempt'], $q['mc']['question'], ['selected_choice_id' => $q['mc']['choices'][1]]);

        $this->assertTrue($result['success']);
        $row = $this->response($q['attempt'], $q['mc']['question']);
        $this->assertSame($q['mc']['choices'][1], (int) $row['selected_choice_id']);
        $this->assertNull($row['selected_choice_ids']);
    }

    public function testSeveralChoicesAreSavedAsIntegers(): void
    {
        $q = $this->running();
        [$a, $b] = $q['multi']['choices'];

        $this->service->saveAnswer($this->ada, $q['attempt'], $q['multi']['question'], ['selected_choice_ids' => [(string) $a, (string) $b], 'selected_choice_id' => 99]);

        $row = $this->response($q['attempt'], $q['multi']['question']);
        $this->assertSame([$a, $b], json_decode($row['selected_choice_ids'], true));
        $this->assertNull($row['selected_choice_id'], 'a multiple-answer question stores the list only');
    }

    public function testTextAnswersAreSavedForShortAnswerAndEssayOnly(): void
    {
        $q = $this->running();

        $this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris']);
        $this->service->saveAnswer($this->ada, $q['attempt'], $q['essay'], ['response_text' => 'A long answer']);
        $this->service->saveAnswer($this->ada, $q['attempt'], $q['mc']['question'], ['response_text' => 'ignored']);

        $this->assertSame('Paris', $this->response($q['attempt'], $q['short'])['response_text']);
        $this->assertSame('A long answer', $this->response($q['attempt'], $q['essay'])['response_text']);
        $this->assertNull($this->response($q['attempt'], $q['mc']['question'])['response_text']);
    }

    public function testSavingAgainReplacesTheAnswerInsteadOfAddingOne(): void
    {
        $q = $this->running();

        $this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Lyon']);
        $this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris']);

        $this->assertSame(1, db_connect()->table('assessment_question_responses')->where('submission_id', $q['attempt'])->where('question_id', $q['short'])->countAllResults());
        $this->assertSame('Paris', $this->response($q['attempt'], $q['short'])['response_text']);
    }

    public function testNobodyElseCanAnswerForAStudent(): void
    {
        $q = $this->running();

        $result = $this->service->saveAnswer($this->bob, $q['attempt'], $q['short'], ['response_text' => 'Paris']);

        $this->assertSame(['success' => false, 'message' => 'Submission not found.'], $result);
        $this->assertNull($this->response($q['attempt'], $q['short']));
    }

    public function testAnAttemptHandedInCannotBeChanged(): void
    {
        $q = $this->running();
        db_connect()->table('assessment_submissions')->where('id', $q['attempt'])->update(['status' => 'Submitted']);

        $result = $this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris']);

        $this->assertSame('This attempt is no longer in progress.', $result['message']);
        $this->assertNull($this->response($q['attempt'], $q['short']));
    }

    public function testAnAnswerCannotBeSavedForAQuestionOfAnotherQuiz(): void
    {
        $q = $this->running();
        $otherQuiz = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId);
        $foreign = AssessmentFactory::shortAnswer($otherQuiz, $this->instructor, ['Paris']);

        $result = $this->service->saveAnswer($this->ada, $q['attempt'], $foreign, ['response_text' => 'Paris']);

        $this->assertSame(['success' => false, 'message' => 'Question not found.'], $result);
        $this->assertNull($this->response($q['attempt'], $foreign));
    }

    public function testAnAnswerCannotBeSavedForAnArchivedQuestion(): void
    {
        $q = $this->running();
        db_connect()->table('assessment_questions')->where('id', $q['short'])->update(['status' => 'Archived']);

        $result = $this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris']);

        $this->assertFalse($result['success']);
        $this->assertNull($this->response($q['attempt'], $q['short']));
    }

    private function timedAttempt(int $minutes, string $startedAgo): array
    {
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['duration' => $minutes]);
        $q = $this->running();
        db_connect()->table('assessment_submissions')->where('id', $q['attempt'])->update(['started_at' => date('Y-m-d H:i:s', strtotime($startedAgo))]);

        return $q;
    }

    public function testAnswersAreAcceptedWhileTimeRemains(): void
    {
        $q = $this->timedAttempt(10, '-9 minutes');

        $this->assertTrue($this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris'])['success']);
    }

    public function testAnswersAreAcceptedForAFewSecondsAfterTheTimerEndsToAllowForLatency(): void
    {
        $q = $this->timedAttempt(10, '-10 minutes -30 seconds');

        $this->assertTrue($this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris'])['success']);
    }

    public function testNoAnswersAreAcceptedOnceTheTimeLimitHasPassed(): void
    {
        $q = $this->timedAttempt(10, '-3 hours');

        $result = $this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris']);

        $this->assertSame(['success' => false, 'message' => 'Time is up for this attempt.'], $result);
        $this->assertNull($this->response($q['attempt'], $q['short']));
    }

    public function testAQuizWithoutATimeLimitHasNoDeadline(): void
    {
        $q = $this->timedAttempt(0, '-3 hours');

        $this->assertTrue($this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris'])['success']);
    }

    public function testAnExpiredAttemptCanStillBeSubmittedButOnlyScoresWhatWasSavedInTime(): void
    {
        $q = $this->timedAttempt(10, '-5 minutes');
        $this->service->saveAnswer($this->ada, $q['attempt'], $q['short'], ['response_text' => 'Paris']);
        db_connect()->table('assessment_submissions')->where('id', $q['attempt'])->update(['started_at' => date('Y-m-d H:i:s', strtotime('-3 hours'))]);

        $late = $this->service->saveAnswer($this->ada, $q['attempt'], $q['mc']['question'], ['selected_choice_id' => $q['mc']['choices'][1]]);
        $submitted = $this->service->submitAttempt($this->ada, $q['attempt']);

        $this->assertFalse($late['success']);
        $this->assertTrue($submitted['success'], 'the browser may submit a moment late: the work is not lost');
        $this->assertEquals(2, $this->row('assessment_submissions', $q['attempt'])['score'], 'only the short answer saved in time scored');
    }

    public function testMissingAttemptsAndQuestionsAreRefused(): void
    {
        $q = $this->running();

        $this->assertSame('Submission not found.', $this->service->saveAnswer($this->ada, 999999, $q['short'], [])['message']);
        $this->assertSame('Question not found.', $this->service->saveAnswer($this->ada, $q['attempt'], 999999, [])['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Marking a question
    |--------------------------------------------------------------------------
    */

    private function question(int $id): array
    {
        return (new App\Models\Assessments\AssessmentQuestionModel())->getWithChoices($id);
    }

    public function testASingleChoiceQuestionIsAllOrNothing(): void
    {
        $q = $this->running();
        $question = $this->question($q['mc']['question']);

        $right = ['selected_choice_id' => $q['mc']['choices'][1], 'selected_choice_ids' => null];
        $wrong = ['selected_choice_id' => $q['mc']['choices'][0], 'selected_choice_ids' => null];

        $this->assertSame(2.0, $this->service->computeAutoScore($question, $right));
        $this->assertSame(0.0, $this->service->computeAutoScore($question, $wrong));
        $this->assertSame(0.0, $this->service->computeAutoScore($question, ['selected_choice_id' => null, 'selected_choice_ids' => null]));
        $this->assertSame(0.0, $this->service->computeAutoScore($question, null), 'unanswered');
    }

    public function testAMultipleAnswerQuestionNeedsTheExactSetOfCorrectChoices(): void
    {
        $q = $this->running();
        $question = $this->question($q['multi']['question']);
        [$a, $b, $c] = $q['multi']['choices'];
        $answer = static fn (array $ids) => ['selected_choice_id' => null, 'selected_choice_ids' => json_encode($ids)];

        $this->assertSame(3.0, $this->service->computeAutoScore($question, $answer([$a, $b])));
        $this->assertSame(3.0, $this->service->computeAutoScore($question, $answer([$b, $a])), 'order does not matter');
        $this->assertSame(0.0, $this->service->computeAutoScore($question, $answer([$a])), 'no partial credit');
        $this->assertSame(0.0, $this->service->computeAutoScore($question, $answer([$a, $b, $c])), 'a wrong extra choice');
        $this->assertSame(0.0, $this->service->computeAutoScore($question, $answer([])));
        $this->assertSame(0.0, $this->service->computeAutoScore($question, ['selected_choice_ids' => null]));
        $this->assertSame(0.0, $this->service->computeAutoScore($question, null));
    }

    public function testAQuestionWithNoCorrectChoiceNeverScores(): void
    {
        $none = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, false]);
        $question = $this->question($none['question']);

        $this->assertSame(0.0, $this->service->computeAutoScore($question, ['selected_choice_id' => $none['choices'][0], 'selected_choice_ids' => null]));
    }

    public function testAShortAnswerIsMarkedAgainstItsKey(): void
    {
        $q = $this->running();
        $question = $this->question($q['short']);

        $this->assertSame(2.0, $this->service->computeAutoScore($question, ['response_text' => '  PARIS ']));
        $this->assertSame(2.0, $this->service->computeAutoScore($question, ['response_text' => 'paris   france']));
        $this->assertSame(0.0, $this->service->computeAutoScore($question, ['response_text' => 'Lyon']));
        $this->assertSame(0.0, $this->service->computeAutoScore($question, null));
    }

    public function testOnlyChoiceQuestionsAndKeyedShortAnswersGradeThemselves(): void
    {
        $q = $this->running();
        $keyless = AssessmentFactory::shortAnswer($this->quiz, $this->instructor, null);

        $this->assertTrue($this->service->isAutoGradable($this->question($q['mc']['question'])));
        $this->assertTrue($this->service->isAutoGradable($this->question($q['short'])));
        $this->assertFalse($this->service->isAutoGradable($this->question($keyless)));
        $this->assertFalse($this->service->isAutoGradable($this->question($q['essay'])));
    }

    /*
    |--------------------------------------------------------------------------
    | Submitting
    |--------------------------------------------------------------------------
    */

    /** Ada's running attempt of a quiz made only of auto-graded questions, answered right. */
    private function fullyAutoGraded(): int
    {
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true], 4);
        $short = AssessmentFactory::shortAnswer($this->quiz, $this->instructor, ['Paris'], 6);
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 10]);

        $attempt = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');
        $this->service->saveAnswer($this->ada, $attempt, $mc['question'], ['selected_choice_id' => $mc['choices'][1]]);
        $this->service->saveAnswer($this->ada, $attempt, $short, ['response_text' => 'paris']);

        return $attempt;
    }

    public function testAQuizOfOnlyAutoGradedQuestionsIsGradedOnSubmit(): void
    {
        $attempt = $this->fullyAutoGraded();

        $result = $this->service->submitAttempt($this->ada, $attempt);

        $this->assertTrue($result['success']);
        $row = $this->row('assessment_submissions', $attempt);
        $this->assertSame('Graded', $row['status']);
        $this->assertSame(1, (int) $row['is_graded']);
        $this->assertEquals(10, $row['score']);
        $this->assertEquals(100, $row['percentage']);
        $this->assertNotNull($row['submitted_at']);
        $this->assertNotNull($row['graded_at']);
        $this->assertSame($row['id'], $result['submission']['id']);
    }

    public function testEachAutoGradedAnswerIsScoredAndMarked(): void
    {
        $attempt = $this->fullyAutoGraded();

        $this->service->submitAttempt($this->ada, $attempt);

        $responses = db_connect()->table('assessment_question_responses')->where('submission_id', $attempt)->orderBy('id')->get()->getResultArray();
        $this->assertEquals([4, 6], array_column($responses, 'score'));
        $this->assertSame([1, 1], array_map('intval', array_column($responses, 'is_auto_graded')));
        $this->assertNotContains(null, array_column($responses, 'graded_at'));
    }

    public function testAWrongAnswerScoresZero(): void
    {
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true], 4);
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 4]);
        $attempt = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');
        $this->service->saveAnswer($this->ada, $attempt, $mc['question'], ['selected_choice_id' => $mc['choices'][0]]);

        $this->service->submitAttempt($this->ada, $attempt);

        $row = $this->row('assessment_submissions', $attempt);
        $this->assertEquals(0, $row['score']);
        $this->assertEquals(0, $row['percentage']);
        $this->assertSame('Graded', $row['status'], 'graded, just with no points');
    }

    public function testQuestionsLeftUnansweredScoreZeroWithoutAResponseRow(): void
    {
        AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true], 4);
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 4]);
        $attempt = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');

        $this->service->submitAttempt($this->ada, $attempt);

        $this->assertEquals(0, $this->row('assessment_submissions', $attempt)['score']);
        $this->assertSame('Graded', $this->row('assessment_submissions', $attempt)['status']);
    }

    public function testAnEssayLeavesTheQuizPartiallyGradedWithTheAutoScoreSoFar(): void
    {
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true], 4);
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 6);
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 10]);
        $attempt = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');
        $this->service->saveAnswer($this->ada, $attempt, $mc['question'], ['selected_choice_id' => $mc['choices'][1]]);

        $this->service->submitAttempt($this->ada, $attempt);

        $row = $this->row('assessment_submissions', $attempt);
        $this->assertSame('Partially Graded', $row['status']);
        $this->assertSame(0, (int) $row['is_graded']);
        $this->assertNull($row['graded_at']);
        $this->assertEquals(4, $row['score']);
        $this->assertEquals(40, $row['percentage']);
    }

    public function testAQuizOfOnlyManualQuestionsIsSimplySubmitted(): void
    {
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 10);
        AssessmentFactory::shortAnswer($this->quiz, $this->instructor, null, 5);
        $attempt = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');

        $this->service->submitAttempt($this->ada, $attempt);

        $row = $this->row('assessment_submissions', $attempt);
        $this->assertSame('Submitted', $row['status']);
        $this->assertSame(0, (int) $row['is_graded']);
        $this->assertEquals(0, $row['score']);
    }

    public function testAnEmptyQuizSubmitsAsGradedWithNoPercentage(): void
    {
        $attempt = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');

        $this->service->submitAttempt($this->ada, $attempt);

        $row = $this->row('assessment_submissions', $attempt);
        $this->assertSame('Graded', $row['status']);
        $this->assertEquals(0, $row['percentage']);
    }

    public function testAnAttemptHandedInAfterTheDueDateIsMarkedLate(): void
    {
        $attempt = $this->fullyAutoGraded();
        $this->assertSame(0, (int) $this->row('assessment_submissions', $attempt)['is_late_submission']);
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);

        $this->service->submitAttempt($this->ada, $attempt);

        $this->assertSame(1, (int) $this->row('assessment_submissions', $attempt)['is_late_submission']);
    }

    public function testAnAttemptHandedInBeforeTheDueDateIsNotLate(): void
    {
        $attempt = $this->fullyAutoGraded();

        $this->service->submitAttempt($this->ada, $attempt);

        $this->assertSame(0, (int) $this->row('assessment_submissions', $attempt)['is_late_submission']);
    }

    public function testNobodyElseCanSubmitAStudentsAttempt(): void
    {
        $attempt = $this->fullyAutoGraded();

        $result = $this->service->submitAttempt($this->bob, $attempt);

        $this->assertSame(['success' => false, 'message' => 'Submission not found.'], $result);
        $this->assertSame('In Progress', $this->row('assessment_submissions', $attempt)['status']);
    }

    public function testSubmittingTwiceKeepsTheFirstResult(): void
    {
        $attempt = $this->fullyAutoGraded();
        $this->service->submitAttempt($this->ada, $attempt);
        $before = $this->row('assessment_submissions', $attempt);

        $again = $this->service->submitAttempt($this->ada, $attempt);

        $this->assertFalse($again['success']);
        $this->assertSame('This attempt has already been submitted.', $again['message']);
        $this->assertSame($before, $this->row('assessment_submissions', $attempt));
    }

    public function testAMissingAttemptIsRefused(): void
    {
        $this->assertSame('Submission not found.', $this->service->submitAttempt($this->ada, 999999)['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | The gradebook
    |--------------------------------------------------------------------------
    */

    private function gradebookScore(int $itemId, User $student): ?array
    {
        return db_connect()->table('gradebook_item_scores')->where('grade_item_id', $itemId)->where('student_id', $student->id)->get()->getRowArray();
    }

    public function testAFullyAutoGradedSubmissionReachesTheGradebook(): void
    {
        $scheme = GradeFactory::scheme($this->offeringId);
        $item = GradeFactory::item($scheme, GradeFactory::category($scheme, 'Quizzes', 100), 'Quiz', 10, 1, $this->quiz);
        $attempt = $this->fullyAutoGraded();

        $this->service->submitAttempt($this->ada, $attempt);

        $score = $this->gradebookScore($item, $this->ada);
        $this->assertEquals(10, $score['raw_score']);
        $this->assertSame($attempt, (int) $score['assessment_submission_id']);
    }

    public function testAPartlyGradedSubmissionDoesNotReachTheGradebookYet(): void
    {
        $scheme = GradeFactory::scheme($this->offeringId);
        $item = GradeFactory::item($scheme, GradeFactory::category($scheme, 'Quizzes', 100), 'Quiz', 10, 1, $this->quiz);
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true], 4);
        AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 6);
        $attempt = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');
        $this->service->saveAnswer($this->ada, $attempt, $mc['question'], ['selected_choice_id' => $mc['choices'][1]]);

        $this->service->submitAttempt($this->ada, $attempt);

        $this->assertNull($this->gradebookScore($item, $this->ada));
    }

    public function testASecondBetterAttemptReplacesTheGradebookScore(): void
    {
        $scheme = GradeFactory::scheme($this->offeringId);
        $item = GradeFactory::item($scheme, GradeFactory::category($scheme, 'Quizzes', 100), 'Quiz', 10, 1, $this->quiz);
        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true], 10);
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['total_points' => 10]);

        $first = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');
        $this->service->saveAnswer($this->ada, $first, $mc['question'], ['selected_choice_id' => $mc['choices'][0]]);
        $this->service->submitAttempt($this->ada, $first);
        $this->assertEquals(0, $this->gradebookScore($item, $this->ada)['raw_score']);

        $second = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');
        $this->service->saveAnswer($this->ada, $second, $mc['question'], ['selected_choice_id' => $mc['choices'][1]]);
        $this->service->submitAttempt($this->ada, $second);

        $this->assertEquals(10, $this->gradebookScore($item, $this->ada)['raw_score']);
        $this->assertSame($second, (int) $this->gradebookScore($item, $this->ada)['assessment_submission_id']);
    }
}
