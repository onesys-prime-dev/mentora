<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The Course Workspace's instructor parts on phones: the Overview cards size by their own width (container
 * queries) and keep the lg+ layout, the announcement composer and in-place editor wrap with finger-sized
 * buttons, hover-only controls show on a touch screen, the discussion moderation controls are 44px, and a
 * resource row's ⋯ menu opens in the row-actions sheet below md. Rendered with view() (no database); the
 * announcement composer reads the signed-in profile, so its markup is read from the file with the PHP removed.
 *
 * @internal
 */
final class CourseWorkspaceInstructorTest extends CIUnitTestCase
{
    private const DIR = 'pages/courses/features/';

    private const TOUCH = 'pointer-coarse:min-h-11 pointer-coarse:min-w-11';

    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon', 'announcement']);
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');

        return new DOMXPath($doc);
    }

    /** A view's markup without its PHP, for a partial that cannot render without the database. */
    private function source(string $view): DOMXPath
    {
        $php = (string) file_get_contents(APPPATH . 'Views/' . self::DIR . $view . '.php');

        return $this->dom((string) preg_replace('/<\?(?:php|=).*?\?>/s', '', $php));
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

    /** Every button under $query is 44px on a touch screen. */
    private function assertTouchButtons(DOMXPath $x, string $query, int $atLeast): void
    {
        $buttons = $x->query($query);
        $this->assertGreaterThanOrEqual($atLeast, $buttons->length, $query);

        foreach ($buttons as $button) {
            $this->assertHasClasses($button, self::TOUCH, trim($button->textContent) ?: $button->getAttribute('aria-label') ?: $button->getAttribute('title'));
        }
    }

    // --- Overview -------------------------------------------------------------------------------------------

    public function testOverviewMetricsSizeByTheirContainerAndKeepTheLgColumns(): void
    {
        $metric = ['label' => 'To Grade', 'icon' => 'tasks', 'tone' => 'neutral', 'value' => '3', 'detail' => ''];

        foreach ([4 => 'lg:grid-cols-4', 3 => 'lg:grid-cols-3'] as $count => $lg) {
            $x = $this->dom(view(self::DIR . 'overview/partials/metrics', ['metrics' => array_fill(0, $count, $metric)], ['saveData' => false]));

            $this->assertHasClasses($this->one($x, '/html/body/div/div[1]'), '@container');
            $grid = $this->one($x, '/html/body/div/div[1]/div[1]');
            $this->assertHasClasses($grid, "grid grid-cols-1 max-lg:@2xs:grid-cols-2 {$lg}");
            $this->assertNotContains('sm:grid-cols-2', $this->classes($grid), 'columns follow the container, not the window');
            // An unguarded container rule comes after the lg rule in the built CSS and would win at lg+.
            $this->assertNotContains('@2xs:grid-cols-2', $this->classes($grid));
        }
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function overviewCards(): array
    {
        return [
            'needs attention' => ['needs-attention', ['attention' => [
                'grading' => [['title' => 'Assignment 1', 'detail' => '3 to grade', 'tone' => 'warning', 'action' => 'grade', 'id' => 4]],
                'behind' => [], 'drafts' => [], 'grading_total' => 1, 'behind_total' => 0, 'drafts_total' => 0,
            ]]],
            'class progress' => ['progress', ['role' => 'instructor', 'class_progress' => [
                'roster' => 4, 'modules' => [['title' => 'Getting Started', 'completion' => 40, 'tone' => 'warning']],
            ]]],
            'closing soon' => ['upcoming-deadlines', ['role' => 'instructor', 'deadlines' => [
                ['id' => 4, 'title' => 'Quiz 1', 'month' => 'NOV', 'day' => '20', 'tone' => 'warning', 'detail' => '2 of 4 handed in'],
            ]]],
            'recent activity' => ['recent-activity', ['role' => 'instructor', 'activity' => [
                ['kind' => 'submission', 'title' => 'New submission', 'detail' => 'Maria Santos handed in Assignment 1', 'when' => '2 hours ago'],
            ]]],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @dataProvider overviewCards
     */
    public function testOverviewCardsAreQueryContainersWithTheirLgPaddingKept(string $partial, array $data): void
    {
        $x = $this->dom(view(self::DIR . 'overview/partials/' . $partial, $data, ['saveData' => false]));

        $card = $this->one($x, '/html/body/div/div[1]');
        // Tighter padding in a narrow column (below lg only); the lg+ padding is what it always was.
        $this->assertHasClasses($card, 'p-4 max-lg:@xs:p-6 lg:p-6 pb-6 max-lg:@xs:pb-8 lg:pb-8', $partial);
        $this->assertNotContains('p-6', $this->classes($card), "{$partial} base padding");
        // A container query never matches the element itself: the card must not be its own container.
        $this->assertNotContains('@container', $this->classes($card), $partial);
    }

    public function testOverviewColumnsAreTheCardsQueryContainers(): void
    {
        $php = (string) file_get_contents(APPPATH . 'Views/' . self::DIR . 'overview/index.php');

        $this->assertStringContainsString('class="@container flex min-w-0 flex-col gap-8 xl:col-span-3"', $php);
        $this->assertStringContainsString('class="@container flex min-w-0 flex-col gap-6 xl:col-span-2"', $php);
    }

    public function testCourseInfoCardsUseTwoColumnsByContainerAndAlwaysFromLg(): void
    {
        $x = $this->dom(view(self::DIR . 'overview/partials/course-info', [
            'role' => 'instructor',
            'overview' => ['course_code' => 'IT101', 'section_code' => 'A', 'enrolled' => 4, 'capacity' => 40, 'description' => 'Intro'],
            'schedule' => [],
        ], ['saveData' => false]));

        $wrapper = $this->one($x, '/html/body/div/div[2]');
        $this->assertHasClasses($wrapper, '@container');
        $grid = $this->one($x, '/html/body/div/div[2]/div[1]');
        $this->assertHasClasses($grid, 'grid grid-cols-1 @md:grid-cols-2 lg:grid-cols-2');
        $this->assertNotContains('sm:grid-cols-2', $this->classes($grid));
    }

    public function testNeedsAttentionListHeaderWraps(): void
    {
        $x = $this->dom(view(self::DIR . 'overview/partials/needs-attention', ['attention' => [
            'grading' => [['title' => 'Assignment 1', 'detail' => '3 to grade', 'tone' => 'warning', 'action' => 'grade', 'id' => 4]],
            'behind' => [], 'drafts' => [], 'grading_total' => 9, 'behind_total' => 0, 'drafts_total' => 0,
        ]], ['saveData' => false]));

        $this->assertHasClasses($this->one($x, '//span[contains(., "Showing")]/..'), 'flex-wrap');
    }

    // --- Announcements --------------------------------------------------------------------------------------

    public function testComposerToolbarAndActionsWrapWithTouchSizedButtons(): void
    {
        $x = $this->source('announcements/partials/composer');

        $this->assertHasClasses($this->one($x, '//*[@data-composer-toolbar]'), 'flex-wrap');
        $this->assertTouchButtons($x, '//*[@data-composer-toolbar]//button', 5);
        $this->assertTouchButtons($x, '//*[@data-announcement-composer]//button', 9);
        $this->assertHasClasses($this->one($x, '//*[@data-composer-submit]/..'), 'flex-wrap');
    }

    public function testPostInCourseIsTouchSized(): void
    {
        $this->assertHasClasses($this->one($this->source('announcements/index'), '//*[@data-composer-toggle]'), 'pointer-coarse:min-h-11');
    }

    private function card(): DOMXPath
    {
        return $this->dom(view(self::DIR . 'announcements/partials/announcement-card', [
            'announcement' => [
                'id' => 7, 'author_id' => 2, 'author_name' => 'Ana Cruz', 'author_role' => 'instructor', 'subject' => 'Quiz',
                'message' => null, 'created_at' => '2026-11-12 08:00:00', 'attachments' => [],
                'replies' => [['id' => 3, 'author_id' => 2, 'author_name' => 'Ana Cruz', 'message' => 'Noted', 'created_at' => '2026-11-12 09:00:00']],
                'is_pinned' => false, 'own_reaction' => null, 'reaction_counts' => [],
                'can_edit' => true, 'can_delete' => true, 'can_pin' => true,
            ],
            'userId' => 2,
        ], ['saveData' => false]));
    }

    public function testInPlaceEditorWrapsWithTouchSizedButtons(): void
    {
        $x = $this->card();

        $toolbar = $this->one($x, '//*[@data-card-editor]/div[2]');
        $this->assertHasClasses($toolbar, 'flex-wrap');
        $this->assertTouchButtons($x, '//*[@data-card-editor]//button', 9);
        $this->assertHasClasses($this->one($x, '//*[@data-card-editor-save]/../..'), 'flex-wrap');
    }

    public function testMoreOptionsEntriesAndReplyControlsAreTouchSized(): void
    {
        $x = $this->card();

        foreach ($x->query('//*[@data-more-options-panel]//button') as $entry) {
            $this->assertHasClasses($entry, 'pointer-coarse:min-h-11', trim($entry->textContent));
        }
        $this->assertTouchButtons($x, '//*[@data-reply-send]', 1);

        // The reply's Delete shows only on hover for a mouse: shown on a touch screen and on keyboard focus.
        $delete = $this->one($x, '//*[@data-reply-thread]//*[@data-delete-reply]');
        $this->assertHasClasses($delete, 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100 pointer-coarse:opacity-100 pointer-coarse:min-h-11');
    }

    // --- Discussions ----------------------------------------------------------------------------------------

    public function testDiscussionHoverMenuShowsOnTouchWithTouchSizedModerationControls(): void
    {
        $x = $this->dom(view(self::DIR . 'discussions/partials/hover-menu', [
            'own' => null,
            'items' => [
                ['attrs' => 'data-thread-edit', 'label' => 'Edit'],
                ['attrs' => 'data-thread-pin data-value="0"', 'label' => 'Pin'],
                ['attrs' => 'data-thread-lock data-value="0"', 'label' => 'Lock'],
                ['attrs' => 'data-thread-delete', 'label' => 'Delete', 'tone' => 'danger'],
            ],
        ], ['saveData' => false]));

        $menu = $this->one($x, '/html/body/div/div[1]');
        $this->assertHasClasses($menu, 'absolute opacity-0 group-hover:opacity-100 focus-within:opacity-100');
        $this->assertHasClasses($menu, 'pointer-coarse:static pointer-coarse:opacity-100 pointer-coarse:flex-wrap pointer-coarse:self-end pointer-coarse:shadow-none');

        $this->assertTouchButtons($x, '/html/body/div/div[1]/button | /html/body/div/div[1]/div/button', 5);
        $entries = $x->query('//*[@data-more-options-panel]/button');
        $this->assertSame(4, $entries->length);
        foreach ($entries as $entry) {
            $this->assertHasClasses($entry, 'pointer-coarse:min-h-11', trim($entry->textContent));
        }
    }

    public function testDiscussionComposerAndToolbarAreTouchSized(): void
    {
        $x = $this->dom(view(self::DIR . 'discussions/partials/composer', [], ['saveData' => false]));

        $this->assertHasClasses($this->one($x, '//*[@data-composer-toolbar]'), 'flex-wrap');
        $this->assertTouchButtons($x, '//*[@data-discussion-composer]//button', 9);

        $x = $this->dom(view(self::DIR . 'discussions/partials/toolbar', ['filters' => ['search' => '', 'sort' => 'activity', 'filter' => 'all']], ['saveData' => false]));

        foreach (['//*[@data-discussions-search]', '//*[@data-discussions-filter]', '//*[@data-discussions-sort]'] as $field) {
            $this->assertHasClasses($this->one($x, $field), 'text-base sm:text-sm pointer-coarse:min-h-11', $field);
        }
    }

    public function testDiscussionReplyBoxIsTouchSized(): void
    {
        $x = $this->dom(view(self::DIR . 'discussions/partials/thread-body', [
            'thread' => [
                'id' => 9, 'title' => 'Week 1', 'body' => null, 'author_name' => 'Ana Cruz', 'author_role' => 'instructor',
                'created_at' => '2026-11-12 08:00:00', 'attachments' => [], 'reaction_counts' => [], 'own_reaction' => null,
                'is_pinned' => false, 'is_locked' => false, 'module_title' => null, 'unit_title' => null,
                'can_edit' => true, 'can_pin' => true, 'can_lock' => true, 'can_delete' => true, 'can_mark_answer' => true,
            ],
            'replies' => [],
        ], ['saveData' => false]));

        $this->assertTouchButtons($x, '//*[@data-reply-box]//button', 2);
        $this->assertHasClasses($this->one($x, '//*[@data-reply-box]//textarea'), 'text-base sm:text-sm');
    }

    // --- Resources ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function resource(array $overrides = []): array
    {
        return $overrides + [
            'resource_id' => 11, 'display_title' => 'Course Syllabus', 'type_bucket' => 'pdf', 'type_label' => 'PDF',
            'size_label' => '120 KB', 'added_label' => 'Nov 12', 'view_url' => '/r/11', 'download_url' => '/r/11/download',
        ];
    }

    /** @return array<string, array{string}> */
    public static function resourceRows(): array
    {
        return ['list row' => ['list-item'], 'grid card' => ['grid-item']];
    }

    /** @dataProvider resourceRows */
    public function testResourceRowOpensItsMenuInTheRowActionsSheetBelowMd(string $partial): void
    {
        $x = $this->dom(view(self::DIR . 'resources/partials/' . $partial, ['resource' => $this->resource()], ['saveData' => false]));

        $row = $this->one($x, '//*[@data-row]');
        // The menu script finds the panel and the phone ⋯ from the closest .relative.
        $this->assertSame(1, $x->query("ancestor-or-self::*[contains(concat(' ', @class, ' '), ' relative ')][1]//*[@data-resource-menu-panel]", $row)->length);

        $opener = $this->one($x, '//*[@data-row]/button[@data-row-actions-open]');
        $this->assertSame('resource-actions-sheet', $opener->getAttribute('aria-controls'));
        $this->assertSame('dialog', $opener->getAttribute('aria-haspopup'));
        $this->assertSame('Actions for Course Syllabus', $opener->getAttribute('aria-label'));
        $this->assertSame('Course Syllabus', $opener->getAttribute('data-row-label'));
        $this->assertHasClasses($opener, 'h-11 w-11 md:hidden');

        $toggle = $this->one($x, '//*[@data-resource-menu-toggle]');
        $this->assertHasClasses($toggle, 'max-md:hidden ' . self::TOUCH);

        $actions = $this->one($x, '//*[@data-resource-menu-panel]/*[@data-role="actions"]');
        $entries = $x->query('./*', $actions);
        $this->assertSame(3, $entries->length, 'View, Download, Share link');
        foreach ($entries as $entry) {
            $this->assertHasClasses($entry, 'pointer-coarse:min-h-11', trim($entry->textContent));
        }
        $this->assertSame(1, $x->query('//*[@data-role="actions"]/*[@data-resource-share]')->length, 'the share hook is kept');
    }

    public function testGridMenuIsNotHoverOnlyOnTouchOrKeyboard(): void
    {
        $x = $this->dom(view(self::DIR . 'resources/partials/grid-item', ['resource' => $this->resource()], ['saveData' => false]));

        $this->assertHasClasses($this->one($x, '//*[@data-resource-menu-toggle]'), 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100 pointer-coarse:opacity-100');
    }

    /** @dataProvider resourceRows */
    public function testLockedResourceHasNoMenu(string $partial): void
    {
        $x = $this->dom(view(self::DIR . 'resources/partials/' . $partial, ['resource' => $this->resource(['is_locked' => true])], ['saveData' => false]));

        $this->assertSame(0, $x->query('//*[@data-row-actions-open] | //*[@data-resource-menu-toggle]')->length);
    }

    public function testResourcesPageRendersTheActionsSheetOnceAtEveryLevel(): void
    {
        foreach (['offering', 'unit'] as $level) {
            $x = $this->dom(view(self::DIR . 'resources/index', [
                'offeringId' => 1, 'level' => $level, 'view' => 'list', 'modules' => [], 'units' => [],
                'resources' => [$this->resource()], 'module' => ['id' => 1, 'title' => 'M'], 'unit' => ['id' => 2, 'title' => 'U'],
                'filters' => ['search' => '', 'sort' => 'name', 'type' => ''],
            ], ['saveData' => false]));

            // Outside the content the script swaps, so a later folder's rows still find it.
            $sheet = $this->one($x, '//*[@id="resource-actions-sheet"]');
            $this->assertSame(0, $x->query('ancestor::*[@data-resources-content]', $sheet)->length, $level);
            $this->assertSame(1, $x->query('.//*[@data-row-actions-slot]', $sheet)->length);
        }
    }

    public function testResourcesToolbarFieldsAreTouchAndPhoneSized(): void
    {
        $x = $this->dom(view(self::DIR . 'resources/partials/toolbar', [
            'filters' => ['search' => '', 'sort' => 'name', 'type' => ''], 'view' => 'list', 'level' => 'unit',
        ], ['saveData' => false]));

        foreach (['//*[@data-resources-search]', '//*[@data-resources-sort]', '//*[@data-resources-type]'] as $field) {
            $node = $this->one($x, $field);
            $this->assertHasClasses($node, 'text-base sm:text-sm pointer-coarse:min-h-11', $field);
            $this->assertNotSame('', $node->getAttribute('aria-label'), "{$field} is named");
        }
        $this->assertTouchButtons($x, '//*[@data-resources-view-toggle]/button', 2);
    }
}
