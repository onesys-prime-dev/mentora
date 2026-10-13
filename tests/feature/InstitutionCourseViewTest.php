<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The institutional administrator's read-only view of a course: reachable for
 * every offering of their institution and no other, shown as its instructor
 * sees it, marked "view only", and with no route anywhere that changes
 * anything in a course.
 *
 * @internal
 */
final class InstitutionCourseViewTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private int $offering;

    private int $foreign;

    private User $admin;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('CVWA');
        $this->other       = SisFixture::institution('CVWB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@cvw.example', $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tom', 'Teacher', 'tom@cvw.example', $this->institution);
        $this->student     = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@cvw.example', $this->institution);

        $this->offering = $this->offeringIn($this->institution, 'IT101');
        $this->foreign  = $this->offeringIn($this->other, 'ZZ900');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $this->signIn($this->admin);
    }

    private function offeringIn(int $institution, string $code): int
    {
        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => $code, 'course_title' => 'Intro to Computing', 'units' => 3]);
        $course = (int) $db->insertID();
        $term   = (int) $db->table('academic_terms')->where('institution_id', $institution)->get()->getRowArray()['id'];

        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'A', 'status' => 'active']);

        return (int) $db->insertID();
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

        return $this->call('get', 'institution/api/courses/' . ($offering ?? $this->offering) . '/' . $path);
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@cvw.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', "institution/courses/{$this->offering}/intro/overview")->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', "institution/api/courses/{$this->offering}/overview")->response()->getStatusCode());
    }

    public function testTheCoursePageOpensViewOnlyAndAddressesTheReadOnlyRoutes(): void
    {
        $body = $this->step('GET', "institution/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody();

        $this->assertStringContainsString('data-view-only', $body);
        $this->assertStringContainsString('View only. You are looking at IT101 A', $body);
        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString("institution\\/api\\/courses\\/{$this->offering}", $body);
        $this->assertStringContainsString("institution\\/courses\\/{$this->offering}\\/intro-to-computing", $body);
        $this->assertStringNotContainsString('instructor\\/api\\/courses', $body);
    }

    public function testTheShortCourseAddressRedirectsWithinTheReadOnlyArea(): void
    {
        $location = $this->step('GET', "institution/courses/{$this->offering}/intro-to-computing")->response()->getHeaderLine('Location');

        $this->assertStringEndsWith("institution/courses/{$this->offering}/intro-to-computing/overview", $location);
    }

    public function testAnotherInstitutionsCourseIsNotFoundOnEveryReadingRoute(): void
    {
        $paths = ['overview', 'announcements', 'announcements/more', 'modules', 'resources', 'discussions', 'assessments', 'grades', 'grades/scheme', 'grades/roster', 'grades/export'];

        foreach ($paths as $path) {
            try {
                $result = $this->api($path, $this->foreign);
                $this->fail("{$path} of another institution's course must be a 404 (got " . $result->response()->getStatusCode() . ')');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->call('get', "institution/courses/{$this->foreign}/x/overview");
            $this->fail('the page of another institution\'s course must be a 404');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testEveryReadingRouteAnswersForTheInstitutionsOwnCourse(): void
    {
        $paths = ['overview', 'announcements', 'modules', 'resources', 'discussions', 'assessments', 'grades', 'grades/scheme', 'grades/roster'];

        foreach ($paths as $path) {
            $result = $this->api($path);

            $this->assertSame(200, $result->response()->getStatusCode(), $path);
        }
    }

    public function testItShowsWhatTheInstructorSeesIncludingDraftsThatAStudentDoesNot(): void
    {
        CourseFactory::module($this->offering, 'published', 1);
        $draft = db_connect()->table('course_modules')->insert(['offering_id' => $this->offering, 'title' => 'Draft week', 'order_index' => 2, 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNotFalse($draft);

        $viewer = json_encode($this->api('modules')->getJSON());
        $this->assertStringContainsString('Draft week', (string) $viewer);

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

                $uri = 'institution/' . str_replace('([0-9]+)', '1', substr($pattern, strlen('chairperson/')));
                $uri = str_replace('1', (string) $this->offering, $uri);

                try {
                    $this->withHeaders(['Accept' => 'application/json']);
                    $result = $this->call(strtolower($verb), $uri);
                    $status = $result->response()->getStatusCode();

                    $this->assertContains($status, [401, 403, 404, 405], "{$verb} {$uri} must not be allowed (got {$status})");
                } catch (PageNotFoundException) {
                    // No such route: exactly what is wanted.
                }

                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked, 'the course write routes were found and each one was refused');
    }

    public function testAnAttemptToWriteLeavesTheCourseUntouched(): void
    {
        $before = [
            'modules'       => db_connect()->table('course_modules')->countAllResults(),
            'announcements' => db_connect()->table('announcements')->countAllResults(),
            'threads'       => db_connect()->table('discussion_threads')->countAllResults(),
        ];

        foreach (['modules' => ['title' => 'Hacked'], 'announcements' => ['subject' => 'Hacked', 'message' => '{}'], 'discussions' => ['title' => 'Hacked', 'body' => 'x']] as $path => $body) {
            try {
                $this->withBody(json_encode($body))->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                    ->call('post', "institution/api/courses/{$this->offering}/{$path}");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, [
            'modules'       => db_connect()->table('course_modules')->countAllResults(),
            'announcements' => db_connect()->table('announcements')->countAllResults(),
            'threads'       => db_connect()->table('discussion_threads')->countAllResults(),
        ]);
    }

    public function testACourseShareFileOpensForTheInstitutionButAStudentsWorkDoesNot(): void
    {
        $teacherFile = CourseFactory::file($this->teacher, 'lecture notes');
        $workFile    = CourseFactory::file($this->student, 'my homework');
        db_connect()->table('files')->whereIn('id', [$teacherFile['id'], $workFile['id']])->update(['status' => 'active']);

        $module = CourseFactory::module($this->offering);
        $unit   = CourseFactory::unit($module);
        [$item] = CourseFactory::items($unit, [['title' => 'Notes']]);
        CourseFactory::resource($item, 'pdf', null, $teacherFile['id']);

        $assessment = CourseFactory::assessment($this->offering, $this->teacher, $module);
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $assessment, 'student_id' => $this->student->id, 'attempt_number' => 1, 'started_at' => date('Y-m-d H:i:s'), 'submission_file_id' => $workFile['id'], 'status' => 'Submitted']);

        $this->assertSame(200, $this->step('GET', 'files/' . $teacherFile['id'])->response()->getStatusCode());

        $this->expectException(PageNotFoundException::class);
        $this->call('GET', 'files/' . $workFile['id']);
    }

    public function testAnotherInstitutionsAdministratorCannotOpenTheCourseFile(): void
    {
        $file = CourseFactory::file($this->teacher, 'lecture notes');
        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active']);
        $module = CourseFactory::module($this->offering);
        [$item] = CourseFactory::items(CourseFactory::unit($module), [['title' => 'Notes']]);
        CourseFactory::resource($item, 'pdf', null, $file['id']);

        $this->signIn(PeopleFactory::person('institutional_admin', 'Eve', 'Elsewhere', 'eve@cvw.example', $this->other));

        $this->expectException(PageNotFoundException::class);
        $this->call('GET', 'files/' . $file['id']);
    }

    public function testAReadOnlyInstitutionCanStillLookAtCourses(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(200, $this->api('overview')->response()->getStatusCode());
        $this->assertStringContainsString('data-view-only', $this->step('GET', "institution/courses/{$this->offering}/intro/overview")->response()->getBody());
    }

    public function testAClosedCourseSaysSoAsWellAsViewOnly(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);

        $body = $this->step('GET', "institution/courses/{$this->offering}/intro/overview")->response()->getBody();

        $this->assertStringContainsString('data-view-only', $body);
        $this->assertStringContainsString('data-offering-closed', $body);
    }

    public function testTheOfferingsListAndTheReportLinkToTheCourse(): void
    {
        $list = html_entity_decode($this->step('GET', 'institution/offerings')->response()->getBody());
        $this->assertStringContainsString("institution/courses/{$this->offering}/intro-to-computing/overview", $list);
        $this->assertStringContainsString('data-view-course', $list);

        $report = html_entity_decode($this->step('GET', "institution/reports/offerings/{$this->offering}")->response()->getBody());
        $this->assertStringContainsString('Open the course (view only)', $report);
    }

    public function testTheChairpersonsAndInstructorsOwnPagesAreUnchanged(): void
    {
        $this->signIn($this->teacher);
        $body = $this->step('GET', "instructor/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody();

        $this->assertStringNotContainsString('data-view-only', $body);
        $this->assertStringContainsString("instructor\\/api\\/courses\\/{$this->offering}", $body);

        $this->signIn($this->student);
        $this->assertStringNotContainsString('data-view-only', $this->step('GET', "student/courses/{$this->offering}/intro-to-computing/overview")->response()->getBody());
        $this->assertTrue(class_exists(GradeFactory::class));
    }
}
