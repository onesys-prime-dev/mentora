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
        'sis_runs', 'sis_links', 'sis_connections', 'feature_flag_overrides', 'enrollments', 'course_offerings', 'courses',
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
