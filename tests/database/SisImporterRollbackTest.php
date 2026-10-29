<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use App\Services\Sis\SisImporter;
use App\Services\Sis\SisRunLog;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * "All or nothing" for the SIS import: a preview writes nothing yet reports
 * exactly what a real run then does, and a real run that fails part-way
 * leaves no partial data.
 *
 * Like OnboardingRollbackTest this class does not run inside a rolled-back
 * test transaction: the import's own transaction must be the outermost one
 * for its rollback to be real.
 *
 * @internal
 */
final class SisImporterRollbackTest extends DatabaseTestCase
{
    protected bool $isolateTests = false;

    /** Touched by these tests; emptied with foreign keys off. */
    private const TABLES = [
        'sis_runs', 'sis_links', 'sis_staff_assignments', 'sis_class_schedules', 'course_schedules', 'dean_assignments', 'chairperson_assignments', 'instructor_assignments',
        'sis_connections', 'feature_flag_overrides', 'enrollments', 'course_offerings', 'courses',
        'academic_terms', 'programs', 'sub_departments', 'departments', 'institution_users', 'user_profiles',
        'auth_groups_users', 'auth_identities', 'users', 'institutions', 'audit_logs', 'notifications',
    ];

    private string $folder;

    private int $institution;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->folder      = SisFixture::useFolder();
        $this->institution = SisFixture::institution();
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@example.com');

