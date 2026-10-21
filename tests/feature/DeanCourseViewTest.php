<?php

declare(strict_types=1);

use App\Services\Institutions\DeanAssignmentService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's read-only view of a course: reachable for every offering of
 * their own department and no other (not another department of the same
 * institution either), shown as its instructor sees it, marked "view only",
 * and with no route anywhere that changes anything in a course. A dean who
 * also teaches sees the courses they teach normally while acting as an
 * instructor.
 *
 * @internal
 */
final class DeanCourseViewTest extends RouteTestCase
{
    private int $institution;

    private int $department;

    private int $program;

    private int $offering;

    private int $sibling;

    private int $foreign;

    private User $dean;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $home              = DeanFactory::department('H');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->program     = DeanFactory::program(DeanFactory::subDepartment($this->department, 'H'), 'H');
        $term              = DeanFactory::term($this->institution);

        // Another department of the same institution, and a department of another institution.
        $next          = DeanFactory::department('N', $this->institution);
        $nextProgram   = DeanFactory::program(DeanFactory::subDepartment($next['department'], 'N'), 'N');
        $elsewhere     = DeanFactory::department('X');
        $foreignProgram = DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X');

        $this->offering = DeanFactory::offering($this->program, $term, 'IT101', 'A', 'active', 'Intro to Computing');
        $this->sibling  = DeanFactory::offering($nextProgram, $term, 'LW100', 'A', 'active', 'Intro to Law');
        $this->foreign  = DeanFactory::offering($foreignProgram, DeanFactory::term($elsewhere['institution']), 'ZZ900', 'A', 'active', 'Foreign Course');

        $this->dean    = DeanFactory::dean($this->institution, $this->department);
        $this->teacher = PeopleFactory::person('instructor', 'Tom', 'Teacher', 'tom@dcv.example', $this->institution);
        $this->student = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@dcv.example', $this->institution);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $this->signIn($this->dean);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function api(string $path, ?int $offering = null): \CodeIgniter\Test\TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);

