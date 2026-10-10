<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Whatever actually makes a database dump. Replaceable so tests never run a
 * real dump (Services::backupRunner()).
 */
interface BackupRunner
{
    /**
     * Writes a compressed dump of the database to $path.
     *
     * @throws \RuntimeException with a message fit to store when it fails
     */
    public function dump(string $path): void;
}
