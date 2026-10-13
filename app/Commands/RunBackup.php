<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Backup\BackupService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The server's backup job (SA11):
 *
 *   php spark backup:run
 *
 * Takes every backup an administrator asked for, and the daily one when none
 * has succeeded in the last 23 hours. Run it from the scheduler every few
 * minutes: with nothing to do it exits at once. Exits non-zero if a backup
 * failed. See docs/backup.md.
 */
class RunBackup extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'backup:run';
    protected $description = 'Takes the backups that were asked for, and the daily one when it is due.';
    protected $usage       = 'backup:run';

    public function run(array $params)
    {
        $service = new BackupService();
        $ids     = $service->runDue();

        if ($ids === []) {
            CLI::write('Nothing to back up.');

            return EXIT_SUCCESS;
        }

        $failed = 0;

        foreach ($service->history(count($ids)) as $row) {
            if ($row['status'] === 'succeeded') {
                CLI::write("Backup #{$row['id']} done: {$row['file_name']} (" . number_format((int) $row['size_bytes']) . ' bytes).', 'green');
            } else {
                $failed++;
                CLI::error("Backup #{$row['id']} failed: {$row['message']}");
            }
        }

        return $failed === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
