<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Notifications\NotificationTriggers;
use App\Services\Subscriptions\SubscriptionLifecycle;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;

/**
 * Moves institutions along their subscription's timeline: an institution
 * whose subscription has run into its read-only period becomes read-only, and
 * one past that becomes past. It only ever moves an institution down; a
 * renewal is what brings one back.
 *
 * Meant to run once a day from the server's scheduler:
 *
 *   php spark subscriptions:sync
 *
 * Add --dry-run to list what would change without changing anything.
 * Exits non-zero if any institution could not be updated.
 */
class SyncSubscriptions extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'subscriptions:sync';
    protected $description = 'Moves institutions to read-only or past when their subscription has run out.';
    protected $usage       = 'subscriptions:sync [--dry-run]';
    protected $options     = [
        '--dry-run' => 'List the changes that would be made, without making them.',
    ];

    public function run(array $params)
    {
        // The flag arrives in $params, and also through CLI::getOption() when run from the shell.
        // Either one means a dry run: a flag that is missed must never turn into a real change.
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $result = (new SubscriptionLifecycle())->sync(new DateTimeImmutable(), $dryRun);

        $verb = $dryRun ? 'Would change' : 'Changed';

        CLI::write(sprintf('%s %d institution(s).', $verb, count($result['changed'])), $result['changed'] === [] ? 'white' : 'green');

        foreach ($result['changed'] as $change) {
            CLI::write("  #{$change['id']}: {$change['from']} -> {$change['to']} (subscription {$change['state']})");
        }

        foreach ($result['failed'] as $id => $reason) {
            CLI::error("Could not update #{$id}: {$reason}");
        }

        // Tell people what the timeline and the limits now say (safe to repeat: each is told once).
        if (! $dryRun) {
            CLI::write(sprintf('Created %d notification(s).', (new NotificationTriggers())->run(new DateTimeImmutable())));
        }

        return $result['failed'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
