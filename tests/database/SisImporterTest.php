<?php

declare(strict_types=1);

use App\Services\Sis\SisImporter;
use App\Services\Sis\SisOwnership;
use App\Services\Sis\SisRunLog;
use App\Services\Users\UserAdminService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The inbound import against a real database: what is created, updated and
 * skipped, how records are matched, what is refused and why, and when a run
 * is allowed. (The all-or-nothing and dry-run guarantees are in
 * SisImporterRollbackTest, which needs a real outer transaction.)
 *
 * @internal
 */
final class SisImporterTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private string $folder;

    private int $institution;

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
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function run_(bool $dry = false, string $trigger = SisRunLog::MANUAL): \App\Services\Sis\SisImportResult
    {
        return (new SisImporter())->run($this->institution, $this->actor, $dry, $trigger, $this->now);
    }

    /** @return array<string, mixed>|null */
    private function user(string $email): ?array
    {
        return db_connect()->table('auth_identities')->select('user_id')->where('secret', $email)->get()->getRowArray();
    }

    private function command(string $line): string
    {
        $this->setUpStreamFilterTrait();
        $this->resetStreamFilterBuffer();

        try {
            command($line);

            return $this->getStreamFilterBuffer();
        } finally {
            $this->tearDownStreamFilterTrait();
        }
    }

    private function dropPeopleOnly(): void
    {
        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\nS1,ann@example.com,Ann,Lee\n");
    }

    /*
    |--------------------------------------------------------------------------
    | What a run does
    |--------------------------------------------------------------------------
    */

    public function testARunCreatesPeopleCoursesAndEnrolmentsAndCountsEachDataset(): void
    {
        SisFixture::dropAll($this->institution);

        $result = $this->run_();

        $this->assertTrue($result->ok);
        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $result->datasets['students']);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $result->datasets['instructors']);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $result->datasets['courses']);
        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $result->datasets['enrollments']);
        $this->assertSame(['created' => 6, 'updated' => 0, 'skipped' => 0, 'failed' => 3], $result->totals());
        $this->assertStringContainsString('created 6', $result->message);

        $db  = db_connect();
        $ann = (int) $this->user('ann@example.com')['user_id'];

        $this->assertSame(1, $db->table('auth_groups_users')->where(['user_id' => $ann, 'group' => 'student'])->countAllResults());
        $this->assertSame(1, $db->table('institution_users')->where(['user_id' => $ann, 'institution_id' => $this->institution])->countAllResults());
        $this->assertSame('2026-001', $db->table('user_profiles')->where('user_id', $ann)->get()->getRowArray()['student_number']);
        $this->assertSame(1, (int) $db->table('users')->where('id', $ann)->get()->getRowArray()['active']);
        $this->assertSame(1, $db->table('auth_groups_users')->where('group', 'instructor')->join('institution_users', 'institution_users.user_id = auth_groups_users.user_id')->where('institution_id', $this->institution)->countAllResults());

        $course = $db->table('courses')->where('course_code', 'IT101')->get()->getRowArray();
        $this->assertSame('Intro to Computing', $course['course_title']);

        $offerings = $db->table('course_offerings')->where('course_id', $course['id'])->orderBy('section_code')->get()->getResultArray();
        $this->assertSame(['A', 'B'], array_column($offerings, 'section_code'));

        $this->assertSame('enrolled', $db->table('enrollments')->where(['offering_id' => $offerings[0]['id'], 'student_user_id' => $ann])->get()->getRowArray()['status']);
        $this->assertSame('dropped', $db->table('enrollments')->where('offering_id', $offerings[1]['id'])->get()->getRowArray()['status']);
        $this->assertSame(4, $db->table('sis_links')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testEachNotImportedRowIsListedWithItsReason(): void
    {
        SisFixture::dropAll($this->institution);

        $issues = $this->run_()->issues;
        $byItem = array_column($issues, 'reason', 'item');

        $this->assertCount(3, $issues);
        $this->assertStringContainsString('email is not a valid address', $byItem['row 4']);
        $this->assertStringContainsString('program_code is required', $byItem['C2 (row 3)']);
        $this->assertStringContainsString('C9 is not known', $byItem['S1 in C9 (row 4)']);
    }

    public function testTheRunIsRecordedWithItsCountsAndDetails(): void
    {
        SisFixture::dropAll($this->institution);

        $result = $this->run_();
        $row    = db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray();

        $this->assertSame('succeeded', $row['status']);
        $this->assertSame('inbound', $row['direction']);
        $this->assertSame('manual', $row['trigger']);
        $this->assertSame(0, (int) $row['dry_run']);
        $this->assertSame([6, 0, 0, 3], [(int) $row['created_count'], (int) $row['updated_count'], (int) $row['skipped_count'], (int) $row['failed_count']]);
        $this->assertSame('2026-10-10 09:00:00', $row['started_at']);

        $details = json_decode((string) $row['details'], true);
        $this->assertCount(3, $details['issues']);
        $this->assertFalse($details['truncated']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.import_run')->countAllResults());
    }

    public function testRunningAgainWithNothingNewSkipsEverythingAndDuplicatesNothing(): void
    {
        SisFixture::dropAll($this->institution);
        $this->run_();
        $people = db_connect()->table('users')->countAllResults();

        $again = $this->run_();

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 6, 'failed' => 3], $again->totals());
        $this->assertSame($people, db_connect()->table('users')->countAllResults());
        $this->assertSame(1, db_connect()->table('courses')->where('course_code', 'IT101')->countAllResults());
    }

    public function testWhatTheSisChangesIsUpdatedAndTheSisWins(): void
    {
        SisFixture::dropAll($this->institution);
        $this->run_();

        $ann = (int) $this->user('ann@example.com')['user_id'];
        db_connect()->table('user_profiles')->where('user_id', $ann)->update(['first_name' => 'Edited locally']);
        db_connect()->table('courses')->where('course_code', 'IT101')->update(['course_title' => 'Edited locally']);

        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name,student_number\nS1,anna.lee@example.com,Ann,Lee,2026-001\nS2,ben@example.com,Ben,Ng,2026-002\n");
        SisFixture::drop($this->institution, 'courses', "external_id,course_code,course_title,units,program_code\nC1,IT101,Intro to Computing,4,BSIT\n");
        SisFixture::drop($this->institution, 'enrollments', "course_external_id,student_external_id,section,status\nC1,S1,,completed\n");

        $result = $this->run_();

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 1, 'failed' => 0], $result->datasets['students']);
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'failed' => 0], $result->datasets['courses']);
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'failed' => 0], $result->datasets['enrollments']);

        $this->assertNotNull($this->user('anna.lee@example.com'));
        $this->assertNull($this->user('ann@example.com'));
        $this->assertSame('Ann', db_connect()->table('user_profiles')->where('user_id', $ann)->get()->getRowArray()['first_name']);

        $course = db_connect()->table('courses')->where('course_code', 'IT101')->get()->getRowArray();
        $this->assertSame('Intro to Computing', $course['course_title']);
        $this->assertEqualsWithDelta(4.0, (float) $course['units'], 0.01);
    }

    public function testADatasetThatIsNotProvidedIsLeftAlone(): void
    {
        SisFixture::dropAll($this->institution);
        $this->run_();

        unlink(\App\Services\Sis\SisConnectors::folders($this->institution)['inbound'] . DIRECTORY_SEPARATOR . 'enrollments.csv');

        $result = $this->run_();

        $this->assertArrayNotHasKey('enrollments', $result->datasets);
        $this->assertSame(2, db_connect()->table('enrollments')->countAllResults());
    }

    public function testPeopleTheSisNoLongerListsAreNotRemoved(): void
    {
        SisFixture::dropAll($this->institution);
        $this->run_();

        $this->dropPeopleOnly();
        $this->run_();

        $this->assertNotNull($this->user('ben@example.com'));
    }

    /*
    |--------------------------------------------------------------------------
    | Matching and conflicts
    |--------------------------------------------------------------------------
    */

    public function testAnExistingAccountOfTheInstitutionWithTheRoleIsLinkedNotDuplicated(): void
    {
        $existing = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.com', $this->institution);
        $this->dropPeopleOnly();

        $result = $this->run_();

        $this->assertSame(0, $result->datasets['students']['created']);
        $this->assertSame(1, db_connect()->table('sis_links')->where(['entity' => 'person', 'local_id' => $existing->id])->countAllResults());
        $this->assertSame(1, db_connect()->table('auth_identities')->where('secret', 'ann@example.com')->countAllResults());
    }

    public function testAnAccountOfAnotherInstitutionIsNeverTakenOver(): void
    {
        $other = PeopleFactory::institution('OTHERX');
        PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.com', $other);
        $this->dropPeopleOnly();

        $result = $this->run_();

        $this->assertSame(1, $result->datasets['students']['failed']);
        $this->assertStringContainsString('already exists', $result->issues[0]['reason']);
        $this->assertSame(0, db_connect()->table('institution_users')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testAnAccountWithTheWrongRoleIsRefused(): void
    {
        PeopleFactory::person('instructor', 'Ann', 'Lee', 'ann@example.com', $this->institution);
        $this->dropPeopleOnly();

        $this->assertSame(1, $this->run_()->datasets['students']['failed']);
    }

    public function testAnAdministratorAccountIsNeverTakenOver(): void
    {
        PeopleFactory::person('admin', 'Ann', 'Lee', 'ann@example.com', $this->institution);
        $this->dropPeopleOnly();

        $this->assertSame(1, $this->run_()->datasets['students']['failed']);
    }

    public function testAnEmailChangeOntoAnotherAccountIsRefused(): void
    {
        SisFixture::dropAll($this->institution);
        $this->run_();
        PeopleFactory::person('student', 'Taken', 'Mail', 'taken@example.com', $this->institution);

        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\nS1,taken@example.com,Ann,Lee\n");

        $result = $this->run_();

        $this->assertSame(1, $result->datasets['students']['failed']);
        $this->assertNotNull($this->user('ann@example.com'));
    }

    public function testTwoSisRecordsCannotShareOneAccount(): void
    {
        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\nS1,ann@example.com,Ann,Lee\n");
        $this->run_();

        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\nS1,ann@example.com,Ann,Lee\nS9,ann@example.com,Ann,Lee\n");
        $result = $this->run_();

        $this->assertSame(1, $result->datasets['students']['failed']);
        $this->assertStringContainsString('another SIS record', $result->issues[0]['reason']);
    }

    public function testAStudentIsRefusedWhenThePlanHasNoSeatLeft(): void
    {
        PeopleFactory::subscribe($this->institution, PeopleFactory::plan('OneSeat', 1));

        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name\nS1,ann@example.com,Ann,Lee\nS2,ben@example.com,Ben,Ng\n");

        $result = $this->run_();

        $this->assertSame(1, $result->datasets['students']['created']);
        $this->assertSame(1, $result->datasets['students']['failed']);
        $this->assertNull($this->user('ben@example.com'));
    }

    public function testAnExistingCourseWithTheSameCodeIsAdoptedNotDuplicated(): void
    {
        $program = (int) db_connect()->table('programs')->where('code', SisFixture::PROGRAM)->get()->getRowArray()['id'];
        db_connect()->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Old title', 'units' => 3]);

        SisFixture::drop($this->institution, 'courses', "external_id,course_code,course_title,units,program_code\nC1,IT101,New title,3,BSIT\n");

        $result = $this->run_();

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'failed' => 0], $result->datasets['courses']);
        $this->assertSame(1, db_connect()->table('courses')->where('course_code', 'IT101')->countAllResults());
        $this->assertSame('New title', db_connect()->table('courses')->where('course_code', 'IT101')->get()->getRowArray()['course_title']);
    }

    public function testAProgramOfAnotherInstitutionIsNotUsable(): void
    {
        SisFixture::drop($this->institution, 'courses', "external_id,course_code,course_title,units,program_code\nC1,IT101,Intro,3,NOPE\n");

        $result = $this->run_();

        $this->assertSame(1, $result->datasets['courses']['failed']);
        $this->assertStringContainsString('not a program of this institution', $result->issues[0]['reason']);
    }

    public function testChangingACourseCodeOntoAnotherCoursesCodeIsRefused(): void
    {
        SisFixture::drop($this->institution, 'courses', "external_id,course_code,course_title,units,program_code\nC1,IT101,One,3,BSIT\nC2,IT102,Two,3,BSIT\n");
        $this->run_();

        SisFixture::drop($this->institution, 'courses', "external_id,course_code,course_title,units,program_code\nC2,IT101,Two,3,BSIT\n");
        $result = $this->run_();

        $this->assertSame(1, $result->datasets['courses']['failed']);
        $this->assertSame('IT102', db_connect()->table('courses')->where('course_title', 'Two')->get()->getRowArray()['course_code']);
    }

    public function testEnrolmentsFailWithoutAnActiveTerm(): void
    {
        db_connect()->table('academic_terms')->where('institution_id', $this->institution)->update(['status' => 'completed']);
        SisFixture::dropAll($this->institution);

        $result = $this->run_();

        $this->assertSame(0, $result->datasets['enrollments']['created']);
        $this->assertSame(3, $result->datasets['enrollments']['failed']);
        $this->assertSame(0, db_connect()->table('enrollments')->countAllResults());
    }

    public function testAnEnrolmentOfAnInstructorIsRefused(): void
    {
        SisFixture::dropAll($this->institution);
        SisFixture::drop($this->institution, 'enrollments', "course_external_id,student_external_id\nC1,I1\n");

        $result = $this->run_();

        $this->assertSame(1, $result->datasets['enrollments']['failed']);
        $this->assertStringContainsString('not a known student', $result->issues[array_key_last($result->issues)]['reason']);
    }

    public function testALinkToAPersonWhoIsGoneIsReplacedByANewAccount(): void
    {
        $this->dropPeopleOnly();
        $this->run_();

        $first = (int) $this->user('ann@example.com')['user_id'];
        db_connect()->table('institution_users')->where('user_id', $first)->delete();
        db_connect()->table('auth_identities')->where('user_id', $first)->update(['secret' => 'gone@example.com']);

        $result = $this->run_();

        $this->assertSame(1, $result->datasets['students']['created']);
        $this->assertNotSame($first, (int) $this->user('ann@example.com')['user_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | When a run is allowed, and what it does when it cannot start
    |--------------------------------------------------------------------------
    */

    public function testARunNeedsTheFeature(): void
    {
        db_connect()->table('feature_flag_overrides')->where('institution_id', $this->institution)->delete();
        SisFixture::dropAll($this->institution);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('feature is off');

        $this->run_();
    }

    public function testARunNeedsASavedConnection(): void
    {
        $other = SisFixture::institution('NOCONN');
        (new \App\Services\Flags\FeatureFlagAdmin())->setForInstitution($other, 'sis.sync', 'on', 'Pilot', null, $this->actor);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Save the connection settings');

        (new SisImporter())->run($other, $this->actor, true);
    }

    public function testARealRunNeedsTheConnectionEnabledButAPreviewDoesNot(): void
    {
        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);
        SisFixture::dropAll($this->institution);

        $this->assertTrue($this->run_(true)->dryRun);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not enabled');

        $this->run_();
    }

    public function testOnlyAnActiveInstitutionIsSynced(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        SisFixture::dropAll($this->institution);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('active institution');

        $this->run_();
    }

    public function testTheFirstRealRunNeedsAPreviewFirst(): void
    {
        db_connect()->table('sis_runs')->emptyTable();
        SisFixture::dropAll($this->institution);

        try {
            $this->run_();
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('preview', $e->getMessage());
        }

        // A connection test is not a preview.
        db_connect()->table('sis_runs')->insert(['institution_id' => $this->institution, 'direction' => 'inbound', 'trigger' => 'test', 'status' => 'succeeded', 'started_at' => '2026-10-01 08:00:00']);

        $this->expectException(InvalidArgumentException::class);
        $this->run_();
    }

    public function testAnUnknownInstitutionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SisImporter())->run(999999, $this->actor, true);
    }

    public function testAFileThatCannotBeReadFailsTheRunBeforeAnythingIsWrittenAndTellsTheAdministrators(): void
    {
        SisFixture::drop($this->institution, 'students', "external_id,first_name\nS1,Ann\n");
        SisFixture::drop($this->institution, 'courses', SisFixture::COURSES);

        $result = $this->run_();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('missing the column', $result->message);
        $this->assertSame(0, db_connect()->table('sis_links')->countAllResults());
        $this->assertSame(0, db_connect()->table('courses')->countAllResults());
        $this->assertSame('failed', db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray()['status']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.import_failed')->countAllResults());
        $this->assertSame(1, db_connect()->table('notifications')->where('type', 'sis.sync_failed')->where('user_id', $this->actor->id)->countAllResults());
    }

    public function testAFailedPreviewDoesNotNotify(): void
    {
        SisFixture::drop($this->institution, 'students', "external_id,first_name\nS1,Ann\n");

        $this->assertFalse($this->run_(true)->ok);
        $this->assertSame(0, db_connect()->table('notifications')->where('type', 'sis.sync_failed')->countAllResults());
    }

    public function testAnEmptyInboundFolderFailsTheRun(): void
    {
        $result = $this->run_();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('has not provided any', $result->message);
    }

    public function testTheListOfIssuesIsCappedButTheCountsAreNot(): void
    {
        $rows = "external_id,email,first_name,last_name\n";

        for ($i = 1; $i <= SisImporter::MAX_ISSUES + 5; $i++) {
            $rows .= "S{$i},bad{$i},A,B\n";
        }

        SisFixture::drop($this->institution, 'students', $rows);

        $result = $this->run_();
        $row    = db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray();
        $detail = json_decode((string) $row['details'], true);

        $this->assertSame(SisImporter::MAX_ISSUES + 5, $result->datasets['students']['failed']);
        $this->assertCount(SisImporter::MAX_ISSUES, $detail['issues']);
        $this->assertTrue($detail['truncated']);
    }

    /*
    |--------------------------------------------------------------------------
    | The SIS owns what it supplies
    |--------------------------------------------------------------------------
    */

    public function testAPersonTheSisSuppliedCannotBeEditedByHandWhileSyncIsOn(): void
    {
        $this->dropPeopleOnly();
        $this->run_();

        $ann = (int) $this->user('ann@example.com')['user_id'];

        $this->assertTrue((new SisOwnership())->managesPerson($ann));

        try {
            (new UserAdminService())->update($ann, ['first_name' => 'Anna', 'last_name' => 'Lee', 'email' => 'ann@example.com'], $this->actor);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('student information system', $e->getMessage());
        }

        $this->assertSame('Ann', db_connect()->table('user_profiles')->where('user_id', $ann)->get()->getRowArray()['first_name']);
    }

    public function testTheLockEndsWhenTheConnectionIsSwitchedOff(): void
    {
        $this->dropPeopleOnly();
        $this->run_();
        $ann = (int) $this->user('ann@example.com')['user_id'];

        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);

        $this->assertFalse((new SisOwnership())->managesPerson($ann));

        (new UserAdminService())->update($ann, ['first_name' => 'Anna', 'last_name' => 'Lee', 'email' => 'ann@example.com'], $this->actor);

        $this->assertSame('Anna', db_connect()->table('user_profiles')->where('user_id', $ann)->get()->getRowArray()['first_name']);
    }

    public function testSomeoneTheSisNeverSuppliedIsNotLocked(): void
    {
        $local = PeopleFactory::person('student', 'Local', 'Person', 'local@example.com', $this->institution);

        $this->assertFalse((new SisOwnership())->managesPerson((int) $local->id));
    }

    /*
    |--------------------------------------------------------------------------
    | The scheduled command
    |--------------------------------------------------------------------------
    */

    public function testTheCommandImportsEveryEnabledInstitutionAndSkipsTheOnesThatCannotRun(): void
    {
        SisFixture::dropAll($this->institution);

        $blocked = SisFixture::institution('BLOCKED');
        SisFixture::connect($blocked, $this->actor); // no preview yet, so it is skipped

        $output = $this->command('sis:sync');

        $this->assertStringContainsString("Institution {$this->institution}: created 6", $output);
        $this->assertStringContainsString("Institution {$blocked}: skipped", $output);
        $this->assertSame('scheduled', db_connect()->table('sis_runs')->where('institution_id', $this->institution)->orderBy('id', 'desc')->get()->getRowArray()['trigger']);
    }

    public function testTheCommandCanBeLimitedToOneInstitutionAndPreviewsWithDryRun(): void
    {
        SisFixture::dropAll($this->institution);

        $output = $this->command('sis:sync --institution ' . $this->institution . ' --dry-run');

        $this->assertStringContainsString("Institution {$this->institution}: Preview", $output);
    }

    public function testTheCommandSaysSoWhenNothingIsEnabled(): void
    {
        db_connect()->table('sis_connections')->update(['enabled' => 0]);

        $this->assertStringContainsString('No institution has an enabled', $this->command('sis:sync'));
    }
}
