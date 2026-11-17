<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The data table's two layouts (components/data-table.php): the table from md
 * up, with lower-priority columns dropping out on narrower screens, and the
 * phone list rows below md, built from the columns' roles and grouped into
 * panels. Also the lock-aware fill mode and the stacked-card escape hatch.
 *
 * @internal
 */
final class DataTableComponentTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url']);
    }

    private function render(array $data): string
    {
        return view('components/data-table', $data + [
            'caption' => 'People',
            'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'status', 'label' => 'Status']],
            'rows'    => [
                ['id' => 1, 'cells' => ['name' => 'Ann Reyes', 'email' => 'ann@example.test', 'status' => 'Active']],
                ['id' => 2, 'cells' => ['name' => 'Ben Cruz', 'email' => 'ben@example.test', 'status' => 'Away']],
            ],
        ], ['saveData' => false]);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** @return list<string> */
    private function classes(?DOMNode $node): array
    {
        $this->assertInstanceOf(DOMElement::class, $node);

        return preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
    }

    private function text(DOMXPath $x, string $query): string
    {
        $node = $x->query($query)->item(0);
        $this->assertNotNull($node, "Nothing matches {$query}");

        return trim(preg_replace('/\s+/', ' ', $node->textContent));
    }

    public function testDefaultColumnsKeepTheirTableOutputAtMd(): void
    {
        $x = $this->xpath($this->render([]));

        $this->assertSame(1, $x->query('//table')->length);
        $this->assertSame(1, $x->query('//thead')->length);
        $this->assertSame(3, $x->query('//thead//th')->length);
        $this->assertContains('max-md:sr-only', $this->classes($x->query('//thead')->item(0)));
        $this->assertSame(3, $x->query('//tbody/tr[1]/td[@data-label]')->length);
    }

    public function testPriorityColumnsHideBelowTheirBreakpoint(): void
    {
        $x = $this->xpath($this->render([
            'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email', 'priority' => 2], ['key' => 'status', 'label' => 'Status', 'priority' => 3]],
        ]));

        foreach (['//thead//th[normalize-space()="%s"]', '//tbody/tr[1]/td[@data-label="%s"]'] as $query) {
            $name   = $this->classes($x->query(sprintf($query, 'Name'))->item(0));
            $email  = $this->classes($x->query(sprintf($query, 'Email'))->item(0));
            $status = $this->classes($x->query(sprintf($query, 'Status'))->item(0));

            $this->assertContains('md:max-lg:hidden', $email);
            $this->assertNotContains('md:max-xl:hidden', $email);
            $this->assertContains('md:max-xl:hidden', $status);
            $this->assertNotContains('md:max-lg:hidden', $name);
            $this->assertNotContains('md:max-xl:hidden', $name);
        }
    }

    public function testTheFirstColumnIsNeverHidden(): void
    {
        $x = $this->xpath($this->render([
            'columns' => [['key' => 'name', 'label' => 'Name', 'priority' => 3], ['key' => 'email', 'label' => 'Email', 'priority' => 3]],
        ]));

        $this->assertNotContains('md:max-xl:hidden', $this->classes($x->query('//thead//th[normalize-space()="Name"]')->item(0)));
        $this->assertNotContains('md:max-xl:hidden', $this->classes($x->query('//tbody/tr[1]/td[@data-label="Name"]')->item(0)));
        $this->assertContains('md:max-xl:hidden', $this->classes($x->query('//tbody/tr[1]/td[@data-label="Email"]')->item(0)));

        // One column only: the phone row still has its title.
        $one = $this->xpath($this->render(['columns' => [['key' => 'name', 'label' => 'Name', 'priority' => 3]]]));
        $this->assertSame('Ann Reyes', $this->text($one, '//tbody/tr[1]/td[@data-role="primary"]'));
    }

    public function testPhoneRowUsesRoles(): void
    {
        $x = $this->xpath($this->render([
            'columns' => [
                ['key' => 'code', 'label' => 'Code', 'role' => 'secondary'],
                ['key' => 'title', 'label' => 'Title', 'role' => 'primary'],
                ['key' => 'state', 'label' => 'State', 'role' => 'status'],
                ['key' => 'other', 'label' => 'Other', 'role' => 'primary'],
            ],
            'rows' => [['id' => 1, 'cells' => ['code' => 'IT101', 'title' => 'Programming', 'state' => 'Open', 'other' => 'Ignored']]],
        ]));

        $this->assertSame('Programming', $this->text($x, '//tbody/tr[1]/td[@data-role="primary"]'));
        $this->assertSame('IT101', $this->text($x, '//tbody/tr[1]/td[@data-role="secondary"]'));
        $this->assertSame('Open', $this->text($x, '//tbody/tr[1]/td[@data-role="status"]'));
        $this->assertSame(1, $x->query('//tbody/tr[1]/td[@data-role="primary"]')->length);

        // A column with no role in the phone row is left for the table and the sheet.
        $this->assertContains('max-md:hidden', $this->classes($x->query('//tbody/tr[1]/td[@data-label="Other"]')->item(0)));
        $this->assertNotContains('max-md:hidden', $this->classes($x->query('//tbody/tr[1]/td[@data-label="Title"]')->item(0)));
    }

    public function testDefaultRolesWhenUnset(): void
    {
        $x = $this->xpath($this->render([]));

        $this->assertSame('Ann Reyes', $this->text($x, '//tbody/tr[1]/td[@data-role="primary"]'));
        $this->assertSame('ann@example.test', $this->text($x, '//tbody/tr[1]/td[@data-role="secondary"]'));
        $this->assertSame('Active', $this->text($x, '//tbody/tr[1]/td[@data-role="status"]'));
        $this->assertSame('AR', $this->text($x, '//tbody/tr[1]/td[@data-role="avatar"]'));

        // A status badge in a column of another name still counts as the status.
        $badge = view('components/status-badge', ['label' => 'Approved', 'tone' => 'success'], ['saveData' => false]);
        $y     = $this->xpath($this->render([
            'columns' => [['key' => 'student', 'label' => 'Student'], ['key' => 'course', 'label' => 'Course'], ['key' => 'age', 'label' => 'Outcome']],
            'rows'    => [['id' => 1, 'cells' => ['student' => 'Ann', 'course' => 'IT101', 'age' => ['html' => $badge]]]],
        ]));
        $this->assertSame('Approved', $this->text($y, '//tbody/tr[1]/td[@data-role="status"]'));
    }

    public function testGroupsRenderPanelsWithHeadingAndCount(): void
    {
        $x = $this->xpath($this->render([
            'rows' => [
                ['id' => 1, 'group' => 'Students', 'cells' => ['name' => 'Ann']],
                ['id' => 2, 'group' => 'Students', 'cells' => ['name' => 'Ben']],
                ['id' => 3, 'group' => 'Instructors', 'cells' => ['name' => 'Cy']],
            ],
        ]));

        $panels = $x->query('//tbody[@data-panel]');
        $this->assertSame(2, $panels->length);
        $this->assertSame('Students', $this->text($x, '//tbody[@data-panel][1]//*[@data-group-heading]//*[@data-group-name]'));
        $this->assertSame('2', $this->text($x, '//tbody[@data-panel][1]//*[@data-group-count]'));
        $this->assertSame('Instructors', $this->text($x, '//tbody[@data-panel][2]//*[@data-group-heading]//*[@data-group-name]'));
        $this->assertSame('1', $this->text($x, '//tbody[@data-panel][2]//*[@data-group-count]'));
        $this->assertContains('text-[11px]', $this->classes($x->query('//*[@data-group-heading]//th')->item(0)));
        $this->assertContains('md:hidden', $this->classes($x->query('//*[@data-group-heading]')->item(0)));

        $plain = $this->xpath($this->render([]));
        $this->assertSame(1, $plain->query('//tbody[@data-panel]')->length);
        $this->assertSame(0, $plain->query('//*[@data-group-heading]')->length);
    }

    public function testFillUsesTheLockVariantAndClip(): void
    {
        $x       = $this->xpath($this->render(['fill' => true]));
        $wrapper = $this->classes($x->query('//*[@data-table]')->item(0));

        $this->assertContains('lock:flex-1', $wrapper);
        $this->assertContains('lock:min-h-0', $wrapper);
        // Clipped (not a scroller) below lg, so the heading pins to the window; a scroller from lg, so a row
        // menu opening past the last row stays reachable (TC-PPL-03).
        $this->assertContains('overflow-clip', $wrapper);
        $this->assertContains('lg:overflow-y-auto', $wrapper);
        $this->assertNotContains('overflow-hidden', $wrapper);

        // From lg the page panel (or, locked, the wrapper) scrolls, so the heading pins at 0;
        // below lg the window scrolls and it parks under the app header.
        foreach ($x->query('//thead//th') as $th) {
            $this->assertContains('sticky', $this->classes($th));
            $this->assertContains('top-0', $this->classes($th));
            $this->assertContains('max-lg:top-[var(--app-header-h,0px)]', $this->classes($th));
            $this->assertNotContains('top-[var(--app-header-h,0px)]', $this->classes($th));
        }
    }

    public function testPhoneRowsKeepTableSemanticsWithExplicitRoles(): void
    {
        $x = $this->xpath($this->render(['selectable' => true, 'rows' => [['id' => 1, 'cells' => ['name' => 'Ann'], 'actions' => '<a href="#">Edit</a>']]]));

        $this->assertSame('table', $x->query('//table')->item(0)->getAttribute('role'));
        $this->assertSame(0, $x->query('//thead[not(@role="rowgroup")] | //tbody[not(@role="rowgroup")]')->length);
        $this->assertSame(0, $x->query('//tr[not(@role="row")]')->length);
        $this->assertSame(0, $x->query('//thead//th[not(@role="columnheader")]')->length);
        $this->assertSame(0, $x->query('//td[not(@role="cell")]')->length);
        $this->assertGreaterThan(0, $x->query('//td')->length);

        $old = $this->xpath($this->render(['phoneRows' => false]));
        $this->assertSame(0, $old->query('//*[@role and not(@data-table)]')->length);
    }

    public function testInitialsComeFromTheFirstAndLastWords(): void
    {
        $x = $this->xpath($this->render([]));
        $this->assertSame('AR', $this->text($x, '//tbody/tr[1]/td[@data-role="avatar"]'));

        $one = $this->xpath($this->render(['rows' => [['id' => 1, 'cells' => ['name' => 'Ann']]]]));
        $this->assertSame('AN', $this->text($one, '//tbody/tr[1]/td[@data-role="avatar"]'));
        $this->assertSame('true', $one->query('//tbody/tr[1]/td[@data-role="avatar"]')->item(0)->getAttribute('aria-hidden'));
    }

    public function testAPrimaryThatIsNotAWordHasNoAvatar(): void
    {
        $x = $this->xpath($this->render(['rows' => [['id' => 1, 'cells' => ['name' => '2026-11-11 09:00']]]]));

        $this->assertSame(0, $x->query('//td[@data-role="avatar"]')->length);
    }

    public function testAvatarFalseOmitsTheAvatar(): void
    {
        $x = $this->xpath($this->render(['avatar' => false]));

        $this->assertSame(0, $x->query('//td[@data-role="avatar"]')->length);
        $this->assertSame('Ann Reyes', $this->text($x, '//tbody/tr[1]/td[@data-role="primary"]'));
    }

    public function testRowsWithActionsRenderTheOpenerAndOneSheet(): void
    {
        $x = $this->xpath($this->render([
            'id'   => 'people',
            'rows' => [
                ['id' => 1, 'cells' => ['name' => 'Ann Reyes', 'email' => 'a@x.test', 'status' => 'Active'], 'actions' => '<a href="#1">Edit</a>'],
                ['id' => 2, 'label' => 'Ben', 'cells' => ['name' => '', 'email' => 'b@x.test', 'status' => 'Away'], 'actions' => '<a href="#2">Edit</a>'],
            ],
        ]));

        $buttons = $x->query('//tr[@data-row]/td/button[@data-row-actions-open]');
        $this->assertSame(2, $buttons->length);

        $first = $buttons->item(0);
        $this->assertSame('button', $first->getAttribute('type'));
        $this->assertSame('dialog', $first->getAttribute('aria-haspopup'));
        $this->assertSame('people-actions', $first->getAttribute('aria-controls'));
        $this->assertSame('Actions for Ann Reyes', $first->getAttribute('aria-label'));
        $this->assertSame('Actions for Ben', $buttons->item(1)->getAttribute('aria-label'));
        $this->assertSame('Ben', $buttons->item(1)->getAttribute('data-row-label'));
        $this->assertContains('md:hidden', $this->classes($first->parentNode));
        $this->assertContains('h-11', $this->classes($first));
        $this->assertContains('w-11', $this->classes($first));
        $this->assertSame('cell', $first->parentNode->getAttribute('role'));

        // One sheet per table, inside the wrapper but not in a row group.
        $sheets = $x->query('//*[@data-dialog]');
        $this->assertSame(1, $sheets->length);
        $this->assertSame('people-actions', $sheets->item(0)->getAttribute('id'));
        $this->assertSame('sheet', $sheets->item(0)->getAttribute('data-dialog-variant'));
        $this->assertSame(1, $x->query('//*[@data-table]/*[@data-dialog]')->length);
        $this->assertSame(1, $x->query('//*[@data-dialog]//*[@data-row-actions-slot]')->length);

        // On phones the actions live in the sheet; from md they stay in their column.
        $this->assertContains('max-md:hidden', $this->classes($x->query('//tr[@data-row][1]/td[@data-role="actions"]')->item(0)));

        // Without an id, each table gets its own sheet id.
        $a = $this->xpath($this->render(['rows' => [['id' => 1, 'cells' => ['name' => 'Ann'], 'actions' => 'x']]]));
        $b = $this->xpath($this->render(['rows' => [['id' => 1, 'cells' => ['name' => 'Ann'], 'actions' => 'x']]]));
        $this->assertNotSame($a->query('//*[@data-dialog]')->item(0)->getAttribute('id'), $b->query('//*[@data-dialog]')->item(0)->getAttribute('id'));
    }

    public function testNoActionsNoSheet(): void
    {
        $x = $this->xpath($this->render([]));

        $this->assertSame(0, $x->query('//*[@data-row-actions-open]')->length);
        $this->assertSame(0, $x->query('//*[@data-dialog]')->length);
        $this->assertSame(0, $x->query('//template')->length);
    }

    public function testAColumnWithoutAPhoneRoleIsListedInTheSheet(): void
    {
        $x = $this->xpath($this->render([
            'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'status', 'label' => 'Status'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'room', 'label' => 'Room']],
            'rows'    => [['id' => 1, 'cells' => ['name' => 'Ann Reyes', 'email' => 'a@x.test', 'status' => 'Active', 'phone' => '<0917>', 'room' => ['html' => '<b>R1</b>']]]],
        ]));

        $button = $x->query('//tr[@data-row]/td/button[@data-row-actions-open]')->item(0);
        $this->assertNotNull($button);
        $this->assertSame('Details for Ann Reyes', $button->getAttribute('aria-label'));
        $this->assertSame(1, $x->query('//*[@data-dialog][@data-dialog-variant="sheet"]')->length);

        // The role-less columns only, in column order, through the table's own cell renderer.
        $lines = $x->query('//tr[@data-row]//template[@data-row-fields]//dt');
        $this->assertSame(['Phone', 'Room'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($lines)));
        $this->assertSame('<0917>', $this->text($x, '//template[@data-row-fields]//dd[1]'));
        $this->assertSame(1, $x->query('//template[@data-row-fields]//dd/b[.="R1"]')->length);

        // The stacked cards show every column already: no sheet.
        $old = $this->xpath($this->render(['phoneRows' => false, 'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'], ['key' => 'c', 'label' => 'C']]]));
        $this->assertSame(0, $old->query('//*[@data-row-actions-open] | //*[@data-dialog]')->length);
    }

    public function testPhoneRowsFalseKeepsStackedCards(): void
    {
        $x = $this->xpath($this->render(['phoneRows' => false]));

        $this->assertContains('max-md:before:content-[attr(data-label)]', $this->classes($x->query('//tbody/tr[1]/td[@data-label="Name"]')->item(0)));
        $this->assertContains('max-md:block', $this->classes($x->query('//tbody/tr[1]')->item(0)));
        $this->assertSame(0, $x->query('//*[@data-phone-rows]')->length);
        $this->assertSame(0, $x->query('//td[@data-role]')->length);
    }

    /** Below md the heading row is screen-reader only, yet it keeps working sort links, 44px on touch, and shows when one has focus. */
    public function testSortLinksStayInTheHeadingRowBelowMd(): void
    {
        $x = $this->xpath($this->render([
            'columns' => [['key' => 'name', 'label' => 'Name', 'sortable' => true], ['key' => 'email', 'label' => 'Email']],
            'sort'    => 'name',
            'dir'     => 'asc',
            'sortUrl' => static fn (string $column, string $next): string => '/people?sort=' . $column . '&dir=' . $next,
        ]));

        $thead = $this->classes($x->query('//thead')->item(0));
        $this->assertContains('max-md:sr-only', $thead);
        $this->assertContains('max-md:focus-within:not-sr-only', $thead, 'a focused sort link is seen');

        $th = $x->query('//thead//th[@aria-sort]')->item(0);
        $this->assertSame('ascending', $th->getAttribute('aria-sort'));
        $link = $x->query('.//a[@href="/people?sort=name&dir=desc"]', $th);
        $this->assertSame(1, $link->length, 'the sort link is still in the heading row');
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($link->item(0)));
        $this->assertNotContains('max-md:hidden', $this->classes($link->item(0)));
        // No plain-label stand-in next to the link.
        $this->assertSame(0, $x->query('.//span[contains(concat(" ", @class, " "), " md:hidden ")]', $th)->length);
    }
}
