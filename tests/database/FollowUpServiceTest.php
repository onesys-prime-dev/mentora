<?php

declare(strict_types=1);

use App\Services\Courses\FollowUpService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Follow-up requests: who may ask whom (a dean the chairpersons and instructors of the
 * department, a chairperson the instructors of their sub-departments), no second open request
 * for the same subject, the reply thread, closing, passing a dean's request down, what counts
 * as overdue, and the notifications and audit entries each step leaves.
 *
 * @internal
 */
final class FollowUpServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FollowUpService $service;
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

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home              = DeanFactory::department('F');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'F');
        $program           = DeanFactory::program($this->sub, 'F');
        $term              = DeanFactory::term($this->institution);

        $this->dean    = DeanFactory::dean($this->institution, $this->department);
        $this->chair   = DeanFactory::chair($this->sub, 'Maria');
        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);

        $this->offering = DeanFactory::offering($program, $term, 'IT101', 'A', 'active', 'Intro to Computing');
        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $this->service = new FollowUpService();
    }

    /** @return array<string, mixed> */
    private function input(User $to, array $over = []): array
    {
        return $over + ['offering_id' => $this->offering, 'recipient_id' => $to->id, 'reason' => 'overdue_grading', 'title' => 'Grading is late', 'message' => 'Please grade the quiz.'];
    }

    private function notified(User $user): int
    {
        return db_connect()->table('notifications')->where(['user_id' => $user->id, 'type' => 'follow_up'])->countAllResults();
    }

    public function testADeanMayAskTheChairpersonAndTheInstructorOfTheirSectionButNotThemselves(): void
    {
        $names = array_column($this->service->recipientsFor($this->dean, $this->offering), 'role', 'name');

        $this->assertSame(['Maria Chair' => 'chairperson', 'Tess Teacher' => 'instructor'], $names);
    }

    public function testAChairpersonMayAskOnlyTheInstructorsOfTheirSubDepartments(): void
    {
        $this->assertSame(['Tess Teacher'], array_column($this->service->recipientsFor($this->chair, $this->offering), 'name'));

        $other = DeanFactory::chair(DeanFactory::subDepartment($this->department, 'Other'), 'Olga');

        $this->assertSame([], $this->service->recipientsFor($other, $this->offering), 'a section of another sub-department is out of reach');
    }

    public function testNobodyOutsideTheDepartmentIsReachedAndNeitherIsAnInstructorOrAStudentASender(): void
    {
        $elsewhere = DeanFactory::department('E', $this->institution);
        $dean      = DeanFactory::dean($this->institution, $elsewhere['department']);

        $this->assertSame([], $this->service->recipientsFor($dean, $this->offering));
        $this->assertFalse($this->service->send($dean, $this->input($this->teacher))['success']);
        $this->assertFalse($this->service->send($this->teacher, $this->input($this->chair))['success'], 'an instructor sends nothing');
        $this->assertFalse($this->service->send($this->chair, $this->input($this->dean))['success'], 'nobody asks upward');
        $this->assertSame(0, db_connect()->table('follow_ups')->countAllResults());
    }

    public function testSendingStoresTheRequestTellsTheRecipientAndAuditsItAsTheDean(): void
    {
        $result = $this->service->send($this->dean, $this->input($this->teacher, ['due_date' => date('Y-m-d', strtotime('+3 days'))]));

        $this->assertTrue($result['success'], $result['message']);

        $row = db_connect()->table('follow_ups')->where('id', $result['id'])->get()->getRowArray();

        $this->assertSame('open', $row['status']);
        $this->assertSame('dean', $row['sender_role']);
        $this->assertSame('instructor', $row['recipient_role']);
        $this->assertSame('offering', $row['subject_type']);
        $this->assertSame(1, (int) $row['open_marker']);
        $this->assertSame(1, $this->notified($this->teacher));
        $this->assertSame(0, $this->notified($this->dean));

        $audit = db_connect()->table('audit_logs')->where('action', 'followup.sent')->get()->getRowArray();

        $this->assertSame('dean', $audit['acting_role']);
        $this->assertSame((string) $result['id'], $audit['target_id']);
    }

    public function testARequestAboutAStudentMustBeAboutSomeoneInTheCourse(): void
    {
        $this->assertTrue($this->service->send($this->dean, $this->input($this->teacher, ['student_id' => $this->student->id, 'reason' => 'at_risk']))['success']);
        $this->assertSame('student', db_connect()->table('follow_ups')->get()->getRowArray()['subject_type']);

        $stranger = PeopleFactory::person('student', 'Not', 'Enrolled', null, $this->institution);

        $this->assertFalse($this->service->send($this->dean, $this->input($this->chair, ['student_id' => $stranger->id]))['success']);
    }

    public function testTheSameSubjectCannotBeAskedTwiceWhileOneIsOpen(): void
    {
        $first = $this->service->send($this->dean, $this->input($this->teacher));
        $again = $this->service->send($this->dean, $this->input($this->teacher, ['title' => 'Reworded']));

        $this->assertTrue($first['success']);
        $this->assertFalse($again['success']);
        $this->assertStringContainsString('already have an open request', $again['message']);
        $this->assertTrue($this->service->send($this->dean, $this->input($this->chair))['success'], 'another person is another request');
        $this->assertTrue($this->service->send($this->dean, $this->input($this->teacher, ['student_id' => $this->student->id]))['success'], 'another subject is another request');

        $this->service->markDone($this->teacher, $first['id']);

        $this->assertTrue($this->service->send($this->dean, $this->input($this->teacher))['success'], 'once done, it can be asked again');
        $this->assertSame(4, db_connect()->table('follow_ups')->countAllResults(), 'the done one stays as history');
    }

    public function testTheDatabaseAloneRefusesASecondOpenRequestForTheSameSubject(): void
    {
        $this->service->send($this->dean, $this->input($this->teacher));
        $row = db_connect()->table('follow_ups')->get()->getRowArray();
        unset($row['id']);

        try {
            db_connect()->table('follow_ups')->insert($row);
        } catch (\Throwable) {
            // Depending on the connection's settings the driver throws or returns false.
        }

        $this->assertSame(1, db_connect()->table('follow_ups')->countAllResults(), 'the unique key kept the second one out');
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function badInputs(): iterable
    {
        yield 'no subject' => [['title' => ' ']];
        yield 'subject too long' => [['title' => str_repeat('a', 201)]];
        yield 'no message' => [['message' => '']];
        yield 'due date in the past' => [['due_date' => '2020-01-01']];
        yield 'due date not a date' => [['due_date' => 'soon']];
        yield 'a person who is not responsible' => [['recipient_id' => 999999]];
    }

    /** @param array<string, mixed> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function testBadInputIsRefusedAndStoresNothing(array $over): void
    {
        $this->assertFalse($this->service->send($this->dean, $this->input($this->teacher, $over))['success']);
        $this->assertSame(0, db_connect()->table('follow_ups')->countAllResults());
    }

    public function testOnlyTheTwoSidesReplyAndEachIsToldOfTheOthers(): void
    {
        $id = $this->service->send($this->dean, $this->input($this->teacher))['id'];

        $this->assertTrue($this->service->reply($this->teacher, $id, 'On it, done by Friday.')['success']);
        $this->assertSame(1, $this->notified($this->dean), 'the sender hears the reply');

        $this->assertTrue($this->service->reply($this->dean, $id, 'Thank you.')['success']);
        $this->assertSame(2, $this->notified($this->teacher), 'the new request and the reply');

        $this->assertFalse($this->service->reply($this->chair, $id, 'Me too')['success'], 'a third person is not in the thread');
        $this->assertFalse($this->service->reply($this->teacher, $id, '  ')['success']);
        $this->assertSame(2, db_connect()->table('follow_up_replies')->countAllResults());
    }

    public function testClosingIsByTheTwoSidesOnlyOnceAndIsAuditedAndTold(): void
    {
        $id = $this->service->send($this->dean, $this->input($this->teacher))['id'];

        $this->assertFalse($this->service->markDone($this->chair, $id)['success']);
        $this->assertTrue($this->service->markDone($this->teacher, $id)['success']);
        $this->assertFalse($this->service->markDone($this->teacher, $id)['success'], 'already done');

        $row = $this->service->find($id);

        $this->assertSame('done', $row['status']);
        $this->assertNull($row['open_marker']);
        $this->assertSame((int) $this->teacher->id, (int) $row['done_by']);
        $this->assertSame(1, $this->notified($this->dean));
        $this->assertSame('instructor', db_connect()->table('audit_logs')->where('action', 'followup.closed')->get()->getRowArray()['acting_role']);
    }

    public function testAChairpersonPassesADeansRequestDownAndTheDeanStillSeesIt(): void
    {
        $id   = $this->service->send($this->dean, $this->input($this->chair))['id'];
        $pass = $this->service->passDown($this->chair, $id, (int) $this->teacher->id, 'Please look at this first.');

        $this->assertTrue($pass['success'], $pass['message']);

        $child = $this->service->find($pass['id']);

        $this->assertSame($id, (int) $child['parent_id']);
        $this->assertSame('chairperson', $child['sender_role']);
        $this->assertSame((int) $this->teacher->id, (int) $child['recipient_id']);
        $this->assertStringContainsString('Please look at this first.', $child['message']);
        $this->assertSame('open', $this->service->find($id)['status'], 'the dean\'s stays open until the instructor is done');
        $this->assertSame(1, $this->notified($this->teacher));
        $this->assertSame(1, $this->notified($this->dean), 'the dean is told it was passed on');

        $sent = $this->service->listFor($this->dean, 'sent')['rows'][0];

        $this->assertSame('Tess Teacher', $sent['passed_to']['name']);
        $this->assertSame('open', $sent['passed_to']['status']);

        $this->service->markDone($this->teacher, $pass['id']);

        $this->assertSame('done', $this->service->find($id)['status'], 'the instructor finishing closes the dean\'s request too');
        $this->assertSame('done', $this->service->listFor($this->dean, 'sent', 'done')['rows'][0]['passed_to']['status']);
    }

    public function testOnlyADeansRequestToTheChairpersonCanBePassedAndOnlyToAnInstructorOfTheCourse(): void
    {
        $toTeacher = $this->service->send($this->dean, $this->input($this->teacher))['id'];
        $toChair   = $this->service->send($this->dean, $this->input($this->chair, ['title' => 'Another']))['id'];
        $stranger  = PeopleFactory::person('instructor', 'Not', 'Teaching', null, $this->institution);

        $this->assertFalse($this->service->passDown($this->chair, $toTeacher, (int) $this->teacher->id)['success'], 'it was not addressed to the chairperson');
        $this->assertFalse($this->service->passDown($this->chair, $toChair, (int) $stranger->id)['success'], 'not an instructor of that course');
        $this->assertFalse($this->service->passDown($this->teacher, $toChair, (int) $this->teacher->id)['success'], 'not the chairperson');
        $this->assertTrue($this->service->passDown($this->chair, $toChair, (int) $this->teacher->id)['success']);
        $this->assertFalse($this->service->passDown($this->chair, $toChair, (int) $this->teacher->id)['success'], 'not twice while the first is open');
    }

    public function testARequestIsOverdueAfterItsDueDateOrAWeekWithoutOne(): void
    {
        $late   = $this->service->send($this->dean, $this->input($this->teacher, ['due_date' => date('Y-m-d')]))['id'];
        $noDate = $this->service->send($this->dean, $this->input($this->chair))['id'];

        $this->assertSame(0, $this->service->listFor($this->dean, 'sent', 'open')['counts']['overdue']);

        $in8Days = date('Y-m-d', strtotime('+8 days'));
        $counts  = $this->service->listFor($this->dean, 'sent', 'open', $in8Days)['counts'];

        $this->assertSame(['open' => 2, 'overdue' => 2, 'done' => 0], $counts);
        $this->assertSame([$noDate, $late], array_map('intval', array_column($this->service->listFor($this->dean, 'sent', 'overdue', $in8Days)['rows'], 'id')));

        $in3Days = date('Y-m-d', strtotime('+3 days'));

        $this->assertSame([$late], array_map('intval', array_column($this->service->listFor($this->dean, 'sent', 'overdue', $in3Days)['rows'], 'id')), 'the one with no date is not late after 3 days');

        $this->service->markDone($this->teacher, $late);

        $this->assertSame(['open' => 1, 'overdue' => 1, 'done' => 1], $this->service->listFor($this->dean, 'sent', 'open', $in8Days)['counts'], 'a done request is never overdue');
    }

    public function testEachPersonSeesOnlyTheirOwnRequestsInTheirBox(): void
    {
        $this->service->send($this->dean, $this->input($this->teacher));
        $this->service->send($this->dean, $this->input($this->chair));

        $this->assertCount(1, $this->service->listFor($this->teacher, 'received')['rows']);
        $this->assertCount(0, $this->service->listFor($this->teacher, 'sent')['rows']);
        $this->assertCount(2, $this->service->listFor($this->dean, 'sent')['rows']);
        $this->assertCount(0, $this->service->listFor($this->dean, 'received')['rows']);
        $this->assertSame(['received' => 1, 'sent_open' => 0, 'sent_overdue' => 0], $this->service->openCounts($this->chair));
    }

    public function testTheCoursePickerListsTheSectionsOfTheSendersReachOnly(): void
    {
        $elsewhere = DeanFactory::department('E', $this->institution);
        DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'E'), 'E'), DeanFactory::term($this->institution, 'Other'), 'LAW900');

        foreach ([$this->dean, $this->chair] as $sender) {
            $this->assertSame([$this->offering], array_column($this->service->offeringsFor($sender), 'id'));
        }

        $this->assertSame([], $this->service->offeringsFor($this->teacher));
    }
}
