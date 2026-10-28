<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

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

    private int $offering;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTrigger();

        // Not rolled back, so an earlier run's rows (the same factory codes) are cleared first, once.
        $this->emptyTablesOnce();

        $this->service = new QuizAttemptService();
        $offering      = CourseFactory::offering();
        $instructor    = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $instructor);
        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($offering, $this->student);
        $this->offering   = $offering;
        $this->instructor = $instructor;

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

    /**
     * A quiz that grades itself whole (one multiple-choice question worth 5), with a module item and a gradebook
     * item, started by the student, who picked the right choice.
     *
     * @return array{attempt: int, response: int, item: int, gradeItem: int}
     */
    private function gradedQuiz(): array
    {
        $module = CourseFactory::module($this->offering);
        $item   = CourseFactory::items(CourseFactory::unit($module), [['type' => 'quiz']])[0];
        $quiz   = CourseFactory::assessment($this->offering, $this->instructor, $module, $item);
        $mc     = AssessmentFactory::multipleChoice($quiz, $this->instructor, [true, false], 5);
        AssessmentFactory::open($quiz);
        $gradeItem = GradeFactory::item(GradeFactory::scheme($this->offering), null, 'The quiz', 5, 1, $quiz);

        $this->service->startAttempt($this->student, $quiz);
        $attempt  = (int) db_connect()->table('assessment_submissions')->where(['assessment_id' => $quiz, 'student_id' => $this->student->id])->get()->getRow('id');
        $response = AssessmentFactory::response($attempt, $mc['question'], ['selected_choice_id' => $mc['choices'][0]]);

        return ['attempt' => $attempt, 'response' => $response, 'item' => $item, 'gradeItem' => $gradeItem];
    }

    private function gradebookScores(int $gradeItem): int
    {
        return db_connect()->table('gradebook_item_scores')->where(['grade_item_id' => $gradeItem, 'student_id' => $this->student->id])->countAllResults();
    }

    private function completions(int $item): int
    {
        return db_connect()->table('learning_completions')->where(['learning_item_id' => $item, 'student_user_id' => $this->student->id, 'status' => 'completed'])->countAllResults();
    }

    public function testTheSameSubmitWithoutARefusalScoresCountsAndCompletes(): void
    {
        // What the refused submit below must not leave: without the refusal, all of it is written.
        $quiz = $this->gradedQuiz();

        $this->assertTrue($this->service->submitAttempt($this->student, $quiz['attempt'])['success']);
        $this->assertEquals(5, db_connect()->table('assessment_question_responses')->where('id', $quiz['response'])->get()->getRow('score'));
        $this->assertSame(1, $this->gradebookScores($quiz['gradeItem']));
        $this->assertSame(1, $this->completions($quiz['item']));
    }

    public function testASubmitTheDatabaseRefusesSaysSoAndChangesNothing(): void
    {
        $quiz = $this->gradedQuiz();
        $this->refuse('BEFORE UPDATE', 'assessment_submissions');

        $result = $this->service->submitAttempt($this->student, $quiz['attempt']);

        $this->assertFalse($result['success']);
        $this->assertSame('The quiz could not be submitted. Your answers are kept; try submitting again.', $result['message']);
        $this->assertSame('In Progress', db_connect()->table('assessment_submissions')->where('id', $quiz['attempt'])->get()->getRow('status'));

        $response = db_connect()->table('assessment_question_responses')->where('id', $quiz['response'])->get()->getRowArray();
        // The column's default (0), not the 5 the scoring wrote before the refusal.
        $this->assertEquals(0, $response['score'], 'the scoring was rolled back');
        $this->assertSame(0, (int) $response['is_auto_graded']);
        $this->assertNull($response['graded_at']);

        $db = db_connect();
        $this->assertSame(0, $db->transDepth, 'no transaction left open');
        $this->assertFalse((new ReflectionProperty(BaseConnection::class, 'transException'))->getValue($db), 'exceptions are off again');

        $this->assertSame(0, $this->gradebookScores($quiz['gradeItem']), 'nothing counted');
        $this->assertSame(0, $this->completions($quiz['item']), 'nothing completed');
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
