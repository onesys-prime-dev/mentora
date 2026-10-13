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

    public int $calls = 0;

    public string $folder;

    public static function install(): self
    {
        $fake         = new self();
        $fake->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-backups-' . bin2hex(random_bytes(4));

        $_SERVER['backup.path'] = $fake->folder;

        Services::injectMock('backupRunner', $fake);

        return $fake;
    }

    public function remove(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);

        unset($_SERVER['backup.path']);
        Services::resetSingle('backupRunner');
    }

    public function dump(string $path): void
    {
        $this->calls++;

        if ($this->fail) {
            throw new RuntimeException('mysqldump failed: access denied');
        }

        file_put_contents($path, 'fake dump ' . $this->calls);
    }
}
