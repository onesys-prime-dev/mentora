<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The learning item editors on phones: the fluid page padding, a Save as Draft / Publish bar pinned to the bottom edge
 * below lg (the assessment editor's footer classes), a reading toolbar that wraps with finger-sized buttons, video and
 * PDF upload areas and previews that never run wider than the column, stacked assignment and quiz actions, and the
 * item dialogs with form-field sizes (the create dialog is full-screen below sm by the dialog component's auto rule).
 * Rendered with view() and a stand-in instructor (no database).
 *
 * @internal
 */
final class LearningItemEditorResponsiveTest extends CIUnitTestCase
{
    private const DIR = 'pages/courses/features/modules/';

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
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');

        return new DOMXPath($doc);
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

    private function assertHasClasses(DOMNode $node, string $classes, string $what = ''): void
    {
        foreach (explode(' ', $classes) as $class) {
            $this->assertContains($class, $this->classes($node), trim("{$what} {$class}"));
        }
    }

    /** @param array<string, mixed> $item */
    private function editor(string $type, array $item = []): DOMXPath
    {
        $currentItem = $item + ['id' => 5, 'title' => 'Welcome to Introduction to Computing', 'item_type' => $type, 'status' => 'draft', 'content' => [], 'resource' => null];

        return $this->dom(view(self::DIR . 'learning-item-editor', [
            'user'              => $this->instructor(),
            'module'            => ['id' => 1, 'title' => 'Getting Started', 'order_index' => 1, 'progress' => 0, 'status' => 'published', 'learning_units' => []],
            'currentUnit'       => ['id' => 2, 'title' => 'Course Orientation', 'status' => 'published'],
            'currentItem'       => $currentItem,
            'currentAssessment' => null,
            'currentSettings'   => [],
            'currentRubric'     => null,
            'assessmentContext' => null,
            'discussion'        => null,
        ], ['saveData' => false]));
    }

    /** @return array<string, array{string}> */
    public static function types(): array
    {
        return array_combine(
            ['reading', 'video', 'pdf', 'link', 'assignment', 'quiz', 'discussion'],
            [['reading'], ['video'], ['pdf'], ['link'], ['assignment'], ['quiz'], ['discussion']]
        );
    }

    /** @dataProvider types */
    public function testEveryEditorUsesTheFluidPaddingAndPinsItsSaveBarBelowLg(string $type): void
    {
        $x = $this->editor($type);

        $page = $this->one($x, '//*[@data-learning-item-editor]');
        $this->assertHasClasses($page, 'min-w-0 flex-1 p-5 sm:p-8 lg:p-10 max-lg:min-h-dvh max-lg:pb-0', 'page');
        $this->assertStringContainsString('scroll-mb-24', $page->getAttribute('class'));

        // The trail truncates instead of pushing the bar wider; on a phone the save text and the Editing chip give way.
        $this->one($x, '//*[contains(@class,"sticky-bar-top")]/div[contains(concat(" ",@class," ")," min-w-0 ") and contains(concat(" ",@class," ")," flex-1 ")]//nav[@aria-label="Breadcrumb"]');
        $this->assertHasClasses($this->one($x, '//*[@data-save-message]'), 'max-md:sr-only');
        $this->assertHasClasses($this->one($x, '//*[@data-save-time]'), 'max-md:hidden');

        $footer = $this->one($x, '//footer[.//*[@data-publish]]');
        $this->assertHasClasses($footer, 'sticky-bar-bottom max-lg:sticky max-lg:bottom-0 max-lg:z-20 max-lg:-mx-5 sm:max-lg:-mx-8 max-lg:pb-safe-2', 'footer');

        foreach (['data-save-draft', 'data-publish'] as $hook) {
            $this->assertHasClasses($this->one($x, "//footer//button[@{$hook}]"), 'max-lg:flex-1 max-lg:min-h-11 pointer-coarse:min-h-11', $hook);
        }

        $delete = $this->one($x, '//footer//button[@data-delete-learning-item]');
        $this->assertSame('Delete learning item', $delete->getAttribute('aria-label'));
        $this->assertHasClasses($delete, 'max-lg:min-h-11 max-lg:min-w-11 pointer-coarse:min-h-11 pointer-coarse:min-w-11');
    }

    public function testTheReadingToolbarWrapsWithFingerSizedNamedButtons(): void
    {
        $x = $this->editor('reading');

        $toolbar = $this->one($x, '//*[@data-reading-editor]/div[1]');
        $this->assertHasClasses($toolbar, 'flex-wrap');

        $buttons = $x->query('//*[@data-reading-editor]//button');
        $this->assertSame(11, $buttons->length);

        foreach ($buttons as $button) {
            $this->assertNotSame('', $button->getAttribute('aria-label'), $button->getAttribute('data-action'));
            $this->assertHasClasses($button, 'pointer-coarse:min-h-11 pointer-coarse:min-w-11');
        }

        $this->assertHasClasses($this->one($x, '//select[@data-style-select]'), 'pointer-coarse:min-h-11 text-base sm:text-sm');
        $this->assertHasClasses($this->one($x, '//*[@id="reading-editor"]'), 'px-5 py-6 sm:px-10 sm:py-10');
    }

    public function testTheVideoUploadAndPreviewStayInsideTheColumn(): void
    {
        $x = $this->editor('video');

        $this->assertHasClasses($this->one($x, '//*[@data-video-upload-panel]//label[.//input[@data-video-file]]'), 'max-w-full');
        $this->assertHasClasses($this->one($x, '//*[@data-video-file-name]'), 'max-w-full truncate');
        $this->assertHasClasses($this->one($x, '//*[@data-video-preview]'), 'aspect-video max-w-full');

        foreach ($x->query('//*[@data-video-editor]//label[.//input[@type="radio" or @type="checkbox"]]') as $label) {
            $this->assertHasClasses($label, 'pointer-coarse:min-h-11');
        }

        $url = $this->one($x, '//input[@data-video-url]');
        $this->one($x, '//label[@for="' . $url->getAttribute('id') . '"]');
    }

    public function testThePdfUploadAndPreviewStayInsideTheColumnAndTheNameTruncates(): void
    {
        $x = $this->editor('pdf', ['resource' => ['file' => ['id' => 3, 'original_filename' => str_repeat('a', 80) . '.pdf', 'file_size' => 1024, 'url' => '/f/3']]]);

        $this->assertHasClasses($this->one($x, '//*[@data-pdf-dropzone]'), 'max-w-full');
        $this->assertHasClasses($this->one($x, '//*[@data-pdf-current-name]'), 'truncate');
        $this->assertHasClasses($this->one($x, '//*[@data-pdf-preview]'), 'max-w-full');
        $this->assertHasClasses($this->one($x, '//a[@data-pdf-current-link]'), 'pointer-coarse:min-h-11');
    }

    /** @return array<string, array{string}> */
    public static function assessments(): array
    {
        return ['assignment' => ['assignment'], 'quiz' => ['quiz']];
    }

    /** @dataProvider assessments */
    public function testAssignmentAndQuizItemsStackTheirActionsOnPhones(string $type): void
    {
        $x = $this->dom(view(self::DIR . 'learning-items/editor/' . $type, [
            'currentItem'       => ['id' => 5, 'title' => 'A', 'item_type' => $type],
            'currentAssessment' => ['id' => 9, 'title' => 'A', 'assessment_type' => ucfirst($type), 'status' => 'Draft', 'due_date' => null, 'available_from' => null, 'available_until' => null, 'total_points' => 30, 'attempt_limit' => 1, 'duration' => null, 'instructions' => ['version' => 1, 'blocks' => []]],
            'currentSettings'   => [],
            'currentRubric'     => null,
            'assessmentContext' => ['counts' => [], 'questionCount' => 0],
        ], ['saveData' => false]));

        $row = $this->one($x, '//div[button[@data-open-assessment]]');
        $this->assertHasClasses($row, 'max-sm:flex-col max-sm:items-stretch');

        foreach ($x->query('//button[@data-open-assessment]') as $button) {
            $this->assertHasClasses($button, 'pointer-coarse:min-h-11');
        }
    }

    public function testTheLinkEditorUsesPhoneSizedFields(): void
    {
        $x = $this->editor('link');

        foreach (['//input[@data-link-url]', '//textarea[@data-link-description]'] as $field) {
            $this->assertHasClasses($this->one($x, $field), 'text-base sm:text-sm');
        }
    }

    public function testTheCreateDialogIsFullScreenOnPhonesByTheAutoRule(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Views/' . self::DIR . 'partials/modals/learning-item-create.php');
        $this->assertStringNotContainsString("'variant'", $source, 'the variant is left to the auto rule');

        $x = $this->dom(view(self::DIR . 'partials/modals/learning-item-create', [], ['saveData' => false]));

        $dialog = $this->one($x, '//*[@id="learning-item-create-modal"]');
        $this->assertSame('fullscreen', $dialog->getAttribute('data-dialog-variant'));
        $this->assertTrue($dialog->hasAttribute('data-dialog'));
        $this->one($x, '//*[@data-learning-item-create-title]');
        $this->one($x, '//*[@data-learning-item-create-subtitle]');
        $this->one($x, '//form[@id="learning-item-create-form"]');
        $this->one($x, '//*[@data-dialog-footer]//button[@data-learning-item-create-submit][@form="learning-item-create-form"]');

        // The type tiles are real radios a keyboard reaches, two to a row on a phone.
        $types = $x->query('//input[@name="item_type"]');
        $this->assertSame(7, $types->length);
        foreach ($types as $radio) {
            $this->assertHasClasses($radio, 'sr-only');
        }
        $this->assertHasClasses($this->one($x, '//*[input[@name="item_type"]]/..'), 'grid-cols-2 sm:grid-cols-3');

        $title = $this->one($x, '//input[@name="title"]');
        $this->one($x, '//label[@for="' . $title->getAttribute('id') . '"]');
    }

    public function testThePublishFieldsUseTheFormFieldSizes(): void
    {
        $x = $this->dom(view(self::DIR . 'partials/modals/publish-fields', ['idPrefix' => 'item'], ['saveData' => false]));

        $this->assertHasClasses($this->one($x, '//input[@data-schedule-input]'), 'w-full min-w-0 text-base sm:text-sm pointer-coarse:min-h-11');
        $this->assertHasClasses($this->one($x, '//label[input[@data-schedule-toggle]]'), 'pointer-coarse:min-h-11');

        foreach ($x->query('//input[@data-publish-status]/following-sibling::span') as $tile) {
            $this->assertHasClasses($tile, 'pointer-coarse:min-h-11');
        }
    }

    public function testTheDependenciesDialogScrollsAndHasFingerSizedControls(): void
    {
        $x = $this->dom(view(self::DIR . 'partials/modals/learning-item-dependencies', [], ['saveData' => false]));

        $this->assertHasClasses($this->one($x, '//*[@id="learning-item-dependencies-modal"]/div'), 'max-h-full overflow-y-auto');
        $this->assertHasClasses($this->one($x, '//input[@data-minscore-percent]'), 'text-base sm:text-sm pointer-coarse:min-h-11');

        foreach ($x->query('//button') as $button) {
            $this->assertHasClasses($button, 'pointer-coarse:min-h-11');
        }

        $this->assertSame('Close', $this->one($x, '//button[not(normalize-space())]')->getAttribute('aria-label'));
    }
}
