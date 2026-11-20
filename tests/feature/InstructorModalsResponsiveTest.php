<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The instructor dialogs on phones (assessments, modules and grades partials/modals). They are the older
 * modals (modals/index.js) apart from the item create dialog (a components/dialog, full-screen by the auto
 * rule, see LearningItemEditorResponsiveTest), so none takes `'variant' => 'default'`: each stays a centred
 * dialog at every size. Each panel is no taller than the window and its body scrolls while the header and the
 * footer (the primary action) stay; every button and field is 44px on a touch screen, fields are 16px on a
 * phone, and the × has a name. Read from the files with the PHP removed (several need the database to render).
 *
 * @internal
 */
final class InstructorModalsResponsiveTest extends CIUnitTestCase
{
    private const DIRS = [
        'pages/courses/features/assessments/partials/modals',
        'pages/courses/features/modules/partials/modals',
        'pages/courses/features/grades/partials/modals',
    ];

    // Task 8's: a components/dialog, and a field group shared by two dialogs (tested with the item editors).
    private const NOT_MODALS = ['learning-item-create', 'publish-fields'];

    /** @return array<string, array{string}> */
    public static function modals(): array
    {
        $files = [];

        foreach (self::DIRS as $dir) {
            foreach (glob(APPPATH . 'Views/' . $dir . '/*.php') as $file) {
                $name = basename($file, '.php');

                if (! in_array($name, self::NOT_MODALS, true)) {
                    $files[$name] = [$file];
                }
            }
        }

        return $files;
    }

    private function dom(string $file): DOMXPath
    {
        $php = (string) file_get_contents($file);
        // A class list kept in a variable ($select = '...';) is put back where it is echoed; other PHP goes.
        preg_match_all("/\\$(\\w+)\\s*=\\s*'([^']*)';/", $php, $vars, PREG_SET_ORDER);
        foreach ($vars as [, $name, $value]) {
            $php = str_replace('<?= $' . $name . ' ?>', $value, $php);
        }
        $html = (string) preg_replace('/<\?(?:php|=).*?\?>/s', '', $php);
        $doc  = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div id="test-root">' . $html . '</div>');

        return new DOMXPath($doc);
    }

    private function classes(DOMElement $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
    }

    private function hasAny(DOMElement $node, string $pattern): bool
    {
        return preg_grep($pattern, $this->classes($node)) !== [];
    }

    private function panel(DOMXPath $x): DOMElement
    {
        $panel = $x->query('//div[@id="test-root"]/div[contains(@class, "fixed")]/div')->item(0);
        $this->assertInstanceOf(DOMElement::class, $panel, 'the modal has a panel');

        return $panel;
    }

    #[DataProvider('modals')]
    public function testThePanelFitsTheWindowAndScrolls(string $file): void
    {
        $x     = $this->dom($file);
        $panel = $this->panel($x);

        $this->assertTrue($this->hasAny($panel, '/^max-h-/'), 'the panel is no taller than the window');
        $this->assertNotContains('h-full', $this->classes($panel), 'a centred dialog, not full-screen');

        $scrolls = $this->hasAny($panel, '/^overflow-y-auto$/')
            || $x->query('./*[contains(concat(" ", normalize-space(@class), " "), " overflow-y-auto ")]', $panel)->length > 0;
        $this->assertTrue($scrolls, 'a long body scrolls inside the panel');
    }

    #[DataProvider('modals')]
    public function testTheHeaderAndFooterStayWhileTheBodyScrolls(string $file): void
    {
        $x     = $this->dom($file);
        $panel = $this->panel($x);

        if (! in_array('flex-col', $this->classes($panel), true)) {
            // The item Dependencies dialog (Task 8's): a short panel that scrolls as one.
            $this->assertContains('overflow-y-auto', $this->classes($panel));

            return;
        }

        foreach ($x->query('./*', $panel) as $child) {
            if ($this->hasAny($child, '/^border-[bt]$/')) {
                $this->assertContains('shrink-0', $this->classes($child), 'a header or footer keeps its height: ' . trim(substr($child->textContent, 0, 40)));
            }
        }
    }

    #[DataProvider('modals')]
    public function testEveryButtonIsAFingerWideOnTouch(string $file): void
    {
        $x = $this->dom($file);

        $short = [];

        foreach ($x->query('//button') as $button) {
            if (! $this->hasAny($button, '/^(pointer-coarse:)?(min-h-11|h-11|h-12)$|^(py-3|py-3\.5|py-4|p-3|p-4)$/')) {
                $short[] = trim($button->textContent) ?: $button->getAttribute('aria-label');
            }
        }

        $this->assertSame([], $short, 'buttons under 44px tall on touch');
    }

