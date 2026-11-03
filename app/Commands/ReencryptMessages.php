<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageReencryptor;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Moves message bodies, report snapshots and report notes onto the current
 * Messages key after the key has been rotated (the old key stays in
 * messages.previousKeys until this has run and nothing is unreadable).
 *
 *   php spark messages:reencrypt
 *
 * Add --dry-run to count what would change without changing anything. A value
 * nothing can open is counted and its row is named; its content is never shown.
 * Exits non-zero if anything was unreadable or there is no usable key.
 */
class ReencryptMessages extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'messages:reencrypt';
    protected $description = 'Re-encrypts Messages data with the current key.';
    protected $usage       = 'messages:reencrypt [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Count what would be re-encrypted, without changing anything.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $cipher = new MessageCipher();

        if (! $cipher->isUsable()) {
            CLI::error('There is no usable Messages encryption key (messages.encryptionKey), so nothing can be re-encrypted.');

            return EXIT_ERROR;
        }

        $result = (new MessageReencryptor($cipher))->run($dryRun);

        CLI::write(sprintf(
            '%s %d value(s) re-encrypted, %d already on the current key, %d unreadable.',
            $dryRun ? 'Would have:' : 'Done:',
            $result['rewritten'],
            $result['current'],
            $result['unreadable'],
        ), $result['unreadable'] === 0 ? 'green' : 'yellow');

        if ($result['unreadable'] > 0) {
            CLI::error('Could not read: ' . implode(', ', $result['unreadable_ids']) . ($result['unreadable'] > count($result['unreadable_ids']) ? ', …' : ''));
        }

        // Counts only, never any content.
        if (! $dryRun) {
            service('auditLogger')->log('messages.reencrypted', null, ['new' => ['rewritten' => $result['rewritten'], 'unreadable' => $result['unreadable']]]);
        }

        return $result['unreadable'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
