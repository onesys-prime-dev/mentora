<?php

declare(strict_types=1);

use App\Models\Assessments\GradeSignoffModel;
use App\Services\Auth\ActiveRoleService;
use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\GradeSignoffService as Signoff;
use App\Services\Courses\Grades\GradingEngineService;
use App\Services\Courses\Grades\GradingSchemeService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * The dean's sign-off of finalized grades: when a section is ready or blocked and
 * why, signing off one or a program's ready sections, that only the dean can reopen
 * a sign-off (and what that does), the notifications, the audit, and that an
 * institution with no dean is untouched.
 *
 * @internal
 */
final class GradeSignoffServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private Signoff $service;
    private GradeFinalizationService $finalization;
    private int $institution;
    private int $department;
    private int $sub;
    private int $program;
    private int $term;
    private int $offering;
    private User $teacher;
    private User $chair;
    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home              = DeanFactory::department('G');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'G');
        $this->program     = DeanFactory::program($this->sub, 'G');
        $this->term        = DeanFactory::term($this->institution);

        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->chair   = DeanFactory::chair($this->sub, 'Maria');
        $this->dean    = DeanFactory::dean($this->institution, $this->department);

        $this->offering = $this->section($this->program, 'IT101', 'A');

        $this->service      = new Signoff();
        $this->finalization = new GradeFinalizationService();
    }

    /** A section whose grades can be finalized: a published scheme and a score for each of two students. */
    private function section(int $program, string $code, string $letter): int
    {
        $offering = DeanFactory::offering($program, $this->term, $code, $letter, 'active', "Course {$code}");
        CourseFactory::assignInstructor($offering, $this->teacher);

        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
        $quizzes = GradeFactory::category($scheme, 'Quizzes', 40, null, 1);
        $exams   = GradeFactory::category($scheme, 'Exams', 60, null, 2);
        $quiz    = GradeFactory::item($scheme, $quizzes, 'Quiz 1', 20);
        $exam    = GradeFactory::item($scheme, $exams, 'Midterm', 100);

        foreach (['Ann', 'Bob'] as $name) {
            $student = PeopleFactory::person('student', $name, 'Lee', null, $this->institution);
            CourseFactory::enroll($offering, $student);
            GradeFactory::score($offering, $student, $quiz, 18);
            GradeFactory::score($offering, $student, $exam, 80);
        }

        (new GradingEngineService())->recalculateOffering($offering);

        return $offering;
    }

    private function finalized(?int $offering = null): int
    {
        $offering ??= $this->offering;
        $result = $this->finalization->finalize($this->teacher, $offering);
        $this->assertTrue($result['success'], $result['message']);

        return $offering;
    }

    private function actions(): array
    {
        return array_column(db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray(), 'action');
    }

    private function notificationsFor(User $user, string $type = 'grade_signoff'): array
    {
        return db_connect()->table('notifications')->where(['user_id' => $user->id, 'type' => $type])->get()->getResultArray();
    }

    public function testASignOffAppliesOnlyWhereTheInstitutionHasADean(): void
    {
        $this->assertTrue($this->service->appliesTo($this->offering));

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertFalse($this->service->appliesTo($this->offering), 'no dean in the institution: finalization is the end, as before');
    }

    public function testASectionIsBlockedUntilTheInstructorHasFinalizedItsGrades(): void
    {
        $status = $this->service->status($this->offering);

        $this->assertSame(Signoff::BLOCKED, $status['state']);
        $this->assertSame(['The instructor has not finalized the grades yet.'], $status['blockers']);

        $this->finalized();

        $this->assertSame(Signoff::READY, $this->service->status($this->offering)['state']);
    }

    public function testAnOpenOverrideRequestBlocksASectionThatIsOtherwiseReady(): void
    {
        $this->finalized();
        $student = (int) db_connect()->table('enrollments')->where('offering_id', $this->offering)->get()->getRowArray()['student_user_id'];

        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $this->offering, 'student_id' => $student, 'reason' => 'x', 'requested_by' => $this->teacher->id,
            'requester_role' => 'instructor', 'status' => 'awaiting_dean', 'open_marker' => 1,
        ]);

        $status = $this->service->status($this->offering);

        $this->assertSame(Signoff::BLOCKED, $status['state']);
        $this->assertSame(['1 grade-override request is still open.'], $status['blockers']);
        $this->assertFalse($this->service->signOff($this->dean, $this->offering)['success']);
    }

    public function testTheDeanIsToldOnceTheGradesAreFinalized(): void
    {
        $this->assertCount(0, $this->notificationsFor($this->dean), 'nothing to sign off yet');

        $this->finalized();

        $told = $this->notificationsFor($this->dean);
        $this->assertCount(1, $told);
        $this->assertStringContainsString('ready for your sign-off', $told[0]['body']);
        $this->assertStringEndsWith('/dean/signoff', $told[0]['link']);
    }

    public function testNoDeanMeansNoSignOffAndNoNotification(): void
    {
        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->finalized();

        $this->assertCount(0, $this->notificationsFor($this->dean));
        $result = $this->service->signOff($this->dean, $this->offering);
        $this->assertFalse($result['success'], 'with no dean there is nobody to sign off');
        $this->assertFalse($this->service->isSignedOff($this->offering));
    }

    public function testTheDeanSignsOffAReadySectionAndThePeopleConcernedAreTold(): void
    {
        $this->finalized();

        $result = $this->service->signOff($this->dean, $this->offering);

        $this->assertTrue($result['success'], $result['message']);

        $current = $this->service->current($this->offering);
        $this->assertSame((int) $this->dean->id, (int) $current['signed_by']);
        $this->assertSame(1, (int) $current['open_marker']);
        $this->assertNotNull($current['signed_at']);
        $this->assertSame(Signoff::SIGNED_OFF, $this->service->status($this->offering)['state']);
        $this->assertTrue($this->service->isSignedOff($this->offering));

        $this->assertContains('grades.signed_off', $this->actions());
        $this->assertCount(1, $this->notificationsFor($this->teacher), 'the instructor');
        $this->assertCount(1, $this->notificationsFor($this->chair), 'the chairperson');
        $this->assertStringContainsString('signed off', $this->notificationsFor($this->teacher)[0]['body']);

        $this->assertFalse($this->service->signOff($this->dean, $this->offering)['success'], 'already signed off');
    }

    public function testASectionThatIsNotReadyCannotBeSignedOff(): void
    {
        $result = $this->service->signOff($this->dean, $this->offering);

        $this->assertFalse($result['success']);
        $this->assertSame('The instructor has not finalized the grades yet.', $result['message']);
        $this->assertNull($this->service->current($this->offering));
    }

    public function testWhoMayAndMayNotSignOff(): void
    {
        $this->finalized();

        $otherDepartment = DeanFactory::department('O', $this->institution);
        $otherDean       = DeanFactory::dean($this->institution, $otherDepartment['department'], 'Olga');

        foreach ([[$otherDean, "another department's dean"], [$this->chair, 'the chairperson'], [$this->teacher, 'the instructor']] as [$who, $label]) {
            $this->assertFalse($this->service->signOff($who, $this->offering)['success'], $label);
            $this->assertFalse($this->service->reopen($who, $this->offering, 'Because')['success'], $label);
        }

        $this->assertFalse($this->service->isSignedOff($this->offering));
    }

    public function testADeanActingAsAnInstructorCannotSignOff(): void
    {
        $this->finalized();

        $person = PeopleFactory::person('instructor', 'Dora', 'Dean', null, $this->institution);
        (new \App\Services\Institutions\DeanAssignmentService())->assign($this->institution, $this->department, (int) $person->id);
        $dora = model(CodeIgniter\Shield\Models\UserModel::class)->findById($person->id);

        (new ActiveRoleService())->switchTo($dora, 'instructor');

        $this->assertFalse($this->service->signOff($dora, $this->offering)['success']);

        (new ActiveRoleService())->switchTo($dora, 'dean');

        $this->assertTrue($this->service->signOff($dora, $this->offering)['success']);
    }

    public function testTheInstructorAndTheChairpersonCannotReopenASignedOffSection(): void
    {
        $this->finalized();
        $this->service->signOff($this->dean, $this->offering);

        foreach ([$this->teacher, $this->chair] as $who) {
            $result = $this->finalization->reopen($who, $this->offering, 'I need to fix a grade');

            $this->assertFalse($result['success']);
            $this->assertSame(GradeFinalizationService::SIGNED_OFF_MESSAGE, $result['message']);
        }

        $this->assertTrue($this->finalization->isFinalized($this->offering), 'still finalized');
        $this->assertTrue($this->service->isSignedOff($this->offering), 'and still signed off');
    }

    public function testSigningOffAddsNoWayToEditAndTheGradebookStaysLocked(): void
    {
        $this->finalized();
        $this->service->signOff($this->dean, $this->offering);

        $item   = (int) db_connect()->table('grade_items')->where('name', 'Quiz 1')->get()->getRowArray()['id'];
        $result = (new GradingSchemeService())->updateManualScore($this->teacher, $this->offering, $item, (int) db_connect()->table('enrollments')->where('offering_id', $this->offering)->get()->getRowArray()['student_user_id'], 5.0);

        $this->assertFalse($result['success']);
        $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $result['message']);
    }

    public function testAnApprovedOverrideStillWorksAfterSignOffAndIsRecordedAsALaterChange(): void
    {
        $this->finalized();
        $this->service->signOff($this->dean, $this->offering);
        $student = PeopleFactory::person('student', 'Cy', 'Day', null, $this->institution);
        CourseFactory::enroll($this->offering, $student);
        (new GradingEngineService())->recalculateStudent($this->offering, (int) $student->id);

        $requests = new GradeOverrideRequestService();
        $id       = $requests->request($this->chair, $this->offering, (int) $student->id, 91.0, 'Appeal upheld');
        $this->assertTrue($id['success'], $id['message'] ?? '');

        $this->assertTrue($requests->approve($this->dean, $id['request']['id'])['success']);

        $approved = db_connect()->table('audit_logs')->where('action', 'grade.override_approved')->get()->getRowArray();
        $this->assertTrue(json_decode($approved['new_values'], true)['after_signoff']);
    }

    public function testOnlyTheDeanCanReopenAndItEndsTheSignOffWithoutOpeningTheGrades(): void
    {
        $this->finalized();
        $this->service->signOff($this->dean, $this->offering);

        $this->assertFalse($this->service->reopen($this->dean, $this->offering, '  ')['success'], 'a reason is required');
        $this->assertTrue($this->service->isSignedOff($this->offering));

        $result = $this->service->reopen($this->dean, $this->offering, 'A mark was entered wrongly.');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString('stay finalized', $result['message']);
        $this->assertFalse($this->service->isSignedOff($this->offering));
        $this->assertTrue($this->finalization->isFinalized($this->offering), 'the grades stay finalized');
        $this->assertSame(Signoff::READY, $this->service->status($this->offering)['state']);

        $row = db_connect()->table('grade_signoffs')->get()->getRowArray();
        $this->assertNull($row['open_marker']);
        $this->assertSame((int) $this->dean->id, (int) $row['reopened_by']);
        $this->assertSame('A mark was entered wrongly.', $row['reopen_reason']);
        $this->assertNotNull($row['reopened_at']);

        $this->assertContains('grades.signoff_reopened', $this->actions());
        $this->assertStringContainsString('A mark was entered wrongly.', implode(' | ', array_column($this->notificationsFor($this->teacher), 'body')), 'the instructor reads why');
        $this->assertStringContainsString('A mark was entered wrongly.', implode(' | ', array_column($this->notificationsFor($this->chair), 'body')), 'and so does the chairperson');

        $this->assertFalse($this->service->reopen($this->dean, $this->offering, 'Again')['success'], 'nothing left to reopen');
    }

    public function testAfterTheDeanReopensTheInstructorMayReopenTheGradesAndTheDeanMaySignOffAgain(): void
    {
        $this->finalized();
        $this->service->signOff($this->dean, $this->offering);
        $this->service->reopen($this->dean, $this->offering, 'Check again');

        $this->assertTrue($this->service->signOff($this->dean, $this->offering)['success'], 'signing off again is allowed');
        $this->assertSame(2, db_connect()->table('grade_signoffs')->countAllResults(), 'the ended sign-off stays as history');

        $this->service->reopen($this->dean, $this->offering, 'Again');
        $this->assertTrue($this->finalization->reopen($this->teacher, $this->offering, 'My own correction')['success']);
    }

    public function testTheDatabaseAllowsOnlyOneCurrentSignOffPerSection(): void
    {
        $model = new GradeSignoffModel();
        $base  = ['offering_id' => $this->offering, 'signed_by' => $this->dean->id, 'signed_at' => date('Y-m-d H:i:s')];

        $model->insert($base + ['open_marker' => 1]);
        $model->insert($base + ['open_marker' => null]);
        $model->insert($base + ['open_marker' => null]);

        $this->assertSame(3, $model->countAllResults(), 'ended ones (no marker) can be many');

        try {
            $second = $model->insert($base + ['open_marker' => 1]) !== false;
        } catch (\Throwable) {
            $second = false;
        }

        $this->assertFalse($second, 'a second current one is rejected by the unique key');
    }

    public function testSigningOffAProgramTakesTheReadySectionsAndLeavesTheRest(): void
    {
        $ready   = $this->finalized();
        $second  = $this->finalized($this->section($this->program, 'IT102', 'A'));
        $blocked = $this->section($this->program, 'IT103', 'A');
        $done    = $this->finalized($this->section($this->program, 'IT104', 'A'));
        $this->service->signOff($this->dean, $done);

        $otherProgram = $this->section(DeanFactory::program($this->sub, 'H'), 'LW100', 'A');
        $this->finalized($otherProgram);

        $result = $this->service->signOffProgram($this->dean, $this->program, $this->term);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['signed']);
        $this->assertSame(1, $result['left'], 'the blocked one');
        $this->assertStringContainsString('Signed off 2 sections', $result['message']);

        $this->assertTrue($this->service->isSignedOff($ready));
        $this->assertTrue($this->service->isSignedOff($second));
        $this->assertFalse($this->service->isSignedOff($blocked));
        $this->assertFalse($this->service->isSignedOff($otherProgram), 'another program is not touched');

        $again = $this->service->signOffProgram($this->dean, $this->program, $this->term);
        $this->assertSame('Nothing was ready to sign off.', $again['message']);
    }

    public function testSigningOffAProgramOutsideTheDepartmentIsRefused(): void
    {
        $elsewhere = DeanFactory::department('X', $this->institution);
        $foreign   = DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X');

        $result = $this->service->signOffProgram($this->dean, $foreign, $this->term);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['signed']);
    }

    public function testTheBoardGroupsTheTermsSectionsByProgramWithWhereEachStands(): void
    {
        $this->finalized();
        $this->service->signOff($this->dean, $this->offering);
        $this->section($this->program, 'IT102', 'A');                                  // blocked: not finalized
        $this->finalized($this->section($this->program, 'IT103', 'A'));                 // ready
        $second = DeanFactory::program($this->sub, 'H');
        $this->section($second, 'LW100', 'A');

        $board = $this->service->board($this->dean, $this->term);

        $this->assertSame($this->term, $board['term']);
        $this->assertSame(['Program G', 'Program H'], array_column($board['programs'], 'name'));

        $first = $board['programs'][0];
        $this->assertSame(['IT101', 'IT102', 'IT103'], array_column($first['sections'], 'course_code'));
        $this->assertEquals([Signoff::SIGNED_OFF => 1, Signoff::READY => 1, Signoff::BLOCKED => 1], $first['counts']);
        $this->assertSame('Tess Teacher', $first['sections'][0]['instructor']);
        $this->assertSame(2, $first['sections'][0]['students']);
        $this->assertNotNull($first['sections'][0]['signed_at']);
        $this->assertNotSame('', (string) $first['sections'][0]['signed_by_name']);
        $this->assertSame(['The instructor has not finalized the grades yet.'], $first['sections'][1]['blockers']);
    }

    public function testTheBoardDefaultsToTheNewestTermAndIgnoresAnotherDepartmentsOrAnUnknownOne(): void
    {
        $later = DeanFactory::term($this->institution, 'Second', '2027-01-10');
        DeanFactory::offering($this->program, $later, 'IT201');

        $this->assertSame($later, $this->service->board($this->dean, null)['term'], 'the newest term');
        $this->assertSame($later, $this->service->board($this->dean, 999999)['term'], 'an unknown term falls back');
        $this->assertSame($this->term, $this->service->board($this->dean, $this->term)['term']);

        $elsewhere = DeanFactory::department('X', $this->institution);
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X'), $this->term, 'ZZ900');
        $codes     = array_merge(...array_map(static fn (array $p): array => array_column($p['sections'], 'course_code'), $this->service->board($this->dean, $this->term)['programs']));

        $this->assertNotContains('ZZ900', $codes);
        $this->assertSame($foreign, $foreign);
    }

    public function testADeanWithoutADepartmentHasAnEmptyBoard(): void
    {
        $board = $this->service->board(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution), null);

        $this->assertSame([], $board['programs']);
        $this->assertNull($board['term']);
    }
}
