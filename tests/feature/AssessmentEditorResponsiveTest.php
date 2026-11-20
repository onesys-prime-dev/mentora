<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The assessment editor at every width: the settings are the pane beside the content from lg up, a side panel opened
 * from the top bar at md–lg, and below md a "Settings" section stacked under the content. The Save as Draft /
 * Publish footer is pinned to the bottom edge below lg. Rendered with view() and a stand-in instructor (no database).
 *
 * @internal
 */
final class AssessmentEditorResponsiveTest extends CIUnitTestCase
{
    private const DIR = 'pages/courses/features/assessments/';

    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon', 'feature']);
    }

    private function instructor(): User
    {
        return new class (['id' => 1]) extends User {
            public function inGroup(string ...$groups): bool
            {
                return in_array('instructor', $groups, true);
            }

            public function getGroups(): ?array
            {
                return ['instructor'];
            }
        };
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    /** The editor page for an assignment or a quiz, as the editor service renders it. */
    private function editor(string $type = 'Assignment'): DOMXPath
    {
        return $this->dom(view(self::DIR . 'editor', [
            'user'              => $this->instructor(),
            'currentAssessment' => [
                'id' => 9, 'title' => 'Assignment 1: Grade Calculator', 'assessment_type' => $type, 'status' => 'Draft',
                'due_date' => null, 'available_from' => null, 'available_until' => null, 'total_points' => 30,
                'attempt_limit' => 1, 'passing_percentage' => null, 'passing_score' => null, 'duration' => null,
                'instructions' => ['version' => 1, 'blocks' => []],
            ],
            'currentUnit'      => ['title' => 'Unit 1'],
            'currentSettings'  => [],
            'currentRubric'    => null,
            'currentQuestions' => [],
        ]));
    }

    private function classes(DOMNode $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class')));
    }

    private function one(DOMXPath $x, string $query): DOMElement
    {
        $nodes = $x->query($query);
        $this->assertSame(1, $nodes->length, $query);

        return $nodes->item(0);
    }

    public function testTheSettingsAreAPaneASidePanelAndAPhoneSection(): void
    {
        $x     = $this->editor();
        $panel = $this->one($x, '//aside[@id="editor-inspector-panel"]');

        // Beside the content from lg (a clamped width, no longer w-1/5), a side panel below lg, stacked below md.
        $this->assertTrue($panel->hasAttribute('data-side-panel'));
        $this->assertTrue($panel->hasAttribute('data-side-panel-stack'));
        $this->assertSame('9', $panel->getAttribute('data-assessment-id'));
        $this->assertContains('lg:w-[clamp(16rem,20%,22rem)]', $this->classes($panel));
        $this->assertNotContains('w-1/5', $this->classes($panel));

        // The md–lg toggle in the top bar, and the panel's own close button.
        $toggle = $this->one($x, '//*[contains(@class,"sticky-bar-top")]//button[@data-side-panel-toggle][@aria-controls="editor-inspector-panel"]');
        foreach (['hidden', 'md:inline-flex', 'lg:hidden'] as $class) {
            $this->assertContains($class, $this->classes($toggle), $class);
        }
        $this->assertStringContainsString('Settings', $toggle->textContent);
        $this->assertContains('lg:hidden', $this->classes($this->one($x, '//aside[@id="editor-inspector-panel"]//button[@data-side-panel-close]')));

        // Below md: an open "Settings" section with a 44px summary that shows only there.
        $details = $this->one($x, '//aside[@id="editor-inspector-panel"]/details[@data-inspector-settings]');
        $this->assertTrue($details->hasAttribute('open'));
        $summary = $this->one($x, '//details[@data-inspector-settings]/summary');
        $this->assertContains('min-h-11', $this->classes($summary));
        $this->assertContains('md:hidden', $this->classes($summary));
        $this->assertStringContainsString('Settings', $summary->textContent);
        $this->assertGreaterThan(0, $x->query('//details[@data-inspector-settings]//*[@data-inspector-field="due_date"]')->length);
    }

    public function testTheSettingsFieldsUseTheFormFieldSizes(): void
    {
        $x = $this->editor();

        $fields = $x->query('//aside[@id="editor-inspector-panel"]//input[@type="datetime-local" or @type="number" or @type="text"]');
        $this->assertGreaterThan(5, $fields->length);
        foreach ($fields as $field) {
            $name = $field->getAttribute('data-inspector-field') ?: 'passing score';
            foreach (['text-base', 'sm:text-sm', 'pointer-coarse:min-h-11'] as $class) {
                $this->assertContains($class, $this->classes($field), "{$name}: {$class}");
            }
        }

        // The date-time fields take the column's width (they stack), and the numbers bring up a number pad.
        foreach ($x->query('//aside[@id="editor-inspector-panel"]//input[@type="datetime-local"]') as $field) {
            $this->assertContains('w-full', $this->classes($field));
            $this->assertContains('min-w-0', $this->classes($field));
        }
        foreach ($x->query('//aside[@id="editor-inspector-panel"]//input[@type="number"]') as $field) {
            $this->assertSame('numeric', $field->getAttribute('inputmode'), $field->getAttribute('data-inspector-field'));
        }
    }

    public function testTheActionFooterIsPinnedBelowLg(): void
    {
        $x      = $this->editor();
        $footer = $this->one($x, '//footer[.//*[@data-publish]]');

        foreach (['sticky-bar-bottom', 'max-lg:sticky', 'max-lg:bottom-0', 'max-lg:z-20', 'max-lg:pb-safe-2'] as $class) {
            $this->assertContains($class, $this->classes($footer), $class);
        }

        // Publish and Save as Draft share the row and are 44px; delete is an icon button with a name.
        foreach (['data-save-draft', 'data-publish'] as $hook) {
            $button = $this->one($x, "//footer//button[@{$hook}]");
            $this->assertContains('max-lg:min-h-11', $this->classes($button), $hook);
            $this->assertContains('max-lg:flex-1', $this->classes($button), $hook);
        }
        $delete = $this->one($x, '//footer//button[@data-delete-assessment]');
        $this->assertSame('Delete assessment', $delete->getAttribute('aria-label'));
        $this->assertSame('9', $delete->getAttribute('data-assessment-id'));
        $this->assertContains('max-lg:min-w-11', $this->classes($delete));

        // The page pads like the others, rests the footer on the bottom edge, and a focused field clears the footer.
        $page = $this->one($x, '/html/body/div[1]');
        foreach (['p-5', 'sm:p-8', 'lg:p-10', 'max-lg:pb-0', 'max-lg:min-h-dvh'] as $class) {
            $this->assertContains($class, $this->classes($page), $class);
        }
        $this->assertNotEmpty(preg_grep('/^max-lg:\[&_:is\(input,select,textarea,\[contenteditable\]\)\]:scroll-mb-24$/', $this->classes($page)));
    }

    public function testTheTopBarTruncatesTheTitleAndTheEditorsWrapTheirToolbars(): void
    {
        $x = $this->editor();

        $this->one($x, '//*[contains(@class,"sticky-bar-top")]/div[contains(@class,"min-w-0")]//span[@aria-current="page"][contains(@class,"truncate")]');

        $toolbar = $this->one($x, '//*[@data-instructions-editor]/div[1]');
        $this->assertContains('flex-wrap', $this->classes($toolbar));
        foreach ($x->query('//*[@data-instructions-editor]//button') as $button) {
            $this->assertNotSame('', trim($button->getAttribute('aria-label') . $button->textContent), 'every editor button has a name');
        }

        $answers = $this->dom(view(self::DIR . 'partials/accepted-answers-editor', ['acceptedAnswers' => ['x']], ['saveData' => false]));
        $this->assertContains('flex-wrap', $this->classes($this->one($answers, '//*[@data-accepted-answers]/div[1]')));
    }

    public function testTheViewAndTheOverviewUseTheFluidPagePadding(): void
    {
        $view = $this->dom(view(self::DIR . 'view', [
            'user'               => $this->instructor(),
            'currentAssessment'  => ['id' => 9, 'title' => 'Assignment 1', 'assessment_type' => 'Assignment', 'status' => 'Published'],
            'currentUnit'        => ['title' => 'Unit 1'],
            'assessmentLock'     => ['message' => 'Locked', 'prerequisites' => []],
            'previousAssessment' => null,
            'nextAssessment'     => null,
        ]));
        $page = $this->one($view, '/html/body/div[1]');
        foreach (['p-5', 'sm:p-8', 'lg:p-10'] as $class) {
            $this->assertContains($class, $this->classes($page), "view: {$class}");
        }

        $overview = $this->dom(view(self::DIR . 'index', [
            'user'     => $this->instructor(),
            'overview' => ['role' => 'student', 'assessments' => [], 'todo' => [], 'recent' => []],
        ]));
        $list = $this->one($overview, '//*[@data-assessments-overview]');
        foreach (['p-5', 'sm:p-8', 'lg:p-10'] as $class) {
            $this->assertContains($class, $this->classes($list), "overview: {$class}");
        }
    }

    public function testAQuizEditorRendersTheSameFrame(): void
    {
        $x = $this->editor('Quiz');

        $this->one($x, '//aside[@id="editor-inspector-panel"]/details[@data-inspector-settings]');
        $this->one($x, '//footer[contains(@class,"max-lg:sticky")]//button[@data-publish]');
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($this->one($x, '//button[@data-enter-quiz-builder]')));
    }
}
