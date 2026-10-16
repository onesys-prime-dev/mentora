<?php

declare(strict_types=1);

use App\Models\Assessments\GradeOverrideRequestModel;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\OverrideRequestChain as Chain;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * Grade-override requests as far as the dean: who may ask, who is next, what a
 * chairperson may do, that the grade stays as calculated, and that every step is
 * audited and tells the person concerned.
 *
 * @internal
 */
final class GradeOverrideRequestServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradeOverrideRequestService $service;
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
        session()->destroy();

        $home              = DeanFactory::department('G');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'G');
        $program           = DeanFactory::program($this->sub, 'G');
        $this->offering    = DeanFactory::offering($program, DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');

        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->chair   = DeanFactory::chair($this->sub, 'Maria');
        $this->dean    = DeanFactory::dean($this->institution, $this->department);
        $this->student = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $this->scheme = GradeFactory::scheme($this->offering);
        $this->record($this->student, 88.0);

        $this->service = new GradeOverrideRequestService();
    }

    private function record(User $student, float $calculated, bool $override = false, ?float $overridden = null): void
    {
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id'    => $this->offering,
            'student_id'            => $student->id,
            'grading_scheme_id'     => $this->scheme,
            'calculated_percentage' => $calculated,
            'is_override'           => $override ? 1 : 0,
            'overridden_percentage' => $overridden,
        ]);
    }

    private function row(int $id): array
    {
        return (new GradeOverrideRequestModel())->find($id);
    }

    private function ask(?float $percentage = 92.0, string $reason = 'Late quiz excused (medical).', ?User $as = null, ?User $student = null): array
    {
        return $this->service->request($as ?? $this->teacher, $this->offering, (int) ($student ?? $this->student)->id, $percentage, $reason);
    }

    private function actions(): array
    {
        return array_column(db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray(), 'action');
    }

    private function notificationsFor(User $user): array
    {
        return db_connect()->table('notifications')->where('user_id', $user->id)->get()->getResultArray();
    }

    public function testRequestsApplyOnlyWhereTheInstitutionHasADean(): void
    {
        $this->assertTrue($this->service->appliesTo($this->offering));

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertFalse($this->service->appliesTo($this->offering), 'no dean in the institution: overrides stay direct');
        $this->assertFalse($this->service->appliesTo(999999));
    }

    public function testADepartmentWithNoDeanInAnInstitutionThatHasOneStillUsesRequests(): void
    {
        $other   = DeanFactory::department('H', $this->institution);
        $program = DeanFactory::program(DeanFactory::subDepartment($other['department'], 'H'), 'H');
        $offer   = DeanFactory::offering($program, DeanFactory::term($this->institution, 'Second', '2027-01-10'), 'LW100');

        $this->assertTrue($this->service->appliesTo($offer));
    }

    public function testARequestLeavesTheGradeAsCalculatedAndWaitsForTheChairperson(): void
    {
        $result = $this->ask();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(Chain::AWAITING_CHAIR, $result['request']['status']);
        $this->assertSame(92.0, $result['request']['requested_percentage']);

        $record = db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->get()->getRowArray();
        $this->assertSame(0, (int) $record['is_override'], 'the grade is not changed by asking');
        $this->assertNull($record['overridden_percentage']);

        $row = $this->row($result['request']['id']);
        $this->assertSame(1, (int) $row['open_marker']);
        $this->assertSame('instructor', $row['requester_role']);
        $this->assertSame(88.0, (float) $row['calculated_percentage']);
        $this->assertSame(88.0, (float) $row['previous_percentage']);
        $this->assertSame('Late quiz excused (medical).', $row['reason']);

        $this->assertContains('grade.override_requested', $this->actions());
        $told = $this->notificationsFor($this->chair);
        $this->assertCount(1, $told);
        $this->assertStringContainsString('IT101 A', $told[0]['body']);
        $this->assertSame(0, count($this->notificationsFor($this->dean)), 'the dean is told once it reaches the dean (a later step)');
    }

    public function testWhereTheRequestStartsFollowsTheChain(): void
    {
        // No chairperson: straight to the dean.
        db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $this->chair->id)->update(['status' => 'revoked']);
        $this->assertSame(Chain::AWAITING_DEAN, $this->ask()['request']['status']);
    }

    public function testAChairpersonsOwnRequestGoesToTheDeanAndADeanlessDepartmentToTheAdministrator(): void
    {
        $asChair = $this->service->request($this->chair, $this->offering, (int) $this->student->id, 91.0, 'Appeal upheld');
        $this->assertSame(Chain::AWAITING_DEAN, $asChair['request']['status']);
        $this->assertSame('chairperson', $this->row($asChair['request']['id'])['requester_role']);

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);
        DeanFactory::dean($this->institution, DeanFactory::department('Z', $this->institution)['department'], 'Zed'); // another department has a dean
        $this->service->withdraw($this->chair, $this->offering, $asChair['request']['id']);

        $this->assertSame(Chain::AWAITING_INSTITUTION, $this->service->request($this->chair, $this->offering, (int) $this->student->id, 91.0, 'Appeal upheld')['request']['status']);
    }

    public function testWhatMakesARequestRefused(): void
    {
        $this->assertFalse($this->ask(101.0)['success'], 'above 100');
        $this->assertFalse($this->ask(-1.0)['success'], 'below 0');
        $this->assertFalse($this->ask(92.0, '   ')['success'], 'a reason is required');
        $this->assertStringContainsString('no override to remove', $this->ask(null, 'Undo it')['message']);

        $stranger = PeopleFactory::person('instructor', 'Not', 'Mine', null, $this->institution);
        $this->assertStringContainsString('cannot request', $this->ask(92.0, 'Because', $stranger)['message'], 'an instructor of another section');
        $this->assertStringContainsString('cannot request', $this->ask(92.0, 'Because', $this->student)['message'], 'a student');

        $nobody = PeopleFactory::person('student', 'Not', 'Enrolled', null, $this->institution);
        $this->assertStringContainsString('not enrolled', $this->ask(92.0, 'Because', null, $nobody)['message']);

        $this->assertSame(0, (new GradeOverrideRequestModel())->countAllResults(), 'nothing was saved by any refusal');
    }

    public function testASecondOpenRequestForTheSameStudentIsRefusedButAClosedOneDoesNotBlock(): void
    {
        $first = $this->ask();
        $this->assertTrue($first['success']);

        $second = $this->ask(95.0, 'Another try');
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('already has an open request', $second['message']);

        $this->service->withdraw($this->teacher, $this->offering, $first['request']['id']);

        $this->assertTrue($this->ask(95.0, 'Another try')['success'], 'a withdrawn request frees the student');
    }

    public function testTheDatabaseAllowsOnlyOneOpenRequestPerStudentAndOffering(): void
    {
        $model = new GradeOverrideRequestModel();
        $base  = ['offering_id' => $this->offering, 'student_id' => $this->student->id, 'reason' => 'x', 'requested_by' => $this->teacher->id, 'requester_role' => 'instructor', 'status' => 'awaiting_chair'];

        $model->insert($base + ['open_marker' => 1]);
        $model->insert(['status' => 'approved', 'open_marker' => null] + $base);
        $model->insert(['status' => 'withdrawn', 'open_marker' => null] + $base);

        $this->assertSame(3, $model->countAllResults(), 'closed requests (no marker) can be many');
        $this->assertFalse($this->tryInsert($model, $base + ['open_marker' => 1]), 'a second open one is rejected by the unique key');
    }

    /** @param array<string, mixed> $row */
    private function tryInsert(GradeOverrideRequestModel $model, array $row): bool
    {
        try {
            return $model->insert($row) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function testRemovingAnExistingOverrideCanBeRequestedAndNeedsAReason(): void
    {
        db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->update(['is_override' => 1, 'overridden_percentage' => 95.0]);

        $this->assertFalse($this->ask(null, '')['success']);
        $result = $this->ask(null, 'The appeal was withdrawn');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertNull($result['request']['requested_percentage'], 'null means remove the override');
        $this->assertSame(95.0, (float) $this->row($result['request']['id'])['previous_percentage']);
        $this->assertSame(1, (int) db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->get()->getRowArray()['is_override'], 'still in force until decided');
    }

    public function testTheChairpersonEndorsesAndItMovesOnToTheDean(): void
    {
        $id = $this->ask()['request']['id'];

        $result = $this->service->endorse($this->chair, $id, 'Looks right to me.');

        $this->assertTrue($result['success'], $result['message']);
        $row = $this->row($id);
        $this->assertSame(Chain::AWAITING_DEAN, $row['status']);
        $this->assertSame((int) $this->chair->id, (int) $row['endorsed_by']);
        $this->assertSame('Looks right to me.', $row['endorse_comment']);
        $this->assertNotNull($row['endorsed_at']);
        $this->assertSame(1, (int) $row['open_marker'], 'still open');

        $this->assertContains('grade.override_endorsed', $this->actions());
        $told = $this->notificationsFor($this->teacher);
        $this->assertCount(1, $told);
        $this->assertStringContainsString('endorsed', strtolower($told[0]['title']));
        $this->assertStringContainsString('the dean', $told[0]['body']);
    }

    public function testWhoMayEndorseOrSendBack(): void
    {
        $id = $this->ask()['request']['id'];

        $elsewhere = DeanFactory::chair(DeanFactory::subDepartment($this->department, 'Other'), 'Olga');
        $notChair  = PeopleFactory::person('instructor', 'Not', 'Chair', null, $this->institution);

        foreach ([[$elsewhere, 'a chairperson of another sub-department'], [$notChair, 'an instructor'], [$this->dean, 'the dean at this step'], [$this->teacher, 'the requester']] as [$who, $label]) {
            $this->assertFalse($this->service->endorse($who, $id, '')['success'], $label);
            $this->assertFalse($this->service->sendBack($who, $id, 'No')['success'], $label);
        }

        $this->assertSame(Chain::AWAITING_CHAIR, $this->row($id)['status'], 'nothing moved');
        $this->assertFalse($this->service->endorse($this->chair, 999999, '')['success']);
    }

    public function testAChairpersonCannotActOnTheirOwnRequestOrOneThatIsNoLongerWaiting(): void
    {
        $own = $this->service->request($this->chair, $this->offering, (int) $this->student->id, 91.0, 'Appeal upheld')['request']['id'];
        $this->assertFalse($this->service->endorse($this->chair, $own, '')['success'], 'their own request is at the dean, not at them');

        $this->service->withdraw($this->chair, $this->offering, $own);
        $id = $this->ask()['request']['id'];
        $this->service->endorse($this->chair, $id, '');

        $this->assertFalse($this->service->endorse($this->chair, $id, '')['success'], 'already endorsed');
        $this->assertFalse($this->service->sendBack($this->chair, $id, 'Too late')['success']);
    }

    public function testSendingBackNeedsACommentAndReturnsItToTheRequester(): void
    {
        $id = $this->ask()['request']['id'];

        $this->assertFalse($this->service->sendBack($this->chair, $id, '  ')['success'], 'a comment is required');
        $this->assertSame(Chain::AWAITING_CHAIR, $this->row($id)['status']);

        $this->assertTrue($this->service->sendBack($this->chair, $id, 'Attach the medical note.')['success']);

        $row = $this->row($id);
        $this->assertSame(Chain::RETURNED, $row['status']);
        $this->assertSame('Attach the medical note.', $row['return_comment']);
        $this->assertSame(1, (int) $row['open_marker'], 'still the one open request');
        $this->assertContains('grade.override_returned', $this->actions());
        $this->assertStringContainsString('Attach the medical note.', $this->notificationsFor($this->teacher)[0]['body']);

        $presented = $this->service->openByStudent($this->offering)[(int) $this->student->id];
        $this->assertTrue($presented['returned']);
        $this->assertSame('Attach the medical note.', $presented['return_comment']);
    }

    public function testTheRequesterEditsAReturnedRequestAndItStartsTheChainAgain(): void
    {
        $id = $this->ask()['request']['id'];
        $this->service->sendBack($this->chair, $id, 'Attach the medical note.');

        $this->assertFalse($this->service->resubmit($this->chair, $this->offering, $id, 93.0, 'x')['success'], "not the requester's");
        $this->assertFalse($this->service->resubmit($this->teacher, $this->offering, $id, 193.0, 'x')['success'], 'out of range');
        $this->assertFalse($this->service->resubmit($this->teacher, $this->offering + 1, $id, 93.0, 'x')['success'], 'another offering in the address');

        $result = $this->service->resubmit($this->teacher, $this->offering, $id, 93.0, 'Medical note attached.');

        $this->assertTrue($result['success'], $result['message']);
        $row = $this->row($id);
        $this->assertSame(Chain::AWAITING_CHAIR, $row['status']);
        $this->assertSame(93.0, (float) $row['requested_percentage']);
        $this->assertSame('Medical note attached.', $row['reason']);
        $this->assertNull($row['return_comment'], 'the old comment is cleared once it is answered');
        $this->assertContains('grade.override_resubmitted', $this->actions());

        $this->assertFalse($this->service->resubmit($this->teacher, $this->offering, $id, 94.0, 'Again')['success'], 'only a returned request can be edited');
    }

    public function testTheRequesterWithdrawsAnOpenRequestAndNobodyElseCan(): void
    {
        $id = $this->ask()['request']['id'];

        $this->assertFalse($this->service->withdraw($this->chair, $this->offering, $id)['success'], 'not theirs');
        $this->assertFalse($this->service->withdraw($this->teacher, $this->offering + 1, $id)['success'], 'another offering in the address');

        $this->assertTrue($this->service->withdraw($this->teacher, $this->offering, $id)['success']);

        $row = $this->row($id);
        $this->assertSame(Chain::WITHDRAWN, $row['status']);
        $this->assertNull($row['open_marker']);
        $this->assertContains('grade.override_withdrawn', $this->actions());
        $this->assertFalse($this->service->withdraw($this->teacher, $this->offering, $id)['success'], 'already closed');
        $this->assertSame([], $this->service->openByStudent($this->offering));
    }

    public function testTheChairpersonsListHoldsWhatWaitsForThemAndNothingElse(): void
    {
        $waiting = $this->ask()['request']['id'];

        $other    = PeopleFactory::person('student', 'Bob', 'Ray', null, $this->institution);
        CourseFactory::enroll($this->offering, $other);
        $this->record($other, 70.0);
        $endorsed = $this->service->request($this->teacher, $this->offering, (int) $other->id, 80.0, 'Re-marked')['request']['id'];
        $this->service->endorse($this->chair, $endorsed, '');

        $own = PeopleFactory::person('student', 'Cy', 'Day', null, $this->institution);
        CourseFactory::enroll($this->offering, $own);
        $this->record($own, 60.0);
        $this->service->request($this->chair, $this->offering, (int) $own->id, 75.0, 'Appeal');

        $pending = $this->service->listForChair($this->chair);
        $all     = $this->service->listForChair($this->chair, 'all');

        $this->assertSame([$waiting], array_map('intval', array_column($pending, 'id')), 'only what waits for them: not endorsed, not their own');
        $this->assertSame('Ann Lee', $pending[0]['student_name']);
        $this->assertSame('Tess Teacher', $pending[0]['requester_name']);
        $this->assertSame('IT101', $pending[0]['course_code']);
        $this->assertCount(3, $all);

        $elsewhere = DeanFactory::chair(DeanFactory::subDepartment($this->department, 'Other'), 'Olga');
        $this->assertSame([], $this->service->listForChair($elsewhere, 'all'), "another sub-department's chairperson sees none of it");
        $this->assertSame([], $this->service->listForChair($this->teacher), 'someone who chairs nothing');
    }

    public function testTheDialogTellsWhoARequestWouldGoTo(): void
    {
        $this->assertSame('It goes to the chairperson (Maria Chair) to endorse, then to the dean to decide.', $this->service->routeText($this->teacher, $this->offering));

        $this->assertSame('It goes to the dean to decide.', $this->service->routeText($this->chair, $this->offering), 'a chairperson skips their own step');
    }

    public function testEveryStepIsAuditedWithTheActorAndTheRequest(): void
    {
        $id = $this->ask()['request']['id'];
        $this->service->sendBack($this->chair, $id, 'Attach the note');
        $this->service->resubmit($this->teacher, $this->offering, $id, 92.0, 'Attached');
        $this->service->endorse($this->chair, $id, '');
        $this->service->withdraw($this->teacher, $this->offering, $id);

        $this->assertSame(
            ['grade.override_requested', 'grade.override_returned', 'grade.override_resubmitted', 'grade.override_endorsed', 'grade.override_withdrawn'],
            $this->actions()
        );

        $log = db_connect()->table('audit_logs')->where('action', 'grade.override_endorsed')->get()->getRowArray();
        $this->assertSame((int) $this->chair->id, (int) $log['actor_user_id']);
        $this->assertSame('grade_override_request', $log['target_type']);
        $this->assertSame((string) $id, $log['target_id']);
        $this->assertSame($this->institution, (int) $log['institution_id']);
    }
}
