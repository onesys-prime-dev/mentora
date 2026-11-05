<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The address the quiz page reports to: the attempt's own student is kept, anyone else is told it was not recorded,
 * and a student of another course cannot reach it at all.
 *
 * @internal
 */
final class QuizSecurityEventRouteTest extends RouteTestCase
{
    private int $offering;

    private int $quiz;

    private User $ada;

    private int $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering('A');
        $instructor     = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);

        $this->quiz = CourseFactory::assessment($this->offering, $instructor, CourseFactory::module($this->offering));
        AssessmentFactory::open($this->quiz, ['attempt_limit' => 3]);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->quiz, 'detect_tab_switch' => 1, 'browser_lockdown' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $this->submission = AssessmentFactory::attempt($this->quiz, $this->ada, 'In Progress');
    }

    private function url(?int $offering = null): string
    {
        return 'student/api/courses/' . ($offering ?? $this->offering) . "/submissions/{$this->submission}/security-events";
    }

    private function recorded(): int
    {
        return db_connect()->table('assessment_security_events')->where('submission_id', $this->submission)->countAllResults();
    }

    /** @return array<string, mixed> */
    private function report(array $event): array
    {
        return json_decode($this->withBodyFormat('json')->post($this->url(), $event)->getJSON(), true);
    }

    public function testTheStudentReportsLeavingThePageAndItIsKept(): void
    {
        $this->loggedInAs($this->ada);

        $answer = $this->report(['kind' => 'left_page', 'duration_seconds' => 17]);

        $this->assertTrue($answer['success']);
        $this->assertSame(1, $this->recorded());
    }

    public function testAnotherStudentOfTheCourseIsToldItWasNotRecorded(): void
    {
        $bob = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $bob);
        $this->loggedInAs($bob);

        $this->assertFalse($this->report(['kind' => 'left_fullscreen'])['success']);
        $this->assertSame(0, $this->recorded());
    }

    public function testAStudentOfAnotherCourseCannotReachIt(): void
    {
        $other = CourseFactory::offering('B');
        $eve   = CourseFactory::user('student');
        CourseFactory::enroll($other, $eve);
        $this->loggedInAs($eve);

        try {
            $this->withBodyFormat('json')->post($this->url($other), ['kind' => 'left_fullscreen']);
            $this->fail('the attempt of another course was reached');
        } catch (PageNotFoundException) {
            $this->assertSame(0, $this->recorded());
        }
    }

    public function testNonsenseIsAnAnswerNotAnError(): void
    {
        $this->loggedInAs($this->ada);

        $this->assertFalse($this->report(['kind' => 'visited_website', 'detail' => 'example.com'])['success']);
        $this->assertFalse(json_decode($this->withBodyFormat('json')->post($this->url(), [])->getJSON(), true)['success']);
        $this->assertSame(0, $this->recorded());
    }
}
