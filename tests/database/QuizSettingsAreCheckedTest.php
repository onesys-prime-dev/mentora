<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use App\Services\Courses\Assessments\QuestionService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The server refuses impossible quiz settings and empty questions (QA MISS-ASM-01), whatever the
 * browser sent: an availability window that ends before it starts, a due date outside it, a
 * duration of zero or less, a question with no text or negative points. A refused change saves
 * nothing.
 *
 * @internal
 */
final class QuizSettingsAreCheckedTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentService $assessments;
    private QuestionService $questions;
    private int $quiz;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assessments = new AssessmentService();
        $this->questions   = new QuestionService();
        $offering          = CourseFactory::offering();
        $this->instructor  = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
        db_connect()->table('assessments')->where('id', $this->quiz)->update([
            'available_from' => '2026-11-01 08:00:00', 'due_date' => '2026-11-05 23:59:00', 'available_until' => '2026-11-07 23:59:00', 'duration' => 30,
        ]);
    }

    private function quiz(): array
    {
        return db_connect()->table('assessments')->where('id', $this->quiz)->get()->getRowArray();
    }

    /** @return array{success: bool, message: string} */
    private function update(array $data): array
    {
        return $this->assessments->updateAssessment($this->instructor, $this->quiz, $data);
    }

    public function testAWindowThatEndsBeforeItStartsIsRefused(): void
    {
        $result = $this->update(['available_until' => '2026-10-30 08:00:00']);

        $this->assertFalse($result['success']);
        $this->assertSame('2026-11-07 23:59:00', $this->quiz()['available_until']);
    }

    public function testADueDateAfterTheWindowClosesIsRefused(): void
    {
        $this->assertFalse($this->update(['due_date' => '2026-11-09 23:59:00'])['success']);
        $this->assertSame('2026-11-05 23:59:00', $this->quiz()['due_date']);
    }

    public function testADueDateBeforeTheWindowOpensIsRefused(): void
    {
        $this->assertFalse($this->update(['due_date' => '2026-10-30 23:59:00'])['success']);
    }

    public function testADurationOfZeroOrLessIsRefused(): void
    {
        $this->assertFalse($this->update(['duration' => 0])['success']);
        $this->assertFalse($this->update(['duration' => -5])['success']);
        $this->assertEquals(30, $this->quiz()['duration']);
    }

    public function testAPositiveOrBlankDurationIsSaved(): void
    {
        $this->assertTrue($this->update(['duration' => 1])['success']);
        $this->assertTrue($this->update(['duration' => null])['success']);
        $this->assertNull($this->quiz()['duration']);
    }

    /** A passing mark outside 0–100 gets a message, not the database's refusal (QA MISS-DB-01). */
    public function testAPassingMarkOutsideAHundredIsRefused(): void
    {
        $result = $this->update(['passing_percentage' => 101]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('passing mark', $result['message']);
        $this->assertFalse($this->update(['passing_percentage' => -1])['success']);
        $this->assertFalse($this->update(['passing_percentage' => 'half'])['success']);
        $this->assertTrue($this->update(['passing_percentage' => 60])['success']);
        $this->assertTrue($this->update(['passing_percentage' => null])['success']);
    }

    public function testAValidWindowIsSaved(): void
    {
        $this->assertTrue($this->update(['due_date' => '2026-11-07 23:59:00'])['success']);
    }

    public function testAQuestionWithNoTextIsRefused(): void
    {
        $result = $this->questions->createQuestion($this->instructor, $this->quiz, ['question_text' => '   ', 'question_type' => 'Essay', 'points' => 1]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, db_connect()->table('assessment_questions')->where('assessment_id', $this->quiz)->countAllResults());
    }

    public function testAQuestionWithNegativePointsIsRefused(): void
    {
        $this->assertFalse($this->questions->createQuestion($this->instructor, $this->quiz, ['question_text' => 'Explain', 'question_type' => 'Essay', 'points' => -1])['success']);
    }

    public function testEditingAQuestionToNoTextIsRefused(): void
    {
        $id = (int) $this->questions->createQuestion($this->instructor, $this->quiz, ['question_text' => 'Explain', 'question_type' => 'Essay', 'points' => 2])['question']['id'];

        $this->assertFalse($this->questions->updateQuestion($this->instructor, $id, ['question_text' => ''])['success']);
        $this->assertSame('Explain', db_connect()->table('assessment_questions')->where('id', $id)->get()->getRowArray()['question_text']);
    }
}
