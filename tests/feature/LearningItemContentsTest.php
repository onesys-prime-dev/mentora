<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The learning item view on phones: the module outline is a "Contents" dropdown above the item and a sidebar
 * from lg up, and Previous / Continue stick to the bottom (no database needed).
 *
 * @internal
 */
final class LearningItemContentsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon']);
    }

    private function render(): DOMXPath
    {
        // A student, without the database the group lookups would read.
        $user = new class (['id' => 9]) extends User {
            public function inGroup(string ...$groups): bool
            {
                return in_array('student', $groups, true);
            }

            public function getGroups(): ?array
            {
                return ['student'];
            }
        };

        $item = fn (int $id, string $title) => [
            'id' => $id, 'title' => $title, 'item_type' => 'reading', 'is_completed' => false, 'is_locked' => false,
            'can_complete' => true, 'complete_hint' => '', 'status' => 'published', 'content' => ['blocks' => []],
        ];
        $unit = ['id' => 3, 'title' => 'Getting started', 'status' => 'published', 'learning_items' => [$item(11, 'First steps'), $item(12, 'Second steps')]];
        $module = [
            'id' => 2, 'order_index' => 1, 'title' => str_repeat('A very long module title ', 6), 'progress' => 40,
            'status' => 'published', 'learning_units' => [$unit],
        ];

        $html = view('pages/courses/features/modules/learning-item-view', [
            'user' => $user, 'module' => $module, 'currentUnit' => $unit, 'currentItem' => $unit['learning_items'][0],
            'currentLock' => null, 'previousItem' => null, 'nextItem' => $unit['learning_items'][1],
        ], ['saveData' => false]);

        $doc = new DOMDocument();
        @$doc->loadHTML($html);

        return new DOMXPath($doc);
    }

    private function classes(DOMNode $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class')));
    }

    public function testTheOutlineIsRenderedOnceInsideAContentsDropdownThatIsASidebarFromLgUp(): void
    {
        $xpath = $this->render();

        $this->assertSame(1, $xpath->query('//*[@data-learning-item-panel]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-panel-item="12"]')->length, 'each item is listed once');

        $details = $xpath->query('//aside[@data-learning-item-panel]/details[@data-menu]')->item(0);
        $this->assertNotNull($details);
        $this->assertSame(2, $xpath->query('.//*[@data-panel-item]', $details)->length, 'the outline is inside the dropdown');

        // The layout stacks below lg and is a row from lg up; the aside is the 320px sidebar there.
        $row = $xpath->query('//aside[@data-learning-item-panel]/..')->item(0);
        $this->assertContains('flex-col', $this->classes($row));
        $this->assertContains('lg:flex-row', $this->classes($row));
        $aside = $xpath->query('//aside[@data-learning-item-panel]')->item(0);
        foreach (['lg:w-80', 'lg:border-r', 'bg-[#F9F9FC]'] as $class) {
            $this->assertContains($class, $this->classes($aside));
        }
        $this->assertNotContains('w-80', $this->classes($aside), 'no fixed width below lg');
        $this->assertContains('lg:sticky', $this->classes($details));

        // The summary reads "Contents" and the module title (truncated), and hides from lg up.
        $summary = $xpath->query('./summary', $details)->item(0);
        $this->assertContains('lg:hidden', $this->classes($summary));
        $this->assertStringContainsString('Contents', $summary->textContent);
        $title = $xpath->query('./span[contains(@class, "truncate")]', $summary)->item(0);
        $this->assertNotNull($title);
        $this->assertStringContainsString('A very long module title', $title->textContent);

        // The panel is styled like a header panel below lg only.
        $panel = $xpath->query('./*[@data-contents-panel]', $details)->item(0);
        foreach (['bg-white', 'lg:bg-transparent', 'max-lg:absolute', 'max-lg:rounded-2xl', 'max-lg:shadow-xl', 'max-lg:max-h-[min(70dvh,32rem)]', 'max-lg:overflow-y-auto'] as $class) {
            $this->assertContains($class, $this->classes($panel));
        }

        // The content column beside it takes the rest, with the fluid padding.
        $column = $xpath->query('//aside[@data-learning-item-panel]/following-sibling::div[1]')->item(0);
        foreach (['min-w-0', 'flex-1', 'p-5', 'sm:p-8', 'lg:p-10'] as $class) {
            $this->assertContains($class, $this->classes($column));
        }
    }

    public function testTheFooterSticksToTheBottomBelowLgWithTouchSizedButtons(): void
    {
        $xpath = $this->render();

        $footer = $xpath->query('//*[@data-learning-item-footer]')->item(0);
        $this->assertNotNull($footer);
        foreach (['bg-white', 'max-lg:sticky', 'max-lg:bottom-0', 'max-lg:pb-safe-2'] as $class) {
            $this->assertContains($class, $this->classes($footer));
        }

        $continue = $xpath->query('//*[@data-continue]')->item(0);
        foreach (['max-lg:w-full', 'max-lg:min-h-11'] as $class) {
            $this->assertContains($class, $this->classes($continue));
        }
        // Not completed yet: Continue waits, and stays hidden (.inline-flex would win over .hidden).
        $this->assertContains('hidden', $this->classes($continue));
        $this->assertNotContains('inline-flex', $this->classes($continue));
        // No previous item: Previous stays, disabled.
        $previous = $xpath->query('//footer//button[contains(., "Previous")]')->item(0);
        $this->assertTrue($previous->hasAttribute('disabled'));
        $this->assertContains('max-lg:min-h-11', $this->classes($previous));
        $this->assertContains('max-lg:min-h-11', $this->classes($xpath->query('//*[@data-mark-complete]')->item(0)));
    }
}
