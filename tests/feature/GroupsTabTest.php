<?php

declare(strict_types=1);

use App\Services\Groups\GroupSetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\RouteTestCase;

/**
 * The course Groups tab (group activities, screen 1): the instructor and the chairperson see it with its write
 * controls, the dean sees it without them, a student never sees the tab nor reaches the screen.
 *
 * @internal
 */
final class GroupsTabTest extends RouteTestCase
{
    private int $offering;

    private User $teacher;

    private User $student;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->offering = CourseFactory::offering('A');
        $this->teacher  = CourseFactory::user('instructor');
        $this->student  = CourseFactory::user('student');
        $this->chair    = CourseFactory::user('chairperson');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        db_connect()->table('chairperson_assignments')->insert([
            'department_id'       => (int) db_connect()->query('SELECT p.department_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id WHERE o.id = ?', [$this->offering])->getRow()->department_id,
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @return array{int, string} status and body; a 404 thrown by the app counts as 404 */
    private function fetchAs(User $user, string $path): array
    {
        $this->signIn($user);

        try {
            $res = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('GET', $path);
        } catch (PageNotFoundException) {
            return [404, ''];
        }

        return [$res->response()->getStatusCode(), (string) $res->response()->getBody()];
    }

    /** The tab's HTML from its endpoint. */
    private function tab(User $user, string $area): string
    {
        [$status, $body] = $this->fetchAs($user, "{$area}/api/courses/{$this->offering}/groups");
        $this->assertSame(200, $status, $body);

        return (string) json_decode($body, true)['html'];
    }

    private function dean(): User
    {
        $inst    = (int) db_connect()->query('SELECT t.institution_id FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $college = (int) db_connect()->query('SELECT d.college_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments d ON d.id = p.department_id WHERE o.id = ?', [$this->offering])->getRow()->college_id;

        return DeanFactory::dean($inst, $college);
    }

    public function testTheInstructorGetsTheTabAndAnEmptyStateWithTheFirstAction(): void
    {
        [$status, $page] = $this->fetchAs($this->teacher, "instructor/courses/{$this->offering}/x/groups");
        $this->assertSame(200, $status);
        $this->assertStringContainsString('data-course-feature="groups"', $page);

        $html = $this->tab($this->teacher, 'instructor');
        $this->assertStringContainsString('No group sets yet', $html);
        $this->assertStringContainsString('data-group-action="new-set"', $html);
        $this->assertStringContainsString('0 of 20 sets used', $html);
        $this->assertStringContainsString('id="group-move-dialog"', $html);
    }

    public function testTheInstructorSeesTheSetsWithTheirCounts(): void
    {
        $service = new GroupSetService();
        $set     = $service->create($this->offering, ['name' => 'Project teams', 'formation_mode' => 'self_signup', 'min_size' => 2, 'max_size' => 4], (int) $this->teacher->id);
        $service->addGroup($set, 'Team 1', (int) $this->teacher->id);
        $archived = $service->create($this->offering, ['name' => 'Old pairs'], (int) $this->teacher->id);
        $service->archive($archived, (int) $this->teacher->id);

        $html = $this->tab($this->teacher, 'instructor');

        $this->assertStringContainsString('data-group-set-open="' . $set . '"', $html);
        $this->assertStringContainsString('Project teams', $html);
        $this->assertStringContainsString('Self-signup', $html);
        $this->assertStringContainsString('1 group · 1 below minimum', $html);
        $this->assertStringContainsString('0 of 1', $html);
        $this->assertStringContainsString('1 of 20 sets used', $html);
        $this->assertStringNotContainsString('Old pairs', $html);
    }

    public function testTheChairpersonGetsWhatTheInstructorGets(): void
    {
        [$status, $page] = $this->fetchAs($this->chair, "chairperson/courses/{$this->offering}/x/groups");
        $this->assertSame(200, $status);
        $this->assertStringContainsString('data-course-feature="groups"', $page);

        $this->assertStringContainsString('data-group-action="new-set"', $this->tab($this->chair, 'chairperson'));
    }

    public function testTheDeanReadsWithoutAnyWriteControl(): void
    {
        (new GroupSetService())->create($this->offering, ['name' => 'Project teams'], (int) $this->teacher->id);
        $dean = $this->dean();

        [$status, $page] = $this->fetchAs($dean, "dean/courses/{$this->offering}/x/groups");
        $this->assertSame(200, $status);
        $this->assertStringContainsString('data-course-feature="groups"', $page);

        $html = $this->tab($dean, 'dean');
        $this->assertStringContainsString('Project teams', $html);
        $this->assertStringContainsString('data-can-edit="0"', $html);
        $this->assertStringContainsString('data-lock="closed"', $html);
        $this->assertStringNotContainsString('data-group-action', $html);
        $this->assertStringNotContainsString('data-dialog', $html);
    }

    public function testFinalizedGradesLockTheTab(): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => $this->teacher->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $html = $this->tab($this->teacher, 'instructor');

        $this->assertStringContainsString('The grades are finalized', $html);
        $this->assertStringNotContainsString('data-group-action', $html);
    }

    public function testAStudentHasNoTabAndNoScreen(): void
    {
        [$status, $page] = $this->fetchAs($this->student, "student/courses/{$this->offering}/x/overview");
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('data-course-feature="groups"', $page);

        $this->assertSame(404, $this->fetchAs($this->student, "student/courses/{$this->offering}/x/groups")[0]);
        $this->assertContains($this->fetchAs($this->student, "student/api/courses/{$this->offering}/groups")[0], [403, 404]);
        $this->assertContains($this->fetchAs($this->student, "instructor/api/courses/{$this->offering}/groups")[0], [302, 403, 404]);
    }
}
