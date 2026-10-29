<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every CSV read or write names its escape character. From PHP 8.4 leaving it out is deprecated: each call
 * raises a notice, which is logged, so an export of 80,000 rows logged 80,000 notices (and in the test suite,
 * where the logger keeps them, held 171 MB; InstitutionExportScaleTest on PHP 8.5).
 *
 * @internal
 */
final class CsvCallsNameTheirEscapeTest extends CIUnitTestCase
{
    /** The argument count that includes $escape, per function. */
    private const WITH_ESCAPE = ['fputcsv' => 5, 'fgetcsv' => 5, 'str_getcsv' => 4];

    public function testEveryCsvCallInTheAppNamesItsEscape(): void
    {
        $this->assertSame([], self::callsWithoutEscape(APPPATH));
    }

    public function testACallWithoutItIsFound(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-csv-scan-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/bad.php', '<?php fputcsv($s, array_map(fn ($c) => trim($c, ","), $row));');
        file_put_contents($dir . '/good.php', '<?php fputcsv($s, $row, ",", "\"", ""); fgetcsv($h, 0, ",", "\"", "\\\\"); str_getcsv($t, escape: "");');

        try {
            $this->assertSame(['bad.php:1 fputcsv'], self::callsWithoutEscape($dir));
        } finally {
            array_map('unlink', glob($dir . '/*.php'));
            @rmdir($dir);
        }
    }

    /** @return list<string> "file:line function" of each call that leaves the escape out */
    private static function callsWithoutEscape(string $root): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            $name   = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1));

            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_STRING || ! isset(self::WITH_ESCAPE[strtolower($token[1])])) {
                    continue;
                }

                [$args, $named] = self::arguments($tokens, $i);

                if ($args !== null && ! $named && $args < self::WITH_ESCAPE[strtolower($token[1])]) {
                    $found[] = "{$name}:{$token[2]} " . strtolower($token[1]);
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * How many top-level arguments the call at $at has, and whether one is named `escape:`; null when the
     * name is not a call (a method of that name, or its declaration).
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{?int, bool}
     */
    private static function arguments(array $tokens, int $at): array
    {
        $before = $at - 1;

        while ($before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
            $before--;
        }

        if ($before >= 0 && is_array($tokens[$before]) && in_array($tokens[$before][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            return [null, false];
        }

        $i = $at + 1;

        while (isset($tokens[$i]) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }

        if (($tokens[$i] ?? null) !== '(') {
            return [null, false];
        }

        $depth = 0;
        $args  = 0;
        $named = false;
        $any   = false;

        for (; isset($tokens[$i]); $i++) {
            $t = $tokens[$i];

            if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                continue;
            }

            if (in_array($t, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($t === ',') {
                $args++;
            } elseif (! is_array($t) || $t[0] !== T_WHITESPACE) {
                $any = true;
            }

            if (is_array($t) && $t[1] === 'escape' && ($tokens[$i + 1] ?? null) === ':') {
                $named = true;
            }
        }

        return [$any ? $args + 1 : 0, $named];
    }
}
