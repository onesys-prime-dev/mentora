<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\Grades\GradeSignoffService;
use App\Services\Institutions\DeanAssignmentService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * With no dean in the department, the institution's administrator signs off
 * the finalized grades (audit decisions 2.1–2.5, D3, D4).
 *
 * @internal
 */
final class AdministratorSignsOffTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $department;

    private int $program;

    private int $term;

    private int $offering;

    private User $teacher;

    private User $adminA;

    private User $adminB;

    private GradeSignoffService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();
        session()->destroy();

        $home              = DeanFactory::department('SO');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];

        $other = DeanFactory::department('SP', $this->institution);
        DeanFactory::dean($this->institution, $other['department'], 'Otto');

        $this->program  = DeanFactory::program(DeanFactory::subDepartment($this->department, 'SO'), 'SO');
        $this->term     = DeanFactory::term($this->institution);
        $this->offering = $this->finalizedSection('IT401');

        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->adminA  = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->adminB  = PeopleFactory::person('institutional_admin', 'Ben', 'Admin', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        $this->service = new GradeSignoffService();
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function finalizedSection(string $code, ?int $program = null): int
    {
        $offering = DeanFactory::offering($program ?? $this->program, $this->term, $code);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        return $offering;
    }

    private function notified(User $user, string $type): array
    {
        return db_connect()->table('notifications')->where(['user_id' => $user->id, 'type' => $type])->get()->getResultArray();
    }

    public function testAnAdministratorSignsOffWhereThereIsNoDean(): void
    {
        $result = $this->service->signOff($this->adminA, $this->offering);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertTrue($this->service->isSignedOff($this->offering));

        $told = $this->notified($this->teacher, 'grade_signoff');
        $this->assertStringContainsString('the administrator signed off', (string) end($told)['body']);
    }

    public function testWhereThereIsADeanTheAdministratorCannot(): void
    {
        DeanFactory::dean($this->institution, $this->department, 'Dana');

        $result = $this->service->signOff($this->adminA, $this->offering);

        $this->assertFalse($result['success']);
        $this->assertSame('That section is not one you sign off.', $result['message']);
    }

    public function testADeanMaySignOffASectionTheyTeach(): void
    {
        $dean = DeanFactory::dean($this->institution, $this->department, 'Dana');
        $dean->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $dean);

        $this->assertTrue($this->service->signOff($dean, $this->offering)['success']);
    }

    public function testAnAdministratorMayNotSignOffTheirOwnSection(): void
    {
        $this->adminA->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $this->adminA);

        $result = $this->service->signOff($this->adminA, $this->offering);

        $this->assertFalse($result['success']);
        $this->assertSame('You teach this section, so another administrator signs it off.', $result['message']);
        $this->assertTrue($this->service->signOff($this->adminB, $this->offering)['success']);
    }

    public function testSigningOffAProgramLeavesTheAdministratorsOwnSectionForAnother(): void
    {
        $this->adminA->addGroup('instructor');
        $own = $this->finalizedSection('IT402');
        CourseFactory::assignInstructor($own, $this->adminA);
        session()->set('active_role', 'institutional_admin');
        auth()->login($this->adminA);

        $program = $this->service->boardFor([$this->program], $this->term, 'institution')['programs'][0];
        $this->assertSame(1, $program['own_ready'], 'the page offers to sign off one fewer');

        $result = $this->service->signOffProgram($this->adminA, $this->program, $this->term);

        $this->assertSame(1, $result['signed']);
        $this->assertSame(0, $result['left']);
        $this->assertStringContainsString('1 you teach was left for another administrator', $result['message']);
        $this->assertSame(GradeSignoffService::READY, $this->service->status($own)['state']);
    }

    public function testTheAdministratorReopensWithAReason(): void
    {
        $this->service->signOff($this->adminA, $this->offering);

        $this->assertFalse($this->service->reopen($this->adminA, $this->offering, ' ')['success']);
        $this->assertTrue($this->service->reopen($this->adminA, $this->offering, 'A grade was mistyped.')['success']);

        $told = $this->notified($this->teacher, 'grade_signoff');
        $this->assertStringContainsString('the administrator reopened', (string) end($told)['body']);
    }

    public function testTheAdministratorsAreToldASectionIsReady(): void
    {
        $this->adminB->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $this->adminB);

        $this->service->announceReady($this->offering);

        $this->assertCount(1, $this->notified($this->adminA, 'grade_signoff'));
        $this->assertCount(0, $this->notified($this->adminB, 'grade_signoff'), 'not the one who teaches it');
    }

    public function testTheFirstDeanTellsEachSignerWhatNowNeedsSignOff(): void
    {
        // A second institution, with no dean yet: its finalized grades go straight to the SIS today.
        ['institution' => $fresh, 'department' => $dept] = DeanFactory::department('SQ');
        ['department' => $other]                         = DeanFactory::department('SR', $fresh);
        $term     = DeanFactory::term($fresh);
        $mine     = DeanFactory::program(DeanFactory::subDepartment($dept, 'SQ'), 'SQ');
        $theirs   = DeanFactory::program(DeanFactory::subDepartment($other, 'SR'), 'SR');
        $finalize = static function (int $program, string $code) use ($term): int {
            $offering = DeanFactory::offering($program, $term, $code);
            db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

            return $offering;
        };
        $finalize($mine, 'LW100');
        $finalize($theirs, 'LW200');
        $sent = $finalize($theirs, 'LW300');
        db_connect()->table('sis_grade_exports')->insert(['institution_id' => $fresh, 'offering_id' => $sent, 'student_id' => 1, 'percentage' => 90, 'remarks' => 'Passed', 'batch_key' => 'k', 'sent_at' => date('Y-m-d H:i:s')]);
        $admin = PeopleFactory::person('institutional_admin', 'Ivy', 'Admin', null, $fresh);
        $dean  = PeopleFactory::person('dean', 'Dora', 'Dean', null, $fresh);

        (new DeanAssignmentService())->assign($fresh, $dept, (int) $dean->id, $admin);

        $toDean = $this->notified($dean, 'grade_signoff');
        $this->assertCount(1, $toDean, 'the new dean signs off their own department');
        $this->assertStringContainsString('1 section', (string) $toDean[0]['body']);
        $this->assertStringContainsString('LW100', (string) $toDean[0]['body']);

        $toAdmin = $this->notified($admin, 'grade_signoff');
        $this->assertCount(1, $toAdmin);
        $this->assertStringContainsString('1 section', (string) $toAdmin[0]['body']);
        $this->assertStringContainsString('LW200', (string) $toAdmin[0]['body']);
        $this->assertStringNotContainsString('LW100', (string) $toAdmin[0]['body']);
        $this->assertStringNotContainsString('LW300', (string) $toAdmin[0]['body'], 'already sent to the SIS');
    }

    public function testExistingSignOffsStayWhenTheSignerChanges(): void
    {
        $this->service->signOff($this->adminA, $this->offering);

        DeanFactory::dean($this->institution, $this->department, 'Dana');

        $this->assertTrue($this->service->isSignedOff($this->offering));
    }

    public function testTheAdministratorsBoardIsTheDepartmentsWithNoDean(): void
    {
        $this->assertSame([$this->program], $this->service->adminProgramIds($this->adminA));

        $board = $this->service->boardFor($this->service->adminProgramIds($this->adminA), null, 'institution');

        $this->assertSame('IT401', $board['programs'][0]['sections'][0]['course_code']);
        $this->assertStringContainsString('institution/courses/', $board['programs'][0]['sections'][0]['grades_url']);
    }
}
