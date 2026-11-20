<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Learning item content on phones (no database): reading text is contained, and the assignment/quiz detail panel
 * sits under the item below xl and beside it from xl up.
 *
 * @internal
 */
final class LearningItemContentTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon']);
    }

    private function render(array $item, array $extra = []): DOMXPath
    {
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

        $item += ['id' => 11, 'title' => 'Item', 'is_completed' => false, 'is_locked' => false, 'can_complete' => true,
            'complete_hint' => '', 'status' => 'published'];
        $unit = ['id' => 3, 'title' => 'Unit', 'status' => 'published', 'learning_items' => [$item]];
        $module = ['id' => 2, 'order_index' => 1, 'title' => 'Module', 'progress' => 0, 'status' => 'published', 'learning_units' => [$unit]];

        $html = view('pages/courses/features/modules/learning-item-view', [
            'user' => $user, 'module' => $module, 'currentUnit' => $unit, 'currentItem' => $item,
            'currentLock' => null, 'previousItem' => null, 'nextItem' => null,
        ] + $extra, ['saveData' => false]);

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    private function classes(DOMNode $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class')));
    }

    public function testReadingTextIsEscapedInsideTheReadingWrapperAndTheContainmentRulesExistInTheStylesheet(): void
    {
        $xpath = $this->render([
            'item_type' => 'reading',
            'content' => ['blocks' => [['type' => 'paragraph', 'content' => [['text' => str_repeat('Unbroken', 40) . ' <table><pre><img src=x>']]]]],
        ]);

        $reading = $xpath->query('//div[contains(concat(" ", @class, " "), " reading-content ")]')->item(0);
        $this->assertNotNull($reading);
        // Authored text is escaped, so markup typed into a paragraph stays text.
        $this->assertSame(0, $xpath->query('.//table | .//pre | .//img', $reading)->length);
        $this->assertStringContainsString('UnbrokenUnbroken', $reading->textContent);

        // The content column can shrink (min-w-0) so the page does not widen around it.
        $column = $xpath->query('//aside[@data-learning-item-panel]/following-sibling::div[1]')->item(0);
        $this->assertContains('min-w-0', $this->classes($column));

        // Only the source of the rules is checked here; that a long word does not widen the page is proved by the
        // 'reading with unbroken text' Playwright test in tests/e2e/responsive.spec.js.
        $css = file_get_contents(ROOTPATH . 'resources/css/components/reading.css');
        $this->assertMatchesRegularExpression('/\.reading-content\s*\{[^}]*wrap-anywhere/', $css);
        $this->assertMatchesRegularExpression('/\.reading-content table,\s*\.reading-content pre\s*\{[^}]*overflow-x-auto/', $css);
        $this->assertMatchesRegularExpression('/\.reading-content img,[^{]*\{[^}]*max-w-full/', $css);
    }

    public function testTheDetailPanelStacksBelowXlAndSitsBesideTheItemFromXl(): void
    {
        $xpath = $this->render(
            ['item_type' => 'assignment', 'content' => []],
            ['currentAssessment' => ['id' => 5, 'total_points' => 10, 'title' => 'A'], 'assessmentContext' => []],
        );

        $section = $xpath->query('//*[@data-learning-item-main]//section')->item(0);
        foreach (['flex-col', 'xl:flex-row'] as $class) {
            $this->assertContains($class, $this->classes($section));
        }
        $this->assertNotContains('flex-row', $this->classes($section));

        $panel = $xpath->query('./div[contains(@class, "xl:w-1/5")]', $section)->item(0);
        $this->assertNotNull($panel, 'the panel is a sibling of the item column');
        $this->assertContains('w-full', $this->classes($panel));
        $this->assertNotContains('w-1/5', $this->classes($panel), 'the fifth-width is xl: only');
    }
}
