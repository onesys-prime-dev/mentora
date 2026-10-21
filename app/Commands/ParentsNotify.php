<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Parents\ParentNotifier;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Tells parents what changed for their children: a final grade, an INC, a
 * withdrawal, a grade that has fallen below the passing mark, a course
 * announcement, and work not handed in. A final grade is also sent at the
 * moment it is finalized; this finds every kind, so a missed one is never lost.
 *
 * Meant to run every 15 minutes from the server's scheduler:
 *
 *   php spark parents:notify
 *
 * Each parent is told each thing once, so running it more often is harmless.
 * Add --dry-run to count what would be told without telling anyone.
 * Exits non-zero if the sweep failed.
 */
class ParentsNotify extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'parents:notify';
    protected $description = 'Tells parents what changed for their children (grades, INC, withdrawals, announcements, work not handed in).';
    protected $usage       = 'parents:notify [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Count what would be told, without telling anyone.',
    ];

    public function run(array $params)
    {
        // The flag arrives in $params, and also through CLI::getOption() when run from the shell.
        // Either one means a dry run: a flag that is missed must never turn into real messages.
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        try {
            $counts = (new ParentNotifier(dryRun: $dryRun))->sweep();
        } catch (Throwable $e) {
            CLI::error('The sweep failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write($dryRun ? 'Would tell:' : 'Told:', 'white');
        CLI::write('  Final grades: ' . $counts['final']);
        CLI::write('  Standings: ' . $counts['standing']);
        CLI::write('  Announcements: ' . $counts['announcement']);
        CLI::write('  Work not handed in: ' . $counts['missing']);

        return EXIT_SUCCESS;
    }
}
