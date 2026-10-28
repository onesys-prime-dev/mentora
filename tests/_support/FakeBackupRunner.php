<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Backup\BackupRunner;
use CodeIgniter\Config\Services;
use RuntimeException;

/**
 * Stands in for mysqldump: writes a small file, or fails when told to. install()
 * makes service('backupRunner') return it, and points the backup folder at a
 * throw-away directory (remove() cleans both up).
 *
 * @internal
 */
final class FakeBackupRunner implements BackupRunner
{
    public bool $fail = false;

    /** What the dump looks like: `complete` (gzip ending as mysqldump's does), `truncated` (cut mid-stream), `unfinished` (no closing line). */
    public string $mode = 'complete';

    public int $calls = 0;

    public string $folder;

    public static function install(): self
    {
        $fake         = new self();
        $fake->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-backups-' . bin2hex(random_bytes(4));

        \Tests\Support\TestEnv::set('backup.path', $fake->folder);

        // No off-site copy unless a test gives one: a .env with backup.offsiteCommand set must not run it (14.2).
        \Tests\Support\TestEnv::unset('backup.offsiteCommand');
        Services::resetSingle('offsiteCopier');

        Services::injectMock('backupRunner', $fake);

        return $fake;
    }

    public function remove(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);

        \Tests\Support\TestEnv::restore();
        Services::resetSingle('backupRunner');
        Services::resetSingle('offsiteCopier');
    }

    public function dump(string $path): void
    {
        $this->calls++;

        if ($this->fail) {
            throw new RuntimeException('mysqldump failed: access denied');
        }

        $sql = "-- MySQL dump\nCREATE TABLE t (id int);\nINSERT INTO t VALUES ({$this->calls});\n" . str_repeat("-- padding\n", 2000);

        // mysqldump writes this line last, once every table is out.
        if ($this->mode !== 'unfinished') {
            $sql .= "-- Dump completed on 2026-10-10 12:00:00\n";
        }

        $gz = gzencode($sql, 9);

        // A gzip stream cut short, as a disk filling up mid-dump leaves it.
        file_put_contents($path, $this->mode === 'truncated' ? substr($gz, 0, intdiv(strlen($gz), 2)) : $gz);
    }
}
