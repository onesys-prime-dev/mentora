<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * CourseController::redirect() sends a course card's short link to the
 * right role's overview page. It must follow the signed-in user's active
 * role, not just "instructor or else student" — a chairperson (who holds
 * neither of those groups) was landing on the student section and getting
 * bounced to their dashboard instead of the course.
 *
 * @internal
 */
final class CourseRedirectTest extends RouteTestCase
{
    public function testAChairpersonIsRedirectedToTheChairpersonOverview(): void
    {
        $offering = CourseFactory::offering();
        $chair = CourseFactory::user('chairperson');

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id'   => $this->subDepartmentOf($offering),
            'chairperson_user_id' => $chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->loggedInAs($chair);

        $result = $this->get("chairperson/courses/{$offering}/any-title");

        $result->assertRedirectTo("chairperson/courses/{$offering}/any-title/overview");
    }

    public function testAnInstructorIsStillRedirectedToTheInstructorOverview(): void
    {
        $offering = CourseFactory::offering();
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $instructor);

        $this->loggedInAs($instructor);

        $result = $this->get("instructor/courses/{$offering}/any-title");

        $result->assertRedirectTo("instructor/courses/{$offering}/any-title/overview");
    }

    public function testAStudentIsStillRedirectedToTheStudentOverview(): void
    {
        $offering = CourseFactory::offering();
        $student = CourseFactory::user('student');
        CourseFactory::enroll($offering, $student);

        $this->loggedInAs($student);

        $result = $this->get("student/courses/{$offering}/any-title");

        $result->assertRedirectTo("student/courses/{$offering}/any-title/overview");
    }

    private function subDepartmentOf(int $offeringId): int
    {
        return (int) db_connect()->table('course_offerings co')
            ->select('programs.sub_department_id')
            ->join('courses', 'courses.id = co.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray()['sub_department_id'];
    }
}
