<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Messaging\CourseChatSync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Creates the course chat of every offering that has none and repairs the
 * members of the ones that have drifted. Archived offerings are left alone.
 * Safe to run repeatedly; it also backfills the offerings that exist today.
 *
 * Meant to run once a day from the server's scheduler:
 *
 *   php spark messages:sync-course-chats
 *
 * Add --dry-run to list what would change without changing anything.
 * Exits non-zero if any offering could not be synced.
 */
class SyncCourseChats extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'messages:sync-course-chats';
    protected $description = 'Creates missing course chats and repairs who is in them.';
    protected $usage       = 'messages:sync-course-chats [--dry-run]';
    protected $options     = [
        '--dry-run' => 'List the changes that would be made, without making them.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $code   = $this->work($dryRun);

        // The scheduler check watches for this job going quiet (6.2); a dry run is not the job.
        if (! $dryRun) {
            \App\Services\Monitoring\ScheduledJobs::heartbeat('messages:sync-course-chats');
        }

        return $code;
    }

    private function work(bool $dryRun): int
    {
        $sync    = new CourseChatSync();
        $db      = db_connect();
        $after   = 0;
        $created = $joined = $left = $failed = 0;

        do {
            $ids = array_map('intval', array_column(
                $db->table('course_offerings')->select('id')->where('status !=', 'archived')->where('id >', $after)->orderBy('id')->limit(200)->get()->getResultArray(),
                'id',
            ));

            foreach ($ids as $id) {
                $after = $id;

                try {
                    if ($dryRun) {
                        $plan    = $sync->plan($id);
                        $changes = ['created' => (int) $plan['create'], 'joined' => count($plan['join']), 'left' => count($plan['leave'])];
                    } else {
                        $result  = $sync->syncOffering($id);
                        $changes = ['created' => (int) $result['created'], 'joined' => $result['joined'], 'left' => $result['left']];
                    }
                } catch (Throwable) {
                    $failed++;
                    CLI::error("Could not sync offering #{$id}.");

                    continue;
                }

                if (array_sum($changes) > 0) {
                    CLI::write("  #{$id}: " . ($changes['created'] ? 'chat created, ' : '') . "{$changes['joined']} joined, {$changes['left']} left");
                }

                $created += $changes['created'];
                $joined  += $changes['joined'];
                $left    += $changes['left'];
            }
        } while ($ids !== []);

        CLI::write(sprintf('%s %d chat(s) created, %d member(s) added, %d removed.', $dryRun ? 'Would have:' : 'Done:', $created, $joined, $left), 'green');

        return $failed === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
