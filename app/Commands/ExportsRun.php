<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Institutions\CsvUploadService;
use App\Services\Institutions\InstitutionExport;
use App\Services\Monitoring\ScheduledJobs;
use App\Services\Notifications\MailOutbox;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * The server's export job (audit decision 12.2):
 *
 *   php spark exports:run
 *
 * Prepares the institution exports administrators asked for, one at a time, and removes expired ones, and
 * expired CSV upload previews too, and sends the emails people are owed (MailOutbox). Run it
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

        // CSV upload previews nobody applied are gone within their hour (QA AMB-INST-06); a failure is logged
        // and tried again next minute, and never stops the exports.
        try {
            $swept = (new CsvUploadService())->forgetExpired();

            if ($swept > 0) {
                CLI::write("Removed {$swept} expired CSV upload preview(s).");
            }
        } catch (Throwable $e) {
            log_message('error', 'CSV upload previews could not be swept: ' . $e->getMessage());
        }

        // The emails the changes of the last minutes call for: a new account's link to choose a password, and the news
        // of a place, a section or a role. One per person; a failure is tried again later and never stops the job.
        try {
            $sent = (new MailOutbox())->sendDue();

            if ($sent > 0) {
                CLI::write("Sent {$sent} email(s) to people added or changed by uploads, imports and assignments.");
            }
        } catch (Throwable $e) {
            log_message('error', 'The mail outbox could not be sent: ' . $e->getMessage());
        }

        // The scheduler check watches for this job going quiet (6.2).
        ScheduledJobs::heartbeat($this->name);

        return EXIT_SUCCESS;
    }
}
