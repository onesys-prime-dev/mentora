<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use App\Services\Institutions\InstitutionExport;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * "All or nothing": when the audit entry for an export cannot be written, no
 * export row and no file is kept.
 *
 * Like OnboardingRollbackTest this class does not run inside a rolled-back
 * test transaction, so the rollback under test really happens.
 *
 * @internal
 */
final class InstitutionExportRollbackTest extends DatabaseTestCase
{
    protected bool $isolateTests = false;

    private const TABLES = ['institution_exports', 'institution_users', 'user_profiles', 'auth_groups_users', 'auth_identities', 'users', 'institutions', 'audit_logs'];

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->folder        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-export-' . bin2hex(random_bytes(4));
        \Tests\Support\TestEnv::set('export.path', $this->folder);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
        \Tests\Support\TestEnv::restore();

        $this->wipe();

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

    public function testNothingIsKeptWhenTheAuditEntryCannotBeWritten(): void
    {
        $id    = PeopleFactory::institution('GONE', 'past');
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@example.com', $id);

        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('audit down');
            }
        });

        try {
            (new InstitutionExport())->request($id, $admin);
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('institution_exports')->countAllResults());
        $this->assertSame([], glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: []);
    }
}
