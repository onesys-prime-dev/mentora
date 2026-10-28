<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\GradeWorkService;
use App\Services\Courses\Grades\GradeFinalizationService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Finalized grades lock grading too (QA AMB-ASG-06): while a section's grades are finalized, work
 * cannot be graded, regraded or reopened; the instructor reopens the finalization first. The frozen
 * final grade was already safe; this keeps the gradebook from drifting under it.
 *
 * @internal
 */
final class GradingLockedWhileFinalizedTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradeWorkService $service;
    private int $offering;
    private int $module;
    private User $instructor;
    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new GradeWorkService();
        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);
    }

    private function finalize(): void
    {
        db_connect()->table('grade_finalizations')->insert([
            'course_offering_id' => $this->offering, 'finalized_by' => $this->instructor->id,
            'finalized_at' => date('Y-m-d H:i:s'), 'students' => 1, 'open_marker' => 1,
        ]);
    }

    private function reopenFinalization(): void
    {
        db_connect()->table('grade_finalizations')->where('course_offering_id', $this->offering)->update(['open_marker' => null, 'reopened_at' => date('Y-m-d H:i:s')]);
    }

    private function assignment(): int
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');
        db_connect()->table('assessments')->where('id', $id)->update(['total_points' => 20]);

        return $id;
    }

    private function row(int $submission): array
    {
        return db_connect()->table('assessment_submissions')->where('id', $submission)->get()->getRowArray();
    }

    public function testGradedWorkCannotBeReopenedWhileFinalized(): void
    {
        $submission = AssessmentFactory::attempt($this->assignment(), $this->ada, 'Graded', 12.0);
        $this->finalize();

        $result = $this->service->reopenGrading($this->instructor, $submission);

        $this->assertFalse($result['success']);
        $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $result['message']);
        $this->assertSame('Graded', $this->row($submission)['status']);
    }

    public function testWorkCannotBeGradedWhileFinalized(): void
    {
        $submission = AssessmentFactory::attempt($this->assignment(), $this->ada, 'Submitted');
        $this->finalize();

        $result = $this->service->submitGrade($this->instructor, $submission, ['score' => 15]);

        $this->assertFalse($result['success']);
        $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $result['message']);
        $this->assertSame('Submitted', $this->row($submission)['status']);
    }

    public function testAQuizQuestionCannotBeGradedWhileFinalized(): void
    {
        $quiz       = CourseFactory::assessment($this->offering, $this->instructor, $this->module);
        $essay      = AssessmentFactory::question($quiz, $this->instructor, 'Essay', 5.0);
        $submission = AssessmentFactory::attempt($quiz, $this->ada, 'Submitted');
        AssessmentFactory::response($submission, $essay, ['response_text' => 'An answer']);
        $this->finalize();

        $result = $this->service->gradeQuestion($this->instructor, $submission, $essay, ['mode' => 'points', 'points' => 4]);

        $this->assertFalse($result['success']);
        $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $result['message']);
    }

    public function testGradingWorksAgainOnceTheFinalizationIsReopened(): void
    {
        $submission = AssessmentFactory::attempt($this->assignment(), $this->ada, 'Submitted');
        $this->finalize();
        $this->reopenFinalization();

        $this->assertTrue($this->service->submitGrade($this->instructor, $submission, ['score' => 15])['success']);
    }
}
