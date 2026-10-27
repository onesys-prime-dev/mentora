<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuizAttemptService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * In a course, the quiz rules follow the person's role there, not the groups they hold (audit decision 9.1):
 * someone who teaches course A and studies in course B is a student in B.
 *
 * @internal
 */
final class QuizCourseRoleTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuizAttemptService $service;

    private int $taught;

    private int $studied;

    private User $teacher;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        session()->destroy();

        $this->service = new QuizAttemptService();
        $this->taught  = CourseFactory::offering();
        $this->studied = CourseFactory::offering();

        // Teaches A, and is enrolled as a student in B.
        $this->teacher = CourseFactory::user('instructor');
        $this->teacher->addGroup('student');
        CourseFactory::assignInstructor($this->taught, $this->teacher);
        CourseFactory::enroll($this->studied, $this->teacher);

        $this->owner = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->studied, $this->owner);
    }

    private function quiz(int $offering, array $settings): int
    {
        $quiz = CourseFactory::assessment($offering, $this->owner, CourseFactory::module($offering));
        AssessmentFactory::open($quiz, $settings);

        return $quiz;
    }

    private function attempts(int $quiz): int
    {
        return db_connect()->table('assessment_submissions')->where(['assessment_id' => $quiz, 'student_id' => $this->teacher->id])->countAllResults();
    }

    public function testInAnotherCourseAnInstructorMeetsTheAttemptLimit(): void
    {
        $quiz = $this->quiz($this->studied, ['attempt_limit' => 1]);
        AssessmentFactory::attempt($quiz, $this->teacher, 'Graded', 3);

        $this->service->startAttempt($this->teacher, $quiz);

        $this->assertSame(1, $this->attempts($quiz), 'no second attempt');
    }

    public function testInAnotherCourseAnInstructorCannotOpenADraftQuiz(): void
    {
        $quiz = $this->quiz($this->studied, ['status' => 'Draft']);

        $this->expectException(PageNotFoundException::class);

        $this->service->startAttempt($this->teacher, $quiz);
    }

    public function testInAnotherCourseAClosedQuizStartsNothing(): void
    {
        $quiz = $this->quiz($this->studied, ['status' => 'Closed']);

        $this->service->startAttempt($this->teacher, $quiz);

        $this->assertSame(0, $this->attempts($quiz));
    }

    public function testAnInstructorWhoIsNeitherEnrolledNorTeachingGetsNotFound(): void
    {
        $quiz     = $this->quiz($this->studied, []);
        $stranger = CourseFactory::user('instructor');

        $this->expectException(PageNotFoundException::class);

        $this->service->startAttempt($stranger, $quiz);
    }

    public function testTheCoursesOwnInstructorStillPreviews(): void
    {
        $quiz = $this->quiz($this->studied, ['status' => 'Draft']);

        $this->assertIsString($this->service->startAttempt($this->owner, $quiz)['html']);
    }
}
