<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Backup\MysqldumpRunner;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeBackupRunner;
use Tests\Support\PeopleFactory;

/**
 * The real dump runner, driven with a stand-in script instead of mysqldump so
 * no database tool is needed: what it writes, how it fails, that the password
 * never shows, and the backup service's remaining failure and no-op paths.
 *
 * @internal
 */
final class MysqldumpRunnerTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private string $file;

    private string $standIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-dump-' . bin2hex(random_bytes(4)) . '.sql.gz';
        $this->standIn = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TESTPATH . '_support/fake-dump.php');

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('FAKE_DUMP');
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        Services::resetSingle('backupRunner');

        parent::tearDown();
    }

    public function testItWritesACompressedDump(): void
    {
        putenv('FAKE_DUMP=ok');

        (new MysqldumpRunner($this->standIn))->dump($this->file);

        $this->assertFileExists($this->file);
        $this->assertStringContainsString('CREATE TABLE things', (string) gzdecode((string) file_get_contents($this->file)));
    }

    public function testAFailingToolIsAnErrorAndLeavesNoFileAndNoPassword(): void
    {
        putenv('FAKE_DUMP=fail');

        $config                          = config('Database');
        $original                        = $config->default['password'];
        $config->default['password']     = 'topsecretpw';

        try {
            (new MysqldumpRunner($this->standIn))->dump($this->file);
            $this->fail('A failing dump should throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mysqldump failed', $e->getMessage());
            $this->assertStringContainsString('Access denied', $e->getMessage());
            $this->assertStringNotContainsString('topsecretpw', $e->getMessage());
        } finally {
            $config->default['password'] = $original;
        }

        $this->assertFileDoesNotExist($this->file);
    }

    public function testAnUnwritableTargetIsAnError(): void
    {
        putenv('FAKE_DUMP=ok');

        $this->expectException(RuntimeException::class);

        @(new MysqldumpRunner($this->standIn))->dump(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'no-such-folder-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR . 'x.sql.gz');
    }

    public function testTheServiceHandsOutTheRealRunnerUnlessReplaced(): void
    {
        $this->assertInstanceOf(MysqldumpRunner::class, Services::backupRunner(false));
    }

    public function testABackupFolderThatCannotBeMadeIsAFailureWithAReason(): void
    {
        $runner = FakeBackupRunner::install();
        $_SERVER['backup.path'] = $this->file . DIRECTORY_SEPARATOR . 'inside'; // under a path that is not a folder
        file_put_contents($this->file, 'x');

        $service = new BackupService();
        $service->request(PeopleFactory::person('admin'), new DateTimeImmutable());
        $service->runDue(new DateTimeImmutable());

        $row = db_connect()->table('backups')->get()->getRowArray();

        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('folder could not be created', (string) $row['message']);

        $runner->remove();
    }

    public function testARequestThatCannotBeRecordedInTheAuditLogIsNotKept(): void
    {
        Services::injectMock('auditLogger', new class () extends App\Services\Audit\AuditLogger {
            public function log(string $action, ?CodeIgniter\Shield\Entities\User $actor = null, array $details = []): int
            {
                throw new RuntimeException('audit store is down');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit store is down');

        (new BackupService())->request(PeopleFactory::person('admin'), new DateTimeImmutable());
    }

    public function testTheCommandSaysSoWhenThereIsNothingToDo(): void
    {
        $runner = FakeBackupRunner::install();
        db_connect()->table('backups')->insert(['status' => 'succeeded', 'trigger' => 'scheduled', 'created_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(EXIT_SUCCESS, (new App\Commands\RunBackup(service('logger'), service('commands')))->run([]));
        $this->assertSame(0, $runner->calls);

        $runner->remove();
    }
}
