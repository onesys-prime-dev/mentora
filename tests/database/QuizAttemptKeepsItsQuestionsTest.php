<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuestionService;
use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * An attempt keeps the questions it started with (QA AMB-ASM-03): an instructor may edit a quiz
 * while a student is taking it, but the running attempt is shown and scored as it began. An
 * attempt started after the edit gets the edited question. (Editing after an attempt is handed in
 * still regrades it: QuestionServiceTest pins that.)
 *
 * @internal
 */
final class QuizAttemptKeepsItsQuestionsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuizAttemptService $attempts;
    private int $offeringId;
    private int $quiz;
    private int $question;
    /** @var list<int> */
    private array $choices;
    private User $instructor;
    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attempts   = new QuizAttemptService();
        $this->offeringId = CourseFactory::offering();
        $module           = CourseFactory::module($this->offeringId);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $this->ada);

        $this->quiz = CourseFactory::assessment($this->offeringId, $this->instructor, $module);
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 2]);

        $made           = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [true, false], 3.0);
        $this->question = $made['question'];
        $this->choices  = $made['choices'];
        db_connect()->table('assessment_questions')->where('id', $this->question)->update(['question_text' => 'Which is a string?']);
    }

    private function start(): int
    {
        $this->attempts->startAttempt($this->ada, $this->quiz, $this->offeringId);

        return (int) db_connect()->table('assessment_submissions')
            ->where('assessment_id', $this->quiz)->where('student_id', $this->ada->id)->where('status', 'In Progress')
            ->get()->getRowArray()['id'];
    }

    /** The instructor moves the correct answer to the other choice and rewords the question. */
    private function editTheQuestion(): void
    {
        (new QuestionService())->updateQuestion($this->instructor, $this->question, [
            'question_text' => 'Which is an integer?',
            'choices'       => [
                ['text' => 'Choice 1', 'is_correct' => false],
                ['text' => 'Choice 2', 'is_correct' => true],
            ],
        ]);
    }

    private function score(int $attempt): float
    {
        return (float) db_connect()->table('assessment_submissions')->where('id', $attempt)->get()->getRowArray()['score'];
    }

    public function testARunningAttemptIsScoredAgainstTheKeyItStartedWith(): void
    {
        $attempt = $this->start();
        $this->attempts->saveAnswer($this->ada, $attempt, $this->question, ['selected_choice_id' => $this->choices[0]]);

        $this->editTheQuestion();
        $this->attempts->submitAttempt($this->ada, $attempt);

        $this->assertSame(3.0, $this->score($attempt));
    }

    public function testARunningAttemptStillShowsTheQuestionAsItBegan(): void
    {
        $this->start();
        $this->editTheQuestion();

        $page = $this->attempts->startAttempt($this->ada, $this->quiz, $this->offeringId)['html'];

        $this->assertStringContainsString('Which is a string?', $page);
        $this->assertStringNotContainsString('Which is an integer?', $page);
    }

    public function testAnAttemptStartedAfterTheEditUsesTheEditedQuestion(): void
    {
        $first = $this->start();
        $this->attempts->submitAttempt($this->ada, $first);
        $this->editTheQuestion();

        $second = $this->start();
        $this->attempts->saveAnswer($this->ada, $second, $this->question, ['selected_choice_id' => $this->choices[1]]);
        $this->attempts->submitAttempt($this->ada, $second);

        $this->assertSame(3.0, $this->score($second));
    }
}
