<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Institutions\InstitutionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;

/**
 * Permanently removes deleted institutions whose restore period is over
 * (90 days by default; the deletedInstitutionRetentionDays setting).
 *
 * Meant to run once a day from the server's scheduler:
 *
 *   php spark institutions:purge-expired
 *
 * Add --dry-run to list what would be purged without removing anything.
 * Exits non-zero if any institution could not be purged.
 */
class PurgeExpiredInstitutions extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'institutions:purge-expired';
    protected $description = 'Purges deleted institutions whose restore period is over.';
    protected $usage       = 'institutions:purge-expired [--dry-run]';
    protected $options     = [
        '--dry-run' => 'List the institutions that would be purged, without removing anything.',
    ];

    public function run(array $params)
    {
        // The flag arrives in $params, and also through CLI::getOption() when run from the shell.
        // Either one means a dry run: a flag that is missed must never turn into a real purge.
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $result = (new InstitutionService())->purgeExpired(new DateTimeImmutable(), $dryRun);

        $verb = $dryRun ? 'Would purge' : 'Purged';

        CLI::write(sprintf('%s %d institution(s).', $verb, count($result['purged'])), $result['purged'] === [] ? 'white' : 'green');

        foreach ($result['purged'] as $id) {
            CLI::write("  #{$id}");
        }

        foreach ($result['failed'] as $id => $reason) {
            CLI::error("Could not purge #{$id}: {$reason}");
        }

        return $result['failed'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
