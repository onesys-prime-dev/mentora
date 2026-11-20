<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The dialog component's layouts (components/dialog.php): the variant is
 * written on the root for shared/dialog.js and the CSS, the full-screen form
 * gets its top-bar action, and the default dialog stays as it was.
 *
 * @internal
 */
final class DialogComponentTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url']);
    }

    private function render(array $data): string
    {
        return view('components/dialog', $data + ['id' => 'ann', 'title' => 'New announcement', 'body' => '<p>Body</p>'], ['saveData' => false]);
    }

    public function testAFullScreenFormRendersItsVariantAndTopAction(): void
    {
        $html = $this->render(['variant' => 'fullscreen', 'topAction' => ['label' => 'Post', 'form' => 'ann-form']]);

        $this->assertStringContainsString('data-dialog-variant="fullscreen"', $html);
        $this->assertStringContainsString('<button type="submit" form="ann-form"', $html);
        $this->assertStringContainsString('>Post</button>', $html);
    }

    public function testASheetHasADragHandle(): void
    {
        $html = $this->render(['variant' => 'sheet']);

        $this->assertStringContainsString('data-dialog-variant="sheet"', $html);
        $this->assertStringContainsString('data-dialog-handle', $html);
    }

    public function testTheDefaultDialogHasNoVariantHandleOrTopAction(): void
    {
        $html = $this->render(['topAction' => ['label' => 'Post', 'form' => 'ann-form']]);

        $this->assertStringNotContainsString('data-dialog-variant', $html);
        $this->assertStringNotContainsString('data-dialog-handle', $html);
        $this->assertStringNotContainsString('form="ann-form"', $html);
    }

    public function testAFullScreenDialogWithoutATopActionKeepsItsFooterOnPhones(): void
    {
        $html = $this->render(['variant' => 'fullscreen', 'footer' => '<button>Save</button>']);

        $this->assertStringNotContainsString('hidden sm:flex', $html);
        $this->assertMatchesRegularExpression('/class="flex flex-wrap[^"]*"><button>Save<\/button>/', $html);
    }

    public function testAFullScreenDialogWithATopActionHidesItsFooterOnPhones(): void
    {
        $html = $this->render(['variant' => 'fullscreen', 'footer' => '<button>Save</button>', 'topAction' => ['label' => 'Post', 'form' => 'ann-form']]);

        $this->assertStringContainsString('class="hidden sm:flex flex-wrap', $html);
    }

    public function testAMalformedTopActionIsLeftOut(): void
    {
        foreach (['Post', ['label' => 'Post'], ['label' => '', 'form' => 'ann-form'], ['form' => 'ann-form']] as $topAction) {
            $html = $this->render(['variant' => 'fullscreen', 'topAction' => $topAction]);

            $this->assertStringNotContainsString('type="submit"', $html);
        }
    }

    public function testTheFullScreenTitleCanShrinkToTruncate(): void
    {
        $html = $this->render(['variant' => 'fullscreen']);

        $this->assertStringContainsString('grid-cols-[1fr_minmax(0,auto)_1fr]', $html);
        $this->assertMatchesRegularExpression('/<h2[^>]*class="[^"]*\bmin-w-0\b[^"]*\btruncate\b/', $html);
    }

    public function testTheDefaultPhonePanelClearsTheHomeIndicator(): void
    {
        $this->assertStringContainsString('max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))]', $this->render([]));
        $this->assertStringNotContainsString('max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))]', $this->render(['centered' => true]));
    }

    public function testAConfirmIsCenteredAtEveryWidth(): void
    {
        $html = view('components/confirm', ['id' => 'rm', 'title' => 'Sign out?', 'text' => 'Ends every session.', 'action' => '/x', 'confirmLabel' => 'Sign out'], ['saveData' => false]);

        $this->assertMatchesRegularExpression('/data-dialog[^>]*class="[^"]*\bitems-center\b/', $html);
        $this->assertStringNotContainsString('items-end', $html);
    }

    private const FORM = '<form id="f" method="post"><input type="hidden" name="csrf" value="x"><input type="text" name="name"></form>';

    /** @return array{root: DOMElement, panel: DOMElement, footer: DOMElement|null} */
    private function parts(string $html): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $x = new DOMXPath($doc);

        return [
            'root'   => $x->query('//*[@data-dialog]')->item(0),
            'panel'  => $x->query('//*[@data-dialog-panel]')->item(0),
            'footer' => $x->query('//*[@data-dialog-footer]')->item(0),
        ];
    }

    public function testAFormWithAFieldRendersFullscreen(): void
    {
        foreach (['<input type="email" name="e">', '<textarea name="t"></textarea>', '<select name="s"></select>'] as $field) {
            $html = $this->render(['body' => '<form><input type="hidden" name="csrf">' . $field . '</form>']);

            $this->assertSame('fullscreen', $this->parts($html)['root']->getAttribute('data-dialog-variant'), $field);
        }
    }

    public function testAHiddenFieldsOnlyFormStaysADefaultDialog(): void
    {
        $html = $this->render(['body' => '<form method="post"><input type="hidden" name="csrf" value="x"><input name="id" type="hidden"><button>Go</button></form>']);

        $this->assertFalse($this->parts($html)['root']->hasAttribute('data-dialog-variant'));
        $this->assertStringContainsString('items-end', $html);
    }

    public function testExplicitDefaultOptsOut(): void
    {
        $html = $this->render(['body' => self::FORM, 'variant' => 'default']);

        $this->assertFalse($this->parts($html)['root']->hasAttribute('data-dialog-variant'));
        $this->assertStringContainsString('items-end', $html);
    }

    public function testCenteredWinsOverAuto(): void
    {
        $root = $this->parts($this->render(['body' => self::FORM, 'centered' => true]))['root'];

        $this->assertFalse($root->hasAttribute('data-dialog-variant'));
        $this->assertMatchesRegularExpression('/\bitems-center justify-center p-4\b/', $root->getAttribute('class'));
    }

    public function testFullscreenFooterIsStickyAndHidesFooterCancelOnPhones(): void
    {
        $parts  = $this->parts($this->render(['body' => self::FORM, 'footer' => '<button type="button" data-dialog-close>Cancel</button><button type="submit" form="f">Save</button>']));
        $footer = explode(' ', $parts['footer']->getAttribute('class'));

        foreach (['max-sm:sticky', 'max-sm:bottom-0', 'bg-white', 'max-sm:pb-safe-4', 'max-sm:border-t', 'border-black/10', 'max-sm:[&_[data-dialog-close]]:hidden'] as $class) {
            $this->assertContains($class, $footer, $class);
        }
        // From sm it still sticks, so a short window (a phone held sideways) that makes the panel scroll keeps the action.
        // ...with the same full-width divider, so content scrolling under it is not cut off flat under the buttons.
        foreach (['sm:sticky', 'sm:bottom-0', 'sm:-mx-6', 'sm:border-t', 'sm:px-6', 'sm:pt-4'] as $class) {
            $this->assertContains($class, $footer, $class);
        }
        // The bar carries the bottom safe area, so the panel does not pad it twice.
        $this->assertStringNotContainsString('pb-safe-4', $parts['panel']->getAttribute('class'));

        // With a top action the footer stays hidden on phones, and is not a sticky bar.
        $withTop = $this->parts($this->render(['body' => self::FORM, 'footer' => '<button>Save</button>', 'topAction' => ['label' => 'Save', 'form' => 'f']]));
        $this->assertStringStartsWith('hidden sm:flex', $withTop['footer']->getAttribute('class'));
        $this->assertStringNotContainsString('sticky', $withTop['footer']->getAttribute('class'));
        $this->assertStringContainsString('max-sm:pb-safe-4', $withTop['panel']->getAttribute('class'));
    }

    public function testConfirmNeverGoesFullscreen(): void
    {
        $html = view('components/confirm', ['id' => 'rm', 'title' => 'Delete?', 'text' => 'Gone for good.', 'action' => '/x', 'confirmLabel' => 'Delete', 'typeToConfirm' => 'DELETE'], ['saveData' => false]);

        $this->assertStringContainsString('data-confirm-match="DELETE"', $html);
        $this->assertFalse($this->parts($html)['root']->hasAttribute('data-dialog-variant'));
    }

    public function testAutoStaysDefaultWhenThereIsNoForm(): void
    {
        // Fields a script reads, outside any <form> (the messages pickers), stay a small dialog.
        foreach (['<p>Body</p>', '<div><input type="search"><select></select></div>'] as $body) {
            $parts = $this->parts($this->render(['body' => $body, 'footer' => '<button>OK</button>']));

            $this->assertFalse($parts['root']->hasAttribute('data-dialog-variant'), $body);
            $this->assertStringNotContainsString('sticky', $parts['footer']->getAttribute('class'));
        }
    }

    private const IN_FORM_ACTIONS = '<form method="post" class="flex flex-col gap-4"><input type="hidden" name="csrf"><input name="name">'
        . '<div class="flex justify-end gap-3"><button type="button" data-dialog-close>Cancel</button><button type="submit">Save</button></div></form>';

    private function body(string $html): DOMElement
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return (new DOMXPath($doc))->query('//*[@data-dialog-body]')->item(0);
    }

    public function testAFullscreenFormWithItsButtonsInsideIsMarkedForThePinnedPhoneBar(): void
    {
        $this->assertTrue($this->body($this->render(['body' => self::IN_FORM_ACTIONS]))->hasAttribute('data-dialog-pin-actions'));
    }

    public function testInFormButtonsAreNotPinnedWhenSomethingElseHoldsTheAction(): void
    {
        $cases = [
            'a footer bar'   => ['body' => self::IN_FORM_ACTIONS, 'footer' => '<button>Save</button>'],
            'a top action'   => ['body' => self::IN_FORM_ACTIONS, 'topAction' => ['label' => 'Save', 'form' => 'f']],
            'two forms'      => ['body' => self::IN_FORM_ACTIONS . self::IN_FORM_ACTIONS],
            'not fullscreen' => ['body' => self::IN_FORM_ACTIONS, 'variant' => 'default'],
        ];

        foreach ($cases as $case => $data) {
            $this->assertFalse($this->body($this->render($data))->hasAttribute('data-dialog-pin-actions'), $case);
        }
    }

    public function testTheReauthPromptIsCentered(): void
    {
        $this->assertStringContainsString("'centered' => true", file_get_contents(APPPATH . 'Views/layouts/partials/reauth-dialog.php'));
    }
}
