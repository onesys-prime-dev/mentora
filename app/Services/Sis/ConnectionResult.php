<?php

declare(strict_types=1);

namespace App\Services\Sis;

/** The outcome of "test the connection". */
final class ConnectionResult
{
    public function __construct(public readonly bool $ok, public readonly string $message)
    {
    }
}
