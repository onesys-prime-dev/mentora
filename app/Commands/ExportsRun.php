<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Institutions\InstitutionExport;
use App\Services\Monitoring\ScheduledJobs;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The server's export job (audit decision 12.2):
 *
 *   php spark exports:run
 *
 * Prepares the institution exports administrators asked for, one at a time, and removes expired ones. Run it
 * from the scheduler every minute: with nothing queued it exits at once. See docs/institution-data-export.md.
 */
class ExportsRun extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'exports:run';
    protected $description = 'Prepares the institution exports that were asked for.';
    protected $usage       = 'exports:run';

    public function run(array $params)
    {
        $done = (new InstitutionExport())->runQueued();

        CLI::write($done === [] ? 'Nothing to export.' : 'Prepared ' . count($done) . ' export(s): #' . implode(', #', $done) . '.');

        // The scheduler check watches for this job going quiet (6.2).
        ScheduledJobs::heartbeat($this->name);

        return EXIT_SUCCESS;
    }
}
