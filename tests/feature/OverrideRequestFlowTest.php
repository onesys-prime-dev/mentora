<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * A change to a final grade through the real routes. Where the institution has
 * deans it is a request: the grade stays as calculated, the chairperson endorses
 * or sends it back, and the requester can edit or withdraw. Where it has none the
 * override applies at once, as before.
 *
 * @internal
 */
final class OverrideRequestFlowTest extends RouteTestCase
{
    private int $institution;
    private int $department;
    private int $sub;
    private int $offering;
    private int $scheme;
    private User $teacher;
    private User $chair;
    private User $dean;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('renderer');

        $home              = DeanFactory::department('F');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'F');
        $this->offering    = DeanFactory::offering(DeanFactory::program($this->sub, 'F'), DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');

        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->chair   = DeanFactory::chair($this->sub, 'Maria');
        $this->dean    = DeanFactory::dean($this->institution, $this->department);
        $this->student = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $this->scheme = GradeFactory::scheme($this->offering);
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
        $this->record($this->offering, $this->student, 88.0);
    }

    private function record(int $offering, User $student, float $calculated): void
    {
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id'    => $offering,
            'student_id'            => $student->id,
            'grading_scheme_id'     => (int) db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->get()->getRowArray()['id'],
            'calculated_percentage' => $calculated,
            'is_override'           => 0,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function json(string $verb, string $uri, array $body = []): array
    {
        $result = $this->withBody(json_encode($body))->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])->call($verb, $uri);

        return (array) json_decode((string) $result->getJSON(), true) + ['_status' => $result->response()->getStatusCode()];
    }

    private function override(string $area = 'instructor', ?float $percentage = 92.0, string $reason = 'Late quiz excused (medical).'): array
    {
        return $this->json('patch', "{$area}/api/courses/{$this->offering}/grades/roster/students/{$this->student->id}/override", ['percentage' => $percentage, 'reason' => $reason]);
    }

    /** The gradebook's HTML as the chairperson's or instructor's page receives it, entities decoded for matching text. */
    private function roster(): string
    {
        return html_entity_decode($this->json('get', "instructor/api/courses/{$this->offering}/grades/roster")['html']);
    }

    private function openRequestId(): int
    {
        return (int) db_connect()->table('grade_override_requests')->where('open_marker', 1)->get()->getRowArray()['id'];
    }

    private function requestStatus(int $id): string
    {
        return (string) db_connect()->table('grade_override_requests')->where('id', $id)->get()->getRowArray()['status'];
    }

    public function testAnInstructorsOverrideBecomesARequestAndTheGradeStaysAsCalculated(): void
    {
        $this->loggedInAs($this->teacher);

        $result = $this->override();

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('awaiting_chair', $result['request']['status']);

        $record = db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->get()->getRowArray();
        $this->assertSame(0, (int) $record['is_override'], 'asking changes nothing');
        $this->assertSame(0, db_connect()->table('grade_history')->countAllResults(), 'and writes no grade history');
    }

    public function testWhereTheInstitutionHasNoDeanTheOverrideStillAppliesAtOnce(): void
    {
        $other   = DeanFactory::department('L');
        $program = DeanFactory::program(DeanFactory::subDepartment($other['department'], 'L'), 'L');
        $offer   = DeanFactory::offering($program, DeanFactory::term($other['institution']), 'LW100');
        $teacher = PeopleFactory::person('instructor', 'Lena', 'Law', null, $other['institution']);
        $student = PeopleFactory::person('student', 'Bob', 'Ray', null, $other['institution']);
        CourseFactory::assignInstructor($offer, $teacher);
        CourseFactory::enroll($offer, $student);
        GradeFactory::scheme($offer);
        $this->record($offer, $student, 70.0);

        $this->loggedInAs($teacher);
        $result = $this->json('patch', "instructor/api/courses/{$offer}/grades/roster/students/{$student->id}/override", ['percentage' => 85, 'reason' => 'Re-marked']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $record = db_connect()->table('gradebook_records')->where('student_id', $student->id)->get()->getRowArray();
        $this->assertSame(1, (int) $record['is_override'], 'applied at once: this institution has no dean');
        $this->assertSame(85.0, (float) $record['overridden_percentage']);
        $this->assertSame(0, db_connect()->table('grade_override_requests')->countAllResults(), 'no request');
    }

    public function testAChairpersonsOwnOverrideGoesToTheDean(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->override('chairperson');

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('awaiting_dean', $result['request']['status']);
    }

    public function testAnOverrideThatIsRefusedSaysWhy(): void
    {
        $this->loggedInAs($this->teacher);

        $this->assertStringContainsString('reason', $this->override('instructor', 92.0, ' ')['message']);
        $this->assertStringContainsString('between 0 and 100', $this->override('instructor', 120.0)['message']);

        $this->override();
        $this->assertStringContainsString('already has an open request', $this->override('instructor', 95.0, 'Again')['message']);
    }

    public function testTheGradebookShowsHowAChangeIsMadeAndWhereARequestStands(): void
    {
        $this->loggedInAs($this->teacher);

        $before = $this->roster();

        $this->assertStringContainsString('data-override-mode="request"', $before);
        $this->assertStringContainsString('It goes to the chairperson (Maria Chair) to endorse, then to the dean to decide.', $before);
        $this->assertStringContainsString('Click to request an override', $before);
        $this->assertStringNotContainsString('data-request-state', $before);

        $this->override();
        $after = $this->roster();

        $this->assertStringContainsString('data-request-state', $after);
        $this->assertStringContainsString('Override requested (92%): waiting for the chairperson', $after);
        $this->assertStringContainsString('data-request-status="awaiting_chair"', $after);
        $this->assertStringContainsString('See your override request', $after);
    }

    public function testTheGradebookIsThePlainOverrideWhereThereAreNoDeans(): void
    {
        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);
        $this->loggedInAs($this->teacher);

        $html = $this->roster();

        $this->assertStringContainsString('data-override-mode="direct"', $html);
        $this->assertStringContainsString('Click to override', $html);
        $this->assertStringNotContainsString('data-request-state', $html);
    }

    public function testTheChairpersonSeesTheRequestAndEndorsesIt(): void
    {
        $this->loggedInAs($this->teacher);
        $this->override();
        $id = $this->openRequestId();

        $this->loggedInAs($this->chair);
        $page = $this->call('get', 'chairperson/override-requests')->response();
        $body = html_entity_decode($page->getBody());

        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('88%', $body);
        $this->assertStringContainsString('92%', $body);
        $this->assertStringContainsString('Late quiz excused (medical).', $body);
        $this->assertStringContainsString('Tess Teacher', $body);
        $this->assertStringContainsString('id="endorse-' . $id . '"', $body);
        $this->assertStringContainsString('id="send-back-' . $id . '"', $body);

        $result = $this->withHeaders(['Accept' => 'application/json'])->call('post', "chairperson/override-requests/{$id}/endorse", ['comment' => 'Fine by me']);
        $json   = json_decode((string) $result->getJSON(), true);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString('waits for the dean', $json['message']);
        $this->assertStringEndsWith('/chairperson/override-requests', $json['redirect']);
        $this->assertSame('awaiting_dean', $this->requestStatus($id));

        $this->assertStringContainsString('Nothing waiting for you', $this->call('get', 'chairperson/override-requests')->response()->getBody());
    }

    public function testSendingBackNeedsACommentAndTheRequesterCanThenEditAndSendAgain(): void
    {
        $this->loggedInAs($this->teacher);
        $this->override();
        $id = $this->openRequestId();

        $this->loggedInAs($this->chair);
        $refused = $this->withHeaders(['Accept' => 'application/json'])->call('post', "chairperson/override-requests/{$id}/send-back", ['comment' => '']);
        $this->assertSame(422, $refused->response()->getStatusCode());
        $this->assertArrayHasKey('comment', json_decode((string) $refused->getJSON(), true)['errors']);
        $this->assertSame('awaiting_chair', $this->requestStatus($id));

        $sent = $this->withHeaders(['Accept' => 'application/json'])->call('post', "chairperson/override-requests/{$id}/send-back", ['comment' => 'Attach the medical note.']);
        $this->assertSame(200, $sent->response()->getStatusCode());
        $this->assertSame('returned', $this->requestStatus($id));

        $this->loggedInAs($this->teacher);
        $html = $this->roster();
        $this->assertStringContainsString('Request sent back: edit or withdraw', $html);
        $this->assertStringContainsString('data-request-comment="Attach the medical note."', $html);

        $edited = $this->json('patch', "instructor/api/courses/{$this->offering}/grades/override-requests/{$id}", ['percentage' => 93, 'reason' => 'Medical note attached.']);
        $this->assertTrue($edited['success'], $edited['message'] ?? '');
        $this->assertSame('awaiting_chair', $this->requestStatus($id));
    }

    public function testWithoutJavaScriptTheDialogFormsRedirectWithAMessage(): void
    {
        $this->loggedInAs($this->teacher);
        $this->override();
        $id = $this->openRequestId();

        $this->loggedInAs($this->chair);
        $this->withHeaders(['Accept' => 'text/html']);
        $response = $this->call('post', "chairperson/override-requests/{$id}/endorse", ['comment' => ''])->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/chairperson/override-requests', $response->getHeaderLine('Location'));
        $this->assertSame('awaiting_dean', $this->requestStatus($id));
    }

    public function testTheRequesterWithdrawsAndNobodyElseCan(): void
    {
        $this->loggedInAs($this->teacher);
        $this->override();
        $id = $this->openRequestId();

        $this->loggedInAs($this->chair);
        $this->assertFalse($this->json('delete', "chairperson/api/courses/{$this->offering}/grades/override-requests/{$id}")['success'], 'not the requester');
        $this->assertSame('awaiting_chair', $this->requestStatus($id));

        $this->loggedInAs($this->teacher);
        $this->assertTrue($this->json('delete', "instructor/api/courses/{$this->offering}/grades/override-requests/{$id}")['success']);
        $this->assertSame('withdrawn', $this->requestStatus($id));
    }

    public function testAnotherSubDepartmentsChairpersonCannotSeeOrActOnTheRequest(): void
    {
        $this->loggedInAs($this->teacher);
        $this->override();
        $id = $this->openRequestId();

        $elsewhere = DeanFactory::chair(DeanFactory::subDepartment($this->department, 'Other'), 'Olga');
        $this->loggedInAs($elsewhere);

        $this->assertStringNotContainsString('Ann Lee', $this->call('get', 'chairperson/override-requests?view=all')->response()->getBody());

        $result = $this->withHeaders(['Accept' => 'application/json'])->call('post', "chairperson/override-requests/{$id}/endorse", ['comment' => '']);
        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertSame('awaiting_chair', $this->requestStatus($id));
    }

    public function testNoOtherRoleReachesTheChairpersonsList(): void
    {
        foreach ([$this->teacher, $this->student, $this->dean] as $user) {
            $this->loggedInAs($user);

            $this->assertSame(302, $this->call('get', 'chairperson/override-requests')->response()->getStatusCode());
            $this->assertSame(302, $this->call('post', 'chairperson/override-requests/1/endorse')->response()->getStatusCode());
        }
    }

    public function testAReadOnlyInstitutionCannotMakeOrAnswerARequest(): void
    {
        $this->loggedInAs($this->teacher);
        $this->override();
        $id = $this->openRequestId();

        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $refused = $this->override('instructor', 95.0, 'Another');
        $this->assertSame(403, $refused['_status']);

        $this->loggedInAs($this->chair);
        $answer = $this->withHeaders(['Accept' => 'application/json'])->call('post', "chairperson/override-requests/{$id}/endorse", ['comment' => '']);
        $this->assertSame(403, $answer->response()->getStatusCode());
        $this->assertSame('awaiting_chair', $this->requestStatus($id));
    }

    public function testOnlyTheChairpersonHasTheOverrideRequestsTile(): void
    {
        $this->loggedInAs($this->chair);
        $this->assertStringContainsString('chairperson/override-requests', html_entity_decode($this->call('get', 'chairperson/dashboard')->response()->getBody()));

        $this->loggedInAs($this->teacher);
        $this->assertStringNotContainsString('override-requests', html_entity_decode($this->call('get', 'instructor/dashboard')->response()->getBody()));
    }
}
