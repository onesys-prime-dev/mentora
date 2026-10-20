<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeSignoffService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's term-end sign-off page as the dean uses it: the sections by program
 * and where each stands, signing off one or all ready ones (which asks for the
 * password again), reopening with a reason, and the doors: nobody else signs off,
 * nothing from another department, and a read-only institution signs nothing.
 *
 * @internal
 */
final class DeanSignoffPageTest extends RouteTestCase
{
    private int $institution;
    private int $department;
    private int $sub;
    private int $program;
    private int $term;
    private int $ready;
    private int $blocked;
    private User $teacher;
    private User $chair;
    private User $dean;
    private GradeSignoffService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('renderer');

        $home              = DeanFactory::department('S');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'S');
        $this->program     = DeanFactory::program($this->sub, 'S');
        $this->term        = DeanFactory::term($this->institution);

        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->chair   = DeanFactory::chair($this->sub, 'Maria');
        $this->dean    = DeanFactory::dean($this->institution, $this->department);

        $this->ready   = $this->section('IT101', true);
        $this->blocked = $this->section('IT102', false);

        $this->service = new GradeSignoffService();
    }

    /** A section with two students; finalized (as the instructor would have) when asked. */
    private function section(string $code, bool $finalized, ?int $program = null): int
    {
        $offering = DeanFactory::offering($program ?? $this->program, $this->term, $code, 'A', 'active', "Course {$code}");
        CourseFactory::assignInstructor($offering, $this->teacher);

        foreach (['Ann', 'Bob'] as $name) {
            CourseFactory::enroll($offering, PeopleFactory::person('student', $name, 'Lee', null, $this->institution));
        }

        if ($finalized) {
            db_connect()->table('grade_finalizations')->insert([
                'course_offering_id' => $offering, 'finalized_by' => $this->teacher->id, 'finalized_at' => date('Y-m-d H:i:s'), 'students' => 2, 'open_marker' => 1,
            ]);
        }

        return $offering;
    }

    private function page(string $query = ''): string
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($this->dean);
        $this->withSession();

        $response = $this->call('GET', '/dean/signoff' . $query)->response();

        $this->assertSame(200, $response->getStatusCode());

        return html_entity_decode($response->getBody());
    }

    private function confirmed(): void
    {
        $_SESSION['reauth'] = ['user' => $this->dean->id, 'at' => time()];
        $this->withSession();   // carry it into the requests that follow, as a browser keeps its cookie
    }

    /** @param array<string, string> $data */
    private function send(string $uri, array $data = []): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])->call('post', $uri, $data);
    }

    public function testTheDeanSeesTheSectionsByProgramAndWhereEachStands(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('Program S', $body);
        $this->assertStringContainsString('1 ready · 1 blocked · 0 signed off', $body);
        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('Tess Teacher', $body);
        $this->assertStringContainsString('2 students', $body);
        $this->assertStringContainsString('The instructor has not finalized the grades yet.', $body);
        $this->assertStringContainsString('data-state="ready"', $body);
        $this->assertStringContainsString('data-state="blocked"', $body);
        $this->assertStringContainsString('Sign off all 1 ready', $body);

        $this->assertStringContainsString('Sign off the final grades of IT101 A (2 students)?', $body);
        $this->assertStringContainsString('Sign off the grades of 1 ready section in Program S?', $body);
        $this->assertStringContainsString('only you can', $body);
        $this->assertStringContainsString('id="reauth-dialog"', $body, 'the password can be asked for without leaving the page');
        $this->assertStringContainsString('First (2026-2027)', $body);
    }

    public function testABlockedSectionLinksToItsGradesAndToTheOpenRequests(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('See the grades (view only)', $body);
        $this->assertStringContainsString("dean/courses/{$this->blocked}/course-it102/grades", $body);
        $this->assertStringNotContainsString('Decide the open requests', $body, 'no request is open yet');

        $student = (int) db_connect()->table('enrollments')->where('offering_id', $this->blocked)->get()->getRowArray()['student_user_id'];
        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $this->blocked, 'student_id' => $student, 'reason' => 'x', 'requested_by' => $this->teacher->id,
            'requester_role' => 'instructor', 'status' => 'awaiting_dean', 'open_marker' => 1,
        ]);

        $withRequest = $this->page();

        $this->assertStringContainsString('Decide the open requests', $withRequest);
        $this->assertStringContainsString('dean/approvals', $withRequest);
        $this->assertStringContainsString('1 grade-override request is still open.', $withRequest);
    }

    public function testSigningOffNeedsThePasswordAgainThenWorks(): void
    {
        $this->loggedInAs($this->dean);

        $refused = $this->send("dean/signoff/{$this->ready}");
        $this->assertSame(403, $refused->response()->getStatusCode());
        $this->assertSame('reauth_required', json_decode((string) $refused->getJSON(), true)['error']);
        $this->assertFalse($this->service->isSignedOff($this->ready));

        $this->confirmed();
        $result = $this->send("dean/signoff/{$this->ready}");
        $json   = json_decode((string) $result->getJSON(), true);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertSame('Signed off.', $json['message']);
        $this->assertStringEndsWith('/dean/signoff', $json['redirect']);
        $this->assertTrue($this->service->isSignedOff($this->ready));

        $body = $this->page();
        $this->assertStringContainsString('0 ready · 1 blocked · 1 signed off', $body);
        $this->assertStringContainsString('Signed off ', $body);
        $this->assertStringContainsString('Reopen…', $body);
    }

    public function testWithoutJavaScriptTheGuardedActionSendsThemToConfirmThePasswordFirst(): void
    {
        $this->loggedInAs($this->dean);
        $this->withHeaders(['Accept' => 'text/html']);

        $response = $this->call('post', "dean/signoff/{$this->ready}")->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/reauth', $response->getHeaderLine('Location'));
        $this->assertFalse($this->service->isSignedOff($this->ready));
    }

    public function testASectionThatIsNotReadyIsRefusedWithTheReason(): void
    {
        $this->loggedInAs($this->dean);
        $this->confirmed();

        $result = $this->send("dean/signoff/{$this->blocked}");

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertSame('The instructor has not finalized the grades yet.', json_decode((string) $result->getJSON(), true)['message']);
        $this->assertFalse($this->service->isSignedOff($this->blocked));
    }

    public function testSigningOffAProgramsReadySectionsSaysHowManyAndStaysOnTheTerm(): void
    {
        $this->section('IT103', true);
        $this->loggedInAs($this->dean);
        $this->confirmed();

        $result = $this->send("dean/signoff/program/{$this->program}/{$this->term}");
        $json   = json_decode((string) $result->getJSON(), true);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString('Signed off 2 sections', $json['message']);
        $this->assertStringEndsWith('/dean/signoff?term=' . $this->term, $json['redirect']);
        $this->assertTrue($this->service->isSignedOff($this->ready));
        $this->assertFalse($this->service->isSignedOff($this->blocked));
    }

    public function testReopeningNeedsAReasonAndEndsTheSignOff(): void
    {
        $this->loggedInAs($this->dean);
        $this->confirmed();
        $this->send("dean/signoff/{$this->ready}");

        $refused = $this->send("dean/signoff/{$this->ready}/reopen", ['reason' => '']);
        $this->assertSame(422, $refused->response()->getStatusCode());
        $this->assertArrayHasKey('reason', json_decode((string) $refused->getJSON(), true)['errors']);
        $this->assertTrue($this->service->isSignedOff($this->ready));

        $result = $this->send("dean/signoff/{$this->ready}/reopen", ['reason' => 'A mark was entered wrongly.']);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertFalse($this->service->isSignedOff($this->ready));
        $this->assertStringContainsString('data-state="ready"', $this->page(), 'back to ready, still finalized');
    }

    public function testNoOtherRoleSignsOffOrEvenReadsThePage(): void
    {
        $student = PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution);

        foreach ([$this->chair, $this->teacher, $student] as $user) {
            $this->loggedInAs($user);

            $this->assertSame(302, $this->call('get', 'dean/signoff')->response()->getStatusCode());
            $this->assertSame(302, $this->call('post', "dean/signoff/{$this->ready}")->response()->getStatusCode());
            $this->assertSame(302, $this->call('post', "dean/signoff/program/{$this->program}/{$this->term}")->response()->getStatusCode());
            $this->assertSame(302, $this->call('post', "dean/signoff/{$this->ready}/reopen", ['reason' => 'x'])->response()->getStatusCode());
        }

        $this->assertFalse($this->service->isSignedOff($this->ready));
    }

    public function testAnotherDepartmentsDeanSeesAndSignsOffNothing(): void
    {
        $other      = DeanFactory::department('B', $this->institution);
        $this->dean = DeanFactory::dean($this->institution, $other['department'], 'Olga');

        $this->assertStringNotContainsString('IT101', $this->page());

        $this->confirmed();
        $this->assertSame(422, $this->send("dean/signoff/{$this->ready}")->response()->getStatusCode());
        $this->assertSame(422, $this->send("dean/signoff/program/{$this->program}/{$this->term}")->response()->getStatusCode());
        $this->assertFalse($this->service->isSignedOff($this->ready));
    }

    public function testADeanWhoAlsoTeachesCannotUseItWhileActingAsAnInstructor(): void
    {
        $person = PeopleFactory::person('instructor', 'Dora', 'Dean', null, $this->institution);
        (new \App\Services\Institutions\DeanAssignmentService())->assign($this->institution, $this->department, (int) $person->id);
        $dora = model(CodeIgniter\Shield\Models\UserModel::class)->findById($person->id);
        $this->loggedInAs($dora);

        $this->assertSame(200, $this->call('get', 'dean/signoff')->response()->getStatusCode(), 'as dean');

        $this->withHeaders(['Accept' => 'application/json'])->call('post', 'role/switch', ['role' => 'instructor']);

        $this->assertSame(302, $this->call('get', 'dean/signoff')->response()->getStatusCode(), 'as an instructor');
        $this->assertSame(302, $this->call('post', "dean/signoff/{$this->ready}")->response()->getStatusCode());
    }

    public function testADeanWithoutADepartmentSeesTheExplanation(): void
    {
        $this->loggedInAs(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution));

        $response = $this->call('GET', '/dean/signoff')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not assigned to a department yet', $response->getBody());
    }

    public function testAReadOnlyInstitutionSignsNothing(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->loggedInAs($this->dean);
        $this->confirmed();

        $this->assertSame(200, $this->call('get', 'dean/signoff')->response()->getStatusCode(), 'looking is fine');
        $this->assertSame(403, $this->send("dean/signoff/{$this->ready}")->response()->getStatusCode());
        $this->assertFalse($this->service->isSignedOff($this->ready));
    }

    public function testAnEmptyDepartmentSaysSo(): void
    {
        $other      = DeanFactory::department('E', $this->institution);
        $this->dean = DeanFactory::dean($this->institution, $other['department'], 'Eve');

        $this->assertStringContainsString('No sections to sign off', $this->page());
    }

    public function testAnotherTermIsChosenInTheAddress(): void
    {
        $later = DeanFactory::term($this->institution, 'Second', '2027-01-10');
        DeanFactory::offering($this->program, $later, 'IT201', 'A', 'active', 'Course IT201');

        $this->assertStringContainsString('IT201', $this->page(), 'the newest term by default');
        $this->assertStringNotContainsString('IT101', $this->page());

        $first = $this->page('?term=' . $this->term);
        $this->assertStringContainsString('IT101', $first);
        $this->assertStringNotContainsString('IT201', $first);
        $this->assertStringContainsString('IT201', $this->page('?term=nonsense'), 'nonsense falls back, not an error');
    }

    public function testTheInstructorSeesTheSignOffAndHasNoWayToReopen(): void
    {
        $this->loggedInAs($this->teacher);
        $before = $this->withHeaders(['Accept' => 'application/json'])->call('get', "instructor/api/courses/{$this->ready}/grades");
        $this->assertSame(200, $before->response()->getStatusCode());
        $html = html_entity_decode((string) (json_decode((string) $before->getJSON(), true)['html'] ?? ''));
        $this->assertStringContainsString('data-open-reopen', $html, 'finalized and not yet signed off: the instructor can reopen');
        $this->assertStringNotContainsString('data-signed-off-tag', $html);

        $this->service->signOff($this->dean, $this->ready);

        $after = html_entity_decode((string) (json_decode((string) $this->withHeaders(['Accept' => 'application/json'])->call('get', "instructor/api/courses/{$this->ready}/grades")->getJSON(), true)['html'] ?? ''));
        $this->assertStringContainsString('data-signed-off-tag', $after);
        $this->assertStringContainsString('Signed off by the dean', $after);
        $this->assertStringContainsString('only the dean can reopen the grades', $after);
        $this->assertStringNotContainsString('data-open-reopen', $after);
    }

    public function testTheReauthPageIsOpenToADeanAndNotToAStudent(): void
    {
        $this->loggedInAs($this->dean);
        $this->assertSame(200, $this->call('get', 'reauth')->response()->getStatusCode());

        $this->loggedInAs(PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution));
        $this->assertSame(302, $this->call('get', 'reauth')->response()->getStatusCode());
    }

    public function testTheAppsMenuHasASignOffTile(): void
    {
        $this->loggedInAs($this->dean);

        $this->assertStringContainsString('dean/signoff', html_entity_decode($this->call('get', 'dean/dashboard')->response()->getBody()));
    }
}
