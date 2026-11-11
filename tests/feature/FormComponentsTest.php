<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phone sizing of the form field, toolbar, stepper and pagination components.
 *
 * @internal
 */
final class FormComponentsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url']);
    }

    private function render(string $component, array $data): string
    {
        return view('components/' . $component, $data, ['saveData' => false]);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    private function first(string $html, string $query): DOMElement
    {
        $node = $this->xpath($html)->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, $query);

        return $node;
    }

    public function testFormFieldInputsAre16pxOnPhonesAnd44pxOnTouch(): void
    {
        foreach (['text', 'select', 'textarea'] as $type) {
            $html  = $this->render('form-field', ['name' => 'x', 'label' => 'X', 'type' => $type]);
            $class = $this->first($html, '//*[@id][self::input or self::select or self::textarea]')->getAttribute('class');
            $this->assertStringContainsString('text-base', $class, $type);
            $this->assertStringContainsString('sm:text-sm', $class, $type);
            $this->assertStringContainsString('pointer-coarse:min-h-11', $class, $type);
            $this->assertStringNotContainsString(' text-sm ', ' ' . $class . ' ', $type);
        }

        $box = $this->render('form-field', ['name' => 'on', 'label' => 'On', 'type' => 'checkbox']);
        $this->assertStringContainsString('pointer-coarse:min-h-11', $this->first($box, '//label')->getAttribute('class'));
        $this->assertSame('on', $this->first($box, '//div[@data-field]')->getAttribute('data-field'));
    }

    public function testEmailAndNumberDefaultsAndExplicitWins(): void
    {
        $email = $this->first($this->render('form-field', ['name' => 'e', 'label' => 'E', 'type' => 'email']), '//input[@id]');
        $this->assertSame('email', $email->getAttribute('inputmode'));
        $this->assertSame('email', $email->getAttribute('autocomplete'));

        $number = $this->first($this->render('form-field', ['name' => 'n', 'label' => 'N', 'type' => 'number']), '//input[@id]');
        $this->assertSame('numeric', $number->getAttribute('inputmode'));
        $this->assertFalse($number->hasAttribute('autocomplete'));

        $pass = $this->first($this->render('form-field', ['name' => 'p', 'label' => 'P', 'type' => 'password']), '//input[@id]');
        $this->assertSame('current-password', $pass->getAttribute('autocomplete'));

        $text = $this->first($this->render('form-field', ['name' => 't', 'label' => 'T']), '//input[@id]');
        $this->assertFalse($text->hasAttribute('inputmode'));
        $this->assertFalse($text->hasAttribute('autocomplete'));

        $own = $this->first($this->render('form-field', ['name' => 'e', 'label' => 'E', 'type' => 'email', 'inputmode' => 'text', 'autocomplete' => 'username']), '//input[@id]');
        $this->assertSame('text', $own->getAttribute('inputmode'));
        $this->assertSame('username', $own->getAttribute('autocomplete'));

        $escaped = $this->render('form-field', ['name' => 'a', 'label' => 'A', 'autocomplete' => '"><x']);
        $this->assertStringNotContainsString('"><x', $escaped);
    }

    public function testCallerAttrsKeepTheirAutocompleteAndInputmode(): void
    {
        $count = static fn (string $html, string $attr): int => preg_match('/<input[^>]*>/', $html, $m) ? substr_count($m[0], ' ' . $attr . '=') : 0;

        $pass = $this->render('form-field', ['name' => 'p', 'label' => 'P', 'type' => 'password', 'attrs' => 'autocomplete="new-password"']);
        $this->assertSame(1, $count($pass, 'autocomplete'));
        $this->assertSame('new-password', $this->first($pass, '//input[@id]')->getAttribute('autocomplete'));

        $num = $this->render('form-field', ['name' => 'n', 'label' => 'N', 'type' => 'number', 'attrs' => 'inputmode="decimal"']);
        $this->assertSame(1, $count($num, 'inputmode'));
        $this->assertSame('decimal', $this->first($num, '//input[@id]')->getAttribute('inputmode'));

        $own = $this->render('form-field', ['name' => 'p', 'label' => 'P', 'type' => 'password', 'autocomplete' => 'off', 'attrs' => '']);
        $this->assertSame(1, $count($own, 'autocomplete'));
        $this->assertSame('off', $this->first($own, '//input[@id]')->getAttribute('autocomplete'));
    }

    public function testDerivedDefaultsOnlySkipWhenAttrsNameTheAttribute(): void
    {
        // Raw count of an attribute name in the input tag, quoted values blanked.
        $count = static function (string $html, string $attr): int {
            preg_match('/<input[^>]*>/', $html, $m);
            $tag = preg_replace('/"[^"]*"/', '""', $m[0]);

            return preg_match_all('/\s' . $attr . '\s*=/i', $tag);
        };
        $input = fn (string $type, string $attrs): array => [$html = $this->render('form-field', ['name' => 'f', 'label' => 'F', 'type' => $type, 'attrs' => $attrs]), $this->first($html, '//input[@id]')];

        [$html, $el] = $input('password', 'aria-autocomplete="list"');
        $this->assertSame(1, $count($html, 'autocomplete'));
        $this->assertSame('current-password', $el->getAttribute('autocomplete'));
        $this->assertSame('list', $el->getAttribute('aria-autocomplete'));

        [$html, $el] = $input('email', 'data-student-autocomplete="x"');
        $this->assertSame(1, $count($html, 'autocomplete'));
        $this->assertSame('email', $el->getAttribute('autocomplete'));

        [$html, $el] = $input('email', 'data-x="autocomplete=off"');
        $this->assertSame(1, $count($html, 'autocomplete'));
        $this->assertSame('email', $el->getAttribute('autocomplete'));

        foreach (['autocomplete = "off"', 'AUTOCOMPLETE="off"'] as $attrs) {
            [$html, $el] = $input('email', $attrs);
            $this->assertSame(1, $count($html, 'autocomplete'), $attrs);
            $this->assertSame('off', $el->getAttribute('autocomplete'), $attrs);
        }

        [$html, $el] = $input('number', 'data-inputmode="x"');
        $this->assertSame(1, $count($html, 'inputmode'));
        $this->assertSame('numeric', $el->getAttribute('inputmode'));

        foreach (['inputmode = "text"', 'INPUTMODE="text"'] as $attrs) {
            [$html, $el] = $input('number', $attrs);
            $this->assertSame(1, $count($html, 'inputmode'), $attrs);
            $this->assertSame('text', $el->getAttribute('inputmode'), $attrs);
        }
    }

    public function testToolbarFieldsAreStackedAndLargeOnPhones(): void
    {
        $html = $this->render('toolbar', ['action' => '/p', 'fields' => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search'],
            ['type' => 'select', 'name' => 'r', 'label' => 'Role', 'options' => ['' => 'Any']],
            ['type' => 'date', 'name' => 'd', 'label' => 'From'],
        ]]);

        foreach ($this->xpath($html)->query('//input[not(@type="hidden")] | //select') as $control) {
            $class = $control->getAttribute('class');
            $this->assertStringContainsString('text-base', $class);
            $this->assertStringContainsString('sm:text-sm', $class);
            $this->assertStringContainsString('pointer-coarse:min-h-11', $class);
        }

        $form = $this->first($html, '//form')->getAttribute('class');
        $this->assertStringContainsString('grid-cols-1', $form);
        $this->assertStringContainsString('sm:grid-cols-2', $form);

        $this->assertStringContainsString('max-sm:w-full', $this->first($html, '//button[@type="submit"]')->getAttribute('class'));
        $this->assertStringContainsString('pointer-coarse:min-h-11', $this->first($html, '//button[@type="submit"]')->getAttribute('class'));
        $this->assertStringContainsString('xl:[grid-template-columns:var(--toolbar-cols)]', $this->render('toolbar', ['action' => '/p', 'inline' => true, 'fields' => []]));
    }

    public function testStepperKeepsItsHooksAndAddsThePhoneSummary(): void
    {
        $html = $this->render('stepper', ['steps' => ['Upload', 'Preview', 'Apply'], 'current' => 2]);
        $xp   = $this->xpath($html);

        $this->assertSame(3, $xp->query('//ol[@data-stepper]//li[@data-step]')->length);
        $this->assertSame('current', $this->first($html, '//li[@data-step="2"]')->getAttribute('data-state'));
        $this->assertStringContainsString('max-sm:sr-only', $this->first($html, '//ol[@data-stepper]')->getAttribute('class'));

        $bar = $this->first($html, '//*[@role="progressbar"]');
        $this->assertSame('2', $bar->getAttribute('aria-valuenow'));
        $this->assertSame('1', $bar->getAttribute('aria-valuemin'));
        $this->assertSame('3', $bar->getAttribute('aria-valuemax'));
        $this->assertNotSame('', $bar->getAttribute('aria-label'));

        $summary = $this->first($html, '//*[@data-stepper-summary]');
        $this->assertStringContainsString('sm:hidden', $summary->getAttribute('class'));
        $this->assertStringContainsString('Step 2 of 3', $summary->textContent);
        $this->assertStringContainsString('Preview', $summary->textContent);
    }

    public function testPaginationTargets(): void
    {
        $html = $this->render('pagination', ['page' => 2, 'pages' => 4, 'baseUrl' => '/x']);

        foreach (['prev', 'next'] as $rel) {
            $class = $this->first($html, '//a[@rel="' . $rel . '"]')->getAttribute('class');
            $this->assertStringContainsString('pointer-coarse:min-h-11', $class);
            $this->assertStringContainsString('max-sm:flex-1', $class);
            $this->assertStringContainsString('max-sm:text-center', $class);
        }

        $this->assertStringContainsString('max-sm:w-full', $this->first($html, '//nav/span[2]')->getAttribute('class'));
    }
}
