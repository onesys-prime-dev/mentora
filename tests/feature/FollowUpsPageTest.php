<?php

declare(strict_types=1);

use App\Services\Courses\FollowUpService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The follow-up pages of the dean, the chairperson and the instructor: each sees the right box, a new
 * request starts from a course (or from an attention item that links with it chosen), the actions answer
 * the dialogs, nothing leaks across departments or roles, and a read-only institution only lets people look.
 *
 * @internal
 */
final class FollowUpsPageTest extends RouteTestCase
{
    private int $institution;
    private int $department;
    private int $sub;
    private int $offering;
    private User $dean;
    private User $chair;
    private User $teacher;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('F');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'F');
        $program           = DeanFactory::program($this->sub, 'F');

        $this->dean    = DeanFactory::dean($this->institution, $this->department);
        $this->chair   = DeanFactory::chair($this->sub, 'Maria');
        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);

        $this->offering = DeanFactory::offering($program, DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');
        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);
    }

    private function page(User $as, string $uri): string
    {
        $this->loggedInAs($as);
        $response = $this->call('get', $uri)->response();

        $this->assertSame(200, $response->getStatusCode(), $uri);

        return html_entity_decode($response->getBody());
    }

    /** @param array<string, mixed> $data */
    private function send(User $as, string $uri, array $data = []): \CodeIgniter\Test\TestResponse
    {
        $this->loggedInAs($as);

        return $this->withHeaders(['Accept' => 'application/json'])->call('post', $uri, $data);
    }

    private function ask(User $to, array $over = []): int
    {
        $result = (new FollowUpService())->send($this->dean, $over + ['offering_id' => $this->offering, 'recipient_id' => $to->id, 'title' => 'Grading is late', 'message' => 'Please grade the quiz.']);

        $this->assertTrue($result['success'], $result['message']);

        return $result['id'];
    }

    public function testTheDeanSeesTheRequestsTheySentAndTheirStanding(): void
    {
        $this->ask($this->teacher);

        $body = $this->page($this->dean, 'dean/follow-ups');

        $this->assertStringContainsString('Grading is late', $body);
        $this->assertStringContainsString('Tess Teacher', $body);
        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('New follow-up', $body);
        $this->assertStringContainsString('Open', $body);
        $this->assertStringNotContainsString('Sent to you', $body, 'a dean has only the one box');
    }

    public function testTheTabsFilterByStandingAndSayWhenNothingIsThere(): void
    {
        $id = $this->ask($this->teacher);

        $this->assertStringContainsString('Nothing is overdue', $this->page($this->dean, 'dean/follow-ups?tab=overdue'));
        $this->assertStringContainsString('Nothing is done yet', $this->page($this->dean, 'dean/follow-ups?tab=done'));

        (new FollowUpService())->markDone($this->teacher, $id);

        $this->assertStringContainsString('Grading is late', $this->page($this->dean, 'dean/follow-ups?tab=done'));
        $this->assertStringContainsString('No open follow-ups', $this->page($this->dean, 'dean/follow-ups'));
    }

    public function testTheChairpersonHasABoxFromTheDeanWithPassOnAndABoxOfTheirOwn(): void
    {
        $this->ask($this->chair);

        $received = $this->page($this->chair, 'chairperson/follow-ups');

        $this->assertStringContainsString('From the dean', $received);
        $this->assertStringContainsString('Grading is late', $received);
        $this->assertStringContainsString('Pass on to an instructor', $received);
        $this->assertStringContainsString('Tess Teacher', $received);

        $sent = $this->page($this->chair, 'chairperson/follow-ups?box=sent');

        $this->assertStringContainsString('No open follow-ups', $sent);
        $this->assertStringNotContainsString('Pass on to an instructor', $sent);
    }

    public function testTheInstructorSeesTheirRequestsUnderThatNameAndCannotSend(): void
    {
        $this->ask($this->teacher);

        $body = $this->page($this->teacher, 'instructor/follow-ups');

        $this->assertStringContainsString('Requests', $body);
        $this->assertStringContainsString('Grading is late', $body);
        $this->assertStringNotContainsString('New follow-up', $body);
        $this->assertStringNotContainsString('Pass on to an instructor', $body);

        $this->loggedInAs($this->teacher);
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->withHeaders(['Accept' => 'application/json'])->call('post', 'instructor/follow-ups', ['offering_id' => $this->offering]);
    }

    public function testALinkFromAnAttentionItemOpensTheFormForThatCourseAndStudent(): void
    {
        $body = $this->page($this->dean, 'dean/follow-ups?' . http_build_query(['offering' => $this->offering, 'student' => $this->student->id, 'reason' => 'at_risk']));

        $this->assertStringContainsString('data-dialog-autoopen', $body);
        $this->assertStringContainsString('value="At-risk student: Stu Dent, IT101 A"', $body);
        $this->assertStringContainsString('Maria Chair (chairperson)', $body);
        $this->assertStringContainsString('Tess Teacher (instructor)', $body);
        $this->assertStringContainsString('name="student_id" value="' . $this->student->id . '"', $body);
    }

    public function testACourseOutsideTheDepartmentOpensNoFormAndAStrangerStudentIsDropped(): void
    {
        $elsewhere = DeanFactory::department('E', $this->institution);
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'E'), 'E'), DeanFactory::term($this->institution, 'Other'), 'LAW900', 'A', 'active', 'Foreign Law');

        $this->assertStringNotContainsString('data-dialog-autoopen', $this->page($this->dean, 'dean/follow-ups?offering=' . $foreign));
        $this->assertStringNotContainsString('LAW900', $this->page($this->dean, 'dean/follow-ups?offering=' . $foreign), 'it is not even offered in the picker');

        $stranger = PeopleFactory::person('student', 'Not', 'Enrolled', null, $this->institution);
        $body     = $this->page($this->dean, 'dean/follow-ups?' . http_build_query(['offering' => $this->offering, 'student' => $stranger->id, 'reason' => 'at_risk']));

        $this->assertStringContainsString('name="student_id" value="0"', $body);
    }

    public function testSendingAnswersTheDialogAndStoresTheRequest(): void
    {
        $response = $this->send($this->dean, 'dean/follow-ups', [
            'offering_id' => $this->offering, 'recipient_id' => $this->teacher->id, 'reason' => 'overdue_grading',
            'title' => 'Grading is late', 'message' => 'Please grade the quiz.',
        ])->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Follow-up sent to Tess Teacher', $response->getBody());
        $this->assertSame(1, db_connect()->table('follow_ups')->countAllResults());
    }

    public function testASendThatBreaksARuleIsRefusedWithTheReasonInTheField(): void
    {
        $data = ['offering_id' => $this->offering, 'recipient_id' => $this->teacher->id, 'title' => 'Late', 'message' => 'Please.'];

        $this->assertSame(200, $this->send($this->dean, 'dean/follow-ups', $data)->response()->getStatusCode());

        $again = $this->send($this->dean, 'dean/follow-ups', $data)->response();

        $this->assertSame(422, $again->getStatusCode());
        $this->assertStringContainsString('already have an open request', $again->getBody());

        $outsider = PeopleFactory::person('instructor', 'Out', 'Sider', null, $this->institution);

        $this->assertSame(422, $this->send($this->dean, 'dean/follow-ups', ['recipient_id' => $outsider->id] + $data)->response()->getStatusCode());
        $this->assertSame(1, db_connect()->table('follow_ups')->countAllResults());
    }

    public function testRepliesAndDoneComeFromTheTwoSidesOnlyAndAThirdPersonIsRefused(): void
    {
        $id = $this->ask($this->teacher);

        $this->assertSame(200, $this->send($this->teacher, "instructor/follow-ups/{$id}/reply", ['body' => 'On it.'])->response()->getStatusCode());
        $this->assertSame(422, $this->send($this->chair, "chairperson/follow-ups/{$id}/reply", ['body' => 'Me too.'])->response()->getStatusCode());
        $this->assertSame(422, $this->send($this->chair, "chairperson/follow-ups/{$id}/done")->response()->getStatusCode());
        $this->assertSame('open', (new FollowUpService())->find($id)['status']);

        $this->assertSame(200, $this->send($this->teacher, "instructor/follow-ups/{$id}/done")->response()->getStatusCode());
        $this->assertSame('done', (new FollowUpService())->find($id)['status']);
        $this->assertStringContainsString('On it.', $this->page($this->dean, 'dean/follow-ups?tab=done'));
    }

    public function testAChairpersonPassesADeansRequestOnAndTheDeanSeesWhereItWent(): void
    {
        $id = $this->ask($this->chair);

        $response = $this->send($this->chair, "chairperson/follow-ups/{$id}/pass-down", ['instructor_id' => $this->teacher->id, 'note' => 'Please look.'])->response();

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertStringContainsString('Passed to Tess Teacher', $this->page($this->dean, 'dean/follow-ups'));
        $this->assertStringContainsString('Please look.', $this->page($this->teacher, 'instructor/follow-ups'));
        $this->assertSame(422, $this->send($this->chair, "chairperson/follow-ups/{$id}/pass-down", ['instructor_id' => $this->teacher->id])->response()->getStatusCode(), 'not twice');
    }

    public function testNobodyElseReachesThePages(): void
    {
        foreach ([
            [PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution), 'dean/follow-ups'],
            [$this->teacher, 'dean/follow-ups'],
            [$this->chair, 'dean/follow-ups'],
            [$this->dean, 'chairperson/follow-ups'],
            [$this->dean, 'instructor/follow-ups'],
        ] as [$user, $uri]) {
            $this->loggedInAs($user);
            $this->assertNotSame(200, $this->call('get', $uri)->response()->getStatusCode(), $uri);
        }
    }

    public function testADeanWithoutADepartmentSeesTheExplanation(): void
    {
        $body = $this->page(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution), 'dean/follow-ups');

        $this->assertStringContainsString('not assigned to a department yet', $body);
    }

    public function testAReadOnlyInstitutionLetsPeopleLookButNotSendReplyOrClose(): void
    {
        $id = $this->ask($this->teacher);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertStringContainsString('Grading is late', $this->page($this->dean, 'dean/follow-ups'));
        $this->assertSame(403, $this->send($this->dean, 'dean/follow-ups', ['offering_id' => $this->offering, 'recipient_id' => $this->chair->id, 'title' => 'x', 'message' => 'y'])->response()->getStatusCode());
        $this->assertSame(403, $this->send($this->teacher, "instructor/follow-ups/{$id}/reply", ['body' => 'hello'])->response()->getStatusCode());
        $this->assertSame(403, $this->send($this->teacher, "instructor/follow-ups/{$id}/done")->response()->getStatusCode());
        $this->assertSame('open', (new FollowUpService())->find($id)['status']);
    }

    public function testTheAppsMenuHasTheTileForEachRole(): void
    {
        $this->assertStringContainsString('dean/follow-ups', html_entity_decode($this->page($this->dean, 'dean/dashboard')));
        $this->assertStringContainsString('chairperson/follow-ups', html_entity_decode($this->page($this->chair, 'chairperson/dashboard')));
        $this->assertStringContainsString('instructor/follow-ups', html_entity_decode($this->page($this->teacher, 'instructor/dashboard')));
    }

    public function testTheSignOffAndMonitorPagesLinkAnAttentionItemToANewFollowUp(): void
    {
        $this->assertStringContainsString('data-follow-up-link', $this->page($this->dean, 'dean/signoff'));
    }
}
