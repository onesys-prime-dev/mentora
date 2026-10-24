<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filters\CoursePermissionFilter;
use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Each course write asks the permission it belongs to (audit decisions 7.4, D6).
 *
 * @internal
 */
final class CoursePermissionFilterTest extends RouteTestCase
{
    private int $offering;

    private int $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->offering    = CourseFactory::offering();
        $this->institution = (int) db_connect()->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $this->teacher     = $this->member('instructor');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function member(string $role): User
    {
        $user = CourseFactory::user($role);
        db_connect()->table('institution_users')->insert(['institution_id' => $this->institution, 'user_id' => $user->id, 'created_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    private function clear(string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => 'instructor', 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();
    }

    /** @param array<string, mixed> $body */
    private function api(User $user, string $verb, string $path, array $body = []): TestResponse
    {
        $this->loggedInAs($user);

        return $this->withBody(json_encode($body))
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call($verb, "instructor/api/courses/{$this->offering}/{$path}");
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function providePaths(): iterable
    {
        yield 'modules'           => ['modules', 'courses.manage'];
        yield 'questions'         => ['assessments/4/questions', 'courses.manage'];
        yield 'rubric'            => ['rubrics/2', 'courses.manage'];
        yield 'announcement'      => ['announcements', 'announcements.manage'];
        yield 'announcement pin'  => ['announcements/3/pin', 'announcements.manage'];
        yield 'announcement reply' => ['announcements/3/reply', null];
        yield 'reply delete'      => ['announcements/replies/5', null];
        yield 'discussion pin'    => ['discussions/3/pin', 'discussions.manage'];
        yield 'discussion lock'   => ['discussions/3/lock', 'discussions.manage'];
        yield 'discussion react'  => ['discussions/3/react', null];
        yield 'discussion post'   => ['discussions', null];
        yield 'discussion delete' => ['discussions/3', null];
        yield 'discussion answer' => ['discussions/3/answer', null];
        yield 'grade scheme'      => ['grades/scheme', 'grading.manage'];
        yield 'grade work'        => ['submissions/9/grade', 'grading.manage'];
        yield 'curve'             => ['grades/curve/preview', 'grading.manage'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providePaths')]
    public function testEachWriteNeedsItsPermission(string $path, ?string $permission): void
    {
        $this->assertSame($permission, CoursePermissionFilter::required($path));
    }

    public function testTheRoleAChangeIsJudgedByIsTheAreaOfTheAddress(): void
    {
        $this->assertSame('chairperson', CoursePermissionFilter::actingRole('chairperson/api/courses/4/modules'));
        $this->assertSame('instructor', CoursePermissionFilter::actingRole('/instructor/api/courses/4/grades/scheme'));
        $this->assertSame('instructor', CoursePermissionFilter::actingRole('instructor/api/calendar/announcements'));
        $this->assertNull(CoursePermissionFilter::actingRole('student/api/courses/4/modules'));
    }

    public function testAnInstructorWithoutGradingCannotChangeTheGradebookButStillSeesIt(): void
    {
        $this->clear('grading.manage');

        $this->assertSame(403, $this->api($this->teacher, 'PATCH', 'grades/scheme', ['uses_periods' => 0])->response()->getStatusCode());
        $this->assertSame(200, $this->api($this->teacher, 'GET', 'grades')->response()->getStatusCode());
    }

    public function testAnotherInstitutionsInstructorIsNotAffected(): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution + 1000, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
        PermissionMatrix::flush();

        $this->assertNotSame(403, $this->api($this->teacher, 'PATCH', 'grades/scheme', ['uses_periods' => 0])->response()->getStatusCode());
    }

    public function testWithoutModerationAnInstructorStillTakesPartButCannotModerate(): void
    {
        $this->clear('discussions.manage');
        $student = $this->member('student');
        CourseFactory::enroll($this->offering, $student);
        $theirs = CourseFactory::thread($this->offering, $student);

        $this->assertSame(403, $this->api($this->teacher, 'PATCH', "discussions/{$theirs}/pin", ['pinned' => true])->response()->getStatusCode());

        $reply = json_decode((string) $this->api($this->teacher, 'POST', "discussions/{$theirs}/reply", ['body' => 'Good question'])->getJSON(), true);
        $this->assertTrue($reply['success'] ?? false, (string) json_encode($reply));

        $deleteTheirs = json_decode((string) $this->api($this->teacher, 'DELETE', "discussions/{$theirs}")->getJSON(), true);
        $this->assertFalse($deleteTheirs['success'] ?? true);
        $this->assertSame(1, db_connect()->table('discussion_threads')->where('id', $theirs)->countAllResults());
    }
}
