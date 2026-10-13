<?php

declare(strict_types=1);

namespace App\Services\Sis;

use RuntimeException;

/**
 * A problem with the SIS side that the administrator can act on: a missing or
 * unreadable file, a bad signature, a file with the wrong columns, no
 * encryption key. The message is safe to show.
 */
class SisException extends RuntimeException
{
}
