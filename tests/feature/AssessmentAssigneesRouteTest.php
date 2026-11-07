<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The two addresses behind the "Specific students" picker: the teacher lists the section's students with who is
 * chosen and saves the choice. A student, and a teacher of another course, are refused as ever.
 *
 * @internal
 */
final class AssessmentAssigneesRouteTest extends RouteTestCase
{
    private int $offering;

    private int $assessment;

    private User $instructor;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering   = CourseFactory::offering('A');
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);

        $this->assessment = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering), null, 'Assignment');
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->assessment, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function url(string $role = 'instructor', ?int $offering = null): string
    {
        return "{$role}/api/courses/" . ($offering ?? $this->offering) . "/assessments/{$this->assessment}/assignees";
    }

    public function testTheTeacherListsTheSectionsStudents(): void
    {
        $this->loggedInAs($this->instructor);

        $answer = json_decode($this->get($this->url())->getJSON(), true);

        $this->assertTrue($answer['success']);
        $this->assertSame([(int) $this->ada->id], array_column($answer['students'], 'id'));
        $this->assertFalse($answer['students'][0]['selected']);
    }

    public function testTheTeacherSavesTheChoiceAndItIsListedAsChosen(): void
    {
        $this->loggedInAs($this->instructor);

        $saved = json_decode($this->withBodyFormat('json')->put($this->url(), ['student_ids' => [$this->ada->id]])->getJSON(), true);

        $this->assertTrue($saved['success'], (string) ($saved['message'] ?? ''));
        $this->assertSame(1, $saved['count']);
        $this->assertSame([(int) $this->ada->id], (new AssessmentService())->assigneeIds($this->assessment));
    }

    public function testAMissingListMeansNobodyIsChosen(): void
    {
        $this->loggedInAs($this->instructor);
        (new AssessmentService())->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id]);

        $saved = json_decode($this->withBodyFormat('json')->put($this->url(), [])->getJSON(), true);

        $this->assertTrue($saved['success']);
        $this->assertSame([], (new AssessmentService())->assigneeIds($this->assessment));
    }

    public function testSomeoneNotEnrolledCannotBeChosenThroughTheRoute(): void
    {
        $this->loggedInAs($this->instructor);
        $outsider = CourseFactory::user('student');

        $saved = json_decode($this->withBodyFormat('json')->put($this->url(), ['student_ids' => [$outsider->id]])->getJSON(), true);

        $this->assertFalse($saved['success']);
        $this->assertSame([], (new AssessmentService())->assigneeIds($this->assessment));
    }

    public function testAStudentCannotListOrChange(): void
    {
        $this->loggedInAs($this->ada);

        foreach (['get', 'put'] as $method) {
            try {
                $this->withBodyFormat('json')->{$method}($this->url('student'), ['student_ids' => [$this->ada->id]]);
                $this->fail("a student reached the assignees route by {$method}");
            } catch (PageNotFoundException) {
                $this->assertSame([], (new AssessmentService())->assigneeIds($this->assessment));
            }
        }
    }

    public function testAnInstructorOfAnotherCourseCannotListOrChange(): void
    {
        $other      = CourseFactory::offering('B');
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($other, $instructor);
        $this->loggedInAs($instructor);

        foreach (['get', 'put'] as $method) {
            try {
                $this->withBodyFormat('json')->{$method}($this->url('instructor', $other), ['student_ids' => [$this->ada->id]]);
                $this->fail("the assessment of another course was reached by {$method}");
            } catch (PageNotFoundException) {
                $this->assertSame([], (new AssessmentService())->assigneeIds($this->assessment));
            }
        }
    }
}
