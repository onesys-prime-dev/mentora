<?php

declare(strict_types=1);

use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use App\Services\Sis\SisImporter;
use App\Services\Sis\SisImportResult;
use App\Services\Sis\SisOwnership;
use App\Services\Sis\SisRunLog;
use App\Services\Users\UserAdminService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The SIS-supplied staff assignments (staff_assignments.csv): deans, chairpersons and who teaches which
 * section, assigned through the services the screens use. What is assigned, replaced and refused, what the
 * SIS stops listing (ended, never deleted, and only what the SIS itself supplied), what a file that could not
 * be fully read must never end, and that what the SIS supplies cannot be changed by hand while its connection
 * is enabled.
 *
 * @internal
 */
final class SisStaffImportTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const PEOPLE = "external_id,email,first_name,last_name\nI1,ivy@example.com,Ivy,Tan\nI2,ian@example.com,Ian,Ong\nI3,ida@example.com,Ida,Uy\n";

    private const COURSES = "external_id,course_code,course_title,units,program_code\nC1,IT101,Intro to Computing,3,BSIT\nC2,IT102,Data Structures,3,BSIT\n";

    private const HEADER = "role,person_external_id,department_code,sub_department_code,course_external_id,section\n";

    private string $folder;

    private int $institution;

    private int $department;

    private int $sub;

    private User $actor;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->folder      = SisFixture::useFolder();
        $this->institution = SisFixture::institution();
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@example.com');
        $this->now         = new DateTimeImmutable('2026-10-10 09:00:00');

        SisFixture::connect($this->institution, $this->actor);
        SisFixture::priorSuccess($this->institution);

        $this->department = (int) db_connect()->table('departments')->where('institution_id', $this->institution)->get()->getRow('id');
        $this->sub        = (int) db_connect()->table('sub_departments')->where('department_id', $this->department)->get()->getRow('id');

        // The people and the courses the staff files refer to, in a first run of their own.
        SisFixture::drop($this->institution, 'instructors', self::PEOPLE);
        SisFixture::drop($this->institution, 'courses', self::COURSES);
        $this->run_();
        unlink(\App\Services\Sis\SisConnectors::folders($this->institution)['inbound'] . DIRECTORY_SEPARATOR . 'instructors.csv');
        unlink(\App\Services\Sis\SisConnectors::folders($this->institution)['inbound'] . DIRECTORY_SEPARATOR . 'courses.csv');
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function run_(bool $dry = false): SisImportResult
    {
        return (new SisImporter())->run($this->institution, $this->actor, $dry, SisRunLog::MANUAL, $this->now);
    }

    /** Drops staff_assignments.csv with these rows (each "role,person,department,sub-department,course,section") and runs. */
    private function sync(string ...$rows): SisImportResult
    {
        SisFixture::drop($this->institution, 'staff_assignments', self::HEADER . implode("\n", $rows) . ($rows === [] ? '' : "\n"));

        return $this->run_();
    }

    /** @return array{created: int, updated: int, skipped: int, failed: int} */
    private function counts(SisImportResult $result): array
    {
        return $result->datasets['staff_assignments'];
    }

    private function person(string $email): int
    {
        return (int) db_connect()->table('auth_identities')->where('secret', $email)->get()->getRow('user_id');
    }

    private function ivy(): int
    {
        return $this->person('ivy@example.com');
    }

    private function ian(): int
    {
        return $this->person('ian@example.com');
    }

    private function ida(): int
    {
        return $this->person('ida@example.com');
    }

    private function deptCode(): string
    {
        return (string) db_connect()->table('departments')->where('id', $this->department)->get()->getRow('code');
    }

    private function subCode(): string
    {
        return (string) db_connect()->table('sub_departments')->where('id', $this->sub)->get()->getRow('code');
    }

    private function hasGroup(int $userId, string $group): bool
    {
        return db_connect()->table('auth_groups_users')->where(['user_id' => $userId, 'group' => $group])->countAllResults() > 0;
    }

    private function giveRole(int $userId, string $group): void
    {
        db_connect()->table('auth_groups_users')->insert(['user_id' => $userId, 'group' => $group, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<array<string, mixed>> */
    private function deans(?string $status = null): array
    {
        $builder = db_connect()->table('dean_assignments')->where('department_id', $this->department)->orderBy('id');

        return ($status === null ? $builder : $builder->where('status', $status))->get()->getResultArray();
    }

    private function chairs(string $status = 'active'): array
    {
        return db_connect()->table('chairperson_assignments')->where(['sub_department_id' => $this->sub, 'status' => $status])->orderBy('id')->get()->getResultArray();
    }

    private function offering(string $code, string $section = 'A'): ?int
    {
        $row = db_connect()->table('course_offerings o')->select('o.id')->join('courses c', 'c.id = o.course_id')->where(['c.course_code' => $code, 'o.section_code' => $section])->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function teachers(int $offering): array
    {
        return array_map('intval', array_column(db_connect()->table('instructor_assignments')->where('offering_id', $offering)->get()->getResultArray(), 'instructor_user_id'));
    }

    private function tracked(?string $role = null): int
    {
        $builder = db_connect()->table('sis_staff_assignments')->where('institution_id', $this->institution);

        return ($role === null ? $builder : $builder->where('role', $role))->countAllResults();
    }

    private function actions(): array
    {
        return array_column(db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray(), 'action');
    }

    /*
    |--------------------------------------------------------------------------
    | Deans
    |--------------------------------------------------------------------------
    */

    public function testADeanIsAssignedToTheDepartmentAndGrantedTheRole(): void
    {
        $result = $this->sync("dean,I1,{$this->deptCode()},,,");

        $this->assertTrue($result->ok);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $this->counts($result));

        $active = $this->deans('active');

        $this->assertCount(1, $active);
        $this->assertSame($this->ivy(), (int) $active[0]['dean_user_id']);
        $this->assertTrue($this->hasGroup($this->ivy(), 'dean'), 'assigning grants the role');
        $this->assertSame(1, $this->tracked('dean'), 'the SIS supplied it, so it is remembered');
        $this->assertContains('structure.dean_assigned', $this->actions(), 'through the same service as the screens, so audited the same way');
    }

    public function testTheSisReplacesTheCurrentDeanOfTheDepartmentAndTheHistoryStays(): void
    {
        $hand = PeopleFactory::person('dean', 'Hand', 'Made', null, $this->institution);
        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $hand->id, $this->actor);

        $result = $this->sync("dean,I1,{$this->deptCode()},,,");

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'failed' => 0], $this->counts($result), 'a dean who is replaced is an update, not a creation');

        $all = $this->deans();

        $this->assertCount(2, $all, 'the earlier assignment is ended, not deleted');
        $this->assertSame('ended', $all[0]['status']);
        $this->assertNotNull($all[0]['ended_at']);
        $this->assertSame('active', $all[1]['status']);
        $this->assertSame($this->ivy(), (int) $all[1]['dean_user_id']);
        $this->assertTrue($this->hasGroup((int) $hand->id, 'dean'), 'ending a deanship does not take their roles away');
    }

    public function testAnotherDeansDeanshipOfThisPersonEndsWhenTheSisMovesThemToAnotherDepartment(): void
    {
        db_connect()->table('departments')->insert(['institution_id' => $this->institution, 'name' => 'Arts', 'code' => 'ARTS']);
        $arts = (int) db_connect()->insertID();
        (new DeanAssignmentService())->assign($this->institution, $arts, $this->ivy(), $this->actor);

        $this->sync("dean,I1,{$this->deptCode()},,,");

        $this->assertSame('ended', db_connect()->table('dean_assignments')->where('department_id', $arts)->get()->getRow('status'), 'a person is dean of one department');
        $this->assertCount(1, $this->deans('active'));
    }

    public function testTwoDeansForOneDepartmentInOneFileRefusesTheSecond(): void
    {
        $result = $this->sync("dean,I1,{$this->deptCode()},,,", "dean,I2,{$this->deptCode()},,,");

        $this->assertSame(1, $this->counts($result)['created']);
        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('has a dean in this file', $result->issues[0]['reason']);
        $this->assertSame($this->ivy(), (int) $this->deans('active')[0]['dean_user_id'], 'the first listed stays');
    }

    public function testOnePersonAsDeanOfTwoDepartmentsInOneFileRefusesTheSecond(): void
    {
        db_connect()->table('departments')->insert(['institution_id' => $this->institution, 'name' => 'Arts', 'code' => 'ARTS']);

        $result = $this->sync("dean,I1,{$this->deptCode()},,,", 'dean,I1,ARTS,,,');

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('dean of another department in this file', $result->issues[0]['reason']);
    }

    public function testAChairpersonCannotAlsoBeADean(): void
    {
        $this->giveRole($this->ivy(), 'chairperson');

        $result = $this->sync("dean,I1,{$this->deptCode()},,,");

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('cannot also be a chairperson', $result->issues[0]['reason'], 'the rule of the service, in its own words');
        $this->assertSame([], $this->deans('active'));
        $this->assertSame(0, $this->tracked());
    }

    /*
    |--------------------------------------------------------------------------
    | Chairpersons
    |--------------------------------------------------------------------------
    */

    public function testAChairpersonIsAssignedToTheSubDepartment(): void
    {
        $this->giveRole($this->ian(), 'chairperson');

        $result = $this->sync("chairperson,I2,,{$this->subCode()},,");

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $this->counts($result));
        $this->assertSame($this->ian(), (int) $this->chairs()[0]['chairperson_user_id']);
        $this->assertSame(1, $this->tracked('chairperson'));
        $this->assertContains('structure.chairperson_assigned', $this->actions());
    }

    public function testSeveralChairpersonsMayChairOneSubDepartmentAndOneMayChairSeveral(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        $this->giveRole($this->ida(), 'chairperson');
        db_connect()->table('sub_departments')->insert(['department_id' => $this->department, 'name' => 'Networks', 'code' => 'NET']);

        $result = $this->sync("chairperson,I2,,{$this->subCode()},,", "chairperson,I3,,{$this->subCode()},,", 'chairperson,I2,,NET,,');

        $this->assertSame(3, $this->counts($result)['created']);
        $this->assertCount(2, $this->chairs());
    }

    public function testAPersonWhoDoesNotHoldTheChairpersonRoleIsRefusedAndNeverGrantedIt(): void
    {
        $result = $this->sync("chairperson,I2,,{$this->subCode()},,");

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('does not hold the chairperson role', $result->issues[0]['reason']);
        $this->assertFalse($this->hasGroup($this->ian(), 'chairperson'), 'a privileged role is never granted by a file');
        $this->assertSame([], $this->chairs());
    }

    public function testASubDepartmentCodeSharedByTwoDepartmentsCannotSayWhichOne(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        db_connect()->table('departments')->insert(['institution_id' => $this->institution, 'name' => 'Arts', 'code' => 'ARTS']);
        db_connect()->table('sub_departments')->insert(['department_id' => (int) db_connect()->insertID(), 'name' => 'Other IT', 'code' => $this->subCode()]);

        $result = $this->sync("chairperson,I2,,{$this->subCode()},,");

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('more than one sub-department', $result->issues[0]['reason']);
        $this->assertSame([], $this->chairs());
    }

    public function testAnInactiveSubDepartmentRefusesItsChairpersonWithTheServicesOwnWords(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        db_connect()->table('sub_departments')->where('id', $this->sub)->update(['is_active' => 0]);

        $result = $this->sync("chairperson,I2,,{$this->subCode()},,");

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('is inactive', $result->issues[0]['reason']);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructors
    |--------------------------------------------------------------------------
    */

    public function testAnInstructorIsAttachedToTheSectionAndANewSectionIsCreatedInTheActiveTerm(): void
    {
        $existing = $this->offering('IT101'); // none yet: the first run only created the courses

        $this->assertNull($existing);

        $result = $this->sync('instructor,I1,,,C1,A', 'instructor,I2,,,C1,B', 'instructor,I3,,,C1,B');

        $this->assertSame(['created' => 3, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $this->counts($result));

        $a = $this->offering('IT101', 'A');
        $b = $this->offering('IT101', 'B');

        $this->assertNotNull($a, 'a taught section that did not exist is created');
        $this->assertNotNull($b);
        $this->assertSame([$this->ivy()], $this->teachers($a));
        $this->assertEqualsCanonicalizing([$this->ian(), $this->ida()], $this->teachers($b), 'a section can have several instructors');
        $this->assertSame(
            (int) db_connect()->table('academic_terms')->where(['institution_id' => $this->institution, 'status' => 'active'])->get()->getRow('id'),
            (int) db_connect()->table('course_offerings')->where('id', $a)->get()->getRow('term_id'),
            'in the active term, as enrolments do'
        );
        $this->assertSame(3, $this->tracked('instructor'));
    }

    public function testASectionWithNoSectionColumnIsSectionA(): void
    {
        $this->sync('instructor,I1,,,C1,');

        $this->assertNotNull($this->offering('IT101', 'A'));
    }

    public function testAnImportedInstructorIsNoLongerAttachedToNoCourse(): void
    {
        $this->assertSame(0, db_connect()->table('instructor_assignments')->countAllResults(), 'before: nobody teaches anything');

        $this->sync('instructor,I1,,,C1,A');

        $this->assertSame(1, db_connect()->table('instructor_assignments')->where('instructor_user_id', $this->ivy())->countAllResults());
        $this->assertContains('structure.offering_instructor_assigned', $this->actions());
    }

    public function testASectionThatIsClosedCannotGetAnInstructor(): void
    {
        $this->sync('instructor,I1,,,C1,A');
        $offering = (int) $this->offering('IT101', 'A');
        db_connect()->table('course_offerings')->where('id', $offering)->update(['status' => 'completed']);

        $result = $this->sync('instructor,I1,,,C1,A', 'instructor,I2,,,C1,A');

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('closed', $result->issues[0]['reason']);
        $this->assertSame([$this->ivy()], $this->teachers($offering));
    }

    /*
    |--------------------------------------------------------------------------
    | Running again, and what the SIS stops listing
    |--------------------------------------------------------------------------
    */

    public function testRunningTheSameFileAgainChangesNothing(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        $rows = ["dean,I1,{$this->deptCode()},,,", "chairperson,I2,,{$this->subCode()},,", 'instructor,I3,,,C1,A'];

        $this->sync(...$rows);
        $before = [count($this->deans()), count($this->chairs()), db_connect()->table('instructor_assignments')->countAllResults(), $this->tracked()];

        $again = $this->sync(...$rows);

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 3, 'failed' => 0], $this->counts($again));
        $this->assertSame($before, [count($this->deans()), count($this->chairs()), db_connect()->table('instructor_assignments')->countAllResults(), $this->tracked()]);
    }

    public function testWhatTheSisStopsListingIsEndedAndNeverDeleted(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        $this->sync("dean,I1,{$this->deptCode()},,,", "chairperson,I2,,{$this->subCode()},,", 'instructor,I3,,,C1,A');
        $offering = (int) $this->offering('IT101', 'A');

        $result = $this->sync();

        $this->assertSame(['created' => 0, 'updated' => 3, 'skipped' => 0, 'failed' => 0], $this->counts($result), 'three ended');

        $dean = $this->deans();
        $this->assertCount(1, $dean, 'the dean row stays');
        $this->assertSame('ended', $dean[0]['status']);

        $chair = db_connect()->table('chairperson_assignments')->where('sub_department_id', $this->sub)->get()->getResultArray();
        $this->assertCount(1, $chair, 'the chairperson row stays');
        $this->assertSame('revoked', $chair[0]['status']);

        $this->assertSame([], $this->teachers($offering), 'the instructor is unassigned');
        $this->assertContains('structure.offering_instructor_unassigned', $this->actions(), 'and that is on record');
        $this->assertContains('structure.dean_ended', $this->actions());
        $this->assertContains('structure.chairperson_revoked', $this->actions());
        $this->assertSame(0, $this->tracked(), 'nothing is tracked once it is over');
    }

    public function testAnAssignmentListedAgainAfterItEndedIsAssignedAgain(): void
    {
        $this->sync("dean,I1,{$this->deptCode()},,,");
        $this->sync();

        $result = $this->sync("dean,I1,{$this->deptCode()},,,");

        $this->assertSame(1, $this->counts($result)['created']);
        $this->assertCount(1, $this->deans('active'));
        $this->assertCount(2, $this->deans(), 'the history of the first stays');
    }

    public function testAnAssignmentMadeByHandIsNeverEndedByTheSync(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        $this->giveRole($this->ida(), 'chairperson');
        (new ChairAssignmentService())->assign($this->institution, $this->sub, $this->ida(), $this->actor);
        $handDean = PeopleFactory::person('dean', 'Hand', 'Made', null, $this->institution);
        $handTeacher = $this->ivy();
        $this->sync('instructor,I2,,,C1,A'); // creates the section
        $offering = (int) $this->offering('IT101', 'A');
        (new OfferingService())->assignInstructor($this->institution, $offering, $handTeacher, $this->actor);

        $this->sync("chairperson,I2,,{$this->subCode()},,", 'instructor,I2,,,C1,A');
        $result = $this->sync();

        $this->assertSame(2, $this->counts($result)['updated'], 'only the two the SIS itself supplied are ended');
        $this->assertCount(1, $this->chairs(), 'the chairperson made by hand stays');
        $this->assertSame($this->ida(), (int) $this->chairs()[0]['chairperson_user_id']);
        $this->assertSame([$handTeacher], $this->teachers($offering), 'so does the instructor added by hand');
        $this->assertNotNull($handDean);
    }

    public function testAnAssignmentThatAlreadyExistsByHandBecomesTheSisAndIsEndedWhenItIsNotListed(): void
    {
        (new ChairAssignmentService())->assign($this->institution, $this->sub, $this->giveChair($this->ian()), $this->actor);

        $first = $this->sync("chairperson,I2,,{$this->subCode()},,");

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'failed' => 0], $this->counts($first), 'adopted: it is now the SIS that supplies it');
        $this->assertSame(1, $this->tracked('chairperson'));

        $this->sync();

        $this->assertSame([], $this->chairs());
    }

    private function giveChair(int $userId): int
    {
        $this->giveRole($userId, 'chairperson');

        return $userId;
    }

    public function testNothingIsEndedWhenTheFileHadRowsThatCouldNotBeRead(): void
    {
        $this->sync("dean,I1,{$this->deptCode()},,,");

        SisFixture::drop($this->institution, 'staff_assignments', self::HEADER . "boss,I1,,,,\n");
        $result = $this->run_();

        $this->assertCount(1, $this->deans('active'), 'a row that could not be read might have been the one that kept the dean listed');
        $this->assertSame(1, $this->counts($result)['failed']);

        $reasons = implode(' ', array_column($result->issues, 'reason'));

        $this->assertStringContainsString('role must be one of', $reasons);
        $this->assertStringContainsString('Nothing was ended', $reasons);
        $this->assertSame(1, $this->tracked('dean'));
    }

    public function testNothingIsEndedWhenTheStaffFileIsNotProvidedAtAll(): void
    {
        $this->sync("dean,I1,{$this->deptCode()},,,");
        unlink(\App\Services\Sis\SisConnectors::folders($this->institution)['inbound'] . DIRECTORY_SEPARATOR . 'staff_assignments.csv');
        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\nS1,ann@example.com,Ann,Lee\n");

        $this->run_();

        $this->assertCount(1, $this->deans('active'), 'a dataset the SIS does not provide is left alone');
    }

    public function testAnAssignmentThatWasEndedByHandIsNotMistakenForOneThatIsStillThere(): void
    {
        $this->sync("dean,I1,{$this->deptCode()},,,");
        db_connect()->table('dean_assignments')->where('department_id', $this->department)->update(['status' => 'ended', 'active_marker' => null]);

        $result = $this->sync("dean,I1,{$this->deptCode()},,,");

        $this->assertSame(1, $this->counts($result)['updated'], 'the SIS wins: it is assigned again');
        $this->assertCount(1, $this->deans('active'));
    }

    public function testInstructorsOfAnEarlierTermAreLeftAsTheyWere(): void
    {
        $this->sync('instructor,I1,,,C1,A');
        $old = (int) $this->offering('IT101', 'A');

        // A new term opens; the earlier one is over.
        db_connect()->table('academic_terms')->where('institution_id', $this->institution)->update(['status' => 'completed']);
        db_connect()->table('academic_terms')->insert(['institution_id' => $this->institution, 'name' => 'Second Term', 'academic_year' => '2026-2027', 'start_date' => '2027-01-10', 'end_date' => '2027-05-20', 'status' => 'active']);

        $result = $this->sync('instructor,I1,,,C1,A');

        $this->assertSame(1, $this->counts($result)['created'], 'the new term gets its own section and assignment');
        $this->assertSame([$this->ivy()], $this->teachers($old), 'the earlier term is history and stays');

        $this->sync();

        $this->assertSame([$this->ivy()], $this->teachers($old), 'and is not ended when the SIS lists only the current term');
        $this->assertSame([], $this->teachers((int) db_connect()->table('course_offerings')->where('term_id !=', (int) db_connect()->table('course_offerings')->where('id', $old)->get()->getRow('term_id'))->get()->getRow('id')));
    }

    /*
    |--------------------------------------------------------------------------
    | Rows that cannot be applied
    |--------------------------------------------------------------------------
    */

    public function testEachRowThatCannotBeAppliedSaysWhy(): void
    {
        $result = $this->sync(
            'dean,NOBODY,' . $this->deptCode() . ',,,',
            'dean,I1,NOPE,,,',
            'chairperson,I2,,NOPE,,',
            'instructor,I3,,,C9,A',
        );

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 4], $this->counts($result));

        $reasons = array_column($result->issues, 'reason');

        $this->assertStringContainsString('NOBODY is not a known person', $reasons[0]);
        $this->assertStringContainsString('department_code "NOPE"', $reasons[1]);
        $this->assertStringContainsString('sub_department_code "NOPE"', $reasons[2]);
        $this->assertStringContainsString('The course C9 is not known', $reasons[3]);
        $this->assertSame(0, db_connect()->table('instructor_assignments')->countAllResults());
    }

    public function testRowsAreCheckedByTheParserWithTheirRowNumbers(): void
    {
        $result = $this->sync('dean,I1,,,,', 'chairperson,I2,,,,', 'instructor,I3,,,,', 'boss,I1,,,,', ',I1,,,,');

        $this->assertSame(5, $this->counts($result)['failed']);

        $reasons = array_column($result->issues, 'reason');

        $this->assertContains('department_code is required for a dean.', $reasons);
        $this->assertContains('sub_department_code is required for a chairperson.', $reasons);
        $this->assertContains('course_external_id is required for an instructor.', $reasons);
        $this->assertContains('role is empty.', $reasons);
        $this->assertSame('row 2', $result->issues[0]['item']);
    }

    public function testAnInstructorRowForSomeoneWhoIsNotAnInstructorCreatesNoSection(): void
    {
        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\nS1,ann@example.com,Ann,Lee\n");
        $this->run_();
        unlink(\App\Services\Sis\SisConnectors::folders($this->institution)['inbound'] . DIRECTORY_SEPARATOR . 'students.csv');

        $result = $this->sync('instructor,S1,,,C1,Z');

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('does not hold the instructor role', $result->issues[0]['reason']);
        $this->assertNull($this->offering('IT101', 'Z'), 'a refused row leaves nothing behind');
    }

    public function testAnInstructorRowNeedsAnActiveTermToPutTheSectionIn(): void
    {
        db_connect()->table('academic_terms')->where('institution_id', $this->institution)->update(['status' => 'completed']);

        $result = $this->sync('instructor,I1,,,C1,A');

        $this->assertSame(1, $this->counts($result)['failed']);
        $this->assertStringContainsString('no active term', $result->issues[0]['reason']);
    }

    public function testTheSameAssignmentListedTwiceIsRefusedTheSecondTime(): void
    {
        $result = $this->sync('instructor,I1,,,C1,A', 'instructor,I1,,,C1,a');

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $this->counts($result), 'the parser refuses the repeat, and a refused row is a failed row');
        $this->assertStringContainsString('Repeats row 2', $result->issues[0]['reason']);
    }

    /*
    |--------------------------------------------------------------------------
    | The lock
    |--------------------------------------------------------------------------
    */

    private function refused(callable $change): string
    {
        try {
            $change();
        } catch (StructureException $e) {
            return $e->getMessage();
        }

        $this->fail('The change should have been refused.');
    }

    public function testWhatTheSisSuppliedCannotBeChangedByHandWhileItsConnectionIsEnabled(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        $this->sync("dean,I1,{$this->deptCode()},,,", "chairperson,I2,,{$this->subCode()},,", 'instructor,I3,,,C1,A');

        $dean    = (int) $this->deans('active')[0]['id'];
        $chair   = (int) $this->chairs()[0]['id'];
        $offered = (int) $this->offering('IT101', 'A');
        $teach   = (int) db_connect()->table('instructor_assignments')->where('offering_id', $offered)->get()->getRow('id');

        $lock = DeanAssignmentService::SIS_LOCK;

        $this->assertSame($lock, $this->refused(fn () => (new DeanAssignmentService())->end($this->institution, $dean, $this->actor)));
        $this->assertSame(ChairAssignmentService::SIS_LOCK, $this->refused(fn () => (new ChairAssignmentService())->revoke($this->institution, $chair, $this->actor)));
        $this->assertSame(OfferingService::SIS_LOCK, $this->refused(fn () => (new OfferingService())->unassignInstructor($this->institution, $teach, $this->actor)));

        $other = PeopleFactory::person('dean', 'Hand', 'Made', null, $this->institution);

        $this->assertSame($lock, $this->refused(fn () => (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $other->id, $this->actor)), 'nor can a hand-made dean replace the SIS one');

        $this->assertCount(1, $this->deans('active'));
        $this->assertCount(1, $this->chairs());
        $this->assertSame([$this->ida()], $this->teachers($offered));

        $own = new SisOwnership();

        $this->assertTrue($own->managesAssignment('dean', $dean));
        $this->assertTrue($own->managesAssignment('chairperson', $chair));
        $this->assertTrue($own->managesAssignment('instructor', $teach));
        $this->assertSame([$chair => true], $own->managedAssignments('chairperson', $this->institution));
    }

    public function testTheLockEndsWhenTheConnectionIsSwitchedOff(): void
    {
        $this->giveRole($this->ian(), 'chairperson');
        $this->sync("dean,I1,{$this->deptCode()},,,", "chairperson,I2,,{$this->subCode()},,");
        $chair = (int) $this->chairs()[0]['id'];

        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);

        (new ChairAssignmentService())->revoke($this->institution, $chair, $this->actor);
        (new DeanAssignmentService())->end($this->institution, (int) $this->deans('active')[0]['id'], $this->actor);

        $this->assertSame([], $this->chairs());
        $this->assertSame([], $this->deans('active'));
        $this->assertFalse((new SisOwnership())->managesAssignment('chairperson', $chair));
    }

    public function testAnAssignmentMadeByHandIsNotLockedEvenWhileTheConnectionIsEnabled(): void
    {
        $this->giveRole($this->ida(), 'chairperson');
        $id = (new ChairAssignmentService())->assign($this->institution, $this->sub, $this->ida(), $this->actor);

        $this->assertFalse((new SisOwnership())->managesAssignment('chairperson', $id));

        (new ChairAssignmentService())->revoke($this->institution, $id, $this->actor);

        $this->assertSame([], $this->chairs());
    }

    public function testTakingTheDeanRoleAwayStillEndsTheDeanshipWhoeverSuppliedIt(): void
    {
        $this->sync("dean,I1,{$this->deptCode()},,,");

        $ended = (new DeanAssignmentService())->endForPerson($this->ivy(), $this->actor);

        $this->assertTrue($ended, 'removing the role or deactivating the person is not a hand edit of the assignment');
        $this->assertSame([], $this->deans('active'));
    }

    public function testAnotherInstitutionsAssignmentsAreNeitherSeenNorEnded(): void
    {
        $this->sync("dean,I1,{$this->deptCode()},,,");

        $other = SisFixture::institution('OTHR');
        SisFixture::connect($other, $this->actor);
        SisFixture::priorSuccess($other);
        $dept = (int) db_connect()->table('departments')->where('institution_id', $other)->get()->getRow('id');
        $dean = PeopleFactory::person('dean', 'Other', 'Dean', null, $other);
        (new DeanAssignmentService())->assign($other, $dept, (int) $dean->id, $this->actor);
        db_connect()->table('sis_staff_assignments')->insert(['institution_id' => $other, 'role' => 'dean', 'person_user_id' => (int) $dean->id, 'target_id' => $dept, 'local_id' => (int) db_connect()->table('dean_assignments')->where('department_id', $dept)->get()->getRow('id')]);

        $this->sync(); // ends this institution's dean only

        $this->assertSame([], $this->deans('active'));
        $this->assertSame(1, db_connect()->table('dean_assignments')->where(['department_id' => $dept, 'status' => 'active'])->countAllResults());
        $this->assertSame(1, db_connect()->table('sis_staff_assignments')->where('institution_id', $other)->countAllResults());
    }

    public function testTheRunIsRecordedWithTheStaffCounts(): void
    {
        $result = $this->sync("dean,I1,{$this->deptCode()},,,", 'boss,I2,,,,');

        $run = db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray();

        $this->assertSame('succeeded', $run['status']);
        $this->assertStringContainsString('"staff_assignments"', (string) $run['details']);
        $this->assertSame(1, (int) $run['created_count']);
        $this->assertSame(1, (int) $run['failed_count']);
    }
}
