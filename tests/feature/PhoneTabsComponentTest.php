<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The phone-tabs component (no database needed).
 *
 * @internal
 */
final class PhoneTabsComponentTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon']);
    }

    private function xpath(array $tabs): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . view('components/phone-tabs', ['label' => 'Dashboard sections', 'tabs' => $tabs], ['saveData' => false]) . '</body>');

        return new DOMXPath($doc);
    }

    private function three(bool $dot = false): array
    {
        return [
            ['key' => 'a', 'label' => 'A'],
            ['key' => 'b', 'label' => 'B', 'dot' => $dot],
            ['key' => 'c', 'label' => 'C'],
        ];
    }

    public function testFirstTabIsSelectedAndOthersLeaveTheTabOrder(): void
    {
        $x = $this->xpath($this->three());

        $this->assertSame(3, $x->query('//div[@role="tablist"][@aria-label="Dashboard sections"]/button[@role="tab"][@data-phone-tab]')->length);
        $this->assertSame('a', $x->query('//button[@aria-selected="true"]')->item(0)->getAttribute('data-phone-tab'));
        $this->assertSame(1, $x->query('//button[@aria-selected="true"]')->length);
        $this->assertSame(2, $x->query('//button[@tabindex="-1"]')->length);
        $this->assertSame(0, $x->query('//button[@aria-selected="true"][@tabindex]')->length);
    }

    public function testDotAddsTheMarkerAndScreenReaderText(): void
    {
        $this->assertSame(0, $this->xpath($this->three())->query('//*[@data-phone-tab-dot]')->length);

        $x = $this->xpath($this->three(true));
        $this->assertSame(1, $x->query('//button[@data-phone-tab="b"]/span[@data-phone-tab-dot][@aria-hidden="true"]')->length);
        $this->assertSame(1, $x->query('//button[@data-phone-tab="b"]/span[contains(@class,"sr-only")][contains(.," (new)")]')->length);
    }

    public function testRootIsPhoneOnlyAndSticky(): void
    {
        $x = $this->xpath($this->three());

        $this->assertSame(1, $x->query('//div[@data-phone-tabs][contains(concat(" ",@class," ")," lg:hidden ")][contains(@class,"sticky")][contains(@class,"bg-white")]')->length);
        $this->assertSame(3, $x->query('//button[contains(concat(" ",@class," ")," min-h-11 ")][contains(concat(" ",@class," ")," flex-1 ")]')->length);
    }
}
