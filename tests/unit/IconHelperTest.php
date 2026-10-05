<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Inline SVG icons: the registry is complete and well-formed, every icon the
 * views ask for exists (an unknown name silently turns into a file icon, which
 * hides typos), the colour rule, accessibility, and the type-to-icon helpers.
 *
 * @internal
 */
final class IconHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('icon');
    }

    /*
    |--------------------------------------------------------------------------
    | Registry
    |--------------------------------------------------------------------------
    */

    public function testEveryIconHasABodyAColourAndASaneViewBox(): void
    {
        foreach (icon_registry() as $name => $icon) {
            $this->assertNotSame('', $icon['body'], $name);
            $this->assertMatchesRegularExpression('/^text-/', $icon['color'], "{$name} default colour");
            $this->assertMatchesRegularExpression('/^0 0 \d+ \d+$/', $icon['viewBox'] ?? '0 0 24 24', "{$name} viewBox");
            $this->assertSame(
                substr_count($icon['body'], '<'),
                substr_count($icon['body'], '/>'),
                "{$name}: every element in the body is self-closed"
            );
        }
    }

    public function testTheRegistryIsBuiltOnce(): void
    {
        $this->assertSame(icon_registry(), icon_registry());
    }

    /** @return list<string> icon names written literally in the app */
    private function namesUsedByTheApp(): array
    {
        $names = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {

            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'icon_helper.php')) {
                continue;
            }

            $code = file_get_contents($file->getPathname());

            if (preg_match_all("/\\bicon\\(\\s*'([a-z0-9.-]+)'/", $code, $calls)) {
                $names = array_merge($names, $calls[1]);
            }

            if (preg_match_all("/'icon'\\s*=>\\s*'([a-z0-9-]+)'/", $code, $keys)) {
                $names = array_merge($names, $keys[1]);
            }

        }

        return array_values(array_unique(array_map(static fn (string $n) => preg_replace('/\.png$/', '', $n), $names)));
    }

    public function testEveryIconTheViewsNameExists(): void
    {
        $known = array_keys(icon_registry()) + [];
        $known[] = 'check-green';

        $this->assertNotSame([], $this->namesUsedByTheApp(), 'the scan found the icon calls');

        $missing = array_values(array_diff($this->namesUsedByTheApp(), $known));

        $this->assertSame([], $missing, 'icons asked for but not in the registry: ' . implode(', ', $missing));
    }

    /*
    |--------------------------------------------------------------------------
    | icon()
    |--------------------------------------------------------------------------
    */

    public function testAnIconIsAnInlineSvgWithItsDefaultColour(): void
    {
        $svg = icon('info', 'h-5 w-5');

        $this->assertStringStartsWith('<svg ', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
        $this->assertStringContainsString('class="shrink-0 h-5 w-5 text-[#0040A6]"', $svg);
    }

    public function testASolidIconIsFilledNotStroked(): void
    {
        $svg = icon('bell');

        $this->assertStringContainsString('viewBox="0 0 20 20"', $svg);
        $this->assertStringContainsString('fill="currentColor"', $svg);
        $this->assertStringNotContainsString('stroke=', $svg);
    }

    public function testTheCallersTextColourReplacesTheDefault(): void
    {
        $this->assertStringContainsString('class="shrink-0 h-3 w-3 text-white"', icon('check', 'h-3 w-3 text-white'));
        $this->assertStringNotContainsString('text-[#5C9F72]', icon('check', 'h-3 w-3 text-white'));
        $this->assertStringContainsString('text-red-500', icon('info', 'text-red-500'));
        $this->assertStringContainsString('text-[#abc]', icon('info', 'text-[#abc]'));
        $this->assertStringNotContainsString('text-[#0040A6]', icon('info', 'text-[#abc]'));
    }

    public function testTextSizeAndAlignmentClassesAreNotMistakenForAColour(): void
    {
        foreach (['text-xs', 'text-sm', 'text-base', 'text-lg', 'text-xl', 'text-2xl', 'text-left', 'text-center', 'text-right', 'text-justify', 'text-[14px]'] as $class) {
            $this->assertStringContainsString('text-[#0040A6]', icon('info', "h-4 {$class}"), $class);
        }
    }

    public function testAnUnknownNameFallsBackToTheFileIcon(): void
    {
        $this->assertSame(icon('file', 'h-4 w-4'), icon('no-such-icon', 'h-4 w-4'));
    }

    public function testTheOldPngNamesStillWork(): void
    {
        $this->assertSame(icon('info'), icon('info.png'));
        $this->assertSame(icon('check'), icon('check-green'));
        $this->assertSame(icon('check'), icon('check-green.png'));
    }

    public function testAnIconIsDecorativeUnlessItHasALabel(): void
    {
        $decorative = icon('info');
        $this->assertStringContainsString('aria-hidden="true"', $decorative);
        $this->assertStringNotContainsString('role="img"', $decorative);

        $labelled = icon('info', 'h-4 w-4', 'More information');
        $this->assertStringContainsString('role="img" aria-label="More', $labelled);
        $this->assertStringContainsString('aria-label="More&#x20;information"', $labelled, 'escaped for an attribute; browsers read it back as plain spaces');
        $this->assertStringNotContainsString('aria-hidden', $labelled);
    }

    public function testTheLabelAndClassesCannotBreakOutOfTheirAttributes(): void
    {
        $svg = icon('info', 'h-4" onclick="x', 'Say "hi" <b>');

        $this->assertStringNotContainsString('onclick="x"', $svg);
        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringContainsString('aria-label="Say&#x20;&quot;hi&quot;&#x20;&lt;b&gt;"', $svg);
    }

    /*
    |--------------------------------------------------------------------------
    | Type helpers
    |--------------------------------------------------------------------------
    */

    public static function learningTypes(): array
    {
        return [
            ['reading', 'reading'], ['VIDEO', 'video'], ['pdf', 'pdf'], ['link', 'link'], ['quiz', 'quiz'],
            ['assignment', 'assignment'], ['discussion', 'discussion'], ['mystery', 'file'], ['', 'file'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('learningTypes')]
    public function testLearningItemsGetTheirTypeIcon(string $type, string $icon): void
    {
        $this->assertSame(icon($icon, 'h-4 w-4'), learning_item_icon($type));
        $this->assertSame(icon($icon, 'h-8 w-8'), learning_item_icon($type, 'h-8 w-8'));
    }

    public static function resourceTypes(): array
    {
        return [
            ['pdf', 'pdf'], ['word', 'word'], ['excel', 'excel'], ['powerpoint', 'powerpoint'], ['image', 'image'],
            ['video', 'video'], ['link', 'link'], ['Link', 'link'], ['other', 'file'], ['zip', 'file'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('resourceTypes')]
    public function testResourceBucketsGetTheirFileTypeIcon(string $bucket, string $icon): void
    {
        $this->assertSame(icon($icon, 'h-5 w-5'), resource_type_icon($bucket));
    }
}
