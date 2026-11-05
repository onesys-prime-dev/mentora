<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The quiz page of a quiz with a password: opening it only asks, and the password is given by POST to the same
 * address, which starts the attempt only when it is right. A student not in the course, and the quiz of another
 * course, are refused as ever.
 *
 * @internal
 */
final class QuizPasswordRouteTest extends RouteTestCase
{
    private int $offering;

    private int $quiz;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering   = CourseFactory::offering('A');
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering));
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 3]);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->quiz, 'created_at' => date('Y-m-d H:i:s')]);
        (new AssessmentService())->setQuizPassword($this->instructor, $this->quiz, 'Blue-Heron-42');
    }

    private function student(): User
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $student);
        $this->loggedInAs($student);

        return $student;
    }

    private function url(): string
    {
        return "student/api/courses/{$this->offering}/assessments/{$this->quiz}/take";
    }

    private function attempts(User $student): int
    {
        return db_connect()->table('assessment_submissions')->where(['assessment_id' => $this->quiz, 'student_id' => $student->id])->countAllResults();
    }

    /** @return array<string, mixed> */
    private function give(string $password): array
    {
        return json_decode($this->withBodyFormat('json')->post($this->url(), ['password' => $password])->getJSON(), true);
    }

    public function testOpeningThePageOnlyAsksAndStartsNothing(): void
    {
        $student = $this->student();

        $result = $this->get($this->url());

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString('data-quiz-password-form', (string) json_decode($result->getJSON(), true)['html']);
        $this->assertTrue(json_decode($result->getJSON(), true)['passwordRequired']);
        $this->assertSame(0, $this->attempts($student));
    }

    public function testTheRightPasswordStartsTheAttempt(): void
    {
        $student = $this->student();

        $answer = $this->give('Blue-Heron-42');

        $this->assertFalse($answer['passwordRequired']);
        $this->assertStringNotContainsString('data-quiz-password-form', $answer['html']);
        $this->assertSame(1, $this->attempts($student));
    }

    public function testAWrongPasswordAsksAgainWithTheReasonAndStartsNothing(): void
    {
        $student = $this->student();

        $answer = $this->give('nope');

        $this->assertTrue($answer['passwordRequired']);
        $this->assertSame('That password is not right.', $answer['passwordError']);
        $this->assertStringContainsString('That password is not right.', $answer['html']);
        $this->assertSame(0, $this->attempts($student));
    }

    public function testAMissingPasswordFieldIsAWrongPasswordNotAnError(): void
    {
        $student = $this->student();

        $answer = json_decode($this->withBodyFormat('json')->post($this->url(), [])->getJSON(), true);

        $this->assertTrue($answer['passwordRequired']);
        $this->assertSame(0, $this->attempts($student));
    }

    public function testSomeoneNotInTheCourseCannotEvenTryAPassword(): void
    {
        $outsider = CourseFactory::user('student');
        $this->loggedInAs($outsider);

        try {
            $this->withBodyFormat('json')->post($this->url(), ['password' => 'Blue-Heron-42']);
            $this->fail('a student outside the course got to the quiz');
        } catch (PageNotFoundException) {
            $this->assertSame(0, $this->attempts($outsider));
        }
    }

    private function setUrl(string $role = 'instructor', ?int $offering = null): string
    {
        return "{$role}/api/courses/" . ($offering ?? $this->offering) . "/assessments/{$this->quiz}/password";
    }

    public function testTheTeacherSetsANewPasswordWhichReplacesTheOldOne(): void
    {
        $this->loggedInAs($this->instructor);

        $answer = json_decode($this->withBodyFormat('json')->patch($this->setUrl(), ['password' => 'Red-Kite-77'])->getJSON(), true);

        $this->assertTrue($answer['success'], (string) ($answer['message'] ?? ''));
        $this->assertTrue((new AssessmentService())->quizPasswordMatches($this->quiz, 'Red-Kite-77'));
        $this->assertFalse((new AssessmentService())->quizPasswordMatches($this->quiz, 'Blue-Heron-42'));
        $this->assertStringNotContainsString('Red-Kite-77', json_encode($answer), 'it is not echoed back');
    }

    public function testAnUnusablePasswordIsAnAnswerNotAnErrorAndChangesNothing(): void
    {
        $this->loggedInAs($this->instructor);

        $short   = json_decode($this->withBodyFormat('json')->patch($this->setUrl(), ['password' => 'ab'])->getJSON(), true);
        $missing = json_decode($this->withBodyFormat('json')->patch($this->setUrl(), [])->getJSON(), true);

        $this->assertFalse($short['success']);
        $this->assertFalse($missing['success']);
        $this->assertTrue((new AssessmentService())->quizPasswordMatches($this->quiz, 'Blue-Heron-42'), 'the old password still works');
    }

    public function testAStudentCannotSetOrChangeThePassword(): void
    {
        $this->student();

        try {
            $this->withBodyFormat('json')->patch($this->setUrl('student'), ['password' => 'Hacked-123']);
            $this->fail('a student reached the password route');
        } catch (PageNotFoundException) {
            $this->assertFalse((new AssessmentService())->quizPasswordMatches($this->quiz, 'Hacked-123'));
        }
    }

    public function testAnInstructorOfAnotherCourseCannotSetThisQuizsPassword(): void
    {
        $other      = CourseFactory::offering('B');
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($other, $instructor);
        $this->loggedInAs($instructor);

        try {
            $this->withBodyFormat('json')->patch($this->setUrl('instructor', $other), ['password' => 'Hacked-123']);
            $this->fail('the quiz of another course was changed');
        } catch (PageNotFoundException) {
            $this->assertFalse((new AssessmentService())->quizPasswordMatches($this->quiz, 'Hacked-123'));
        }
    }

    public function testTheAnswerNeverCarriesThePasswordOrItsHash(): void
    {
        $this->student();

        foreach ([$this->get($this->url())->getJSON(), $this->withBodyFormat('json')->post($this->url(), ['password' => 'x'])->getJSON()] as $body) {
            $this->assertStringNotContainsString('Blue-Heron-42', (string) $body);
            $this->assertStringNotContainsString('$2y$', (string) $body);
        }
    }
}
