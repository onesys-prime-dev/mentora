<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The permission grid (components/permission-grid.php) in both modes sits in
 * the pinned horizontal scroller: a focusable labelled region, the first column
 * pinned, and cell buttons that grow to a touch target under a coarse pointer.
 *
 * @internal
 */
final class PermissionGridComponentTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url']);
    }

    private function xpath(bool $scrollable): DOMXPath
    {
        $html = view('components/permission-grid', [
            'roleTitles'    => ['student' => 'Student', 'instructor' => 'Instructor'],
            'sections'      => ['Courses' => ['courses.view' => 'See courses']],
            'lockedRole'    => '',
            'currentMatrix' => ['student' => ['courses.view'], 'instructor' => []],
            'defaultMatrix' => ['student' => [], 'instructor' => []],
            'gridAction'    => '/grid',
            'gridReadOnly'  => false,
            'gridScrollable' => $scrollable,
        ], ['saveData' => false]);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** @return array<string, array{bool}> */
    public static function modes(): array
    {
        return ['plain' => [false], 'scrollable' => [true]];
    }

    /** @dataProvider modes */
    public function testContainerIsAFocusableLabelledRegion(bool $scrollable): void
    {
        $c = $this->xpath($scrollable)->query('//div[@data-scroll-x]');

        $this->assertSame(1, $c->length);
        $this->assertSame('0', $c->item(0)->getAttribute('tabindex'));
        $this->assertSame('region', $c->item(0)->getAttribute('role'));
        $this->assertNotSame('', $c->item(0)->getAttribute('aria-label'));
        $this->assertTrue($c->item(0)->hasAttribute('data-sticky-first'));
    }

    /** @dataProvider modes */
    public function testFirstColumnIsPinnedAndOpaque(bool $scrollable): void
    {
        $x = $this->xpath($scrollable);

        foreach ($x->query('//tr[not(@data-roles-section-head)]/*[1]') as $cell) {
            $classes = preg_split('/\s+/', $cell->getAttribute('class'));
            $this->assertContains('sticky', $classes);
            $this->assertContains('left-0', $classes);
        }
        foreach ($x->query('//tbody//tr[@data-roles-row]/td[1]') as $cell) {
            $this->assertContains('z-10', preg_split('/\s+/', $cell->getAttribute('class')));
            $this->assertContains('bg-white', preg_split('/\s+/', $cell->getAttribute('class')));
        }
    }

    /** @dataProvider modes */
    public function testCellButtonsGrowUnderACoarsePointer(bool $scrollable): void
    {
        $buttons = $this->xpath($scrollable)->query('//form//button');

        $this->assertGreaterThan(0, $buttons->length);
        foreach ($buttons as $b) {
            $classes = preg_split('/\s+/', $b->getAttribute('class'));
            $this->assertContains('pointer-coarse:min-h-11', $classes);
            $this->assertContains('pointer-coarse:min-w-11', $classes);
        }
    }

    public function testScrollableModeKeepsItsHooks(): void
    {
        $x = $this->xpath(true);

        $this->assertSame(1, $x->query('//div[@data-scroll-x and @data-table]')->length);
        $this->assertSame(1, $x->query('//*[@data-roles-section-body]')->length);
        $this->assertSame(1, $x->query('//*[@data-roles-empty]')->length);
        $this->assertSame(1, $x->query('//tr[@data-roles-row and @data-search and @data-edited]')->length);
        $this->assertSame(2, $x->query('//form[@data-dialog-form]')->length);
    }
}
