<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Copies a good backup off the server (audit U3): the dump, and the uploaded files.
 * Throws when the copy could not be made and verified.
 */
interface OffsiteCopier
{
    public function copy(string $dumpPath, string $uploadsPath): void;
}
