<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Dumps the database with `mysqldump | gzip`, the same way the deploy takes
 * its pre-deploy backup. The password goes in the environment (MYSQL_PWD),
 * never on the command line, and never into an error message.
 */
class MysqldumpRunner implements BackupRunner
{
    public function dump(string $path): void
    {
        $db = config('Database')->default;

        $command = sprintf(
            'mysqldump --no-tablespaces --single-transaction --routines --triggers -h %s -P %d -u %s %s',
            escapeshellarg((string) $db['hostname']),
            (int) ($db['port'] ?? 3306),
            escapeshellarg((string) $db['username']),
            escapeshellarg((string) $db['database']),
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['MYSQL_PWD' => (string) $db['password'], 'PATH' => (string) getenv('PATH')],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('mysqldump could not be started. Is it installed on this server?');
        }

        $gz = gzopen($path, 'wb9');

        if ($gz === false) {
            proc_close($process);

            throw new RuntimeException('The backup file could not be created. Check the backup folder is writable.');
        }

        while (! feof($pipes[1])) {
            gzwrite($gz, (string) fread($pipes[1], 65536));
        }

        $errors = trim((string) stream_get_contents($pipes[2]));
        gzclose($gz);

        $code = proc_close($process);

        if ($code !== 0) {
            @unlink($path);

            throw new RuntimeException('mysqldump failed' . ($errors !== '' ? ': ' . str_replace((string) $db['password'], '********', mb_substr($errors, 0, 300)) : '.'));
        }
    }
}
