<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every view with a form that posts carries the CSRF token (audit finding 4),
 * so a new form can't ship without it and be refused for everyone.
 *
 * @internal
 */
final class PostFormsCarryATokenTest extends CIUnitTestCase
{
    public function testEveryPostFormInTheViewsCarriesTheToken(): void
    {
        $this->assertSame([], self::formsWithoutAToken(APPPATH . 'Views'));
    }

    public function testAFormWithoutTheTokenIsFound(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-csrf-scan-' . bin2hex(random_bytes(4));
        mkdir($dir . '/pages', 0775, true);
        file_put_contents($dir . '/pages/bad.php', '<form action="/x" method="POST"><button>Go</button></form>');
        file_put_contents($dir . '/pages/good.php', '<form method="post"><?= csrf_field() ?></form>');
        file_put_contents($dir . '/pages/search.php', '<form method="get"></form>');

        try {
            $this->assertSame(['pages/bad.php'], self::formsWithoutAToken($dir));
        } finally {
            array_map('unlink', glob($dir . '/pages/*.php'));

            // Windows can hold a just-read directory for a moment; a leftover in the temp folder is harmless.
            @rmdir($dir . '/pages');
            @rmdir($dir);
        }
    }

    /**
     * The view files (relative to $root) with a form that posts and no csrf_field() or form_open() in it.
     *
     * @return list<string>
     */
    private static function formsWithoutAToken(string $root): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/<form\b[^>]*method=["\']post["\']/i', $source) === 1 && preg_match('/csrf_field\(|form_open\(/', $source) !== 1) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($found);

        return $found;
    }
}
