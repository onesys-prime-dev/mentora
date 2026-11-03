<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Messaging\MessageNotifier;
use App\Services\Monitoring\ScheduledJobs;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Tells people about messages the moment of the send did not: chats too big to
 * notify inside a send, and anything a quiet-hours window held back, once the
 * window has ended. Safe to run as often as the scheduler likes; nobody is told
 * the same thing twice and no message text is ever sent.
 *
 *   php spark messages:notify
 *
 * Add --dry-run to count the conversations with something to tell without
 * telling anyone. Exits non-zero if any conversation failed.
 */
class NotifyMessages extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'messages:notify';
    protected $description = 'Notifies people of new messages, and releases those held by quiet hours.';
    protected $usage       = 'messages:notify [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Count the conversations with something to tell, without telling anyone.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $code   = $this->work($dryRun);

        // The scheduler check watches for this job going quiet (6.2); a dry run is not the job.
        if (! $dryRun) {
            ScheduledJobs::heartbeat('messages:notify');
        }

        return $code;
    }

    private function work(bool $dryRun): int
    {
        $notifier = new MessageNotifier();
        $after    = 0;
        $chats    = $told = $failed = 0;

        do {
            $ids = $notifier->pendingConversationIds($after, 200);

            foreach ($ids as $id) {
                $after = $id;
                $chats++;

                if ($dryRun) {
                    continue;
                }

                try {
                    $told += $notifier->notifyConversation($id);
                } catch (Throwable) {
                    $failed++;
                    CLI::error("Could not notify conversation #{$id}.");
                }
            }
        } while ($ids !== []);

        CLI::write($dryRun
            ? sprintf('Would look at %d conversation(s).', $chats)
            : sprintf('Looked at %d conversation(s); told %d person(s).', $chats, $told), 'green');

        return $failed === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
