<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * A course tab whose permission the institution took away shows what is
 * there, without the controls (audit decisions 7.4, D6).
 *
 * @internal
 */
final class CourseAbilitiesViewTest extends RouteTestCase
{
    private int $offering;

    private int $institution;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->offering    = CourseFactory::offering();
        $this->institution = (int) db_connect()->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $this->teacher     = $this->member('instructor');
        $this->student     = $this->member('student');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);
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

        if (db_connect()->table('user_profiles')->where('user_id', $user->id)->countAllResults() === 0) {
            db_connect()->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => ucfirst($role), 'last_name' => 'Person']);
        }

        return $user;
    }

    private function clear(string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => 'instructor', 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();
    }

    /** The HTML a course tab's API answers with, as the instructor. */
    private function tab(string $path): string
    {
        $this->loggedInAs($this->teacher);

        $response = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call('GET', "instructor/api/courses/{$this->offering}/{$path}");

        $json = json_decode((string) $response->getJSON(), true);

        return (string) ($json['html'] ?? $response->getBody());
    }

    private function announcement(): void
    {
        db_connect()->table('announcements')->insert([
            'offering_id' => $this->offering, 'author_id' => $this->teacher->id, 'subject' => 'Exam moved',
            'message' => '{}', 'is_pinned' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testWithModerationAnInstructorCanPinLockAndDeleteAStudentsThread(): void
    {
        $thread = CourseFactory::thread($this->offering, $this->student);
        db_connect()->table('discussion_threads')->where('id', $thread)->update(['created_at' => date('Y-m-d H:i:s')]);

        $html = $this->tab("discussions/{$thread}");

        $this->assertStringContainsString('data-thread-pin', $html);
        $this->assertStringContainsString('data-thread-lock', $html);
        $this->assertStringContainsString('data-thread-delete', $html);
    }

    public function testWithoutModerationAnInstructorStillRepliesButCannotModerate(): void
    {
        $thread = CourseFactory::thread($this->offering, $this->student);
        db_connect()->table('discussion_threads')->where('id', $thread)->update(['created_at' => date('Y-m-d H:i:s')]);
        $this->clear('discussions.manage');

        $html = $this->tab("discussions/{$thread}");

        $this->assertStringNotContainsString('data-thread-pin', $html);
        $this->assertStringNotContainsString('data-thread-lock', $html);
        $this->assertStringNotContainsString('data-thread-delete', $html);
        $this->assertMatchesRegularExpression('#<textarea|data-reply-form|data-reply-composer#', $html);
    }

    public function testWithGradingTheInstructorCanCurveAndChangeTheScheme(): void
    {
        $html = $this->tab('grades');

        $this->assertStringContainsString('data-open-curve', $html);
        $this->assertStringContainsString('data-open-grading-scheme', $html);
    }

    public function testWithoutGradingTheGradebookIsShownButNotChanged(): void
    {
        $this->clear('grading.manage');

        $html = $this->tab('grades');

        $this->assertStringContainsString('data-open-gradebook-roster', $html);
        $this->assertStringNotContainsString('data-open-curve', $html);
        $this->assertStringNotContainsString('data-open-grading-scheme', $html);
    }

    /** The grading page of a submitted assignment, as the instructor. */
    private function gradeWorkPage(): string
    {
        $assessment = CourseFactory::assessment($this->offering, $this->teacher, CourseFactory::module($this->offering), null, 'Assignment');
        \Tests\Support\AssessmentFactory::attempt($assessment, $this->student, 'Submitted');

        return $this->tab("assessments/{$assessment}/grade-work/{$this->student->id}");
    }

    public function testWithGradingTheInstructorCanGradeTheWork(): void
    {
        $html = $this->gradeWorkPage();
        $this->assertStringContainsString('data-expand-grade-work-actions', $html);
    }

    public function testWithoutGradingTheWorkIsShownButNotGraded(): void
    {
        $this->clear('grading.manage');

        $html = $this->gradeWorkPage();

        $this->assertStringNotContainsString('data-expand-grade-work-actions', $html);
        $this->assertStringNotContainsString('data-regrade-open', $html);
    }

    public function testWithContentTheInstructorCanAddAndArrangeModules(): void
    {
        CourseFactory::module($this->offering);

        $html = $this->tab('modules');

        $this->assertStringContainsString('data-create-module-button', $html);
        $this->assertStringContainsString('data-module-card-drag-handle', $html);
    }

    public function testWithoutContentTheModulesAreShownButNotChanged(): void
    {
        CourseFactory::module($this->offering);
        $this->clear('courses.manage');

        $html = $this->tab('modules');

        $this->assertStringContainsString('data-module-content=', $html);
        $this->assertStringNotContainsString('data-create-module-button', $html);
        $this->assertStringNotContainsString('data-module-card-drag-handle', $html);
    }

    public function testTheCoursePageTellsItsScriptsWhatMayBeChanged(): void
    {
        $this->clear('grading.manage');
        $this->loggedInAs($this->teacher);

        $slug = url_title((string) db_connect()->query('SELECT c.course_title FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE o.id = ?', [$this->offering])->getRow()->course_title, '-', true);
        $html = (string) $this->call('GET', "instructor/courses/{$this->offering}/{$slug}/overview")->getBody();

        $this->assertStringContainsString('"can":{"content":true,"grading":false,"announcements":true,"discussions":true}', $html);
    }

    public function testWithAnnouncementsAnInstructorHasTheComposerAndTheCardControls(): void
    {
        $this->announcement();

        $html = $this->tab('announcements');

        $this->assertStringContainsString('data-composer-toggle', $html);
        $this->assertStringContainsString('data-toggle-pin', $html);
    }

    public function testWithoutAnnouncementsTheyAreShownButNotChanged(): void
    {
        $this->announcement();
        $this->clear('announcements.manage');

        $html = $this->tab('announcements');

        $this->assertStringContainsString('Exam moved', $html);
        $this->assertStringNotContainsString('data-composer-toggle', $html);
        $this->assertStringNotContainsString('data-toggle-pin', $html);
    }
}
