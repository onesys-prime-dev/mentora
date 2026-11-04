<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Message text is plain text, 1 to 4000 characters, trimmed (spec 5.3).
 * Control characters other than the newline are removed before the length is
 * counted.
 */
final class MessageBody
{
    public const MAX = 4000;

    /** The cleaned body, or null when it is empty, too long or not valid UTF-8. */
    public static function clean(string $raw): ?string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text);

        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::MAX) {
            return null;
        }

        return $text;
    }
}
