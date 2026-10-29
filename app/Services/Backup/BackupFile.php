<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Whether a dump file is a whole one (audit decision 11.1): it decompresses as
 * gzip to the stream's own end (so its length and checksum trailer are there and
 * right), and its last line is mysqldump's own "-- Dump completed", which it
 * writes only after the last table.
 *
 * Read in pieces, keeping only the last line, so a dump of any size is checked in
 * a little memory: running out of memory here would be a fatal error, not a
 * failed backup.
 */
final class BackupFile
{
    public const CLOSING = '-- Dump completed';

    /** Compressed bytes read at a time; small, since SQL can inflate a hundred-fold or more. */
    private const CHUNK = 8192;

    public static function isComplete(string $path): bool
    {
        $file    = @fopen($path, 'rb');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);

        if ($file === false || $inflate === false) {
            return false;
        }

        $tail = '';
        $last = '';

        try {
            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK);

                if ($chunk === false) {
                    return false;
                }

                $out = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);

                if ($out === false) {
                    return false; // not gzip, or corrupt
                }

                // Only the last line matters: the newest non-empty line seen, and the unfinished end after it.
                $text = $tail . $out;
                $cut  = strrpos($text, "\n");

                if ($cut === false) {
                    $tail = $text;
                } else {
                    $done = rtrim(substr($text, 0, $cut));
                    $tail = substr($text, $cut + 1);

                    if ($done !== '') {
                        $from = strrpos($done, "\n");
                        $line = trim($from === false ? $done : substr($done, $from + 1));
                        $last = $line !== '' ? $line : $last;
                    }
                }

                unset($text, $out);

                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    break;
                }
            }
        } finally {
            fclose($file);
        }

        if (trim($tail) !== '') {
            $last = trim($tail);
        }

        // A stream cut short never reaches its end; its checksum is checked on the way there.
        return inflate_get_status($inflate) === ZLIB_STREAM_END && str_starts_with($last, self::CLOSING);
    }
}