    #[DataProvider('modals')]
    public function testTheCloseButtonHasANameAndIs44px(string $file): void
    {
        $x = $this->dom($file);

        $bad = [];

        foreach ($x->query('//button[.//svg and normalize-space(.) = ""]') as $button) {
            $classes = $this->classes($button);

            if ($button->getAttribute('aria-label') === '' || ! in_array('pointer-coarse:min-h-11', $classes, true) || ! in_array('pointer-coarse:min-w-11', $classes, true)) {
                $bad[] = $button->getAttribute('class');
            }
        }

        $this->assertSame([], $bad, 'icon buttons without a name or under 44×44 on touch');
    }

    #[DataProvider('modals')]
    public function testFieldsAre16pxOnAPhone(string $file): void
    {
        $x = $this->dom($file);

        $fields = $x->query('//select | //textarea | //input[not(@type="hidden") and not(@type="checkbox") and not(@type="radio") and not(@type="file")]');

        $small = [];

        foreach ($fields as $field) {
            if (! in_array('text-base', $this->classes($field), true)) {
                $small[] = $field->getAttribute('aria-label') ?: $field->getAttribute('name') ?: $field->getAttribute('id') ?: $field->nodeName;
            }
        }

        $this->assertSame([], $small, 'fields under 16px on a phone (iOS zooms on focus)');
    }

    #[DataProvider('modals')]
    public function testEveryFieldHasAName(string $file): void
    {
        $x        = $this->dom($file);
        $unnamed  = [];
        $fields   = $x->query('//select | //textarea | //input[not(@type="hidden")]');

        foreach ($fields as $field) {
            $id    = $field->getAttribute('id');
            $named = $field->getAttribute('aria-label') !== ''
                || $field->getAttribute('aria-labelledby') !== ''
                || $x->query('ancestor::label', $field)->length > 0
                || ($id !== '' && $x->query('//label[@for="' . $id . '"]')->length > 0);

            if (! $named) {
                $unnamed[] = $field->getAttribute('name') ?: $field->getAttribute('type') ?: $field->nodeName;
            }
        }

        $this->assertSame([], $unnamed, 'fields with no accessible name');
    }

    #[DataProvider('modals')]
    public function testEveryCheckboxOrRadioLabelIs44pxOnTouch(string $file): void
    {
        $x     = $this->dom($file);
        $short = [];

        foreach ($x->query('//label[.//input[@type="checkbox" or @type="radio"]]') as $label) {
            if (! $this->hasAny($label, '/^(pointer-coarse:)?min-h-11$|^(py-3|py-4|p-3|p-4)$/') && $x->query('.//*[contains(@class, "py-3")]', $label)->length === 0) {
                $short[] = trim(preg_replace('/\s+/', ' ', $label->textContent)) ?: $label->getAttribute('class');
            }
        }

        $this->assertSame([], $short, 'checkbox and radio labels under 44px tall on touch');
    }

    public function testTheShortFieldDialogsAreNamedAndFingerSized(): void
    {
        $ai = $this->dom(APPPATH . 'Views/pages/courses/features/assessments/partials/modals/ai-question-settings.php');

        foreach (['Required Question', 'Allow Multiple Answers', 'Case Sensitive'] as $name) {
            $toggle = $ai->query('//input[@type="checkbox"][@aria-label="' . $name . '"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $toggle, $name);
            $this->assertContains('pointer-coarse:min-h-11', $this->classes($toggle->parentNode), "{$name} switch is 44px tall on touch");
        }

        $count = $ai->query('//input[@aria-label="How many answers?"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $count);
        $this->assertContains('text-base', $this->classes($count));

        $item = $this->dom(APPPATH . 'Views/pages/courses/features/grades/partials/modals/grade-item-create.php');
        $max  = $item->query('//input[@aria-label="Max points"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $max);
        $this->assertSame('decimal', $max->getAttribute('inputmode'));
        $this->assertContains('text-base', $this->classes($max));
        $this->assertSame(1, $item->query('//input[@aria-label="Name"]')->length);
    }

    public function testThePhoneQuizBuilderBarOffersGenerateWithAi(): void
    {
        $bar = (string) file_get_contents(APPPATH . 'Views/pages/courses/features/assessments/partials/quiz-builder-bar.php');

        // Below md the palette (and its Build Faster button) does not show: the bar's panel carries the button too.
        $this->assertStringContainsString('data-open-ai-generate', $bar);
        $this->assertStringContainsString("feature_enabled('ai.question_generation')", $bar);

        $script = (string) file_get_contents(ROOTPATH . 'resources/js/pages/courses/features/assessments/ai-generation/ai-generation.js');
        $this->assertStringContainsString('.querySelectorAll("[data-open-ai-generate]")', $script, 'both buttons open the dialog');
    }

    public function testTheCurvePreviewTableScrollsInsideTheDialog(): void
    {
        $script = (string) file_get_contents(ROOTPATH . 'resources/js/pages/courses/features/grades/curve/curve-dialog.js');

        $this->assertMatchesRegularExpression('/<div data-scroll-x role="region" aria-label="Curve preview" tabindex="0" class="overflow-x-auto">\s*<table class="w-full min-w-max/', $script);
    }
}
