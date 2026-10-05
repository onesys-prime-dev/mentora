<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * file_url() and vite(): the protected file link and the built-asset tags.
 * vite() reads public/build/.vite/manifest.json outside development; the
 * tests put their own manifest there and restore whatever was there before.
 *
 * @internal
 */
final class StorageAndViteHelperTest extends CIUnitTestCase
{
    private string $manifest;

    private ?string $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        helper(['storage', 'vite']);

        $this->manifest = ROOTPATH . 'public/build/.vite/manifest.json';
        $this->original = is_file($this->manifest) ? file_get_contents($this->manifest) : null;
    }

    protected function tearDown(): void
    {
        if ($this->original !== null) {
            file_put_contents($this->manifest, $this->original);
        } elseif (is_file($this->manifest)) {
            unlink($this->manifest);
        }

        parent::tearDown();
    }

    private function writeManifest(string $contents): void
    {
        if (! is_dir(dirname($this->manifest))) {
            mkdir(dirname($this->manifest), 0775, true);
        }

        file_put_contents($this->manifest, $contents);
    }

    /*
    |--------------------------------------------------------------------------
    | file_url
    |--------------------------------------------------------------------------
    */

    public function testAFileUrlGoesThroughTheProtectedRoute(): void
    {
        $this->assertSame(site_url('files/42'), file_url(42));
        $this->assertSame(site_url('files/42') . '?download=1', file_url(42, true));
    }

    /*
    |--------------------------------------------------------------------------
    | vite
    |--------------------------------------------------------------------------
    */

    public function testAnEntryBecomesItsStylesheetsThenItsScript(): void
    {
        $this->writeManifest(json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-abc.js', 'css' => ['assets/app-abc.css', 'assets/vendor.css']],
        ]));

        $this->assertSame(
            '<link rel="stylesheet" href="' . base_url('build/assets/app-abc.css') . '">' . PHP_EOL
            . '<link rel="stylesheet" href="' . base_url('build/assets/vendor.css') . '">' . PHP_EOL
            . '<script type="module" src="' . base_url('build/assets/app-abc.js') . '"></script>',
            vite('resources/js/app.js')
        );
    }

    public function testAnEntryWithoutStylesheetsIsJustTheScript(): void
    {
        $this->writeManifest(json_encode(['x.js' => ['file' => 'assets/x.js']]));

        $this->assertSame('<script type="module" src="' . base_url('build/assets/x.js') . '"></script>', vite('x.js'));
    }

    public function testAnEntryThatWasNotBuiltIsAnError(): void
    {
        $this->writeManifest(json_encode(['x.js' => ['file' => 'assets/x.js']]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Vite entry 'missing.js' not found.");

        vite('missing.js');
    }

    public function testAMissingManifestSaysToBuild(): void
    {
        if (is_file($this->manifest)) {
            unlink($this->manifest);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('npm run build');

        vite('x.js');
    }

    public function testACorruptManifestIsAnErrorNotABlankPage(): void
    {
        $this->writeManifest('{ not json');

        $this->expectException(JsonException::class);

        vite('x.js');
    }
}
