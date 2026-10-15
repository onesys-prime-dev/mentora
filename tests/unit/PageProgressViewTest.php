<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every page's HTML carries the slim top bar already running, so a server
 * page reload is never a silent white wait.
 *
 * @internal
 */
final class PageProgressViewTest extends CIUnitTestCase
{
    public function testThePartialIsAnAnnouncedBarMarkedAsThePageLoad(): void
    {
        $html = view('layouts/partials/page-progress');

        $this->assertStringContainsString('data-spa-progress', $html);
        $this->assertStringContainsString('data-page-load', $html);
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('is-active', $html);
    }

    /**
     * @dataProvider layouts
     */
    public function testEveryLayoutIncludesTheBar(string $layout): void
    {
        $source = (string) file_get_contents(APPPATH . 'Views/layouts/' . $layout);

        $this->assertStringContainsString("layouts/partials/page-progress", $source, $layout);
    }

    /** @return iterable<string, array{string}> */
    public static function layouts(): iterable
    {
        foreach (['app.php', 'admin.php', 'auth.php'] as $layout) {
            yield $layout => [$layout];
        }
    }
}
