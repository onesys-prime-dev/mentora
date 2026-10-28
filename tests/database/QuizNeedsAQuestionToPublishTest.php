<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use App\Services\Courses\ModuleService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * A quiz needs at least one question before it can be published (QA AMB-ASM-02), from its own
 * settings and from its item in Modules alike. An empty draft can still be saved; an assignment
 * has no questions and is unaffected.
 *
 * @internal
 */
final class QuizNeedsAQuestionToPublishTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;
    private int $module;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
    }

    private function draft(string $type = 'Quiz', ?int $item = null): int
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $item, $type);
        db_connect()->table('assessments')->where('id', $id)->update(['status' => 'Draft']);

        return $id;
    }

    private function statusOf(int $id): string
    {
        return db_connect()->table('assessments')->where('id', $id)->get()->getRowArray()['status'];
    }

    public function testAQuizWithNoQuestionsIsNotPublished(): void
    {
        $quiz = $this->draft();

        $result = (new AssessmentService())->updateAssessment($this->instructor, $quiz, ['status' => 'Published']);

        $this->assertFalse($result['success']);
        $this->assertSame(AssessmentService::NO_QUESTIONS, $result['message']);
        $this->assertSame('Draft', $this->statusOf($quiz));
    }

    public function testAQuizWithAQuestionIsPublished(): void
    {
        $quiz = $this->draft();
        AssessmentFactory::question($quiz, $this->instructor, 'Essay', 5.0);

        $this->assertTrue((new AssessmentService())->updateAssessment($this->instructor, $quiz, ['status' => 'Published'])['success']);
        $this->assertSame('Published', $this->statusOf($quiz));
    }

    public function testAnAssignmentNeedsNoQuestions(): void
    {
        $assignment = $this->draft('Assignment');

        $this->assertTrue((new AssessmentService())->updateAssessment($this->instructor, $assignment, ['status' => 'Published'])['success']);
    }

    public function testAnEmptyQuizItemIsNotPublishedFromModules(): void
    {
        $unit = CourseFactory::unit($this->module);
        $item = CourseFactory::items($unit, [['type' => 'quiz', 'status' => 'draft']])[0];
        $quiz = $this->draft('Quiz', $item);

        $result = (new ModuleService())->updateLearningItem($this->instructor, $this->offering, $item, ['status' => 'published']);

        $this->assertFalse($result['success']);
        $this->assertSame(AssessmentService::NO_QUESTIONS, $result['message']);
        $this->assertSame('Draft', $this->statusOf($quiz));
        $this->assertSame('draft', db_connect()->table('learning_items')->where('id', $item)->get()->getRowArray()['status']);
    }
}
