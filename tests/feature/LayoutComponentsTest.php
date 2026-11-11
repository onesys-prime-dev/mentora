<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The responsive card, head, tab, breadcrumb, empty-state and skeleton components.
 *
 * @internal
 */
final class LayoutComponentsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon']);
    }

    private function xpath(string $component, array $data): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . view('components/' . $component, $data, ['saveData' => false]) . '</body>');

        return new DOMXPath($doc);
    }

    public function testMetricCardIsAContainerWithFluidValue(): void
    {
        $x = $this->xpath('metric-card', ['label' => 'Grade', 'value' => '90', 'icon' => 'user', 'size' => 'prominent', 'feature' => 'grades']);

        // The query container is a wrapper, so a query measures the card's outer width, not its padded content box.
        $this->assertSame(1, $x->query('/html/body/div[contains(@class,"@container")]/button[contains(@class,"pointer-coarse:active:scale")][contains(@class,"h-full")]')->length);
        $this->assertSame(0, $x->query('//button[contains(@class,"@container")]')->length);
        $this->assertSame(1, $x->query('//span[contains(@class,"text-fluid-metric")]')->length);

        $plain = $this->xpath('metric-card', ['label' => 'Grade', 'value' => '90', 'icon' => 'user']);
        $this->assertSame(1, $plain->query('/html/body/div[contains(@class,"@container")]/div[contains(@class,"rounded-2xl")]')->length);
        $this->assertSame(1, $plain->query('//span[contains(@class,"text-2xl")]')->length);
    }

    public function testMetricCardIconHidesOnlyOnTinyCards(): void
    {
        $prominent = $this->xpath('metric-card', ['label' => 'Grade', 'value' => '90', 'icon' => 'user', 'size' => 'prominent']);
        $plain = $this->xpath('metric-card', ['label' => 'Grade', 'value' => '90', 'icon' => 'user']);

        $this->assertSame(1, $prominent->query('//span[contains(concat(" ",@class," ")," @max-[10rem]:hidden ")]')->length);
        $this->assertSame(1, $plain->query('//*[local-name()="svg"][contains(concat(" ",@class," ")," @max-[10rem]:hidden ")]')->length);
        $this->assertSame(0, $prominent->query('//*[contains(@class,"@max-[14rem]")]')->length);
    }

    public function testTabsScrollOnPhones(): void
    {
        $x = $this->xpath('tabs', ['tabs' => [['label' => 'A', 'href' => '/a', 'active' => true], ['label' => 'B', 'href' => '/b']]]);

        $this->assertSame(1, $x->query('//nav[@data-tabs]//div[@data-scroll-x]')->length);
        $this->assertSame(0, $x->query('//div[@data-scroll-x][@role or @tabindex or @aria-label]')->length);
        $this->assertSame(1, $x->query('//ul[contains(@class,"flex-nowrap")][contains(@class,"md:flex-wrap")]')->length);
        $this->assertSame(1, $x->query('//a[@aria-current="page"]')->length);
        $this->assertSame(2, $x->query('//a[contains(@class,"pointer-coarse:min-h-11")]')->length);

        // The underline overlaps the nav's border (-mb-px, no pb-px), and the scroller cannot clip the focus ring.
        $scroller = preg_split('/\s+/', $x->query('//div[@data-scroll-x]')->item(0)->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertContains('-mb-px', $scroller);
        $this->assertNotContains('pb-px', $scroller);
        $this->assertSame(2, $x->query('//a[contains(concat(" ",@class," ")," focus-visible:ring-inset ")]')->length);
        // scroll-x.js centres the current tab only in a strip that opts in.
        $this->assertSame(1, $x->query('//div[@data-scroll-x][@data-scroll-current]')->length);
    }

    public function testBreadcrumbCollapsesEarlierCrumbs(): void
    {
        $x = $this->xpath('breadcrumb', ['crumbs' => [
            ['label' => 'Course', 'attrs' => 'data-x="1"'],
            ['label' => 'Modules', 'attrs' => 'data-y="1"'],
            ['label' => 'Week 1'],
        ]]);

        $html = view('components/breadcrumb', ['crumbs' => [
            ['label' => 'Course', 'attrs' => 'data-modules-breadcrumb'],
            ['label' => 'Modules', 'attrs' => 'data-module-breadcrumb'],
            ['label' => 'Week 1'],
        ]], ['saveData' => false]);

        // Every hook is bound with querySelector, so each crumb must exist exactly once.
        $this->assertSame(1, substr_count($html, 'data-modules-breadcrumb'));
        $this->assertSame(1, substr_count($html, 'data-module-breadcrumb'));
        $this->assertSame(1, $x->query('//nav[@data-sticky-breadcrumb][contains(@class,"sticky-bar-top")]')->length);
        $this->assertSame(1, $x->query('//ol')->length);
        $this->assertSame(1, $x->query('//*[@aria-current="page"][contains(@class,"truncate")]')->length);
        $this->assertSame(1, $x->query('//ol//li[contains(@class,"sm:hidden")]/details/summary[@aria-label="Show the full path"]')->length);
        $this->assertSame(2, $x->query('//ol//li[contains(@class,"max-sm:hidden")]')->length);
        // A named group: an open <details> elsewhere in the trail (a data-menu dropdown) cannot reveal the crumbs.
        $this->assertSame(1, $x->query('//nav[contains(concat(" ",@class," ")," group/crumbs ")]')->length);
        $this->assertSame(0, $x->query('//nav[contains(concat(" ",@class," ")," group ")]')->length);
        $this->assertSame(2, $x->query('//ol//li[contains(concat(" ",@class," ")," max-sm:group-has-[details[open]]/crumbs:flex ")]')->length);
        $this->assertSame(1, $x->query('//ol[contains(@class,"flex-nowrap")][contains(@class,"sm:flex-wrap")]')->length);

        // The ellipsis is a 44×44 centred target under touch, with no disclosure marker: each a class token of its own.
        $tokens = preg_split('/\s+/', $x->query('//summary[@aria-label="Show the full path"]')->item(0)->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);
        foreach (['pointer-coarse:min-h-11', 'pointer-coarse:min-w-11', 'pointer-coarse:justify-center', '[&::-webkit-details-marker]:hidden'] as $token) {
            $this->assertContains($token, $tokens);
        }
        $this->assertSame([], array_values(array_filter($tokens, static fn (string $t): bool => str_contains($t, 'justify-center') && str_contains($t, 'details-marker'))), 'two utilities merged into one class');
    }

    public function testSkeletonListVariant(): void
    {
        $rows = $this->xpath('skeleton', ['rows' => 2]);
        $this->assertSame(1, $rows->query('//div[@data-skeleton][@role="status"]')->length);
        $this->assertSame(0, $rows->query('//*[contains(@class,"rounded-full")]')->length);

        $list = $this->xpath('skeleton', ['rows' => 2, 'variant' => 'list']);
        $this->assertSame(1, $list->query('//div[@data-skeleton][@role="status"]')->length);
        $this->assertSame(2, $list->query('//div[contains(@class,"h-10")][contains(@class,"rounded-full")]')->length);
    }

    public function testPageHeadTitleIsFluid(): void
    {
        foreach (['page-head' => 'h1', 'page-header' => 'h2'] as $component => $tag) {
            $x = $this->xpath($component, ['title' => 'People', 'actions' => '<button>Go</button>']);
            $this->assertSame(1, $x->query('//' . $tag . '[contains(@class,"text-fluid-h1")]')->length, $component);
            $this->assertSame(1, $x->query('//div[contains(@class,"max-xs:w-full")][contains(@class,"max-xs:*:basis-full")]')->length, $component);
        }
    }

    public function testEmptyStateIsFluidWithPhoneAction(): void
    {
        $x = $this->xpath('empty-state', ['title' => 'None', 'action' => '<a href="/x">Add</a>']);
        $this->assertSame(1, $x->query('//div[contains(@class,"py-10")][contains(@class,"sm:py-14")]')->length);
        $this->assertSame(1, $x->query('//div[contains(@class,"max-sm:w-full")]/a')->length);
    }

    public function testAppTileLabelWrapsAndHitAreaIsLarge(): void
    {
        $x = $this->xpath('app-tile', ['app' => ['key' => 'x', 'label' => 'Long label', 'icon' => 'user', 'url' => '/x']]);
        $this->assertSame(1, $x->query('//a[contains(@class,"pointer-coarse:min-h-11")][contains(@class,"pointer-coarse:active:scale")]')->length);
        $this->assertSame(1, $x->query('//span[contains(@class,"line-clamp-2")]')->length);
    }
}
