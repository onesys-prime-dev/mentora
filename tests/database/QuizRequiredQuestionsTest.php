<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Submit rules: an attempt cannot be handed in while a required question is unanswered (the server says no even
 * if the button was enabled by hand), unanswered optional questions do not stop it, and a timer that ran out
 * hands in whatever there is. The page marks each question as required or not so the Submit button can follow.
 *
 * @internal
 */
final class QuizRequiredQuestionsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuizAttemptService $service;

    private int $quiz;

    private User $instructor;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new QuizAttemptService();
        $offering         = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($offering, $this->ada);

        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 2]);
    }

    /** @return int the attempt, started with the questions as they are now */
    private function attempt(): int
    {
        $this->service->startAttempt($this->ada, $this->quiz);

        return (int) db_connect()->table('assessment_submissions')->where(['assessment_id' => $this->quiz, 'student_id' => $this->ada->id])->get()->getRowArray()['id'];
    }

    private function required(string $type = 'Short Answer', bool $required = true): int
    {
        return AssessmentFactory::question($this->quiz, $this->instructor, $type, 1.0, ['is_required' => $required ? 1 : 0]);
    }

    private function statusOf(int $attempt): string
    {
        return (string) db_connect()->table('assessment_submissions')->where('id', $attempt)->get()->getRowArray()['status'];
    }

    public function testAnUnansweredRequiredQuestionStopsTheSubmitAndLeavesTheAttemptInProgress(): void
    {
        $this->required();
        $attempt = $this->attempt();

        $result = $this->service->submitAttempt($this->ada, $attempt);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['unanswered_required']);
        $this->assertSame('In Progress', $this->statusOf($attempt));
    }

    public function testTheMessageCountsTheRequiredQuestionsLeft(): void
    {
        $this->required();
        $this->required('Essay');
        $this->required('Short Answer', false);
        $attempt = $this->attempt();

        $result = $this->service->submitAttempt($this->ada, $attempt);

        $this->assertSame(2, $result['unanswered_required']);
        $this->assertStringContainsString('2 required questions', $result['message']);
    }

    public function testOneQuestionLeftIsSaidInTheSingular(): void
    {
        $this->required();
        $attempt = $this->attempt();

        $this->assertStringContainsString('1 required question ', $this->service->submitAttempt($this->ada, $attempt)['message'] . ' ');
        $this->assertStringNotContainsString('1 required questions', $this->service->submitAttempt($this->ada, $attempt)['message']);
    }

    public function testAnsweringTheRequiredQuestionsLetsItThrough(): void
    {
        $short = $this->required();
        $mc    = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [true, false]);
        $attempt = $this->attempt();

        AssessmentFactory::response($attempt, $short, ['response_text' => 'my answer']);
        AssessmentFactory::response($attempt, $mc['question'], ['selected_choice_id' => $mc['choices'][0]]);

        $this->assertTrue($this->service->submitAttempt($this->ada, $attempt)['success']);
    }

    public function testUnansweredOptionalQuestionsDoNotStopIt(): void
    {
        $this->required('Short Answer', false);
        $this->required('Essay', false);
        $attempt = $this->attempt();

        $this->assertTrue($this->service->submitAttempt($this->ada, $attempt)['success']);
        $this->assertNotSame('In Progress', $this->statusOf($attempt));
    }

    public function testAWhitespaceOnlyAnswerIsNotAnAnswer(): void
    {
        $question = $this->required();
        $attempt  = $this->attempt();
        AssessmentFactory::response($attempt, $question, ['response_text' => "  \n "]);

        $this->assertFalse($this->service->submitAttempt($this->ada, $attempt)['success']);
    }

    public function testAMultipleSelectionQuestionNeedsAtLeastOneChoice(): void
    {
        $mc      = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [true, true, false]);
        $attempt = $this->attempt();
        AssessmentFactory::response($attempt, $mc['question'], ['selected_choice_ids' => json_encode([])]);

        $this->assertFalse($this->service->submitAttempt($this->ada, $attempt)['success']);

        db_connect()->table('assessment_question_responses')->where('submission_id', $attempt)->update(['selected_choice_ids' => json_encode([$mc['choices'][0]])]);

        $this->assertTrue($this->service->submitAttempt($this->ada, $attempt)['success']);
    }

    public function testAFileUploadQuestionIsNeverRequiredBecauseAQuizCannotTakeOne(): void
    {
        $this->required('File Upload');
        $attempt = $this->attempt();

        $this->assertTrue($this->service->submitAttempt($this->ada, $attempt)['success']);
    }

    public function testATimerThatRanOutHandsInWhatThereIsEvenWithRequiredQuestionsUnanswered(): void
    {
        $this->required();
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['duration' => 10]);
        $attempt = $this->attempt();
        db_connect()->table('assessment_submissions')->where('id', $attempt)->update(['started_at' => date('Y-m-d H:i:s', strtotime('-12 minutes'))]);

        $this->assertTrue($this->service->submitAttempt($this->ada, $attempt)['success']);
    }

    public function testTheBrowsersAutoSubmitJustAfterTheTimerIsNotHeldBackByTheRule(): void
    {
        $this->required();
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['duration' => 10]);
        $attempt = $this->attempt();
        db_connect()->table('assessment_submissions')->where('id', $attempt)->update(['started_at' => date('Y-m-d H:i:s', strtotime('-10 minutes -20 seconds'))]);

        $this->assertTrue($this->service->submitAttempt($this->ada, $attempt)['success']);
    }

    public function testAHandInBeforeTheTimerEndsIsHeldToTheRule(): void
    {
        $this->required();
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['duration' => 10]);
        $attempt = $this->attempt();
        db_connect()->table('assessment_submissions')->where('id', $attempt)->update(['started_at' => date('Y-m-d H:i:s', strtotime('-9 minutes'))]);

        $this->assertFalse($this->service->submitAttempt($this->ada, $attempt)['success']);
    }

    public function testATimedQuizStillInTimeIsHeldToTheRule(): void
    {
        $this->required();
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['duration' => 10]);

        $this->assertFalse($this->service->submitAttempt($this->ada, $this->attempt())['success']);
    }

    public function testTheRuleFollowsTheQuestionsTheAttemptStartedWithNotLaterEdits(): void
    {
        $question = $this->required();
        $attempt  = $this->attempt();

        db_connect()->table('assessment_questions')->where('id', $question)->update(['is_required' => 0]);

        $this->assertFalse($this->service->submitAttempt($this->ada, $attempt)['success'], 'it was required when the attempt began');
    }

    public function testThePageMarksEachQuestionAsRequiredOrNot(): void
    {
        $this->required('Short Answer', true);
        $this->required('Essay', false);

        $html = $this->service->startAttempt($this->ada, $this->quiz)['html'];

        $this->assertSame(1, substr_count($html, 'data-required="1"'));
        $this->assertSame(1, substr_count($html, 'data-required="0"'));
        $this->assertStringContainsString('data-submit-hint', $html);
        $this->assertStringContainsString('id="quiz-confirm"', $html);
    }
}
