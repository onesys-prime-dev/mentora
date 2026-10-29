<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * CI runs the suite a second time with every setting filled in (audit decision 14.2); that .env must keep up
 * with the template, so a new setting is never left out of the run.
 *
 * @internal
 */
final class CiFullEnvTest extends CIUnitTestCase
{
    /** @return list<string> the setting names a file sets ("key = value", commented or not) */
    private static function keys(string $file, bool $commentedToo): array
    {
        $keys = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $pattern = $commentedToo ? '/^\s*#?\s*([A-Za-z][A-Za-z0-9_.]*)\s*=/' : '/^\s*([A-Za-z][A-Za-z0-9_.]*)\s*=/';

            if (preg_match($pattern, $line, $m) === 1) {
                $keys[] = $m[1];
            }
        }

        return array_values(array_unique($keys));
    }

    public function testItSetsEveryKeyOfTheTemplate(): void
    {
        $file = ROOTPATH . 'tests/_support/ci-full.env';
        $this->assertFileExists($file);

        $missing = array_values(array_diff(self::keys(ROOTPATH . 'env', true), self::keys($file, false)));

        $this->assertSame([], $missing, 'settings of the env template that ci-full.env does not set');
    }
}
