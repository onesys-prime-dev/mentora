<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use RuntimeException;

/**
 * Someone has done a Messages action too often (spec 5.3). The message is a
 * friendly sentence meant to be shown as it is; it never holds message text.
 */
final class RateLimitedException extends RuntimeException
{
    public function __construct(private readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    /** message, thread or report */
    public function kind(): string
    {
        return $this->kind;
    }
}
