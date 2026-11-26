<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogFilters;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;

/**
 * The institution administrator's own pages on a phone (responsive Phase 7). No fixtures: each page is rendered with
 * made-up data inside a stand-in layout. One method per page group; later tasks add theirs.
 *
 * @internal
 */
final class InstitutionPagesResponsiveTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['url', 'icon', 'form']);
    }

    /** @param array<string, mixed> $data */
    private function page(string $name, array $data): DOMXPath
    {
        $view = new View(config('View'), TESTPATH . '_support/Views/', service('locator'), false);
        $html = $view->setData($data)->render($name, null, false);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    /** @return list<string> */
    private function classes(?DOMNode $node): array
    {
        return $node instanceof DOMElement ? preg_split('/\s+/', trim($node->getAttribute('class'))) : [];
    }

    /** Every node found (at least one) carries sticky and left-0 as class tokens, in any order. */
    private function assertPinned(DOMXPath $x, string $query, string $what): void
    {
        $nodes = $x->query($query);
        $this->assertGreaterThan(0, $nodes->length, "{$what}: found");

        foreach ($nodes as $node) {
            $this->assertSame([], array_diff(['sticky', 'left-0'], $this->classes($node)), $what);
        }
    }

    private function assertTouch(DOMXPath $x, string $query, string $what): void
    {
        $nodes = $x->query($query);
        $this->assertGreaterThan(0, $nodes->length, "{$what}: found");

        foreach ($nodes as $node) {
            $classes = $this->classes($node);
            $this->assertContains('pointer-coarse:min-h-11', $classes, "{$what} is 44px on touch");
            $this->assertNotSame([], array_intersect(['flex', 'inline-flex'], $classes), "{$what} is a flex box");
        }
    }

    public function testHomeCardsStackOnAPhoneAndLinksAreTouchSized(): void
    {
        $x = $this->page('pages/institution/home', [
            'homeInstitution' => ['name' => 'Northfield University', 'status' => 'active'],
            'homeSnapshot'    => ['peopleTotal' => 3, 'people' => ['student' => 1, 'instructor' => 1, 'chairperson' => 0, 'dean' => 0, 'institutional_admin' => 1, 'parent' => 0], 'courses' => 2, 'offerings' => 1, 'enrolments' => 1, 'activeTerm' => null],
            'homeUsage'       => ['plan' => 'Standard', 'seats' => ['used' => 1, 'limit' => 10], 'storage' => ['used_mb' => 1, 'limit_mb' => 10], 'ai' => ['used' => 0, 'limit' => 10]],
            'homeAttention'   => [['key' => 'terms', 'tone' => 'info', 'title' => 'No active term', 'text' => 'Set one.', 'action' => 'Open terms', 'url' => 'institution/terms']],
            'homeActivity'    => [],
            'homeCanAudit'    => true,
            'homeShortcuts'   => [['icon' => 'hat', 'label' => 'Courses', 'url' => '/institution/courses']],
        ]);

        $grid = $x->query('//*[@data-section="snapshot"]//div[contains(@class, "grid")]')->item(0);
        $this->assertContains('grid-cols-1', $this->classes($grid), 'one column on a phone');
        $this->assertContains('sm:grid-cols-2', $this->classes($grid));

        $this->assertSame(1, $x->query('//*[@data-activity-empty]')->length, 'the activity list has an empty state');
        $this->assertSame(1, $x->query('//*[@data-section="attention"]//*[@data-attention]')->length);
        $this->assertTouch($x, '//*[@data-section="attention"]//a | //*[@data-section="shortcuts"]//a | //*[@data-section="activity"]//h2/following-sibling::a', 'a home link');
    }

    public function testAuditAndSubscriptionAreDataTablesAndTheExportLinkIsTouchSized(): void
    {
        $x = $this->page('pages/institution/audit', [
            'auditFilters' => AuditLogFilters::fromInput([]), 'auditRows' => [['id' => 1, 'action_label' => 'Signed in', 'created_at' => '2026-11-01 10:00:00', 'actor' => 'a@b.c', 'via' => null, 'acting_role' => 'student', 'target_type' => null, 'target_id' => null, 'withheld' => false, 'old_values' => null, 'new_values' => null]], 'auditTotal' => 1, 'auditPages' => 1, 'auditActions' => [], 'auditTargets' => [],
        ]);
        $this->assertSame(1, $x->query('//*[@data-table]')->length, 'the audit log is on components/data-table');
        $this->assertTouch($x, '//a[@data-audit-export]', 'Export CSV');

        $x = $this->page('pages/institution/subscription', [
            'planInfo'    => ['name' => 'Standard', 'retired' => false, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30'],
            'planStatus'  => (object) ['state' => 'read_only', 'daysToEnd' => 0, 'graceEnds' => '2026-07-07', 'readOnlyEnds' => '2026-08-07'],
            'planUsage'   => ['seats' => ['used' => 1, 'limit' => 10], 'storage' => ['used_mb' => 1, 'limit_mb' => 10], 'ai' => ['used' => 0, 'limit' => 10]],
            'planHistory' => [['kind' => 'start', 'corrects' => null, 'recorded_at' => '2026-01-01', 'plan_name' => 'Standard', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'amount' => '100', 'currency' => 'PHP', 'reference' => null]],
        ]);
        $this->assertSame(1, $x->query('//*[@data-table]')->length, 'the history is on components/data-table');
        $this->assertTouch($x, '//a[contains(@href, "institution/export")]', 'Take a full export');
    }

    public function testExportButtonIsTouchSized(): void
    {
        $x = $this->page('pages/institution/export', ['exportName' => 'Northfield', 'exportAllowed' => true, 'exportDays' => 7, 'exportRows' => [
            ['id' => 1, 'status' => 'ready', 'created_at' => '2026-11-01 10:00:00', 'expires_at' => '2999-01-01 00:00:00', 'requested_by_label' => 'Ana', 'size_bytes' => 2048, 'message' => null],
        ]]);

        $this->assertTouch($x, '//form[@data-export-form]//button', 'Create an export');
        $this->assertTouch($x, '//a[@data-export="ready"]', 'Download');
    }

    /** @return array<string, mixed> */
    private function structureData(): array
    {
        $program = ['id' => 5, 'code' => 'BSIT', 'name' => 'BS IT', 'status' => 'active', 'course_count' => 2];
        $dept    = ['id' => 3, 'code' => 'CS', 'name' => 'Computing', 'is_active' => 1, 'programs' => [$program]];
        $college = ['id' => 2, 'code' => 'ENG', 'name' => 'Engineering', 'is_active' => 1, 'location' => '', 'established_year' => null, 'departments' => [$dept]];

        return [
            'structureTree' => [$college], 'structureCounts' => ['colleges' => 1, 'departments' => 1, 'programs' => 1], 'structureCanEdit' => true,
            'structureChairs' => [], 'structureSisChairs' => [], 'structureCandidates' => [['id' => 9, 'name' => 'Chair', 'email' => 'c@x.y']],
            'structureDeans' => [], 'structureSisDeans' => [], 'structureDeanCandidates' => [['id' => 8, 'name' => 'Dean', 'email' => 'd@x.y']],
        ];
    }

    public function testStructureDialogsUseTheRightVariant(): void
    {
        $x = $this->page('pages/institution/structure', $this->structureData());

        foreach (['structure-dean', 'structure-chair'] as $id) {
            $dialog = $x->query("//*[@id='{$id}']")->item(0);
            $this->assertNotNull($dialog, "{$id} keeps its id");
            $this->assertFalse($dialog->hasAttribute('data-dialog-variant'), "{$id} is a bottom dialog (one field)");
        }

        foreach (['structure-college', 'structure-college-new', 'structure-sub', 'structure-program'] as $id) {
            $this->assertSame('fullscreen', $x->query("//*[@id='{$id}']")->item(0)?->getAttribute('data-dialog-variant'), "{$id} stays full-screen on a phone");
        }

        $this->assertSame(1, $x->query("//*[@id='structure-confirm']")->length);
    }

    public function testStructureControlsAreTouchSized(): void
    {
        $x = $this->page('pages/institution/structure', $this->structureData());

        $this->assertTouch($x, '//*[@data-structure-tree]//button | //*[@data-view]//button[@data-dialog-open or @data-select] | //*[@data-row-menu]/summary | //*[@data-row-menu]//button | //*[@data-dean]//button | //*[@data-chairs]//button | //*[@data-program]/..//button[@data-dialog-open]', 'a structure control');
        $search = $this->classes($x->query('//*[@data-structure-search]')->item(0));
        $this->assertContains('pointer-coarse:min-h-11', $search);
        $this->assertContains('text-base', $search, 'the search field is 16px on a phone');
        $this->assertTouch($x, '//button[@data-dialog-open="structure-college-new"]', 'Add a college');
    }

    private function dialogVariant(DOMXPath $x, string $id): ?string
    {
        $dialog = $x->query("//*[@id='{$id}']")->item(0);
        $this->assertNotNull($dialog, "{$id} keeps its id");

        return $dialog->hasAttribute('data-dialog-variant') ? $dialog->getAttribute('data-dialog-variant') : null;
    }

    public function testTermsAndTermCloseKeepTheirConfirmAndAreTouchSized(): void
    {
        $term = ['id' => 1, 'name' => 'First Semester', 'academic_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2026-12-01', 'status' => 'upcoming', 'offering_count' => 1, 'enrolment_count' => 2, 'overlaps' => [], 'close_snapshot' => null, 'copied_count' => 0];
        $x    = $this->page('pages/institution/terms', ['termRows' => [$term], 'termAll' => [$term], 'termYears' => ['2026-2027'], 'termFilters' => ['q' => '', 'year' => '', 'status' => ''], 'termCanEdit' => true]);

        $this->assertSame(1, $x->query('//*[@data-table]')->length, 'terms are on components/data-table');
        $this->assertTouch($x, '//td[@data-role="actions"]//a | //td[@data-role="actions"]//button', 'a term row action');
        $this->assertTouch($x, '//button[@data-dialog-open="term-form"]', 'Add a term');
        $this->assertTouch($x, '//*[@id="term-form"]//form//button[@type="submit" or @data-dialog-close]', 'a term dialog button');

        $x = $this->page('pages/institution/term-close', ['closeCanEdit' => true, 'closePlan' => ['canClose' => true, 'reason' => '', 'offeringCount' => 1, 'enrolmentCount' => 2, 'untouched' => 0, 'term' => $term, 'offerings' => [['id' => 4, 'course_code' => 'IT101', 'course_title' => 'Intro', 'section_code' => 'A', 'enrolled' => 2]]]]);

        $this->assertSame(1, $x->query('//form[contains(@action, "/close")]//button[@type="submit"]')->length, 'the destructive button is inside its form');
        $this->assertTouch($x, '//form[contains(@action, "/close")]//button | //main//a[contains(@class, "rounded-full")]', 'a term-close button');
    }

    public function testOfferingsAreDataTablesWithEditPathsAndDialogVariants(): void
    {
        $offering = ['id' => 4, 'status' => 'active', 'course_code' => 'IT101', 'course_title' => 'Intro', 'section_code' => 'A', 'term_name' => 'First', 'academic_year' => '2026-2027', 'capacity' => 40, 'enrolled_count' => 2, 'description' => '', 'instructors' => [['assignment_id' => 7, 'name' => 'Ana Cruz']]];
        $x        = $this->page('pages/institution/offerings', [
            'offeringRows' => [$offering], 'offeringTotal' => 1, 'offeringPages' => 1, 'offeringPage' => 1,
            'offeringFilters' => ['q' => '', 'term' => 0, 'program' => 0, 'instructor' => '', 'status' => '', 'sort' => '', 'dir' => ''],
            'offeringChoices' => ['terms' => [], 'programs' => []], 'offeringOptions' => ['courses' => [['id' => 1, 'code' => 'IT101', 'title' => 'Intro']], 'sections' => [['id' => 2, 'code' => 'BSIT 1-A', 'program' => 'BSIT', 'year_level' => 1, 'term' => 'First', 'term_year' => '2026-2027']]],
            'offeringInstructors' => [['id' => 9, 'name' => 'Ana Cruz', 'email' => 'a@x.y']], 'offeringSisInstructors' => [], 'offeringSchedules' => [], 'offeringCanEdit' => true,
        ]);

        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertTouch($x, '//td[@data-role="actions"]//a | //td[@data-role="actions"]//button | //td[@data-label="Instructors"]//button', 'an offering row action');
        // The chip's Remove and the roster link sit in cells a phone row moves into a read-only sheet: each has a live copy in the row's actions.
        $this->assertSame(1, $x->query('//td[@data-role="actions"]//button[@data-remove-instructor]')->length, 'Remove has an edit path');
        $this->assertSame(1, $x->query('//td[@data-role="actions"]//a[@data-roster]')->length, 'the roster link has an edit path');
        $this->assertNull($this->dialogVariant($x, 'offering-instructor'), 'one field: a bottom dialog');
        $this->assertSame('fullscreen', $this->dialogVariant($x, 'offering-add'));
        $this->assertSame('fullscreen', $this->dialogVariant($x, 'offering-edit'));
        $this->assertTouch($x, '//*[@id="offering-add"]//form//button[@type="submit" or @data-dialog-close]', 'an offering dialog button');
    }

    public function testSectionsRosterAndSectionAreTouchSizedWithTheRightVariants(): void
    {
        $row = ['id' => 2, 'code' => 'BSIT 1-A', 'program_code' => 'BSIT', 'program_name' => 'BS IT', 'year_level' => 1, 'term_name' => 'First', 'academic_year' => '2026-2027', 'course_count' => 1, 'student_count' => 1, 'capacity' => 40, 'status' => 'active'];
        $x   = $this->page('pages/institution/sections', [
            'sectionRows' => [$row], 'sectionTotal' => 1, 'sectionPages' => 1, 'sectionPage' => 1, 'sectionFilters' => ['q' => '', 'term' => 0, 'program' => 0, 'status' => ''],
            'sectionOptions' => ['terms' => [], 'openTerms' => [['id' => 1, 'name' => 'First', 'academic_year' => '2026-2027', 'status' => 'active']], 'programs' => [['id' => 5, 'code' => 'BSIT', 'name' => 'BS IT']]], 'sectionCanEdit' => true,
        ]);
        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertTouch($x, '//td[@data-role="actions"]//a | //button[@data-dialog-open="section-add"] | //*[@id="section-add"]//form//button[@type="submit" or @data-dialog-close]', 'a sections control');

        $section = $row + ['term_status' => 'active'];
        $x       = $this->page('pages/institution/section', [
            'section' => $section, 'sectionCanEdit' => true,
            'sectionCourses' => [['id' => 4, 'course_code' => 'IT101', 'course_title' => 'Intro', 'instructors' => [], 'enrolled_count' => 1, 'capacity' => 40]],
            'sectionStudents' => [['id' => 6, 'name' => 'Sam Lee', 'email' => 's@x.y', 'student_number' => '1', 'places' => 1]],
            'sectionAvailable' => [['id' => 8, 'course_code' => 'IT102', 'course_title' => 'More']],
        ]);
        $this->assertNull($this->dialogVariant($x, 'section-course'), 'one field: a bottom dialog');
        $this->assertSame('fullscreen', $this->dialogVariant($x, 'section-edit'));
        $this->assertTouch($x, '//td[@data-role="actions"]//button | //td[@data-role="actions"]//a | //button[@data-dialog-open="section-students"] | //button[@data-dialog-open="section-confirm"]', 'a section control');

        $offering = ['id' => 4, 'course_code' => 'IT101', 'section_code' => 'A', 'course_title' => 'Intro', 'term_name' => 'First', 'academic_year' => '2026-2027', 'status' => 'active', 'enrolled_count' => 1, 'capacity' => 40];
        $x        = $this->page('pages/institution/roster', [
            'rosterOffering' => $offering, 'rosterRows' => [['id' => 3, 'student_id' => 3, 'name' => 'Sam Lee', 'email' => 's@x.y', 'student_number' => '1', 'status' => 'enrolled', 'locked' => false, 'enrolled_at' => '2026-08-02 10:00:00']],
            'rosterTotal' => 1, 'rosterPages' => 1, 'rosterPage' => 1, 'rosterCounts' => ['dropped' => 0, 'withdrawn' => 0, 'completed' => 0], 'rosterLocked' => false,
            'rosterFilters' => ['q' => '', 'status' => '', 'sort' => '', 'dir' => ''], 'rosterCanEdit' => true,
        ]);
        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertTouch($x, '//td[@data-role="actions"]//button | //button[@data-dialog-open="roster-add"] | //*[@id="roster-add"]//form//button[@type="submit" or @data-dialog-close]', 'a roster control');
    }

    public function testScheduleAndCopyGridsArePinned(): void
    {
        $offering = ['id' => 4, 'status' => 'active', 'course_code' => 'IT101', 'course_title' => 'Intro', 'section_code' => 'A', 'term_name' => 'First', 'academic_year' => '2026-2027'];
        $x        = $this->page('pages/institution/offering-schedule', [
            'scheduleOffering' => $offering, 'scheduleRows' => [['day' => 'mon', 'start' => '08:00', 'end' => '09:00', 'room' => '204', 'mode' => 'onsite']],
            'scheduleSisOwned' => false, 'scheduleReadOnly' => false, 'scheduleCanEdit' => true, 'scheduleWarnings' => [],
        ]);

        $this->assertSame(1, $x->query('//*[@data-scroll-x]//table')->length, 'the schedule grid scrolls in its box');
        $this->assertPinned($x, '//tbody/tr/td[1]', 'every first cell is pinned');
        $this->assertSame(0, $x->query('//tbody//select[not(@aria-label)] | //tbody//input[not(@aria-label)]')->length, 'every control is named');
        $this->assertGreaterThan(0, $x->query('//tbody//input[@type="time"]')->length);
        $this->assertGreaterThan(0, $x->query('//tbody/tr[1]/td[1]//*[contains(@class, "md:hidden")]')->length, 'a phone card labels its fields');
        $this->assertTouch($x, '//form[@data-schedule-form]//button[@type="submit"]', 'Save class times');
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($x->query('//tbody//input[@type="time"]')->item(0)), 'a time input is 44px on touch');

        $sis = $this->page('pages/institution/offering-schedule', [
            'scheduleOffering' => $offering, 'scheduleRows' => [['day' => 'mon', 'start' => '08:00', 'end' => '09:00', 'room' => '204', 'mode' => 'onsite']],
            'scheduleSisOwned' => true, 'scheduleReadOnly' => true, 'scheduleCanEdit' => false, 'scheduleWarnings' => [],
        ]);
        $this->assertSame(1, $sis->query('//*[@data-sis-managed][not(ancestor::*[@data-scroll-x])]')->length, 'the SIS note sits above the scroller, so it never scrolls away');
        $this->assertSame(1, $sis->query('//*[@data-scroll-x]//table')->length);

        $x = $this->page('pages/institution/offering-copy', [
            'copyTerms' => ['sources' => [], 'targets' => []], 'copySource' => 1, 'copyTarget' => 2, 'copyError' => null, 'copyCanEdit' => true,
            'copyPlan' => ['shiftDays' => 0, 'source' => ['name' => 'A'], 'target' => ['name' => 'B'], 'offerings' => [['id' => 4, 'course_code' => 'IT101', 'course_title' => 'Intro', 'section_code' => 'A', 'modules' => 1, 'items' => 2, 'assessments' => 3, 'instructors' => 1, 'conflict' => false]]],
        ]);

        $this->assertSame(1, $x->query('//*[@data-scroll-x][@data-sticky-first]//table')->length, 'the offering list is a pinned grid');
        $this->assertPinned($x, '//tbody/tr/td[1]', 'every checkbox cell is pinned');
        $this->assertPinned($x, '//thead/tr/th[1]', 'the header cell is pinned');
        $this->assertSame(1, $x->query('//thead//input[@type="checkbox"][@data-check-all][@aria-label]')->length, 'select-all is named');
        $this->assertSame(0, $x->query('//form[@data-copy-form]//input[@type="checkbox"][not(@aria-label)][not(ancestor::label)]')->length, 'every checkbox is named');
        $this->assertSame(1, $x->query('//form[@data-copy-form]//*[@data-copy-bar]//button[@type="submit"]')->length, 'the Copy button sits in the pinned bar, inside its form');
        $this->assertContains('pb-safe-4', $this->classes($x->query('//*[@data-copy-bar]')->item(0)));
        $this->assertTouch($x, '//*[@data-copy-bar]//button | //*[@data-copy-bar]//a | //form[@data-copy-terms]//button', 'a copy button');
    }

    /** @return array<string, mixed> */
    private function personRow(): array
    {
        return ['id' => 7, 'name' => 'Sam Lee', 'first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 's@x.y', 'roles' => ['student'], 'sis' => false, 'viewable' => true, 'active' => true, 'self' => false, 'last_admin' => false, 'last_active' => null, 'student' => true, 'minor' => false];
    }

    public function testPeopleAndParentsAreDataTablesWithTouchSizedActionsAndTheRightDialogVariants(): void
    {
        $x = $this->page('pages/institution/people', [
            'peopleView' => 'people', 'peopleRows' => [$this->personRow()], 'peopleTotal' => 1, 'peoplePages' => 1, 'peopleFilters' => \App\Services\Users\UserFilters::fromInput([]),
            'peopleInvitations' => [], 'peopleOpen' => 0, 'peopleRoles' => ['student', 'parent'], 'peopleRoleLabels' => ['student' => 'Student', 'parent' => 'Parent'],
            'peopleSeats' => ['used' => 1, 'limit' => 10, 'percent' => 10, 'state' => 'ok'], 'peopleCanEdit' => true, 'peopleCanViewAs' => true,
        ]);

        $this->assertSame(1, $x->query('//*[@data-table]')->length, 'people are on components/data-table');
        // A closed <details> would hide the entries in the row's phone sheet (and its summary closes the sheet): phones get the entries flat.
        $this->assertSame(1, $x->query('//td[@data-role="actions"]//details[@data-person-menu][contains(@class, "max-md:hidden")]')->length, 'the dropdown is for md and up');
        $this->assertSame(0, $x->query('//*[@data-person-actions]//*[@data-return-focus]')->length, 'the flat copy leaves focus to the row sheet');
        $this->assertSame($x->query('//details[@data-person-menu]//button')->length, $x->query('//details[@data-person-menu]//button[@data-return-focus="person-menu-7"]')->length, 'every dropdown entry returns focus to its summary');
        $this->assertSame(1, $x->query('//summary[@id="person-menu-7"]')->length);
        $this->assertGreaterThan(0, $x->query('//td[@data-role="actions"]//*[@data-person-actions][contains(@class, "md:hidden")]//button[@data-dialog-open="person-roles"]')->length, 'phones get the entries flat');
        $this->assertTouch($x, '//td[@data-role="actions"]//button | //td[@data-role="actions"]//summary | //a[contains(@href, "people/upload")] | //a[contains(@href, "people/export")] | //button[@data-dialog-open="person-invite"]', 'a people control');
        $this->assertTouch($x, '//*[@id="person-invite"]//form//button[@type="submit" or @data-dialog-close] | //*[@id="person-roles"]//form//button[@type="submit" or @data-dialog-close] | //*[@id="person-view-as"]//form//button[@type="submit" or @data-dialog-close]', 'a people dialog button');
        $this->assertTouch($x, '//*[@id="person-roles"]//label', 'a role tick');

        foreach (['person-invite', 'person-edit', 'person-roles'] as $id) {
            $this->assertSame('fullscreen', $this->dialogVariant($x, $id), "{$id} holds several fields: full-screen on a phone");
        }
        $this->assertNull($this->dialogVariant($x, 'person-view-as'), 'one field: a bottom dialog');

        $x = $this->page('pages/institution/parents', [
            'parentRows' => [['id' => 9, 'name' => 'Pat Lee', 'email' => 'p@x.y', 'active' => true, 'students' => [['link_id' => 3, 'name' => 'Sam Lee']]]],
            'parentTotal' => 1, 'parentPages' => 1, 'parentPage' => 1, 'parentFilters' => ['q' => ''], 'parentOpen' => 0, 'parentCanEdit' => true,
        ]);
        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertTouch($x, '//td[@data-role="actions"]//button | //button[@data-dialog-open="parent-confirm"] | //*[@id="parent-link"]//form//button[@type="submit" or @data-dialog-close]', 'a parents control');
        $this->assertSame(1, $x->query('//td[@data-role="actions"]//button[@data-dialog-open="parent-link"]')->length, 'Link students has an edit path');
        $this->assertNull($this->dialogVariant($x, 'parent-link'), 'one field: a bottom dialog');
        $copy = $x->query('//td[@data-role="actions"]//button[contains(@class, "md:hidden")][@data-dialog-open="parent-confirm"]')->item(0);
        $this->assertNotNull($copy, 'the phone Unlink copy');
        $this->assertContains('text-[#C33939]', $this->classes($copy), 'the phone Unlink is red');
        $this->assertNotContains('text-brand', $this->classes($copy), 'and not also brand green');
        $this->assertSame('Unlink Sam Lee from Pat Lee', $copy->getAttribute('aria-label'));
    }

    public function testCoursesAreTouchSizedWithFullScreenForms(): void
    {
        $course = ['id' => 4, 'course_code' => 'IT101', 'course_title' => 'Intro', 'program_code' => 'BSIT', 'program_name' => 'BS IT', 'units' => 3, 'offering_count' => 1, 'status' => 'active', 'sis_managed' => 0, 'program_status' => 'active', 'description' => ''];
        $x      = $this->page('pages/institution/courses', [
            'courseRows' => [$course], 'courseTotal' => 1, 'coursePages' => 1, 'coursePage' => 1, 'courseFilters' => ['q' => '', 'program' => 0, 'status' => '', 'sort' => '', 'dir' => ''],
            'coursePrograms' => [], 'courseActivePrograms' => [['id' => 5, 'code' => 'BSIT', 'name' => 'BS IT']], 'courseCanEdit' => true, 'courseLockedFields' => [],
        ]);

        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertTouch($x, '//td[@data-role="actions"]//button | //button[@data-dialog-open="course-add"] | //*[@id="course-add"]//form//button[@type="submit" or @data-dialog-close]', 'a courses control');
        $this->assertSame(3, $x->query('//td[@data-role="actions"]//button[@data-dialog-open]')->length, 'Edit, Deactivate and Archive each keep their dialog');
        $this->assertSame('fullscreen', $this->dialogVariant($x, 'course-add'));
        $this->assertSame('fullscreen', $this->dialogVariant($x, 'course-edit'));
    }

    public function testUploadFieldsAreTouchSizedAndTheIssueListsWrap(): void
    {
        $x = $this->page('pages/institution/upload', ['uploadStep' => 1, 'uploadBlocked' => false, 'uploadCanEdit' => true, 'uploadDatasets' => ['students', 'courses'], 'uploadKept' => null, 'uploadToken' => '']);

        $this->assertGreaterThan(0, $x->query('//input[@type="file"]')->length, 'file fields found');
        $this->assertSame(0, $x->query('//input[@type="file"][not(contains(@class, "pointer-coarse:min-h-11"))]')->length, 'every file field is 44px on touch');
        $this->assertSame(0, $x->query('//input[@type="file"][not(contains(@class, "pointer-coarse:file:py-3"))]')->length, 'and so is its Choose button');
        $this->assertTouch($x, '//form[@data-upload-form]//a | //form[@data-upload-form]//button', 'an upload button');
        $this->assertSame(2, $x->query('//form[@data-upload-form]//a[contains(@href, "template/")][contains(@class, "pointer-coarse:min-h-11")]')->length, 'the template links are 44px on touch');

        $issue  = ['dataset' => 'students', 'item' => 'row 12 (a-very-long-address-that-has-no-break@example.edu)', 'reason' => 'The email is already used by another person in the institution.', 'note' => false];
        $counts = ['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1];
        $x      = $this->page('pages/institution/upload', ['uploadStep' => 2, 'uploadBlocked' => false, 'uploadCanEdit' => true, 'uploadDatasets' => [], 'uploadToken' => 'tok', 'uploadKept' => [
            'totals' => $counts + ['ended' => 0, 'removed' => 0], 'datasets' => ['students' => $counts], 'issues' => [$issue], 'email' => true, 'mail' => ['people' => 1, 'welcome' => 1, 'notices' => 0],
        ]]);

        $this->assertGreaterThan(0, $x->query('//*[@data-upload-issues]//li')->length, 'refused rows found');
        $this->assertGreaterThan(0, $x->query('//*[@data-upload-file]/span')->length, 'file lines found');
        $this->assertSame(0, $x->query('//*[@data-upload-issues]//li[not(contains(@class, "break-words"))]')->length, 'a refused row breaks long words at 320');
        $this->assertSame(0, $x->query('//*[@data-upload-file]/span[not(contains(@class, "min-w-0"))]')->length, 'a file line shrinks');
        $this->assertTouch($x, '//form[contains(@action, "/apply") or contains(@action, "/discard")]//button', 'Apply and Start over');
    }

    public function testIntegrationsAndSettingsAreTouchSizedAndKeepEachFormOnItsOwn(): void
    {
        $x = $this->page('pages/institution/integrations', ['sisCanRun' => true, 'sisConnection' => ['available' => true, 'configured' => true, 'enabled' => true, 'label' => 'akademus', 'runs' => [], 'latest' => null, 'latestGrades' => null]]);
        $this->assertSame(4, $x->query('//form[@method="post"]//button')->length);
        $this->assertTouch($x, '//form[@method="post"]//button', 'an integrations button');
        $this->assertSame(0, $x->query('//form//form')->length);

        $d        = ['name' => 'Northfield', 'code' => 'NF', 'contact_email' => '', 'contact_phone' => '', 'address' => '', 'logo_file_id' => 5, 'primary' => '#0A5C36', 'accent' => '#C9A227', 'custom' => true];
        $academic = ['passing' => 75, 'passingFromInstitution' => true, 'scales' => [], 'scaleId' => 0, 'scaleFromInstitution' => false, 'categories' => [], 'categoriesFromInstitution' => false, 'inactiveDays' => 7, 'inactiveFromInstitution' => false, 'behindBelow' => 50, 'behindFromInstitution' => false, 'overdueGradingDays' => 5, 'overdueFromInstitution' => false, 'highLoadUnits' => 24, 'highLoadFromInstitution' => false];
        $keys     = ['passwordMinLength' => 8, 'sessionTimeoutMinutes' => 30, 'lockoutAttempts' => 5, 'lockoutMinutes' => 15];
        $base     = ['settingsData' => $d, 'settingsCanEdit' => true, 'settingsDefaults' => ['primary' => '#0A5C36', 'accent' => '#C9A227'],
            'settingsAnnouncement' => ['text' => 'Hi', 'until' => '', 'showing' => true], 'settingsParents' => ['own' => true, 'platform' => true], 'settingsMessages' => ['scope' => 'institution', 'quietStart' => null, 'quietEnd' => null],
            'settingsSecurity' => ['own' => $keys, 'ownRoles' => ['dean'], 'platform' => $keys + ['twoFactorRoles' => ['institutional_admin']], 'effective' => $keys], 'settingsAcademic' => $academic];

        $forms = 0;

        foreach (['profile', 'branding', 'academic', 'announcement', 'security', 'parents', 'messages'] as $tab) {
            $x = $this->page('pages/institution/settings', ['settingsTab' => $tab] + $base);

            $this->assertSame(1, $x->query('//nav[@data-tabs]//*[@data-scroll-x]')->length, "{$tab}: the tab bar is a scrolling strip");
            $this->assertSame(0, $x->query('//form//form')->length, "{$tab}: no form is nested");

            foreach ($x->query('//form') as $form) {
                $forms++;
                $id  = $form->getAttribute('id');
                $own = $x->query('.//button[@type="submit"]', $form)->length > 0 || ($id !== '' && $x->query("//button[@form='{$id}']")->length > 0);
                $this->assertTrue($own, "{$tab}: a form has its own submit");
            }

            $this->assertTouch($x, '//form//button | //form//label[input[@type="radio" or @type="checkbox"]]', "{$tab}: a settings control");
            $this->assertSame(0, $x->query('//input[@type="file"][not(contains(@class, "pointer-coarse:min-h-11"))]')->length, "{$tab}: a file field is 44px on touch");
        }

        $this->assertSame(12, $forms, 'settings keeps its 12 forms');

        $x = $this->page('pages/institution/settings', ['settingsTab' => 'security'] + $base);
        $this->assertSame(0, $x->query('//input[@type="checkbox" or @type="radio"][not(contains(@class, "pointer-coarse:h-6"))]')->length, 'switches are 24px boxes in a 44px label on touch');
    }

    public function testReportsTablesScrollOrStack(): void
    {
        $filters = new \App\Services\Courses\MonitorProgramFilters();
        $row     = ['offering_id' => 7, 'course_code' => 'CS101', 'section_code' => 'A', 'course_title' => 'Intro', 'program_name' => 'BSCS', 'instructor_name' => null, 'enrolled' => 3,
            'completion_percentage' => 50, 'average_percentage' => 80, 'avg_grading_turnaround_hours' => 2];
        $risk    = ['name' => 'Ana', 'offering_id' => 7, 'course_code' => 'CS101', 'section_code' => 'A', 'reason' => 'inactive', 'progress' => 10, 'days_since' => 9];
        $data    = ['snapshot' => ['courses' => 1, 'instructors' => 1, 'students' => 3], 'grading' => ['percent' => 50, 'turnaround' => 2, 'average' => 80],
            'distribution' => ['0-59' => 1, '60-100' => 2], 'comparison' => [$row], 'rows' => [$row], 'atRisk' => [$risk]];
        $base    = ['reportData' => $data, 'reportOptions' => ['terms' => [], 'colleges' => [], 'programs' => []], 'reportFilters' => $filters, 'reportCollege' => null, 'reportProgram' => null,
            'reportSort' => 'course_code', 'reportDir' => 'asc', 'reportExports' => ['courses' => 'Courses']];

        foreach (['overview', 'courses', 'at-risk'] as $tab) {
            $x = $this->page('pages/institution/reports', ['reportTab' => $tab] + $base);
            $this->assertSame(1, $x->query('//nav[@data-tabs]//*[@data-scroll-x]')->length, "{$tab}: the tab strip scrolls");
            $this->assertTouch($x, '//details[@data-menu]/summary', "{$tab}: Export CSV");
            $this->assertTouch($x, '//details[@data-menu]//a[@data-export]', "{$tab}: an Export CSV entry");
            $this->assertSame($tab === 'overview' ? 0 : 1, $x->query('//table//tbody[@data-panel]')->length, "{$tab}: the report table is a data-table");
        }

        $x = $this->page('pages/institution/reports', ['reportTab' => 'overview'] + $base);
        $this->assertGreaterThan(0, $x->query('//*[@data-report-distribution]//li')->length);
        $this->assertSame(1, $x->query('//*[@data-report-distribution][contains(@class, "@container")]')->length, 'the bars scale to their container');

        $offering = ['offering' => ['offering_id' => 7, 'course_code' => 'CS101', 'section_code' => 'A', 'course_title' => 'Intro', 'program_name' => 'BSCS', 'term_name' => 'T1', 'academic_year' => '2026', 'capacity' => 40],
            'instructors' => [], 'enrolled' => 3, 'completion' => 50, 'grades' => ['average_percentage' => 80, 'graded_count' => 2, 'enrolled' => 3, 'distribution' => ['0-59' => 1, '60-100' => 2]],
            'activity' => ['avg_grading_turnaround_hours' => 2, 'graded_count' => 2, 'content_updates' => 1, 'announcements' => 1], 'atRisk' => [$risk]];
        $x = $this->page('pages/institution/report-offering', ['offeringReport' => $offering, 'reportFilters' => $filters]);
        $this->assertTouch($x, '//a[@data-view-course]', 'Open the course');
        $this->assertSame(1, $x->query('//*[@data-report-distribution][contains(@class, "@container")]')->length, 'the offering bars scale to their container');
        $this->assertSame(1, $x->query('//table//tbody[@data-panel]')->length, 'the offering table is a data-table');
    }
}
