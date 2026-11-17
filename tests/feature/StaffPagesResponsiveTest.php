<?php

declare(strict_types=1);

use App\Services\Courses\ChairpersonCourseFilters;
use App\Services\Courses\DeanCourseFilters;
use App\Services\Courses\MonitorCollegeFilters;
use App\Services\Courses\MonitorProgramFilters;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;

/**
 * The chairperson's and the dean's own pages on a phone (responsive Phase 6): filters 44px on touch and 16px on
 * phones, comparison tables in the shared pinned scroller (shared/scroll-x.js), list tables on
 * components/data-table. No fixtures: each page is rendered with made-up data inside a stand-in layout
 * (tests/_support/Views/layouts/app.php). One method per page; later tasks add theirs.
 *
 * @internal
 */
final class StaffPagesResponsiveTest extends CIUnitTestCase
{
    private const BARE_TABLE = '<table class="w-full text-left text-sm">';

    protected function setUp(): void
    {
        parent::setUp();

        helper(['url', 'icon']);
    }

    /** @param array<string, mixed> $data */
    private function page(string $name, array $data): DOMXPath
    {
        $user = new class () {
            public function getGroups(): array
            {
                return ['chairperson'];
            }
        };

        $view = new View(config('View'), TESTPATH . '_support/Views/', service('locator'), false);
        $html = $view->setData($data + ['user' => $user, 'previousPage' => 'dashboard', 'loadError' => false])->render($name, null, false);

        $this->assertStringNotContainsString(self::BARE_TABLE, $html, "{$name}: no hand-written list table");

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

    private function assertTouch(DOMXPath $x, string $query, string $what, bool $phoneText = false): void
    {
        $nodes = $x->query($query);
        $this->assertGreaterThan(0, $nodes->length, "{$what}: found");

        foreach ($nodes as $node) {
            $classes = $this->classes($node);
            $this->assertContains('pointer-coarse:min-h-11', $classes, "{$what} is 44px on touch");
            // A min-height does nothing on an inline link: a link must be a flex box (on touch at least).
            $node->nodeName === 'a' && $this->assertNotSame([], array_intersect(['flex', 'inline-flex', 'pointer-coarse:flex', 'pointer-coarse:inline-flex'], $classes), "{$what} is a flex box");

            if ($phoneText) {
                $this->assertContains('text-base', $classes, "{$what} is 16px on a phone");
                $this->assertContains('sm:text-sm', $classes, "{$what} drops to 14px from sm");
            }
        }
    }

    /** The comparison grid sits in the pinned scroller: focusable, named, the first cell of every row pinned. */
    private function assertPinnedGrid(DOMXPath $x, string $table, string $what): void
    {
        $scroller = $x->query('//*[@data-scroll-x][@data-sticky-first][.//table[' . $table . ']]');
        $this->assertSame(1, $scroller->length, "{$what}: in the pinned scroller");
        $this->assertSame('0', $scroller->item(0)->getAttribute('tabindex'), $what);
        $this->assertSame('region', $scroller->item(0)->getAttribute('role'), $what);
        $this->assertNotSame('', $scroller->item(0)->getAttribute('aria-label'), $what);

        $rows = $x->query('//table[' . $table . ']//tr');
        $this->assertGreaterThan(1, $rows->length, $what);

        foreach ($rows as $row) {
            $first = $x->query('./*[1]', $row)->item(0);
            $this->assertContains('sticky', $this->classes($first), "{$what}: the first cell is pinned");
            $this->assertContains('left-0', $this->classes($first), $what);
        }
    }

    /** The "days silent" badge uses the chair/dean danger pair, which passes contrast and has a dark mapping. */
    private function assertBadge(DOMXPath $x): void
    {
        $badge = $this->classes($x->query('//li//span[contains(., "days silent")]')->item(0));
        $this->assertContains('bg-[#FBE8E2]', $badge);
        $this->assertContains('text-[#A63A18]', $badge);
    }

    private function course(int $id): array
    {
        return ['offering_id' => $id, 'slug' => 'course-' . $id, 'course_code' => 'IT10' . $id, 'section_code' => 'A', 'course_title' => 'Computing ' . $id, 'progress' => 40];
    }

    public function testTheChairpersonCoursesFiltersStackAndAreTouchSized(): void
    {
        $x = $this->page('pages/courses/chairperson-index', [
            'filters' => ChairpersonCourseFilters::fromInput(['q' => 'it']), 'terms' => [['id' => 1, 'name' => '1st', 'academic_year' => '2026']],
            'instructors' => [['id' => 2, 'name' => 'Ana']], 'statuses' => ['active'], 'total' => 2, 'courses' => [$this->course(1), $this->course(2)], 'pages' => 3,
        ]);

        $form = $x->query('//form[@action[contains(., "chairperson/courses")]]')->item(0);
        // One column below sm: no unprefixed column count.
        $this->assertSame([], preg_grep('/^grid-cols-(?!1$)/', $this->classes($form)));
        $this->assertTouch($x, '//form//input[@type="search"] | //form//select', 'a filter control', true);
        $this->assertTouch($x, '//form//button[@type="submit"] | //form//a[normalize-space()="Clear"]', 'Apply and Clear');
        $this->assertTouch($x, '//a[contains(@href, "sort=")]', 'a sort link');
        $this->assertTouch($x, '//nav[@aria-label="Pages"]//a', 'a page link');
    }

    public function testTheDeanCoursesSortLinksAreTouchSized(): void
    {
        $x = $this->page('pages/dean/courses', [
            'filters' => DeanCourseFilters::fromInput([]), 'terms' => [], 'departments' => [], 'programs' => [], 'instructors' => [], 'statuses' => [],
            'total' => 1, 'courses' => [$this->course(1)], 'pages' => 1,
        ]);

        $this->assertTouch($x, '//a[contains(@href, "sort=")]', 'a sort link');
    }

    public function testMonitorProgramFiltersFitAPhoneAndTheComparisonIsAPinnedGrid(): void
    {
        $x = $this->page('pages/courses/monitor-program', [
            'filters' => MonitorProgramFilters::fromInput(['from' => '2026-08-01']), 'sort' => 'course_code', 'dir' => 'asc', 'terms' => [],
            'snapshot' => ['courses' => 2, 'instructors' => 1, 'students' => 30],
            'comparison' => [
                ['offering_id' => 1, 'slug' => 'a', 'course_code' => 'IT101', 'section_code' => 'A', 'enrolled' => 20, 'completion_percentage' => 50.0, 'average_percentage' => 80.0, 'avg_grading_turnaround_hours' => 4.0, 'instructor_name' => 'Ana'],
                ['offering_id' => 2, 'slug' => 'b', 'course_code' => 'IT102', 'section_code' => 'B', 'enrolled' => 10, 'completion_percentage' => null, 'average_percentage' => null, 'avg_grading_turnaround_hours' => null, 'instructor_name' => null],
            ],
            'gradeDistribution' => [], 'atRisk' => [
                ['offering_id' => 1, 'slug' => 'a', 'name' => 'Stu Dent', 'course_code' => 'IT101', 'section_code' => 'A', 'reason' => 'inactive', 'days_since' => 9, 'progress' => 0],
            ],
            'audit' => ['rows' => [], 'total' => 0], 'auditPage' => 1, 'auditPages' => 1,
        ]);

        $this->assertTouch($x, '//form//select | //form//input[@type="date"]', 'a filter control', true);
        $this->assertTouch($x, '//form//button[@type="submit"] | //form//a[normalize-space()="Clear"] | //a[@data-grade-records]', 'Apply, Clear and View grade records');

        // The From/To pair stacks on a phone instead of pushing the page sideways.
        $dates = $x->query('//form//input[@name="from"]/ancestor::div[1]')->item(0);
        $this->assertContains('max-sm:flex-col', $this->classes($dates));
        $this->assertNotContains('min-w-72', $this->classes($dates), 'no 18rem floor on a phone');

        $this->assertPinnedGrid($x, '@data-course-comparison', 'the course comparison');
        $this->assertSame(2, $x->query('//a[@data-course-comparison-link]')->length);
        $this->assertTouch($x, '//a[@data-course-comparison-link] | //li//a', 'a course or student link');

        $this->assertBadge($x);
    }

    public function testMonitorCollegeComparesInAPinnedGridAndListsTheAuditAsPhoneRows(): void
    {
        $group = static fn (int $id, string $name): array => [
            'id' => $id, 'name' => $name, 'context' => '', 'students' => 10, 'completion' => 50.0, 'average_grade' => 80.0,
            'grading_progress' => 90.0, 'turnaround' => 5.0, 'at_risk' => 1, 'last_activity' => '2026-11-01 10:00:00',
        ];
        $entry = static fn (int $id): array => [
            'id' => $id, 'actor' => 'Carla Chair', 'description' => 'Changed a grade', 'offering_id' => 1, 'slug' => 'a',
            'course_code' => 'IT101', 'section_code' => 'A', 'acting_role' => 'chairperson', 'created_at' => '2026-11-01 10:00',
        ];

        $x = $this->page('pages/dean/monitor-college', [
            'filters' => MonitorCollegeFilters::fromInput(['compare' => 'program']), 'collegeName' => 'College B', 'terms' => [], 'departments' => [], 'programs' => [],
            'totals' => ['courses' => 2, 'students' => 20, 'instructors' => 2, 'completion' => 50.0, 'average_grade' => 80.0, 'grading_progress' => 90.0, 'at_risk' => 1],
            'groups' => [$group(1, 'BSIT'), $group(2, 'BSCS')], 'gradeDistribution' => [], 'instructorActivity' => [],
            'atRisk' => [['offering_id' => 1, 'slug' => 'a', 'student_id' => 5, 'name' => 'Stu Dent', 'course_code' => 'IT101', 'section_code' => 'A', 'reason' => 'inactive', 'days_since' => 9, 'progress' => 0]],
            'audit' => ['rows' => [$entry(1), $entry(2), $entry(3)], 'total' => 3], 'auditPage' => 1, 'auditPages' => 1,
        ]);

        $this->assertSame(1, $x->query('//*[@data-section="totals"]')->length);

        $this->assertPinnedGrid($x, '@data-college-comparison', 'the college comparison');
        $this->assertSame(2, $x->query('//table[@data-college-comparison]//tr[@data-group]')->length);
        $this->assertSame(2, $x->query('//a[@data-program-link]')->length);
        $this->assertTouch($x, '//a[@data-program-link] | //*[@data-section="at-risk"]//a | //*[@data-section="audit"]//td[@data-role="secondary"]//a', 'a program, student or course link');
        $this->assertBadge($x);
        $this->assertSame('Details for Changed a grade, Carla Chair, 2026-11-01 10:00', $x->query('(//*[@data-section="audit"]//button[@data-row-actions-open])[1]')->item(0)->getAttribute('aria-label'));

        // The audit log is a data table with phone rows; each entry is counted once (the hook is not in the row sheet).
        $audit = $x->query('//*[@data-section="audit"]//*[@data-table][@data-phone-rows]');
        $this->assertSame(1, $audit->length, 'the audit log is on components/data-table');
        $this->assertSame(3, $x->query('//*[@data-section="audit"]//*[@data-audit-entry]')->length);
        // Who, What and Course show in the row; Acting as and When are in the row sheet.
        $this->assertSame(['status', 'primary', 'secondary'], array_map(
            static fn ($td) => $td->getAttribute('data-role'),
            iterator_to_array($x->query('(//*[@data-section="audit"]//tr[@data-row])[1]/td[@data-role="status" or @data-role="primary" or @data-role="secondary"]'))
        ));
        $this->assertSame(3, $x->query('//*[@data-section="audit"]//button[@data-row-actions-open]')->length);

        $this->assertTouch($x, '//a[@data-export] | //a[@data-grade-records]', 'an export link');
        $this->assertTouch($x, '//*[@aria-label="Compare by"]//a', 'a Compare pill');
        $this->assertTouch($x, '//a[@data-follow-up-link]', 'the follow-up link');
    }

    /** A reason or comment box: named by its label, 16px on a phone. */
    private function assertReasonField(DOMXPath $x, string $dialog, string $name): void
    {
        $field = $x->query('//*[@id="' . $dialog . '"]//textarea[@name="' . $name . '"]')->item(0);
        $this->assertNotNull($field, "{$dialog}: the {$name} box");
        $this->assertSame(1, $x->query('//*[@id="' . $dialog . '"]//label[@for="' . $field->getAttribute('id') . '"][normalize-space()]')->length, "{$dialog}: the {$name} box is named");
        $this->assertContains('text-base', $this->classes($field), "{$dialog}: 16px on a phone");
        $this->assertContains('sm:text-sm', $this->classes($field), $dialog);
    }

    /** A one-form dialog: full screen on a phone with its button row pinned (app.css [data-dialog-pin-actions]), 44px buttons. */
    private function assertPinnedForm(DOMXPath $x, string $dialog): void
    {
        $this->assertSame('fullscreen', $x->query('//*[@id="' . $dialog . '"]')->item(0)?->getAttribute('data-dialog-variant'), "{$dialog}: full screen on a phone");
        $this->assertSame(1, $x->query('//*[@id="' . $dialog . '"]//*[@data-dialog-body][@data-dialog-pin-actions]')->length, "{$dialog}: its button row is pinned");
        $this->assertTouch($x, '//*[@id="' . $dialog . '"]//*[@data-dialog-body]//button', "{$dialog}'s buttons");
    }

    private function assertStatusPills(DOMXPath $x): void
    {
        $this->assertTouch($x, '//*[@role="group"][@aria-label="Show"]//a', 'a status pill');
    }

    /** @return array<string, mixed> */
    private function overrideRequest(int $id): array
    {
        return [
            'id' => $id, 'status' => 'awaiting_dean', 'student_name' => 'Stu Dent', 'course_code' => 'IT101', 'section_code' => 'A', 'program_name' => 'BSIT',
            'calculated_percentage' => 70.0, 'current_calculated' => 70.0, 'requested_percentage' => 75.0, 'reason' => 'Late work excused.', 'requested_by' => 99,
            'requester_name' => 'Ana Instructor', 'requester_role' => 'instructor', 'created_at' => '2026-11-01 10:00:00', 'decided_at' => null,
            'endorsed_by' => 3, 'endorser_name' => 'Carla Chair', 'endorse_comment' => null, 'breakdown' => [['name' => 'Quizzes', 'percentage' => 80.0]],
        ];
    }

    public function testTheDeanApprovalsReviewDialogPinsApproveAndRejectOnAPhone(): void
    {
        $x = $this->page('pages/dean/approvals', [
            'filters' => App\Services\Courses\Grades\OverrideApprovalFilters::fromInput([]), 'requests' => [$this->overrideRequest(7)], 'pending' => 1, 'programs' => [], 'requesters' => [],
        ]);

        $this->assertStatusPills($x);
        $this->assertTouch($x, '//button[@data-dialog-open="review-7"]', 'Review');
        $this->assertTouch($x, '//th//a[contains(@href, "sort=")]', 'a sort header (components/data-table)');
        $this->assertReasonField($x, 'review-7', 'reason');

        // Two forms (approve, reject): the phone bar holds both submits, tied to their forms; the in-form rows are for sm and up.
        $bar = $x->query('//*[@id="review-7"]//*[@data-dialog-body]/*[@data-dialog-actions]');
        $this->assertSame(1, $bar->length, 'a pinned phone action bar');
        $this->assertContains('sm:hidden', $this->classes($bar->item(0)));
        $forms = array_map(static fn ($f) => $f->getAttribute('id'), iterator_to_array($x->query('//*[@id="review-7"]//form[@data-dialog-form]')));
        $this->assertSame(['review-7-approve', 'review-7-reject'], $forms);
        $this->assertSame(['review-7-reject', 'review-7-approve'], array_map(static fn ($b) => $b->getAttribute('form'), iterator_to_array($x->query('./button[@type="submit"]', $bar->item(0)))), 'Reject, then the primary Approve last');
        $this->assertTouch($x, '//*[@id="review-7"]//*[@data-dialog-body]//button', 'a review button');

        foreach ($x->query('//*[@id="review-7"]//form//button[@type="submit"]/..') as $row) {
            $this->assertContains('max-sm:hidden', $this->classes($row), 'the in-form button row gives way to the bar on a phone');
        }
    }

    public function testTheDeanSignoffLinksAndButtonsAreTouchSizedAndReopenIsPinned(): void
    {
        $section = static fn (int $id, string $state): array => [
            'offering_id' => $id, 'state' => $state, 'course_code' => 'IT10' . $id, 'section_code' => 'A', 'course_title' => 'Computing', 'instructor' => 'Ana',
            'students' => 3, 'blockers' => ['Not finalized.'], 'grades_url' => '/dean/courses/' . $id, 'counts' => ['open_requests' => 1], 'signed_at' => '2026-11-01', 'signed_by_name' => 'Dee',
        ];

        $x = $this->page('pages/dean/signoff', [
            'board' => ['terms' => [['id' => 1, 'name' => '1st', 'academic_year' => '2026']], 'term' => 1, 'programs' => [[
                'id' => 1, 'name' => 'BSIT', 'counts' => ['ready' => 1, 'blocked' => 1, 'signed_off' => 1], 'own_ready' => 0,
                'sections' => [$section(1, 'blocked'), $section(2, 'ready'), $section(3, 'signed_off')],
            ]]],
        ]);

        $this->assertTouch($x, '//a[@data-export="csv"]', 'Download CSV');
        $this->assertTouch($x, '//*[@data-blocker-links]//a', 'a blocker link');
        $this->assertTouch($x, '//button[@data-signoff-all] | //button[@data-dialog-open="signoff-2"] | //button[@data-dialog-open="reopen-3"]', 'a sign-off button');
        $this->assertReasonField($x, 'reopen-3', 'reason');
        $this->assertPinnedForm($x, 'reopen-3');
    }

    public function testTheDeanTeachingLoadSortHeadersAndLinksAreTouchSized(): void
    {
        $x = $this->page('pages/dean/teaching-load', [
            'filters' => App\Services\Courses\TeachingLoadFilters::fromInput([]), 'term' => 1, 'terms' => [['id' => 1, 'name' => '1st', 'academic_year' => '2026']], 'departments' => [], 'highLoadUnits' => 24,
            'instructors' => [['id' => 5, 'name' => 'Ana Instructor', 'high_load' => true, 'sections' => 2, 'students' => 40, 'units' => 6.0, 'ungraded' => 3, 'turnaround' => 30.0]],
        ]);

        $this->assertTouch($x, '//a[@data-export="csv"]', 'Download CSV');
        $this->assertTouch($x, '//th//a[contains(@href, "sort=")]', 'a sort header (components/data-table)');
        $this->assertSame(6, $x->query('//th//a[contains(@href, "sort=")]')->length);
        $this->assertTouch($x, '//td//a[contains(@href, "teaching-load/5")]', 'the instructor link');

        $x = $this->page('pages/dean/teaching-load-instructor', [
            'instructor' => ['id' => 5, 'name' => 'Ana Instructor', 'high_load' => false, 'units' => 6.0, 'sections' => 1, 'students' => 20, 'ungraded' => 0, 'turnaround' => null],
            'term' => 1, 'terms' => [], 'highLoadUnits' => 24,
            'sections' => [['offering_id' => 4, 'slug' => 'it201-a', 'course_code' => 'IT201', 'section_code' => 'A', 'course_title' => 'Data', 'student_ids' => [1, 2], 'units' => 3.0, 'ungraded' => 0, 'turnaround' => null]],
        ]);

        $this->assertTouch($x, '//td//a[contains(@href, "dean/courses/4")]', 'the section link');
    }

    public function testTheDeanChairpersonReviewButtonsAndCourseLinksAreTouchSized(): void
    {
        $x = $this->page('pages/dean/chairperson-review', [
            'filters' => App\Services\Courses\ChairpersonReviewFilters::fromInput([]), 'counts' => ['unreviewed' => 1, 'reviewed' => 0], 'chairpersons' => [], 'departments' => [],
            'pageNumber' => 1, 'pages' => 1, 'total' => 1,
            'entries' => [['id' => 9, 'actor' => 'Carla Chair', 'description' => 'Edited a module', 'offering_id' => 3, 'slug' => 'it102-a', 'course_code' => 'IT102', 'section_code' => 'A', 'department' => 'IT', 'created_at' => '2026-11-01 10:00:00', 'reviewed_at' => null]],
        ]);

        $this->assertTouch($x, '//button[@data-mark-reviewed] | //button[@data-mark-page]', 'a Mark reviewed button');
        $this->assertTouch($x, '//td//a[contains(@href, "dean/courses/3")]', 'the course link');
    }

    public function testTheDeanAnnouncementsButtonsAreTouchSizedAndTheFormsPinned(): void
    {
        $x = $this->page('pages/dean/announcements', [
            'tab' => 'active', 'scopes' => ['college:1' => 'The college'],
            'list' => ['counts' => ['active' => 1, 'ended' => 0], 'rows' => [[
                'id' => 4, 'status' => 'active', 'showing' => true, 'title' => 'Exams', 'message' => 'Week 9.', 'scope_label' => 'The college', 'audience_label' => 'Everyone', 'reach' => 30, 'ends_on' => null, 'ended_at' => null,
            ]]],
        ]);

        $this->assertTouch($x, '//button[@data-dialog-open="announcement-new"] | //button[@data-dialog-open="edit-4"] | //button[@data-dialog-open="end-4"]', 'New, Edit and End');
        $this->assertPinnedForm($x, 'announcement-new');
        $this->assertPinnedForm($x, 'edit-4');
    }

    public function testTheChairpersonOverrideRequestsPillsActionsAndDialogsAreTouchSized(): void
    {
        $x = $this->page('pages/chairperson/override-requests', [
            'view' => 'pending', 'requests' => [['status' => 'awaiting_chair'] + $this->overrideRequest(8)],
        ]);

        $this->assertStatusPills($x);
        $this->assertTouch($x, '//button[@data-dialog-open="send-back-8"] | //button[@data-dialog-open="endorse-8"]', 'Send back and Endorse');
        $this->assertReasonField($x, 'endorse-8', 'comment');
        $this->assertReasonField($x, 'send-back-8', 'comment');
        $this->assertPinnedForm($x, 'endorse-8');
        $this->assertPinnedForm($x, 'send-back-8');
    }

    public function testTheFollowUpsPillsButtonsAndDialogsAreTouchSized(): void
    {
        $x = $this->page('pages/follow-ups/index', [
            'area' => 'chairperson', 'boxes' => ['received', 'sent'], 'box' => 'received', 'tab' => 'open', 'received' => 1, 'canSend' => true,
            'offerings' => [['id' => 1, 'label' => 'IT101 A']], 'recipients' => [['id' => 6, 'name' => 'Ana', 'role' => 'instructor']], 'passTo' => [3 => [['id' => 6, 'name' => 'Ana', 'role' => 'instructor']]],
            'prefill' => ['offering' => 1, 'student' => 0, 'label' => 'IT101 A', 'student_name' => '', 'reason' => 'other', 'title' => 'About IT101 A'],
            'list' => ['counts' => ['open' => 1, 'overdue' => 0, 'done' => 0], 'rows' => [[
                'id' => 3, 'status' => 'open', 'overdue' => false, 'title' => 'Look at IT101', 'label' => 'IT101 A', 'student_name' => '', 'sender_name' => 'Dee Dean', 'sender_role' => 'dean',
                'recipient_name' => 'Carla Chair', 'recipient_role' => 'chairperson', 'due_on' => '2026-11-20', 'due_date' => '2026-11-20', 'passed_to' => null, 'offering_id' => 1, 'slug' => 'it101-a',
                'reason' => 'other', 'message' => 'Please check.', 'replies' => [['name' => 'Carla', 'created_at' => '2026-11-02 09:00:00', 'body' => 'On it.']],
            ]]],
        ]);

        $this->assertStatusPills($x);
        $this->assertTouch($x, '//button[@data-dialog-open="follow-up-pick"] | //button[@data-dialog-open="thread-3"]', 'New follow-up and Open');
        $this->assertTouch($x, '//*[@id="thread-3"]//*[@data-dialog-body]//button | //*[@id="follow-up-pick"]//*[@data-dialog-body]//button', 'a follow-up dialog button');
        $this->assertTouch($x, '//*[@id="thread-3"]//a[contains(@href, "courses/1")]', 'the course link in the thread');
        // Three forms: Mark as done and Send reply (the primary, last) share the pinned phone bar.
        $this->assertSame(['thread-3-done', 'thread-3-reply'], array_map(static fn ($b) => $b->getAttribute('form'), iterator_to_array($x->query('//*[@id="thread-3"]//*[@data-dialog-body]/*[@data-dialog-actions]/button[@type="submit"]'))));
        $this->assertReasonField($x, 'thread-3', 'body');
        $this->assertReasonField($x, 'follow-up-new', 'message');
        $this->assertPinnedForm($x, 'follow-up-new');
    }

    /**
     * With nobody signed in who may edit, the Modules tab and a module page draw none of the editing dialogs, so no
     * Save button is in the page at all (Task 7: the dependencies dialog's Save used to be there, hidden). Nobody is
     * signed in here (auth()->user() is null, so viewOnly is true whatever $viewer is); the real split, an instructor
     * with every dialog and a dean with none, is ModulesEditingDialogsTest (DB-backed).
     */
    public function testAReadOnlyViewersModulesPagesCarryNoEditingDialogs(): void
    {
        $viewer = new class (['id' => 1]) extends \CodeIgniter\Shield\Entities\User {
            public function inGroup(string ...$groups): bool
            {
                return in_array('institutional_admin', $groups, true);
            }

            public function getGroups(): ?array
            {
                return ['institutional_admin'];
            }
        };
        $module = ['id' => 1, 'title' => 'Getting Started', 'status' => 'published', 'display_number' => 1, 'duration_minutes' => 0, 'progress' => 0, 'description' => '', 'has_roster' => true, 'learning_units' => []];

        foreach ([
            'modules' => view('pages/courses/features/modules/index', ['user' => $viewer, 'modules' => [], 'course_description' => ''], ['saveData' => false]),
            'module'  => view('pages/courses/features/modules/module', ['user' => $viewer, 'is_instructor' => true, 'module' => $module, 'resume_item' => null], ['saveData' => false]),
        ] as $page => $html) {
            foreach (['data-dependencies-save', 'id="content-actions-sheet"', 'id="module-editor-modal"', 'id="module-delete-modal"', 'id="content-transfer-modal"', 'id="module-dependencies-modal"', 'id="learning-unit-editor-modal"', 'id="learning-item-create-modal"', 'id="learning-item-dependencies-modal"', 'id="learning-item-delete-modal"'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$page}: {$needle}");
            }

            $this->assertDoesNotMatchRegularExpression('/<button[^>]*>(?:(?!<\/button>).)*\bSave\b/is', $html, "{$page}: a Save button");
        }
    }

    /**
     * The assessment view a dean opens from the Grades tab passes axe contrast (Task 7 fix round): Previous is
     * gray-600 on its gray-100 button (gray-500 was 4.39:1), and the instructor panel's small notes on #F9F9FC are
     * #646464 (#767676 was 4.32:1). Shared with the instructor and the student: a contrast fix for everyone.
     */
    public function testTheAssessmentViewsGreysPassContrastOnTheirGreyGrounds(): void
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . view('pages/courses/features/assessments/partials/navigations', ['previousAssessment' => ['id' => 2], 'nextAssessment' => ['id' => 4]], ['saveData' => false]));
        $x        = new DOMXPath($doc);
        $previous = $x->query('//button[@data-assessment="2"]')->item(0);
        $this->assertNotNull($previous, 'Previous');
        $this->assertContains('text-gray-600', $this->classes($previous));
        $this->assertNotContains('text-gray-500', $this->classes($previous));

        $source = (string) file_get_contents(APPPATH . 'Views/pages/courses/features/assessments/partials/assignment-work.php');
        $panel  = substr($source, strpos($source, 'bg-[#F9F9FC] p-10'), 1500);
        $this->assertStringContainsString('per student', $panel);
        $this->assertStringNotContainsString('text-[#767676]', $panel, 'no #767676 on the #F9F9FC panel');
    }
}
