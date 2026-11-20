<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Module management on phones: the module, learning unit and learning item ⋯ menus open as a bottom sheet below md
 * (shared/row-actions.js moves their entries in), their entries are finger-sized on touch, and the rows carry ▲ ▼
 * move buttons for a coarse pointer. Renders the partials alone (no database needed).
 *
 * @internal
 */
final class ModuleManagementResponsiveTest extends CIUnitTestCase
{
    private const MODULE = ['id' => 4, 'title' => 'Intro & Basics', 'description' => 'd', 'status' => 'draft', 'scheduled_publish_at' => null];

    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon', 'feature']);
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');

        return new DOMXPath($doc);
    }

    private function classes(DOMNode $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class')));
    }

    /** @return array<string, array{string, array<string, mixed>, string, string}> partial, data, trigger hook, delete hook */
    public static function menus(): array
    {
        return [
            'module' => ['module-actions', ['module' => self::MODULE], 'data-module-actions', 'data-delete-module'],
            'unit'   => ['learning-unit-actions', ['module' => self::MODULE, 'learning_unit' => ['id' => 7, 'title' => 'Intro & Basics', 'description' => '', 'status' => 'published']], 'data-learning-unit-actions', 'data-delete-learning-unit'],
            'item'   => ['learning-item-actions', ['learning_item' => ['id' => 9, 'title' => 'Intro & Basics', 'status' => 'draft']], 'data-learning-item-actions', 'data-row-delete-learning-item'],
        ];
    }

    /**
     * @dataProvider menus
     *
     * @param array<string, mixed> $data
     */
    public function testTheMenuOpensAsASheetBelowMdWithFingerSizedEntries(string $partial, array $data, string $trigger, string $delete): void
    {
        $x = $this->dom(view('pages/courses/features/modules/partials/' . $partial, $data, ['saveData' => false]));

        $row = $x->query('//*[@data-row]');
        $this->assertCount(1, $row);
        $row = $row->item(0);

        // The phone ⋯: shown below md, 44px, names its row and the one sheet of the page.
        $opener = $x->query('.//button[@data-row-actions-open]', $row);
        $this->assertCount(1, $opener);
        $opener = $opener->item(0);
        $this->assertSame('content-actions-sheet', $opener->getAttribute('aria-controls'));
        $this->assertSame('dialog', $opener->getAttribute('aria-haspopup'));
        $this->assertSame('Actions for Intro & Basics', $opener->getAttribute('aria-label'));
        $this->assertSame('Intro & Basics', $opener->getAttribute('data-row-label'));

        foreach (['md:hidden', 'h-11', 'w-11'] as $class) {
            $this->assertContains($class, $this->classes($opener), $class);
        }

        // The md+ dropdown trigger keeps its hook, hides below md and is 44px on touch.
        $old = $x->query(".//button[@{$trigger}]", $row)->item(0);
        $this->assertNotNull($old);

        foreach (['max-md:hidden', 'pointer-coarse:h-11', 'pointer-coarse:w-11'] as $class) {
            $this->assertContains($class, $this->classes($old), $class);
        }
        $this->assertNotSame('', $old->getAttribute('aria-label'));

        // The entries the sheet moves in, every one 44px on touch, Delete last and marked destructive.
        $entries = $x->query('.//*[@data-action-menu-panel]/*[@data-role="actions"]/button', $row);
        $this->assertGreaterThanOrEqual(5, $entries->length);

        foreach ($entries as $entry) {
            $this->assertContains('pointer-coarse:min-h-11', $this->classes($entry), trim($entry->textContent));
        }

        $last = $entries->item($entries->length - 1);
        $this->assertTrue($last->hasAttribute($delete));
        $this->assertTrue($last->hasAttribute('data-destructive'));
        $this->assertCount(1, $x->query('.//*[@data-destructive]', $row));
    }

    public function testTheSheetIsABottomSheetWithTheSlotTheEntriesMoveInto(): void
    {
        $x     = $this->dom(view('pages/courses/features/modules/partials/content-actions-sheet', [], ['saveData' => false]));
        $sheet = $x->query('//*[@id="content-actions-sheet"][@data-dialog]');

        $this->assertCount(1, $sheet);
        $this->assertSame('sheet', $sheet->item(0)->getAttribute('data-dialog-variant'));
        $this->assertCount(1, $x->query('.//*[@data-row-actions-slot]', $sheet->item(0)));
    }

    public function testTheMoveButtonsShowForACoarsePointerAndAreFingerSized(): void
    {
        $x = $this->dom(view('pages/courses/features/modules/partials/move-buttons', [
            'attr' => 'data-module-move', 'noun' => 'module', 'title' => 'Intro & Basics',
        ], ['saveData' => false]));

        $group = $x->query('//div[button]')->item(0);
        $this->assertContains('hidden', $this->classes($group));
        $this->assertContains('pointer-coarse:flex', $this->classes($group));

        $buttons = $x->query('//button[@data-module-move]');
        $this->assertCount(2, $buttons);
        $this->assertSame('up', $buttons->item(0)->getAttribute('data-module-move'));
        $this->assertSame('down', $buttons->item(1)->getAttribute('data-module-move'));
        $this->assertSame('Move module Intro & Basics up', $buttons->item(0)->getAttribute('aria-label'));

        foreach ($buttons as $button) {
            foreach (['min-h-11', 'min-w-11', 'touch-manipulation'] as $class) {
                $this->assertContains($class, $this->classes($button), $class);
            }
        }
    }

    public function testTheModuleTopBarActionsWrapAndAreFingerSized(): void
    {
        $x   = $this->dom(view('pages/courses/features/modules/partials/module-actions-inline', ['module' => self::MODULE], ['saveData' => false]));
        $bar = $x->query('/html/body/div/div')->item(0);

        $this->assertContains('flex-wrap', $this->classes($bar));
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($x->query('//button[@data-module-publish-toggle]')->item(0)));

        foreach ($x->query('//button[@data-edit-module or @data-module-dependencies or @data-transfer or @data-delete-module]') as $button) {
            $this->assertContains('pointer-coarse:h-11', $this->classes($button), $button->getAttribute('aria-label'));
        }
    }
}
