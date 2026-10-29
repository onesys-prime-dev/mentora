<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Sis\SisGradeExporter;
use App\Services\Sis\SisRunLog;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * The scheduled grade export to each institution's SIS (SA10):
 *
 *   php spark sis:grades [--institution=ID] [--dry-run]
 *
 * Sends the final grades that are new or changed since they were last sent,
 * for every institution whose connection is enabled (or just one). An
 * institution that cannot send right now is reported and skipped; the others
 * continue. A failed delivery is retried, and the next run sends the same
 * batch again without duplicating it. Exits non-zero if any run failed. See
 * docs/sis-integration.md.
 */
class SisGrades extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'sis:grades';
    protected $description = 'Sends new and changed final grades to each institution\'s SIS.';
    protected $usage       = 'sis:grades [--institution=ID] [--dry-run]';
    protected $options     = [
        '--institution' => 'Only this institution (by id).',
        '--dry-run'     => 'Preview only: work out what would be sent, send nothing.',
    ];

    public function run(array $params)
    {
        $code = $this->work($params);

        // The scheduler check watches for this job going quiet (6.2); a dry run is not the job.
        if (! array_key_exists('dry-run', $params) && CLI::getOption('dry-run') === null) {
            \App\Services\Monitoring\ScheduledJobs::heartbeat('sis:grades');
        }

        return $code;
    }

    private function work(array $params)
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
        $exporter = new SisGradeExporter();

        foreach ($ids as $id) {
            try {
                $result = $exporter->run($id, null, $dryRun, SisRunLog::SCHEDULED);
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
