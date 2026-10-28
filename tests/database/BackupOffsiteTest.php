<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Backup\OffsiteCopier;
use App\Services\Backup\ShellOffsiteCopier;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeBackupRunner;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Each good backup is copied off the server when an off-site command is configured (audit decisions 11.4, U3).
 *
 * @internal
 */
final class BackupOffsiteTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FakeBackupRunner $runner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->runner = FakeBackupRunner::install();
        $this->admin  = PeopleFactory::person('admin', 'Root', 'Admin', 'root@offsite.test');
    }

    protected function tearDown(): void
    {
        $this->runner->remove();
        Services::resetSingle('offsiteCopier');
        Services::resetSingle('settings');
        Services::resetSingle('email');

        parent::tearDown();
    }

    /** A copier that records what it was given, or fails. */
    private function copier(?string $failWith = null): OffsiteCopier
    {
        $copier = new class ($failWith) implements OffsiteCopier {
            /** @var list<array{string, string}> */
            public array $calls = [];

            public function __construct(private readonly ?string $failWith)
            {
            }

            public function copy(string $dumpPath, string $uploadsPath): void
            {
                $this->calls[] = [$dumpPath, $uploadsPath];

                if ($this->failWith !== null) {
                    throw new RuntimeException($this->failWith);
                }
            }
        };

        Services::injectMock('offsiteCopier', $copier);

        return $copier;
    }

    private function take(string $mode = 'complete'): array
    {
        $this->runner->mode = $mode;
        db_connect()->table('backups')->insert(['status' => 'requested', 'trigger' => 'manual', 'created_at' => '2026-10-10 12:00:00']);
        $id = (int) db_connect()->insertID();
        (new BackupService())->take($id, new DateTimeImmutable('2026-10-10 12:00:00'));

        return db_connect()->table('backups')->where('id', $id)->get()->getRowArray();
    }

    public function testAGoodBackupIsCopiedWithTheDumpAndTheUploads(): void
    {
        $copier = $this->copier();

        $row = $this->take();

        $this->assertSame('copied', $row['offsite_status']);
        $this->assertCount(1, $copier->calls);
        $this->assertStringEndsWith((string) $row['file_name'], $copier->calls[0][0]);
        $this->assertSame(rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads', $copier->calls[0][1]);
    }

    public function testAFailedCopyKeepsTheBackupGoodAndAlerts(): void
    {
        $this->copier('the bucket refused the upload');

        $row = $this->take();

        $this->assertSame('succeeded', $row['status']);
        $this->assertSame('failed', $row['offsite_status']);
        $this->assertSame('the bucket refused the upload', $row['offsite_message']);
        $this->assertSame(1, db_connect()->table('notifications')->where(['user_id' => $this->admin->id, 'title' => 'Off-site backup copy failed'])->countAllResults());
    }

    public function testWithoutACommandNothingIsCopied(): void
    {
        $row = $this->take();

        $this->assertNull($row['offsite_status']);
        $this->assertSame(0, db_connect()->table('notifications')->countAllResults());
    }

    public function testAFailedBackupIsNeverCopied(): void
    {
        $copier = $this->copier();

        $row = $this->take('truncated');

        $this->assertSame([], $copier->calls);
        $this->assertNull($row['offsite_status']);
    }

    public function testACommandThatSucceedsIsASuccess(): void
    {
        $ok   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'offsite-ok-' . bin2hex(random_bytes(3)) . '.php';
        $fail = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'offsite-fail-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($ok, '<?php exit(0);');
        file_put_contents($fail, '<?php exit(3);');

        try {
            // The exit code is read from the last poll: a second proc_get_status() only has it on PHP 8.3+.
            (new ShellOffsiteCopier(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ok)))->copy('dump.sql.gz', 'uploads');

            try {
                (new ShellOffsiteCopier(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fail)))->copy('dump.sql.gz', 'uploads');
                $this->fail('a failing command throws');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('(exit 3)', $e->getMessage());
            }
        } finally {
            @unlink($ok);
            @unlink($fail);
        }
    }

    public function testTheShellCopierMasksThePassword(): void
    {
        $password = (string) config('Database')->default['password'];
        $script   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'offsite-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($script, '<?php echo "denied for ' . ($password === '' ? 'secret-pass' : addslashes($password)) . '"; exit(1);');

        try {
            (new ShellOffsiteCopier(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $password === '' ? 'secret-pass' : $password))->copy('dump.sql.gz', 'uploads');
            $this->fail('a failing command throws');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('********', $e->getMessage());
            $this->assertStringNotContainsString($password === '' ? 'secret-pass' : $password, $e->getMessage());
        } finally {
            @unlink($script);
        }
    }
}