        SisFixture::connect($this->institution, $this->actor);
        SisFixture::dropAll($this->institution);
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);
        $this->wipe();

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function wipe(): void
    {
        $db = db_connect();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $db->query('TRUNCATE TABLE `' . $table . '`');
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return array<string, int> row counts of everything an import touches */
    private function snapshot(): array
    {
        $counts = [];

        foreach (['users', 'auth_identities', 'auth_groups_users', 'user_profiles', 'institution_users', 'courses', 'course_offerings', 'enrollments', 'sis_links'] as $table) {
            $counts[$table] = db_connect()->table($table)->countAllResults();
        }

        return $counts;
    }

    public function testAPreviewOfStaffAssignmentsWritesNothingAndItsCountsAreWhatTheRealRunThenDoes(): void
    {
        // People and courses go in first, for real, so the staff rows have someone and something to refer to.
        (new SisImporter())->run($this->institution, $this->actor, true);
        $this->assertTrue((new SisImporter())->run($this->institution, $this->actor, false)->ok);

        $dept = (string) db_connect()->table('departments')->where('institution_id', $this->institution)->get()->getRow('code');
        SisFixture::drop($this->institution, 'staff_assignments', "role,person_external_id,department_code,sub_department_code,course_external_id,section\n"
            . "dean,I1,{$dept},,,\n"
            . "instructor,I1,,,C1,A\n"
            . "instructor,I1,,,C1,B\n");

        $tables = ['dean_assignments', 'instructor_assignments', 'sis_staff_assignments', 'course_offerings', 'auth_groups_users'];
        $count  = static fn (): array => array_combine($tables, array_map(static fn (string $t): int => db_connect()->table($t)->countAllResults(), $tables));

        $before  = $count();
        $preview = (new SisImporter())->run($this->institution, $this->actor, true);

        $this->assertTrue($preview->ok);
        $this->assertSame(['created' => 3, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'ended' => 0], $preview->datasets['staff_assignments']);
        $this->assertSame($before, $count(), 'a preview must assign nothing, create no section, grant no role and track nothing');

        $real = (new SisImporter())->run($this->institution, $this->actor, false);

        $this->assertSame($preview->datasets['staff_assignments'], $real->datasets['staff_assignments'], 'what the preview said is what the run does');
        $this->assertSame(1, db_connect()->table('dean_assignments')->where('status', 'active')->countAllResults());
        $this->assertSame(2, db_connect()->table('instructor_assignments')->countAllResults());
        $this->assertSame(3, db_connect()->table('sis_staff_assignments')->countAllResults());
    }

    public function testAPreviewOfClassTimesWritesNothingAndItsCountsAreWhatTheRealRunThenDoes(): void
    {
        $header = "course_external_id,section,day_of_week,start_time,end_time,room,delivery_mode\n";

        (new SisImporter())->run($this->institution, $this->actor, true);
        SisFixture::drop($this->institution, 'class_schedules', $header . "C1,A,mon,09:00,10:00,Rm 1,\n");
        $this->assertTrue((new SisImporter())->run($this->institution, $this->actor, false)->ok);

        // Section A is no longer listed (its times would go); section B is new (it would be created, with times).
        SisFixture::drop($this->institution, 'class_schedules', $header . "C1,B,tue,09:00,10:00,,\n");

        $tables = ['course_schedules', 'sis_class_schedules', 'course_offerings'];
        $count  = static fn (): array => array_combine($tables, array_map(static fn (string $t): int => db_connect()->table($t)->countAllResults(), $tables));

        $before  = $count();
        $preview = (new SisImporter())->run($this->institution, $this->actor, true);

        $this->assertTrue($preview->ok);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'removed' => 1], $preview->datasets['class_schedules']);
        $this->assertSame($before, $count(), 'a preview must set no times, clear none, create no section and track nothing');

        $real = (new SisImporter())->run($this->institution, $this->actor, false);

        $this->assertSame($preview->datasets['class_schedules'], $real->datasets['class_schedules'], 'what the preview said is what the run does');
        $this->assertSame(['tue'], array_column(db_connect()->table('course_schedules')->get()->getResultArray(), 'day_of_week'));
        $this->assertSame(1, db_connect()->table('sis_class_schedules')->countAllResults());
    }

    public function testAPreviewWritesNothingAndItsCountsAreWhatTheRealRunThenDoes(): void
    {
        $before = $this->snapshot();

        $preview = (new SisImporter())->run($this->institution, $this->actor, true);

        $this->assertTrue($preview->ok);
        $this->assertTrue($preview->dryRun);
        $this->assertStringStartsWith('Preview, nothing was written', $preview->message);
        $this->assertSame($before, $this->snapshot(), 'a preview must change nothing');
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'sis.import_run')->countAllResults());

        $row = db_connect()->table('sis_runs')->where('id', $preview->runId)->get()->getRowArray();
        $this->assertSame(1, (int) $row['dry_run']);
        $this->assertSame('succeeded', $row['status']);
        $this->assertSame(6, (int) $row['created_count']);

        $real = (new SisImporter())->run($this->institution, $this->actor, false);

        $this->assertTrue($real->ok);
        $this->assertSame($preview->totals(), $real->totals());
        $this->assertSame($preview->datasets, $real->datasets);
        $this->assertGreaterThan($before['users'], $this->snapshot()['users']);
    }

    public function testARealRunThatFailsPartWayLeavesNothingBehindAndSaysSo(): void
    {
        (new SisImporter())->run($this->institution, $this->actor, true); // the required preview
        $before = $this->snapshot();

        // The audit entry is the last thing a real run writes; making it fail
        // makes the run fail after every person, course and enrolment was written.
        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                if ($action === 'sis.import_run') {
                    throw new RuntimeException('audit down');
                }

                return parent::log($action, $actor, $details);
            }
        });

        $result = (new SisImporter())->run($this->institution, $this->actor, false, SisRunLog::MANUAL);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('nothing was changed', $result->message);
        $this->assertSame($before, $this->snapshot(), 'no partial data');

        $row = db_connect()->table('sis_runs')->where('id', $result->runId)->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertSame(0, (int) $row['created_count']);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.import_failed')->countAllResults());
        $this->assertSame(1, db_connect()->table('notifications')->where('type', 'sis.sync_failed')->countAllResults());
    }

    public function testAfterAFailedRunTheNextOneStartsClean(): void
    {
        (new SisImporter())->run($this->institution, $this->actor, true);

        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                if ($action === 'sis.import_run') {
                    throw new RuntimeException('audit down');
                }

                return parent::log($action, $actor, $details);
            }
        });
        (new SisImporter())->run($this->institution, $this->actor, false);

        Services::resetSingle('auditLogger');

        $result = (new SisImporter())->run($this->institution, $this->actor, false);

        $this->assertTrue($result->ok);
        $this->assertSame(6, $result->totals()['created']);
    }
}
