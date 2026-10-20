<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeOverrideRequestService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's Approvals as the dean uses them: what waits for them with how the
 * grade is made up, approving (the grade changes) and rejecting (a reason), the
 * history, the filters, and the doors: nobody else decides, nothing from another
 * department, and a read-only institution decides nothing.
 *
 * @internal
 */
final class DeanApprovalsPageTest extends RouteTestCase
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
    private GradeOverrideRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('renderer');

        $home              = DeanFactory::department('A');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'A');
        $this->offering    = DeanFactory::offering(DeanFactory::program($this->sub, 'A'), DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');

        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->chair   = DeanFactory::chair($this->sub, 'Maria');
        $this->dean    = DeanFactory::dean($this->institution, $this->department);
        $this->student = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $this->scheme = GradeFactory::scheme($this->offering);
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $this->offering, 'student_id' => $this->student->id, 'grading_scheme_id' => $this->scheme,
            'calculated_percentage' => 88.0, 'is_override' => 0,
        ]);

        $quizzes = GradeFactory::category($this->scheme, 'Quizzes', 30);
        GradeFactory::score($this->offering, $this->student, GradeFactory::item($this->scheme, $quizzes, 'Quiz 1', 10), 8.0);

        $this->service = new GradeOverrideRequestService();
    }

    /** A request that has reached the dean. @return int request id */
    private function waiting(float $percentage = 92.0, ?User $student = null): int
    {
        $id = $this->service->request($this->teacher, $this->offering, (int) ($student ?? $this->student)->id, $percentage, 'Late quiz excused (medical).')['request']['id'];
        $this->service->endorse($this->chair, $id, 'Looks right to me.');

        return $id;
    }

    private function page(string $query = ''): string
    {
        $this->loggedInAs($this->dean);

        $response = $this->call('GET', '/dean/approvals' . $query)->response();

        $this->assertSame(200, $response->getStatusCode());

        return html_entity_decode($response->getBody());
    }

    private function grade(): array
    {
        return db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->get()->getRowArray();
    }

    private function requestStatus(int $id): string
    {
        return (string) db_connect()->table('grade_override_requests')->where('id', $id)->get()->getRowArray()['status'];
    }

    /** @param array<string, string> $data */
    private function send(string $uri, array $data = []): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])->call('post', $uri, $data);
    }

    public function testTheDeanSeesWhatWaitsForThemAndHowTheGradeIsMadeUp(): void
    {
        $id   = $this->waiting();
        $body = $this->page();

        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('88%', $body);
        $this->assertStringContainsString('92%', $body);
        $this->assertStringContainsString('Tess Teacher', $body);
        $this->assertStringContainsString('(1)', $body, 'the count on the tab');

        $this->assertStringContainsString('id="review-' . $id . '"', $body);
        $this->assertStringContainsString('Approve 92% for Ann Lee in IT101 A? This replaces the calculated 88%.', $body);
        $this->assertStringContainsString('Late quiz excused (medical).', $body);
        $this->assertStringContainsString('Maria Chair', $body);
        $this->assertStringContainsString('Looks right to me.', $body);
        $this->assertStringContainsString('How the grade is made up', $body);
        $this->assertStringContainsString('Quizzes', $body);
        $this->assertStringContainsString('80%', $body);
        $this->assertStringContainsString('dean/approvals/' . $id . '/approve', $body);
        $this->assertStringContainsString('dean/approvals/' . $id . '/reject', $body);
        $this->assertStringNotContainsString('data-drift', $body);
    }

    public function testARequestStillWithTheChairpersonIsNotListed(): void
    {
        $this->service->request($this->teacher, $this->offering, (int) $this->student->id, 92.0, 'Late quiz');

        $this->assertStringContainsString('Nothing waiting for you', $this->page());
    }

    public function testARequestThatHadNoChairpersonSaysNobodyEndorsedIt(): void
    {
        db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $this->chair->id)->update(['status' => 'revoked']);
        $this->service->request($this->teacher, $this->offering, (int) $this->student->id, 92.0, 'Late quiz');

        $this->assertStringContainsString('Nobody: no chairperson to ask', $this->page());
    }

    public function testARemovalIsWordedAsOne(): void
    {
        db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->update(['is_override' => 1, 'overridden_percentage' => 95.0]);
        $id = $this->service->request($this->teacher, $this->offering, (int) $this->student->id, null, 'The appeal was withdrawn')['request']['id'];
        $this->service->endorse($this->chair, $id, '');

        $body = $this->page();

        $this->assertStringContainsString('remove the override', $body);
        $this->assertStringContainsString('Approve removing the override for Ann Lee in IT101 A? The grade returns to the calculated 88%.', $body);
        $this->assertStringContainsString('Approve removal', $body);
    }

    public function testAGradeThatMovedSinceTheRequestIsFlagged(): void
    {
        $this->waiting();
        db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->update(['calculated_percentage' => 90.0]);

        $body = $this->page();

        $this->assertStringContainsString('data-drift', $body);
        $this->assertStringContainsString('The calculated grade changed from 88% to 90% since this was asked.', $body);
        $this->assertStringContainsString('This replaces the calculated 90%.', $body, 'the decision names the grade as it is now');
    }

    public function testApprovingChangesTheGradeAndMovesTheRequestToTheDecidedTab(): void
    {
        $id = $this->waiting();
        $this->loggedInAs($this->dean);

        $result = $this->send("dean/approvals/{$id}/approve", ['comment' => 'Agreed']);
        $json   = json_decode((string) $result->getJSON(), true);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString('final grade was changed', $json['message']);
        $this->assertStringEndsWith('/dean/approvals', $json['redirect']);
        $this->assertSame('approved', $this->requestStatus($id));

        $grade = $this->grade();
        $this->assertSame(1, (int) $grade['is_override']);
        $this->assertSame(92.0, (float) $grade['overridden_percentage']);

        $this->assertStringContainsString('Nothing waiting for you', $this->page());

        $decided = $this->page('?view=decided');
        $this->assertStringContainsString('Ann Lee', $decided);
        $this->assertStringContainsString('Approved', $decided);
        $this->assertStringNotContainsString('data-dialog-open', $decided, 'a decided request has nothing left to do');
    }

    public function testRejectingNeedsAReasonAndChangesNothing(): void
    {
        $id = $this->waiting();
        $this->loggedInAs($this->dean);

        $refused = $this->send("dean/approvals/{$id}/reject", ['reason' => '']);
        $this->assertSame(422, $refused->response()->getStatusCode());
        $this->assertArrayHasKey('reason', json_decode((string) $refused->getJSON(), true)['errors']);
        $this->assertSame('awaiting_dean', $this->requestStatus($id));

        $result = $this->send("dean/approvals/{$id}/reject", ['reason' => 'The note does not cover that quiz.']);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertSame('rejected', $this->requestStatus($id));
        $this->assertSame(0, (int) $this->grade()['is_override'], 'the grade is untouched');
        $this->assertStringContainsString('Rejected', $this->page('?view=decided'));
    }

    public function testWithoutJavaScriptADecisionRedirectsWithAMessage(): void
    {
        $id = $this->waiting();
        $this->loggedInAs($this->dean);

        $this->withHeaders(['Accept' => 'text/html']);
        $response = $this->call('post', "dean/approvals/{$id}/approve", ['comment' => ''])->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/dean/approvals', $response->getHeaderLine('Location'));
        $this->assertSame('approved', $this->requestStatus($id));
    }

    public function testTheFiltersNarrowTheListAndTheTabSurvivesThem(): void
    {
        $this->waiting();
        $program = (int) db_connect()->table('programs')->where('sub_department_id', $this->sub)->get()->getRowArray()['id'];

        $this->assertStringContainsString('Ann Lee', $this->page('?q=ann'));
        $this->assertStringNotContainsString('data-dialog-open', $this->page('?q=nobody'));
        $this->assertStringContainsString('No requests match these filters', $this->page('?q=nobody'));
        $this->assertStringContainsString('Ann Lee', $this->page('?program=' . $program));
        $this->assertStringContainsString('No requests match these filters', $this->page('?program=' . ($program + 999)));
        $this->assertStringContainsString('Ann Lee', $this->page('?requested_by=' . $this->teacher->id));
        $this->assertStringContainsString('Ann Lee', $this->page('?view=nonsense&program=x&requested_by=-1'), 'nonsense is ignored, not an error');

        $this->assertStringContainsString('name="view" value="decided"', $this->page('?view=decided'), 'applying a filter stays on the Decided tab');
        $this->assertStringNotContainsString('name="view"', $this->page(), 'and the waiting tab needs no marker');
    }

    public function testTheColumnHeadersSortTheListKeepTheFiltersAndShowTheDirection(): void
    {
        $this->waiting();
        $program = (int) db_connect()->table('programs')->where('sub_department_id', $this->sub)->get()->getRowArray()['id'];

        $body = $this->page('?program=' . $program . '&sort=requested&dir=desc');

        $this->assertMatchesRegularExpression('/<th[^>]*aria-sort="descending"[^>]*>.*?Calculated → requested/s', $body, 'the sorted column says which way');
        $this->assertMatchesRegularExpression('#href="[^"]*dean/approvals\?[^"]*program=' . $program . '[^"]*sort=student#', $body, 'a header link keeps the filter');
        $this->assertStringContainsString('name="sort" value="requested"', $body, 'applying a filter keeps the sort');
        $this->assertStringContainsString('name="dir" value="desc"', $body);

        $plain = $this->page();

        $this->assertStringNotContainsString('name="sort"', $plain, 'the default order needs no marker');
        $this->assertStringContainsString('aria-sort="ascending"', $plain, 'oldest first while waiting');
        $this->assertStringContainsString('Ann Lee', $this->page('?sort=password&dir=up'), 'nonsense is ignored, not an error');
    }

    public function testNoOtherRoleDecidesOrEvenReadsTheList(): void
    {
        $id = $this->waiting();

        foreach ([$this->chair, $this->teacher, $this->student] as $user) {
            $this->loggedInAs($user);

            $this->assertSame(302, $this->call('get', 'dean/approvals')->response()->getStatusCode());
            $this->assertSame(302, $this->call('post', "dean/approvals/{$id}/approve")->response()->getStatusCode());
            $this->assertSame(302, $this->call('post', "dean/approvals/{$id}/reject", ['reason' => 'No'])->response()->getStatusCode());
        }

        $this->assertSame('awaiting_dean', $this->requestStatus($id));
        $this->assertSame(0, (int) $this->grade()['is_override']);
    }

    public function testAnotherDepartmentsDeanSeesAndDecidesNothing(): void
    {
        $id = $this->waiting();

        $other = DeanFactory::department('B', $this->institution);
        $this->dean = DeanFactory::dean($this->institution, $other['department'], 'Olga');

        $this->assertStringNotContainsString('Ann Lee', $this->page());

        $result = $this->send("dean/approvals/{$id}/approve");
        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertSame('awaiting_dean', $this->requestStatus($id));
        $this->assertSame(0, (int) $this->grade()['is_override']);
    }

    public function testADeanWhoAlsoTeachesCannotUseItWhileActingAsAnInstructor(): void
    {
        $id = $this->waiting();

        $person = PeopleFactory::person('instructor', 'Dora', 'Dean', null, $this->institution);
        (new \App\Services\Institutions\DeanAssignmentService())->assign($this->institution, $this->department, (int) $person->id);
        $dora = model(CodeIgniter\Shield\Models\UserModel::class)->findById($person->id);
        $this->loggedInAs($dora);

        $this->assertSame(200, $this->call('get', 'dean/approvals')->response()->getStatusCode(), 'acting as dean (the default)');

        $this->send('role/switch', ['role' => 'instructor']);

        $this->assertSame(302, $this->call('get', 'dean/approvals')->response()->getStatusCode(), 'as an instructor');
        $this->assertSame(302, $this->call('post', "dean/approvals/{$id}/approve")->response()->getStatusCode());
        $this->assertSame('awaiting_dean', $this->requestStatus($id));
    }

    public function testADeanWithoutADepartmentSeesTheExplanation(): void
    {
        $this->loggedInAs(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution));

        $response = $this->call('GET', '/dean/approvals')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not assigned to a department yet', $response->getBody());
    }

    public function testAReadOnlyInstitutionDecidesNothing(): void
    {
        $id = $this->waiting();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->loggedInAs($this->dean);

        $this->assertSame(200, $this->call('get', 'dean/approvals')->response()->getStatusCode(), 'looking is fine');
        $this->assertSame(403, $this->send("dean/approvals/{$id}/approve")->response()->getStatusCode());
        $this->assertSame(403, $this->send("dean/approvals/{$id}/reject", ['reason' => 'No'])->response()->getStatusCode());
        $this->assertSame('awaiting_dean', $this->requestStatus($id));
    }

    public function testTheAppsMenuHasAnApprovalsTile(): void
    {
        $this->loggedInAs($this->dean);

        $this->assertStringContainsString('dean/approvals', html_entity_decode($this->call('get', 'dean/dashboard')->response()->getBody()));
    }
}
