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

    /*
    |--------------------------------------------------------------------------
    | The dean's decision
    |--------------------------------------------------------------------------
    */

    /** A request that has reached the dean: asked by the instructor, endorsed by the chairperson. @return int request id */
    private function waitingForDean(?float $percentage = 92.0): int
    {
        $id = $this->ask($percentage)['request']['id'];
        $this->service->endorse($this->chair, $id, 'Fine by me');

        return $id;
    }

    private function gradeRecord(): array
    {
        return db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->get()->getRowArray();
    }

    public function testTheDeanIsToldWhenARequestReachesThemAndNotBefore(): void
    {
        $id = $this->ask()['request']['id'];
        $this->assertCount(0, $this->notificationsFor($this->dean), 'still with the chairperson');

        $this->service->endorse($this->chair, $id, '');

        $told = $this->notificationsFor($this->dean);
        $this->assertCount(1, $told);
        $this->assertStringContainsString('waiting for your decision', $told[0]['body']);
        $this->assertStringEndsWith('/dean/approvals', $told[0]['link']);
    }

    public function testARequestThatSkipsTheChairpersonTellsTheDeanAtOnce(): void
    {
        $this->service->request($this->chair, $this->offering, (int) $this->student->id, 91.0, 'Appeal upheld');

        $this->assertCount(1, $this->notificationsFor($this->dean));
    }

    public function testApprovingChangesTheGradeThroughTheEngineAndClosesTheRequest(): void
    {
        $id = $this->waitingForDean();

        $result = $this->service->approve($this->dean, $id, 'Agreed');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString('final grade was changed', $result['message']);

        $record = $this->gradeRecord();
        $this->assertSame(1, (int) $record['is_override']);
        $this->assertSame(92.0, (float) $record['overridden_percentage']);
        $this->assertSame('Late quiz excused (medical).', $record['override_reason']);
        $this->assertSame('Passed', $record['remarks'], 'the remarks follow the new grade');

        $history = db_connect()->table('grade_history')->get()->getRowArray();
        $this->assertSame(88.0, (float) $history['previous_percentage']);
        $this->assertSame(92.0, (float) $history['new_percentage']);
        $this->assertSame((int) $this->dean->id, (int) $history['changed_by']);

        $row = $this->row($id);
        $this->assertSame(Chain::APPROVED, $row['status']);
        $this->assertNull($row['open_marker']);
        $this->assertSame((int) $this->dean->id, (int) $row['decided_by']);
        $this->assertSame('Agreed', $row['decision_reason']);
        $this->assertNotNull($row['decided_at']);

        $this->assertContains('grade.override_approved', $this->actions());
        $told = array_values(array_filter($this->notificationsFor($this->teacher), static fn (array $n): bool => str_contains($n['title'], 'approved')));
        $this->assertCount(1, $told);
        $this->assertStringContainsString('92%', $told[0]['body']);

        $this->assertTrue($this->ask(95.0, 'A later appeal')['success'], 'a decided request no longer blocks the student');
    }

    public function testApprovingARemovalClearsTheOverrideAndRestoresTheCalculatedGrade(): void
    {
        db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->update(['is_override' => 1, 'overridden_percentage' => 95.0]);
        $id = $this->waitingForDean(null);

        $result = $this->service->approve($this->dean, $id);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString('override was removed', $result['message']);

        $record = $this->gradeRecord();
        $this->assertSame(0, (int) $record['is_override']);
        $this->assertNull($record['overridden_percentage']);
        $this->assertSame(95.0, (float) db_connect()->table('grade_history')->get()->getRowArray()['previous_percentage']);
        $this->assertSame(88.0, (float) db_connect()->table('grade_history')->get()->getRowArray()['new_percentage']);
    }

    public function testRejectingNeedsAReasonAndLeavesTheGradeAlone(): void
    {
        $id = $this->waitingForDean();

        $this->assertFalse($this->service->reject($this->dean, $id, '  ')['success'], 'a reason is required');
        $this->assertSame(Chain::AWAITING_DEAN, $this->row($id)['status']);

        $result = $this->service->reject($this->dean, $id, 'The note does not cover that quiz.');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(0, (int) $this->gradeRecord()['is_override'], 'the grade is untouched');
        $this->assertSame(0, db_connect()->table('grade_history')->countAllResults());

        $row = $this->row($id);
        $this->assertSame(Chain::REJECTED, $row['status']);
        $this->assertNull($row['open_marker']);
        $this->assertSame('The note does not cover that quiz.', $row['decision_reason']);

        $this->assertContains('grade.override_rejected', $this->actions());
        $told = array_values(array_filter($this->notificationsFor($this->teacher), static fn (array $n): bool => str_contains($n['title'], 'rejected')));
        $this->assertStringContainsString('The note does not cover that quiz.', $told[0]['body']);
    }

    public function testWhoMayDecide(): void
    {
        $id = $this->waitingForDean();

        $otherDepartment = DeanFactory::department('O', $this->institution);
        $otherDean       = DeanFactory::dean($this->institution, $otherDepartment['department'], 'Olga');
        $notDean         = PeopleFactory::person('instructor', 'Not', 'Dean', null, $this->institution);

        foreach ([[$otherDean, "another department's dean"], [$this->chair, 'the chairperson'], [$this->teacher, 'the requester'], [$notDean, 'an instructor'], [$this->student, 'a student']] as [$who, $label]) {
            $this->assertFalse($this->service->approve($who, $id)['success'], $label);
            $this->assertFalse($this->service->reject($who, $id, 'No')['success'], $label);
        }

        $this->assertSame(Chain::AWAITING_DEAN, $this->row($id)['status']);
        $this->assertSame(0, (int) $this->gradeRecord()['is_override']);
        $this->assertFalse($this->service->approve($this->dean, 999999)['success']);
    }

    public function testARequestStillWithTheChairpersonCannotBeDecidedByTheDean(): void
    {
        $id = $this->ask()['request']['id'];

        $this->assertFalse($this->service->approve($this->dean, $id)['success']);
        $this->assertFalse($this->service->reject($this->dean, $id, 'No')['success']);
        $this->assertSame(Chain::AWAITING_CHAIR, $this->row($id)['status']);
    }

    public function testADeanWhoAlsoTeachesCannotDecideTheirOwnRequestNorAnyWhileActingAsAnInstructor(): void
    {
        $dean = PeopleFactory::person('instructor', 'Dora', 'Dean', null, $this->institution);
        (new \App\Services\Institutions\DeanAssignmentService())->assign($this->institution, $this->department, (int) $dean->id);
        $dean = model(CodeIgniter\Shield\Models\UserModel::class)->findById($dean->id);
        CourseFactory::assignInstructor($this->offering, $dean);

        $roles = new \App\Services\Auth\ActiveRoleService();

        // As dean she cannot even ask: she asks as the instructor she also is.
        $roles->switchTo($dean, 'dean');
        $this->assertFalse($this->service->request($dean, $this->offering, (int) $this->student->id, 91.0, 'My appeal')['success']);

        // Her own request: the dean step is the administrator's, never hers.
        $roles->switchTo($dean, 'instructor');
        $own = $this->service->request($dean, $this->offering, (int) $this->student->id, 91.0, 'My appeal');
        $this->assertSame(Chain::AWAITING_CHAIR, $own['request']['status'], 'the chairperson endorses it first');
        $this->service->endorse($this->chair, $own['request']['id'], '');
        $this->assertSame(Chain::AWAITING_INSTITUTION, $this->row($own['request']['id'])['status']);

        $roles->switchTo($dean, 'dean');
        $this->assertFalse($this->service->approve($dean, $own['request']['id'])['success'], 'never her own request');

        // Acting as an instructor she is not the dean, for anyone else's request either.
        $this->service->withdraw($dean, $this->offering, $own['request']['id']);
        $other = $this->waitingForDean();
        $roles->switchTo($dean, 'instructor');

        $this->assertFalse($this->service->approve($dean, $other)['success'], 'as an instructor');
        $this->assertSame(Chain::AWAITING_DEAN, $this->row($other)['status']);
    }

    public function testOnlyOneDecisionWinsAndAWithdrawnRequestCannotBeApproved(): void
    {
        $id = $this->waitingForDean();

        $this->assertTrue($this->service->approve($this->dean, $id)['success']);
        $this->assertFalse($this->service->approve($this->dean, $id)['success'], 'already decided');
        $this->assertFalse($this->service->reject($this->dean, $id, 'Changed my mind')['success'], 'already decided');
        $this->assertSame(Chain::APPROVED, $this->row($id)['status']);

        $other = PeopleFactory::person('student', 'Bob', 'Ray', null, $this->institution);
        CourseFactory::enroll($this->offering, $other);
        $this->record($other, 70.0);
        $second = $this->service->request($this->teacher, $this->offering, (int) $other->id, 80.0, 'Re-marked')['request']['id'];
        $this->service->endorse($this->chair, $second, '');
        $this->service->withdraw($this->teacher, $this->offering, $second);

        $this->assertFalse($this->service->approve($this->dean, $second)['success'], 'withdrawn before the dean answered');
        $this->assertSame(0, (int) db_connect()->table('gradebook_records')->where('student_id', $other->id)->get()->getRowArray()['is_override']);
    }

    public function testAGradeThatCannotBeChangedLeavesTheRequestWaitingForTheDean(): void
    {
        $id = $this->waitingForDean();

        // The student left the section after the request: the engine has nothing to change.
        db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->delete();
        db_connect()->table('enrollments')->where('student_user_id', $this->student->id)->update(['status' => 'dropped']);

        $result = $this->service->approve($this->dean, $id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('could not be changed', $result['message']);

        $row = $this->row($id);
        $this->assertSame(Chain::AWAITING_DEAN, $row['status'], 'given back, not lost');
        $this->assertSame(1, (int) $row['open_marker']);
        $this->assertNull($row['decided_by']);
        $this->assertNotContains('grade.override_approved', $this->actions());
    }

    public function testTheDeansListHoldsWhatWaitsForThemAndTheDecidedOnes(): void
    {
        $waiting = $this->waitingForDean();

        $other = PeopleFactory::person('student', 'Bob', 'Ray', null, $this->institution);
        CourseFactory::enroll($this->offering, $other);
        $this->record($other, 70.0);
        $this->service->request($this->teacher, $this->offering, (int) $other->id, 80.0, 'Re-marked');   // still with the chairperson

        $decidedStudent = PeopleFactory::person('student', 'Cy', 'Day', null, $this->institution);
        CourseFactory::enroll($this->offering, $decidedStudent);
        $this->record($decidedStudent, 60.0);
        $decided = $this->service->request($this->teacher, $this->offering, (int) $decidedStudent->id, 75.0, 'Appeal')['request']['id'];
        $this->service->endorse($this->chair, $decided, '');
        $this->service->reject($this->dean, $decided, 'No grounds');

        $pending = $this->service->listForDean($this->dean, new \App\Services\Courses\Grades\OverrideApprovalFilters());

        $this->assertSame([$waiting], array_map('intval', array_column($pending, 'id')), 'only what the chairperson passed on');
        $this->assertSame('Ann Lee', $pending[0]['student_name']);
        $this->assertSame('Tess Teacher', $pending[0]['requester_name']);
        $this->assertSame('Maria Chair', $pending[0]['endorser_name']);
        $this->assertSame('Program G', $pending[0]['program_name']);
        $this->assertSame(88.0, (float) $pending[0]['current_calculated']);
        $this->assertSame(1, $this->service->pendingCountForDean($this->dean));

        $history = $this->service->listForDean($this->dean, new \App\Services\Courses\Grades\OverrideApprovalFilters('decided'));
        $this->assertSame([$decided], array_map('intval', array_column($history, 'id')));
        $this->assertSame('rejected', $history[0]['status']);
    }

    public function testTheDeansListFollowsItsFiltersAndStaysInTheirDepartment(): void
    {
        $this->waitingForDean();
        $filters = static fn (array $input) => \App\Services\Courses\Grades\OverrideApprovalFilters::fromInput($input);
        $count   = fn (array $input) => count($this->service->listForDean($this->dean, $filters($input)));

        $program = (int) db_connect()->table('programs')->where('sub_department_id', $this->sub)->get()->getRowArray()['id'];

        $this->assertSame(1, $count(['q' => 'ann']));
        $this->assertSame(1, $count(['q' => 'IT101']));
        $this->assertSame(1, $count(['q' => 'computing']));
        $this->assertSame(0, $count(['q' => 'nobody']));
        $this->assertSame(0, $count(['q' => '%']), 'a wildcard is plain text');
        $this->assertSame(1, $count(['program' => (string) $program]));
        $this->assertSame(0, $count(['program' => (string) ($program + 999)]));
        $this->assertSame(1, $count(['requested_by' => (string) $this->teacher->id]));
        $this->assertSame(0, $count(['requested_by' => (string) $this->chair->id]));

        $otherDepartment = DeanFactory::department('O', $this->institution);
        $otherDean       = DeanFactory::dean($this->institution, $otherDepartment['department'], 'Olga');

        $this->assertSame([], $this->service->listForDean($otherDean, new \App\Services\Courses\Grades\OverrideApprovalFilters()), "another department's dean sees none of it");
        $this->assertSame([], $this->service->listForDean(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution), new \App\Services\Courses\Grades\OverrideApprovalFilters()));
        $this->assertSame(['Tess Teacher'], array_column($this->service->requestersForDean($this->dean), 'name'));
    }

    /** Three students who each have a request waiting for the dean, asked at different times by two instructors. @return array<string, int> name => request id */
    private function threeWaiting(): array
    {
        $second = PeopleFactory::person('instructor', 'Zed', 'Zane', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $second);

        $ids = [];

        foreach ([['Ann', 'Lee', 80.0, 'Tess', $this->teacher, '2026-10-01 09:00:00'], ['Bob', 'Ray', 95.0, 'Zed', $second, '2026-10-03 09:00:00'], ['Cy', 'Day', 70.0, 'Tess', $this->teacher, '2026-10-02 09:00:00']] as [$first, $last, $asked, $by, $teacher, $at]) {
            $student = $first === 'Ann' ? $this->student : PeopleFactory::person('student', $first, $last, null, $this->institution);

            if ($first !== 'Ann') {
                CourseFactory::enroll($this->offering, $student);
                $this->record($student, 60.0);
            }

            $id = $this->service->request($teacher, $this->offering, (int) $student->id, $asked, 'Reason for ' . $first)['request']['id'];
            $this->service->endorse($this->chair, $id, '');
            db_connect()->table('grade_override_requests')->where('id', $id)->update(['created_at' => $at]);

            $ids["{$first} {$last}"] = $id;
        }

        return $ids;
    }

    /** @return list<string> the students of the dean's list, in order */
    private function sortedBy(array $input): array
    {
        $rows = $this->service->listForDean($this->dean, \App\Services\Courses\Grades\OverrideApprovalFilters::fromInput($input));

        return array_map(static fn (array $row): string => trim((string) $row['student_name']), $rows);
    }

    public function testTheDeansListSortsByTheChosenColumnBothWays(): void
    {
        $this->threeWaiting();

        $this->assertSame(['Ann Lee', 'Cy Day', 'Bob Ray'], $this->sortedBy([]), 'oldest request first by default');
        $this->assertSame(['Bob Ray', 'Cy Day', 'Ann Lee'], $this->sortedBy(['dir' => 'desc']));
        $this->assertSame(['Cy Day', 'Ann Lee', 'Bob Ray'], $this->sortedBy(['sort' => 'student', 'dir' => 'asc']), 'by last name: Day, Lee, Ray');
        $this->assertSame(['Bob Ray', 'Ann Lee', 'Cy Day'], $this->sortedBy(['sort' => 'student', 'dir' => 'desc']));
        $this->assertSame(['Cy Day', 'Ann Lee', 'Bob Ray'], $this->sortedBy(['sort' => 'requested']), 'by the percentage asked for: 70, 80, 95');
        $this->assertSame(['Bob Ray', 'Ann Lee', 'Cy Day'], $this->sortedBy(['sort' => 'requested', 'dir' => 'desc']));
        $this->assertSame('Bob Ray', $this->sortedBy(['sort' => 'requester', 'dir' => 'desc'])[0], 'by who asked: Zane after Teacher');
        $this->assertSame('Bob Ray', $this->sortedBy(['sort' => 'requester'])[2]);
    }

    public function testTheSortKeepsTheFiltersAndTheDecidedListIsNewestFirst(): void
    {
        $ids = $this->threeWaiting();

        $this->assertSame(['Cy Day', 'Ann Lee'], $this->sortedBy(['sort' => 'requested', 'requested_by' => (string) $this->teacher->id]), 'filtered to Tess, then sorted');

        $this->service->reject($this->dean, $ids['Ann Lee'], 'No grounds');
        $this->service->reject($this->dean, $ids['Bob Ray'], 'No grounds');
        db_connect()->table('grade_override_requests')->where('id', $ids['Ann Lee'])->update(['decided_at' => '2026-10-05 09:00:00']);
        db_connect()->table('grade_override_requests')->where('id', $ids['Bob Ray'])->update(['decided_at' => '2026-10-06 09:00:00']);

        $this->assertSame(['Bob Ray', 'Ann Lee'], $this->sortedBy(['view' => 'decided']), 'newest decision first');
        $this->assertSame(['Ann Lee', 'Bob Ray'], $this->sortedBy(['view' => 'decided', 'dir' => 'asc']));
    }

    public function testTheBreakdownShowsHowTheGradeIsMadeUp(): void
    {
        $quizzes = GradeFactory::category($this->scheme, 'Quizzes', 30);
        $exams   = GradeFactory::category($this->scheme, 'Exams', 70, null, 2);
        $quiz    = GradeFactory::item($this->scheme, $quizzes, 'Quiz 1', 10);
        $exam    = GradeFactory::item($this->scheme, $exams, 'Midterm', 100);
        GradeFactory::score($this->offering, $this->student, $quiz, 8.0);
        GradeFactory::score($this->offering, $this->student, $exam, 90.0);

        $parts = $this->service->breakdown($this->offering, (int) $this->student->id);

        $this->assertSame(['Quizzes', 'Exams'], array_column($parts, 'name'));
        $this->assertSame(80.0, $parts[0]['percentage']);
        $this->assertSame(90.0, $parts[1]['percentage']);

        $this->assertSame([], $this->service->breakdown($this->offering, 999999), 'not enrolled');
        $this->assertSame([], $this->service->breakdown(999999, (int) $this->student->id), 'no such offering');
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
