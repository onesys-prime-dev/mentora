<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * Turns what an administrator pasted into the "add students" box into a
 * clean list of identifiers: student numbers or email addresses, one per
 * line or separated by commas, semicolons or spaces. Blank entries go,
 * repeats go (case-insensitively) and the first spelling is kept.
 */
final class EnrolmentList
{
    /** More than this at once is almost certainly a mistake; use the CSV upload. */
    public const MAX = 500;

    /**
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $parts = preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $seen  = [];
        $list  = [];

        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B\"'");
            $key  = mb_strtolower($part);

            if ($part === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $list[]     = $part;
        }

        return $list;
    }
}
