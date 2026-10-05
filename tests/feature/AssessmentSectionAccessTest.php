<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * An assessment id in the URL must belong to the course in the URL. Being
 * enrolled in (or teaching) one section is not a way into another section's
 * quizzes and assignments, even though the route's own course check passes.
 *
 * @internal
 */
final class AssessmentSectionAccessTest extends RouteTestCase
{
    private int $mine;
    private int $theirs;
    private int $theirQuiz;
    private int $theirAssignment;
    private int $myQuiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = CourseFactory::offering('A');
        $this->theirs = CourseFactory::offering('B');
        $theirInstructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->theirs, $theirInstructor);
        $myInstructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $myInstructor);

        $this->theirQuiz = CourseFactory::assessment($this->theirs, $theirInstructor, CourseFactory::module($this->theirs));
        AssessmentFactory::open($this->theirQuiz, ['attempt_limit' => 3]);
        $this->theirAssignment = CourseFactory::assessment($this->theirs, $theirInstructor, CourseFactory::module($this->theirs), null, 'Assignment');
        AssessmentFactory::open($this->theirAssignment);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->theirAssignment, 'allow_text_entry' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        $this->myQuiz = CourseFactory::assessment($this->mine, $myInstructor, CourseFactory::module($this->mine));
        AssessmentFactory::open($this->myQuiz);
    }

    private function attempts(int $assessmentId): int
    {
        return db_connect()->table('assessment_submissions')->where('assessment_id', $assessmentId)->countAllResults();
    }

    private function student()
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine, $student);
        $this->loggedInAs($student);

        return $student;
    }

    public function testAStudentCannotTakeAnotherSectionsQuizThroughTheirOwnCourseUrl(): void
    {
        $this->student();

        try {
            $this->get("student/api/courses/{$this->mine}/assessments/{$this->theirQuiz}/take");
            $this->fail('the quiz of another section opened');
        } catch (PageNotFoundException) {
            $this->assertSame(0, $this->attempts($this->theirQuiz));
        }
    }

    public function testAStudentCanTakeTheirOwnSectionsQuiz(): void
    {
        $student = $this->student();

        $response = $this->get("student/api/courses/{$this->mine}/assessments/{$this->myQuiz}/take")->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, db_connect()->table('assessment_submissions')->where('assessment_id', $this->myQuiz)->where('student_id', $student->id)->countAllResults());
    }

    public function testAStudentCannotOpenAnotherSectionsAssessment(): void
    {
        $this->student();

        $this->expectException(PageNotFoundException::class);

        $this->get("student/api/courses/{$this->mine}/assessments/{$this->theirQuiz}/view");
    }

    public function testAStudentCanOpenTheirOwnSectionsAssessment(): void
    {
        $this->student();

        $this->assertSame(200, $this->get("student/api/courses/{$this->mine}/assessments/{$this->myQuiz}/view")->response()->getStatusCode());
    }

    public function testAnInstructorCannotOpenAnotherSectionsAssessmentThroughTheirOwnCourseUrl(): void
    {
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $instructor);
        $this->loggedInAs($instructor);

        $this->expectException(PageNotFoundException::class);

        $this->get("instructor/api/courses/{$this->mine}/assessments/{$this->theirQuiz}/view");
    }

    public function testAnInstructorCannotPreviewAnotherSectionsQuizThroughTheirOwnCourseUrl(): void
    {
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $instructor);
        $this->loggedInAs($instructor);

        try {
            $this->get("instructor/api/courses/{$this->mine}/assessments/{$this->theirQuiz}/take");
            $this->fail('the quiz of another section opened for an instructor of this one');
        } catch (PageNotFoundException) {
            $this->assertSame(0, $this->attempts($this->theirQuiz));
        }
    }

    public function testAStudentEnrolledInBothSectionsStillTakesAQuizOnlyFromItsOwnCourse(): void
    {
        $student = $this->student();
        CourseFactory::enroll($this->theirs, $student);

        $this->assertSame(200, $this->get("student/api/courses/{$this->theirs}/assessments/{$this->theirQuiz}/take")->response()->getStatusCode());

        $this->expectException(PageNotFoundException::class);
        $this->get("student/api/courses/{$this->mine}/assessments/{$this->theirQuiz}/take");
    }

    public function testAStudentCannotSubmitToAnotherSectionsAssignment(): void
    {
        $student = $this->student();

        try {
            $this->withBody(json_encode([]))->call('post', "student/api/courses/{$this->mine}/assessments/{$this->theirAssignment}/assignment-submissions", ['submission_text' => 'Sneaky']);
            $this->fail('the assignment of another section accepted a submission');
        } catch (PageNotFoundException) {
            $this->assertSame(0, db_connect()->table('assessment_submissions')->where('student_id', $student->id)->countAllResults());
        }
    }
}
