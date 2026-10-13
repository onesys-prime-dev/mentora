<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Sis\SisImporter;
use App\Services\Sis\SisRunLog;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * The scheduled SIS import (SA10):
 *
 *   php spark sis:sync [--institution=ID] [--dry-run]
 *
 * Runs the inbound sync for every institution whose connection is enabled
 * (or just one). An institution that cannot be synced right now (feature off,
 * not active, no preview yet) is reported and skipped; it does not stop the
 * others. Exits non-zero if any run failed. Put it in the server's scheduler,
 * for example nightly. See docs/sis-integration.md.
 */
class SisSync extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'sis:sync';
    protected $description = 'Imports people, courses and enrolments from each institution\'s SIS.';
    protected $usage       = 'sis:sync [--institution=ID] [--dry-run]';
    protected $options     = [
        '--institution' => 'Only this institution (by id).',
        '--dry-run'     => 'Preview only: work out the counts, write nothing.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $only   = $params['institution'] ?? CLI::getOption('institution');

        $query = db_connect()->table('sis_connections')->select('institution_id')->where('enabled', 1)->orderBy('institution_id');

        if ($only !== null && $only !== true) {
            $query->where('institution_id', (int) $only);
        }

        $ids = array_map(static fn (array $row): int => (int) $row['institution_id'], $query->get()->getResultArray());

        if ($ids === []) {
            CLI::write('No institution has an enabled SIS connection.');

            return EXIT_SUCCESS;
        }

        $failed   = 0;
        $importer = new SisImporter();

        foreach ($ids as $id) {
            try {
                $result = $importer->run($id, null, $dryRun, SisRunLog::SCHEDULED);
            } catch (InvalidArgumentException $e) {
                CLI::write("Institution {$id}: skipped. {$e->getMessage()}", 'yellow');

                continue;
            }

            if ($result->ok) {
                CLI::write("Institution {$id}: {$result->message}", 'green');
            } else {
                $failed++;
                CLI::error("Institution {$id}: failed. {$result->message}");
            }
        }

        return $failed === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
