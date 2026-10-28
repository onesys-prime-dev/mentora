<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Runs the server's off-site command (`backup.offsiteCommand` in .env, audit U3) with the dump's path and the
 * uploads folder. The command itself uploads, encrypts and verifies (docs/server-setup.md); exit 0 means the
 * storage confirmed it holds both. It is stopped after two hours. Its output is shown to the system
 * administrators, so the database password is masked in it.
 */
class ShellOffsiteCopier implements OffsiteCopier
{
    public const TIME_LIMIT_SECONDS = 7200;

    public function __construct(private readonly string $command, private readonly string $secret = '')
    {
    }

    public function copy(string $dumpPath, string $uploadsPath): void
    {
        $process = proc_open(
            $this->command . ' ' . escapeshellarg($dumpPath) . ' ' . escapeshellarg($uploadsPath),
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The off-site command could not be started.');
        }

        stream_set_blocking($pipes[1], false);
        $output   = '';
        $deadline = time() + self::TIME_LIMIT_SECONDS;

        // The exit code is kept from the last poll: once the process has been seen to end, a second
        // proc_get_status() only returns it on PHP 8.3+ (before that, -1 on Linux).
        while (($status = proc_get_status($process))['running']) {
            $output .= (string) stream_get_contents($pipes[1]);

            if (time() > $deadline) {
                proc_terminate($process);
                proc_close($process);

                throw new RuntimeException('The off-site copy took more than two hours and was stopped.');
            }

            usleep(100_000);
        }

        $output .= (string) stream_get_contents($pipes[1]);
        $code    = $status['exitcode'];
        proc_close($process);

        if ($code !== 0) {
            $output = trim($this->secret === '' ? $output : str_replace($this->secret, '********', $output));

            throw new RuntimeException($output === '' ? "The off-site command failed (exit {$code})." : mb_substr($output, 0, 300));
        }
    }
}
