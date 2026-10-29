<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

/**
 * The scheduled jobs' block in the site user's crontab (audit decisions D5, 6.4).
 *
 * The deploy installs it: the lines of `deploy/crontab.txt`, with this
 * environment's site folder and PHP binary, between two marker lines. Only
 * that block is ever replaced, so whatever else is in the crontab (another
 * site's jobs, a MAILTO line) is kept. After installing, the deploy checks
 * every scheduled job is there and fails if one is not.
 */
final class CrontabBlock
{
    public const BEGIN = '# BEGIN mentora jobs';

    public const END = '# END mentora jobs';

    public static function template(): string
    {
        return (string) file_get_contents(ROOTPATH . 'deploy/crontab.txt');
    }

    public static function render(string $template, string $site, string $php): string
    {
        $lines = trim(str_replace(['{site}', '{php}', "\r\n"], [$site, $php, "\n"], $template));

        return self::BEGIN . "\n" . $lines . "\n" . self::END . "\n";
    }

    /** The crontab with the block put in place of the old one, or added at the end when there is none. */
    public static function merge(string $existing, string $block): string
    {
        $existing = str_replace("\r\n", "\n", $existing);
        $pattern  = '/^' . preg_quote(self::BEGIN, '/') . '\n.*?^' . preg_quote(self::END, '/') . '\n?/ms';

        if (preg_match($pattern, $existing) === 1) {
            return (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $existing, 1);
        }

        // A block cut short (its END line lost by a hand edit): replacing a guess could delete other lines.
        if (preg_match('/^' . preg_quote(self::BEGIN, '/') . '$/m', $existing) === 1) {
            throw new \InvalidArgumentException('The crontab has "' . self::BEGIN . '" without its "' . self::END . '" line. Fix it by hand (crontab -e), then deploy again.');
        }

        $existing = rtrim($existing, "\n");

        return ($existing === '' ? '' : $existing . "\n") . $block;
    }

    /**
     * The scheduled jobs the installed crontab does not run.
     *
     * @return list<string>
     */
    public static function missing(string $installed): array
    {
        return array_values(array_filter(
            array_keys(ScheduledJobs::JOBS),
            static fn (string $command): bool => preg_match('/spark ' . preg_quote($command, '/') . '(\s|$)/m', $installed) !== 1,
        ));
    }
}
