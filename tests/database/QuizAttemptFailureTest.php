<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * When the database refuses a quiz write, the student is told so, and nothing claims it worked: a submit is not
 * "submitted", an answer is not "saved", and a start that failed does not say "already submitted".
 *
 * The refusal is a trigger made for the test. A trigger is DDL, which ends a transaction, so this class does not
 * run inside a rolled-back one; it drops its trigger after each test.
 *
 * @internal
 */
final class QuizAttemptFailureTest extends DatabaseTestCase
{
    protected bool $isolateTests = false;

    private const TRIGGER = 'mentora_test_refuse_quiz_write';

    private QuizAttemptService $service;

    private int $quiz;

    private int $question;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTrigger();

        $this->service = new QuizAttemptService();
        $offering      = CourseFactory::offering();
        $instructor    = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $instructor);
        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($offering, $this->student);

        $this->quiz     = CourseFactory::assessment($offering, $instructor, CourseFactory::module($offering));
        $this->question = AssessmentFactory::question($this->quiz, $instructor, 'Essay', 5);
        AssessmentFactory::open($this->quiz);
    }

    protected function tearDown(): void
    {
        $this->dropTrigger();

        parent::tearDown();
    }

    private function dropTrigger(): void
    {
        db_connect()->query('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
    }

    private function refuse(string $when, string $table): void
    {
        db_connect()->query('CREATE TRIGGER ' . self::TRIGGER . " {$when} ON " . db_connect()->prefixTable($table) . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused for the test'");
    }

    private function attempt(): array
    {
        $this->service->startAttempt($this->student, $this->quiz);

        return db_connect()->table('assessment_submissions')->where(['assessment_id' => $this->quiz, 'student_id' => $this->student->id])->get()->getRowArray();
    }

    public function testASubmitTheDatabaseRefusesSaysSoAndChangesNothing(): void
    {
        $attempt = $this->attempt();
        $this->refuse('BEFORE UPDATE', 'assessment_submissions');

        $result = $this->service->submitAttempt($this->student, (int) $attempt['id']);

        $this->assertFalse($result['success']);
        $this->assertSame('The quiz could not be submitted. Your answers are kept; try submitting again.', $result['message']);
        $this->assertSame('In Progress', db_connect()->table('assessment_submissions')->where('id', $attempt['id'])->get()->getRow('status'));
    }

    public function testAnAnswerTheDatabaseRefusesSaysSo(): void
    {
        $attempt = $this->attempt();
        $this->refuse('BEFORE INSERT', 'assessment_question_responses');

        $result = $this->service->saveAnswer($this->student, (int) $attempt['id'], $this->question, ['response_text' => 'An answer']);

        $this->assertFalse($result['success']);
        $this->assertSame('Your answer could not be saved. Try again.', $result['message']);
    }

    public function testAStartTheDatabaseRefusesSaysItCouldNotStart(): void
    {
        $this->refuse('BEFORE INSERT', 'assessment_submissions');

        $html = $this->service->startAttempt($this->student, $this->quiz)['html'];

        $this->assertStringContainsString('The quiz could not be started. Reload the page to try again.', $html);
        $this->assertStringNotContainsString('already been submitted', $html);
    }
}
