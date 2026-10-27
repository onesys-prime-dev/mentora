<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Viewing a quiz as a student shows its landing and never starts an attempt (audit decisions 3.1, 3.2).
 *
 * @internal
 */
final class ViewAsQuizTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $quiz;

    private User $student;

    private int $question;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $offering      = CourseFactory::offering();
        $instructor    = $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        CourseFactory::assignInstructor($offering, $instructor);
        CourseFactory::enroll($offering, $this->student);
        db_connect()->table('user_profiles')->insert(['user_id' => $this->student->id, 'first_name' => 'Stu', 'last_name' => 'Dent']);

        $this->quiz     = CourseFactory::assessment($offering, $instructor, CourseFactory::module($offering));
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 3]);
        $this->question = AssessmentFactory::question($this->quiz, $instructor, 'Essay', 5.0, ['question_text' => 'Explain recursion in your own words.']);

        // An administrator is viewing as the student: the session is the student's.
        session()->set('impersonator_id', 1);
        session()->set('impersonation', ['target_id' => (int) $this->student->id, 'reason' => 'Checking the quiz', 'started_at' => time(), 'expires_at' => time() + 600]);
    }

    protected function tearDown(): void
    {
        session()->remove(['impersonator_id', 'impersonation']);

        parent::tearDown();
    }

    private function attempts(): int
    {
        return db_connect()->table('assessment_submissions')->where('student_id', $this->student->id)->countAllResults();
    }

    public function testViewingAsAStudentTheQuizShowsItsLandingAndStartsNothing(): void
    {
        $html = (new QuizAttemptService())->startAttempt($this->student, $this->quiz)['html'];

        $this->assertSame(0, $this->attempts());
        $this->assertStringContainsString('You are viewing as Stu Dent. Opening the quiz here does not start an attempt.', $html);
        $this->assertStringContainsString('0 of 3 attempts used', $html);
        $this->assertStringNotContainsString('Explain recursion', $html, 'no unseen question');
        $this->assertStringNotContainsString('Take Quiz', $html, 'nothing is being taken');
    }

    public function testAnAttemptInProgressIsShownReadOnly(): void
    {
        $attempt = AssessmentFactory::attempt($this->quiz, $this->student, 'In Progress');
        AssessmentFactory::response($attempt, $this->question, ['response_text' => 'A function that calls itself.']);

        $html = (new QuizAttemptService())->startAttempt($this->student, $this->quiz)['html'];

        $this->assertSame(1, $this->attempts());
        $this->assertStringContainsString('A function that calls itself.', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('data-submit-quiz', $html);
        $this->assertStringNotContainsString('id="quiz-answer-form"', $html, 'nothing the page script would save from');
        $this->assertStringContainsString('1 of 1 answered', $html, 'counted on the server, since the page script does not run here');
    }

    public function testAnAttemptWaitingForGradingSaysSoInsteadOfAZeroScore(): void
    {
        AssessmentFactory::attempt($this->quiz, $this->student, 'Submitted', 0.0);

        $html = (new QuizAttemptService())->startAttempt($this->student, $this->quiz)['html'];

        $this->assertStringContainsString('Waiting for grading', $html);
        $this->assertStringNotContainsString('0 / 5', $html);
    }

    public function testViewingAsTheInstructorSaysTheyWouldPreviewIt(): void
    {
        db_connect()->table('user_profiles')->insert(['user_id' => $this->instructor->id, 'first_name' => 'Tess', 'last_name' => 'Teacher']);
        session()->set('impersonation', ['target_id' => (int) $this->instructor->id, 'reason' => 'Checking the quiz', 'started_at' => time(), 'expires_at' => time() + 600]);

        $html = (new QuizAttemptService())->startAttempt($this->instructor, $this->quiz)['html'];

        $this->assertSame(0, db_connect()->table('assessment_submissions')->where('student_id', $this->instructor->id)->countAllResults());
        $this->assertStringContainsString("Tess Teacher teaches this course, so they would preview the quiz here. Viewing as them shows no preview.", $html);
        $this->assertStringNotContainsString('attempts used', $html);
    }

    public function testPastResultsAreListed(): void
    {
        AssessmentFactory::attempt($this->quiz, $this->student, 'Graded', 4.0);

        $html = (new QuizAttemptService())->startAttempt($this->student, $this->quiz)['html'];

        $this->assertSame(1, $this->attempts());
        $this->assertStringContainsString('1 of 3 attempts used', $html);
        $this->assertStringContainsString('Attempt 1', $html);
        $this->assertStringContainsString('Graded', $html);
    }
}
