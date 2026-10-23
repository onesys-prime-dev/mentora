<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The shared interface components (app/Views/components): what each renders
 * for its parameters, that text is escaped, and that the accessibility hooks
 * (roles, labels, aria state) are there.
 *
 * @internal
 */
final class ComponentViewsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['icon', 'form', 'url']);
    }

    private function render(string $component, array $data): string
    {
        return view('components/' . $component, $data, ['saveData' => false]);
    }

    /*
    |--------------------------------------------------------------------------
    | alert
    |--------------------------------------------------------------------------
    */

    public function testADangerAlertIsAnnouncedAtOnceAndTheOthersPolitely(): void
    {
        $danger = $this->render('alert', ['tone' => 'danger', 'text' => 'It failed.']);
        $info   = $this->render('alert', ['tone' => 'info', 'text' => 'FYI.']);

        $this->assertStringContainsString('role="alert"', $danger);
        $this->assertStringContainsString('role="status"', $info);
        $this->assertStringContainsString('Problem: ', $danger);
        $this->assertStringContainsString('Note: ', $info);
    }

    public function testAnAlertEscapesItsText(): void
    {
        $html = $this->render('alert', ['tone' => 'success', 'text' => '<script>x</script>', 'title' => '<b>t</b>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Done: ', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | form field
    |--------------------------------------------------------------------------
    */

    public function testAFieldTiesLabelHelpAndErrorToTheControl(): void
    {
        $html = $this->render('form-field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'a@b.example', 'help' => 'Their sign-in address.', 'error' => 'Not valid.', 'required' => true]);

        $this->assertMatchesRegularExpression('/<label[^>]*for="(f-email-[a-z0-9]+)"/', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('data-field="email"', $html);
        $this->assertStringContainsString('Their sign-in address.', $html);
        $this->assertStringContainsString('Not valid.', $html);
        $this->assertMatchesRegularExpression('/aria-describedby="f-email-[a-z0-9]+-help f-email-[a-z0-9]+-error"/', $html);
    }

    public function testAFieldWithoutAnErrorKeepsAnEmptyHiddenSlotForTheScript(): void
    {
        $html = $this->render('form-field', ['name' => 'name', 'label' => 'Name']);

        $this->assertMatchesRegularExpression('/data-field-error hidden><\/p>/', $html);
        $this->assertStringNotContainsString('aria-invalid', $html);
    }

    public function testAFieldRendersSelectsTextareasAndCheckboxes(): void
    {
        $select = $this->render('form-field', ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => ['student' => 'Student', 'instructor' => 'Instructor'], 'value' => 'instructor']);
        $area   = $this->render('form-field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => 'a <b> b']);
        $box    = $this->render('form-field', ['name' => 'on', 'label' => 'Enabled', 'type' => 'checkbox', 'value' => true]);

        $this->assertStringContainsString('<select', $select);
        $this->assertMatchesRegularExpression('/value="instructor" selected/', $select);
        $this->assertStringContainsString('<textarea', $area);
        $this->assertStringContainsString('a &lt;b&gt; b', $area);
        $this->assertStringContainsString('type="checkbox"', $box);
        $this->assertStringContainsString('checked', $box);
    }

    /*
    |--------------------------------------------------------------------------
    | data table
    |--------------------------------------------------------------------------
    */

    private function table(array $override = []): string
    {
        return $this->render('data-table', array_merge([
            'caption' => 'People',
            'columns' => [['key' => 'name', 'label' => 'Name', 'sortable' => true], ['key' => 'role', 'label' => 'Role']],
            'rows'    => [
                ['id' => 7, 'label' => 'Ann', 'cells' => ['name' => '<i>Ann</i>', 'role' => ['html' => '<b>Student</b>']], 'actions' => '<a href="#">Edit</a>'],
            ],
            'sort'    => 'name',
            'dir'     => 'asc',
            'sortUrl' => static fn (string $column, string $next): string => "/people?sort={$column}&dir={$next}",
        ], $override));
    }

    public function testATableEscapesPlainCellsAndTrustsOnlyHtmlCells(): void
    {
        $html = $this->table();

        $this->assertStringContainsString('&lt;i&gt;Ann&lt;/i&gt;', $html);
        $this->assertStringContainsString('<b>Student</b>', $html);
        $this->assertStringContainsString('<caption class="sr-only">People</caption>', $html);
    }

    public function testASortedHeaderSaysSoAndLinksToTheOppositeOrder(): void
    {
        $html = $this->table();

        $this->assertStringContainsString('aria-sort="ascending"', $html);
        $this->assertStringContainsString('/people?sort=name&amp;dir=desc', $html);
        $this->assertStringContainsString('aria-sort="none"', $this->table(['sort' => 'role']));
    }

    public function testASelectableTableHasTickBoxesAndABulkBar(): void
    {
        $html = $this->table(['selectable' => true, 'nounOne' => 'person', 'nounMany' => 'people', 'bulk' => '<form data-bulk-form></form>']);

        $this->assertStringContainsString('data-select-all', $html);
        $this->assertStringContainsString('data-row-select value="7"', $html);
        $this->assertStringContainsString('data-bulk-bar hidden', $html);
        $this->assertStringContainsString('data-noun-many="people"', $html);
        $this->assertStringContainsString('aria-label="Select Ann"', $html);
    }

    public function testATableWithoutSelectionHasNoTickBoxes(): void
    {
        $this->assertStringNotContainsString('data-row-select', $this->table());
    }

    public function testATableWithNoRowsShowsTheEmptyStateInstead(): void
    {
        $html = $this->table(['rows' => [], 'emptyTitle' => 'No people yet', 'emptyText' => 'Invite someone.']);

        $this->assertStringContainsString('No people yet', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testEveryCellCarriesItsLabelForTheStackedPhoneLayout(): void
    {
        $this->assertStringContainsString('data-label="Role"', $this->table());
    }

    /*
    |--------------------------------------------------------------------------
    | pagination, toolbar
    |--------------------------------------------------------------------------
    */

    public function testPaginationKeepsFiltersInItsLinksAndHidesOnOnePage(): void
    {
        $html = $this->render('pagination', ['page' => 2, 'pages' => 4, 'total' => 90, 'baseUrl' => '/people', 'query' => ['role' => 'student']]);

        $this->assertStringContainsString('Page 2 of 4', $html);
        $this->assertStringContainsString('90 in all', $html);
        $this->assertStringContainsString('role=student&amp;page=1', $html);
        $this->assertStringContainsString('role=student&amp;page=3', $html);

        $this->assertStringNotContainsString('<nav', $this->render('pagination', ['page' => 1, 'pages' => 1, 'baseUrl' => '/people']));
    }

    public function testPaginationOffersNoPreviousOnTheFirstPageAndNoNextOnTheLast(): void
    {
        $first = $this->render('pagination', ['page' => 1, 'pages' => 3, 'baseUrl' => '/x']);
        $last  = $this->render('pagination', ['page' => 3, 'pages' => 3, 'baseUrl' => '/x']);

        $this->assertStringNotContainsString('Previous', $first);
        $this->assertStringContainsString('Next', $first);
        $this->assertStringContainsString('Previous', $last);
        $this->assertStringNotContainsString('Next', $last);
    }

    public function testAToolbarKeepsValuesAndOffersClearOnlyWhenFiltered(): void
    {
        $fields = [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'value' => 'ann "x"'],
            ['type' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => ['' => 'Any', 'student' => 'Student'], 'value' => 'student'],
        ];

        $filtered = $this->render('toolbar', ['action' => '/people', 'fields' => $fields, 'filtered' => true, 'clearUrl' => '/people']);
        $plain    = $this->render('toolbar', ['action' => '/people', 'fields' => $fields]);

        $this->assertStringContainsString('value="ann &quot;x&quot;"', $filtered);
        $this->assertMatchesRegularExpression('/value="student" selected/', $filtered);
        $this->assertStringContainsString('>Clear</a>', $filtered);
        $this->assertStringNotContainsString('>Clear</a>', $plain);
        $this->assertStringContainsString('role="search"', $plain);
    }

    /*
    |--------------------------------------------------------------------------
    | dialog, confirm
    |--------------------------------------------------------------------------
    */

    public function testADialogIsHiddenModalAndLabelled(): void
    {
        $html = $this->render('dialog', ['id' => 'add', 'title' => 'Add <person>', 'body' => '<p>Body</p>', 'footer' => '<button>Go</button>']);

        $this->assertStringContainsString('id="add"', $html);
        $this->assertStringContainsString('data-dialog hidden', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="add-title"', $html);
        $this->assertStringContainsString('Add &lt;person&gt;', $html);
        $this->assertStringContainsString('<p>Body</p>', $html);
        $this->assertStringContainsString('aria-label="Close"', $html);
    }

    public function testAConfirmNamesTheConsequenceAndCanRequireTypedText(): void
    {
        $html = $this->render('confirm', [
            'id' => 'rm', 'title' => 'Deactivate Ann?', 'text' => 'She will be signed out.', 'action' => '/people/1/deactivate',
            'confirmLabel' => 'Deactivate', 'tone' => 'danger', 'typeToConfirm' => 'Ann Lee',
        ]);

        $this->assertStringContainsString('She will be signed out.', $html);
        $this->assertStringContainsString('action="/people/1/deactivate"', $html);
        $this->assertStringContainsString('data-confirm-match="Ann Lee"', $html);
        $this->assertStringContainsString('data-confirm-submit', $html);
        $this->assertStringContainsString('bg-[#C33939]', $html);
        $this->assertStringContainsString('name="csrf_test_name"', $html . 'name="csrf_test_name"', 'a CSRF field is rendered by csrf_field()');
    }

    public function testAConfirmWithoutTypedTextHasNoMatchBox(): void
    {
        $html = $this->render('confirm', ['id' => 'rm', 'title' => 'Sign out?', 'text' => 'Ends every session.', 'action' => '/x', 'confirmLabel' => 'Sign out']);

        $this->assertStringNotContainsString('data-confirm-match', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | tabs, stepper, lock note, usage bar, skeleton, page head
    |--------------------------------------------------------------------------
    */

    public function testTabsMarkTheCurrentOneAndShowCounts(): void
    {
        $html = $this->render('tabs', ['tabs' => [
            ['label' => 'Details', 'href' => '/a', 'active' => true],
            ['label' => 'Roster', 'href' => '/b', 'count' => 38],
        ]]);

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringContainsString('>38<', preg_replace('/\s+/', '', str_replace('38', '>38<', $html)) ?? '');
        $this->assertStringContainsString('href="/b"', $html);
    }

    public function testAStepperMarksDoneCurrentAndComingSteps(): void
    {
        $html = $this->render('stepper', ['steps' => ['Upload', 'Preview', 'Apply'], 'current' => 2]);

        $this->assertSame(1, substr_count($html, 'aria-current="step"'));
        $this->assertStringContainsString('Done: ', $html);
        $this->assertStringContainsString('Preview', $html);
    }

    public function testALockNoteSaysWhoOwnsTheThing(): void
    {
        $this->assertStringContainsString('student information system', $this->render('lock-note', ['kind' => 'sis']));
        $this->assertStringContainsString('read-only', $this->render('lock-note', ['kind' => 'read_only']));
        $this->assertStringContainsString('Reopen it', $this->render('lock-note', ['kind' => 'closed']));
        $this->assertStringContainsString('Custom words.', $this->render('lock-note', ['kind' => 'sis', 'text' => 'Custom words.']));
    }

    /**
     * @return iterable<string, array{int, ?int, int, string}>
     */
    public static function provideUsage(): iterable
    {
        yield 'comfortable' => [410, 500, 82, 'OK'];
        yield 'nearly full' => [450, 500, 90, 'Nearly full'];
        yield 'full' => [500, 500, 100, 'Full'];
        yield 'over' => [530, 500, 100, 'Full'];
        yield 'unlimited' => [90, null, 0, 'Unlimited'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideUsage')]
    public function testAUsageBarSaysItInWordsAsWellAsColour(int $used, ?int $limit, int $percent, string $word): void
    {
        $html = $this->render('usage-bar', ['label' => 'Seats', 'used' => $used, 'limit' => $limit]);

        $this->assertStringContainsString($word . ' ·', $html);
        $this->assertStringContainsString('aria-valuenow="' . $percent . '"', $html);
        $this->assertStringContainsString('role="progressbar"', $html);
    }

    public function testASkeletonIsAnAnnouncedBusyRegion(): void
    {
        $html = $this->render('skeleton', ['rows' => 3, 'label' => 'Loading people']);

        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringContainsString('Loading people', $html);
        $this->assertSame(3, substr_count($html, 'animate-pulse'));
    }

    public function testABackLinkIsOneLabelledLinkToTheAddressItIsGiven(): void
    {
        $html = $this->render('back-link', ['backUrl' => '/dean/dashboard', 'backLabel' => 'Back to dashboard']);

        $this->assertSame(1, substr_count($html, '<a '));
        $this->assertStringContainsString('href="/dean/dashboard"', $html);
        $this->assertStringContainsString('Back to dashboard', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html, 'the arrow is decoration; the words carry the meaning');

        $plain = $this->render('back-link', ['backUrl' => '/x']);
        $this->assertStringContainsString('Back', $plain, 'a link with no label still says where it goes');

        $hostile = $this->render('back-link', ['backUrl' => '/x"><script>alert(1)</script>', 'backLabel' => '<b>Back</b>']);
        $this->assertStringNotContainsString('<script>', $hostile);
        $this->assertStringNotContainsString('<b>', $hostile);
    }

    public function testAPageHeadDrawsItsBackLinkWithTheBackLinkComponent(): void
    {
        $head = $this->render('page-head', ['title' => 'People', 'backUrl' => '/dashboard', 'backLabel' => 'Back to dashboard']);
        $link = $this->render('back-link', ['backUrl' => '/dashboard', 'backLabel' => 'Back to dashboard']);

        // Without the debug comments the test environment wraps every view in, and without whitespace.
        $plain = static fn (string $html): string => preg_replace('/\s+/', ' ', trim(preg_replace('/<!--.*?-->/s', '', $html)));

        $this->assertStringContainsString($plain($link), $plain($head), 'the same markup, so pages that use either look alike');
    }

    public function testAPageHeadHasOneH1AnOptionalBackLinkAndActions(): void
    {
        $html = $this->render('page-head', ['title' => 'People', 'icon' => 'user', 'description' => 'Everyone here.', 'backUrl' => '/dashboard', 'backLabel' => 'Back to dashboard', 'actions' => '<button>Invite</button>']);

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('href="/dashboard"', $html);
        $this->assertStringContainsString('Back to dashboard', $html);
        $this->assertStringContainsString('<button>Invite</button>', $html);

        $bare = $this->render('page-head', ['title' => 'People']);
        $this->assertStringNotContainsString('href=', $bare);
    }
}