        return $this->call('get', 'dean/api/courses/' . ($offering ?? $this->offering) . '/' . $path);
    }

    private function assertNotFound(callable $request, string $what): void
    {
        try {
            $status = $request()->response()->getStatusCode();
            $this->fail("{$what} must be a 404 (got {$status})");
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin', 'institutional_admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@dcv.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', "dean/courses/{$this->offering}/intro/overview")->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', "dean/api/courses/{$this->offering}/overview")->response()->getStatusCode());
    }

    public function testTheCoursePageOpensViewOnlyAndAddressesTheDeanRoutes(): void
    {
        $body = $this->step('GET', "dean/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody();

        $this->assertStringContainsString('data-view-only', $body);
        $this->assertStringContainsString('View only. You are looking at IT101 A as the dean of College H', $body);
        $this->assertStringContainsString("dean\\/api\\/courses\\/{$this->offering}", $body);
        $this->assertStringContainsString("dean\\/courses\\/{$this->offering}\\/intro-to-computing", $body);
        $this->assertStringNotContainsString('instructor\\/api\\/courses', $body);
        $this->assertStringNotContainsString('institution\\/api\\/courses', $body);
    }

    public function testTheShortCourseAddressRedirectsWithinTheDeanArea(): void
    {
        $location = $this->step('GET', "dean/courses/{$this->offering}/intro-to-computing")->response()->getHeaderLine('Location');

        $this->assertStringEndsWith("dean/courses/{$this->offering}/intro-to-computing/overview", $location);
    }

    public function testAnotherDepartmentsCourseIsNotFoundOnEveryReadingRouteInTheSameInstitutionOrAnother(): void
    {
        $paths = ['overview', 'announcements', 'announcements/more', 'modules', 'resources', 'discussions', 'assessments', 'grades', 'grades/scheme', 'grades/roster', 'grades/export', 'grades/report/class', 'grades/report/summary'];

        foreach (['another department' => $this->sibling, 'another institution' => $this->foreign] as $where => $offering) {
            foreach ($paths as $path) {
                $this->assertNotFound(fn () => $this->api($path, $offering), "{$path} of a course in {$where}");
            }

            $this->assertNotFound(fn () => $this->call('get', "dean/courses/{$offering}/x/overview"), "the page of a course in {$where}");
            $this->assertNotFound(fn () => $this->call('get', "dean/courses/{$offering}/x"), "the short address of a course in {$where}");
        }
    }

    public function testEveryReadingRouteAnswersForTheDepartmentsOwnCourse(): void
    {
        $paths = ['overview', 'announcements', 'modules', 'resources', 'discussions', 'assessments', 'grades', 'grades/scheme', 'grades/roster'];

        foreach ($paths as $path) {
            $this->assertSame(200, $this->api($path)->response()->getStatusCode(), $path);
        }
    }

    public function testItShowsWhatTheInstructorSeesIncludingDraftsThatAStudentDoesNot(): void
    {
        CourseFactory::module($this->offering, 'published', 1);
        db_connect()->table('course_modules')->insert(['offering_id' => $this->offering, 'title' => 'Draft week', 'order_index' => 2, 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertStringContainsString('Draft week', (string) json_encode($this->api('modules')->getJSON()));

        $this->signIn($this->student);
        $this->withHeaders(['Accept' => 'application/json']);
        $asStudent = json_encode($this->call('get', "student/api/courses/{$this->offering}/modules")->getJSON());

        $this->assertStringNotContainsString('Draft week', (string) $asStudent);
    }

    public function testNoRouteExistsToChangeAnythingInACourse(): void
    {
        // The application's routes as written, read into a collection of their own.
        $routes = new \CodeIgniter\Router\RouteCollection(Services::locator(), config(\Config\Modules::class), config(\Config\Routing::class));
        require APPPATH . 'Config/Routes.php';

        $checked = 0;

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $pattern) {
                if (! str_starts_with($pattern, 'chairperson/api/courses/')) {
                    continue;
                }

                $uri = 'dean/' . str_replace('([0-9]+)', '1', substr($pattern, strlen('chairperson/')));
                $uri = str_replace('1', (string) $this->offering, $uri);

                try {
                    $this->withHeaders(['Accept' => 'application/json']);
                    $status = $this->call(strtolower($verb), $uri)->response()->getStatusCode();

                    $this->assertContains($status, [401, 403, 404, 405], "{$verb} {$uri} must not be allowed (got {$status})");
                } catch (PageNotFoundException) {
                    // No such route: exactly what is wanted.
                }

                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked, 'the course write routes were found and each one was refused');
        // The dean has pages of their own that change things (approving an override request, signing off
        // grades), but nothing inside a course: no write route under the course pages or their API.
        $writes = [];

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $writes = array_merge($writes, array_filter(array_keys($routes->getRoutes($verb)), static fn (string $p): bool => str_starts_with($p, 'dean/courses') || str_starts_with($p, 'dean/api/')));
        }

        $this->assertSame([], array_values($writes), 'the dean can write nothing in a course');
    }

    public function testAnAttemptToWriteLeavesTheCourseUntouched(): void
    {
        $count = static fn (): array => [
            'modules'       => db_connect()->table('course_modules')->countAllResults(),
            'announcements' => db_connect()->table('announcements')->countAllResults(),
            'threads'       => db_connect()->table('discussion_threads')->countAllResults(),
        ];
        $before = $count();

        foreach (['modules' => ['title' => 'Hacked'], 'announcements' => ['subject' => 'Hacked', 'message' => '{}'], 'discussions' => ['title' => 'Hacked', 'body' => 'x']] as $path => $body) {
            try {
                $this->withBody(json_encode($body))->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                    ->call('post', "dean/api/courses/{$this->offering}/{$path}");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, $count());
    }

    public function testACourseShareFileOpensForTheDean(): void
    {
        $teacherFile = CourseFactory::file($this->teacher, 'lecture notes');
        db_connect()->table('files')->where('id', $teacherFile['id'])->update(['status' => 'active']);

        [$item] = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->offering)), [['title' => 'Notes']]);
        CourseFactory::resource($item, 'pdf', null, $teacherFile['id']);

        $this->assertSame(200, $this->step('GET', 'files/' . $teacherFile['id'])->response()->getStatusCode());
    }

    public function testAStudentsSubmissionFileDoesNotOpenForTheDean(): void
    {
        $workFile = CourseFactory::file($this->student, 'my homework');
        db_connect()->table('files')->where('id', $workFile['id'])->update(['status' => 'active']);

        $module     = CourseFactory::module($this->offering);
        $assessment = CourseFactory::assessment($this->offering, $this->teacher, $module);
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $assessment, 'student_id' => $this->student->id, 'attempt_number' => 1, 'started_at' => date('Y-m-d H:i:s'), 'submission_file_id' => $workFile['id'], 'status' => 'Submitted']);

        $this->assertNotFound(fn () => $this->call('GET', 'files/' . $workFile['id']), "a student's submission");
    }

    public function testTheDeanOfAnotherDepartmentCannotOpenTheCourseFile(): void
    {
        $file = CourseFactory::file($this->teacher, 'lecture notes');
        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active']);
        [$item] = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->offering)), [['title' => 'Notes']]);
        CourseFactory::resource($item, 'pdf', null, $file['id']);

        $next = DeanFactory::department('M', $this->institution);
        $this->signIn(DeanFactory::dean($this->institution, $next['department'], 'Neil'));

        $this->assertNotFound(fn () => $this->call('GET', 'files/' . $file['id']), "another department's course file");
    }

    public function testADeanWithoutADepartmentFindsNoCourse(): void
    {
        $this->signIn(CourseFactory::user('dean'));

        $this->assertNotFound(fn () => $this->call('get', "dean/courses/{$this->offering}/x/overview"), 'a course for an unassigned dean');
        $this->assertNotFound(fn () => $this->api('overview'), 'its api for an unassigned dean');
    }

    public function testAnEndedDeanshipTakesEffectAtOnce(): void
    {
        $this->assertSame(200, $this->api('overview')->response()->getStatusCode());

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertNotFound(fn () => $this->api('overview'), 'a course after the deanship ended');
    }

    public function testAReadOnlyInstitutionCanStillLookAtCourses(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(200, $this->api('overview')->response()->getStatusCode());
        $this->assertStringContainsString('data-view-only', $this->step('GET', "dean/courses/{$this->offering}/intro/overview")->response()->getBody());
    }

    public function testAClosedCourseSaysSoAsWellAsViewOnly(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);

        $body = $this->step('GET', "dean/courses/{$this->offering}/intro/overview")->response()->getBody();

        $this->assertStringContainsString('data-view-only', $body);
        $this->assertStringContainsString('data-offering-closed', $body);
    }

    public function testTheDeansCoursesListLinksToTheViewOnlyCourse(): void
    {
        $list = html_entity_decode($this->step('GET', 'dean/courses')->response()->getBody());

        $this->assertStringContainsString("dean/courses/{$this->offering}/intro-to-computing", $list);
        $this->assertStringNotContainsString("courses/{$this->sibling}/", $list);
    }

    public function testADeanWhoAlsoTeachesSeesTheirOwnCourseViewOnlyAsDeanAndNormallyAsInstructor(): void
    {
        // Dora teaches and is made dean through the real service (which replaces the department's earlier dean).
        $dean = PeopleFactory::person('instructor', 'Dora', 'Dean', 'dora@dcv.example', $this->institution);
        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $dean->id);
        $dean = model(CodeIgniter\Shield\Models\UserModel::class)->findById($dean->id);
        CourseFactory::assignInstructor($this->offering, $dean);
        $this->signIn($dean);

        // Acting as dean (the default): the dean's read-only view.
        $asDean = $this->step('GET', "dean/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody();
        $this->assertStringContainsString('data-view-only', $asDean);
        $this->assertSame(200, $this->api('modules')->response()->getStatusCode());

        // Switch to instructor: the instructor's own page, not view-only, and the dean area is closed.
        $this->assertSame(200, $this->step('POST', 'role/switch', ['role' => 'instructor'])->response()->getStatusCode());

        $asInstructor = $this->step('GET', "instructor/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody();
        $this->assertStringNotContainsString('data-view-only', $asInstructor);
        $this->assertStringContainsString("instructor\\/api\\/courses\\/{$this->offering}", $asInstructor);

        $this->assertSame(302, $this->step('GET', "dean/courses/{$this->offering}/intro-to-computing/overview")->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', "dean/api/courses/{$this->offering}/overview")->response()->getStatusCode());
    }

    public function testTheInstructorsAndStudentsOwnPagesAreUnchanged(): void
    {
        $this->signIn($this->teacher);
        $body = $this->step('GET', "instructor/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody();

        $this->assertStringNotContainsString('data-view-only', $body);
        $this->assertStringContainsString("instructor\\/api\\/courses\\/{$this->offering}", $body);

        $this->signIn($this->student);
        $this->assertStringNotContainsString('data-view-only', $this->step('GET', "student/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody());
    }
}
