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
}
