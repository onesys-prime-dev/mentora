<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use App\Services\Sis\SisGradeExporter;
use App\Services\Sis\SisRunLog;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\FakeSisConnector;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * A crash between "the SIS has it" and "Mentora recorded it" must not lead to
 * a duplicate: nothing is recorded as sent, so the next run sends the same
 * batch, with the same key, and the SIS replaces it.
 *
 * Like OnboardingRollbackTest this class does not run inside a rolled-back
 * test transaction, so the rollback under test really happens.
 *
 * @internal
 */
final class SisGradeExporterRollbackTest extends DatabaseTestCase
{
    protected bool $isolateTests = false;

    private const TABLES = [
        'sis_grade_exports', 'gradebook_records', 'sis_runs', 'sis_links', 'sis_connections', 'feature_flag_overrides',
        'course_offerings', 'courses', 'academic_terms', 'programs', 'sub_departments', 'departments',
        'institution_users', 'user_profiles', 'auth_groups_users', 'auth_identities', 'users', 'institutions',
        'audit_logs', 'notifications',
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
        $this->institution = SisFixture::institution('RB');
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@example.com');

        SisFixture::connect($this->institution, $this->actor);

        $course = SisFixture::course($this->institution);
        $ann    = SisFixture::student($this->institution, 'S1', 'ann@example.com');

        SisFixture::grade($course['offering'], $ann, 88.5);
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

    public function testACrashAfterDeliveryRecordsNothingAndTheRetryReplacesTheDelivery(): void
    {
        $sis = new FakeSisConnector();

        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                if ($action === 'sis.grades_sent') {
                    throw new RuntimeException('audit down');
                }

                return parent::log($action, $actor, $details);
            }
        });

        $first = (new SisGradeExporter(null, null, $sis))->run($this->institution, $this->actor, false, SisRunLog::MANUAL);

        $this->assertFalse($first->ok);
        $this->assertStringContainsString('replaces it', $first->message);
        $this->assertSame(0, db_connect()->table('sis_grade_exports')->countAllResults(), 'nothing recorded as sent');
        $this->assertCount(1, $sis->deliveries, 'but the SIS did get it');

        $key = array_key_first($sis->deliveries);

        Services::resetSingle('auditLogger');

        $second = (new SisGradeExporter(null, null, $sis))->run($this->institution, $this->actor, false, SisRunLog::MANUAL);

        $this->assertTrue($second->ok);
        $this->assertSame(2, $sis->calls);
        $this->assertSame([$key], array_keys($sis->deliveries), 'the same key: replaced, not duplicated');
        $this->assertSame(1, db_connect()->table('sis_grade_exports')->countAllResults());
    }
}
