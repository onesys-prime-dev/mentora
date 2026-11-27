<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;

/**
 * Every dialog the chairperson or the dean opens on their own pages, on a phone (responsive Phase 6, Task 8):
 * every field is named (a label, aria-label or aria-labelledby: a placeholder does not count) and 16px on a phone,
 * every button and checkbox row is 44px on touch, a dialog with one short field keeps the bottom dialog
 * (`'variant' => 'default'`, or no form at all), and a confirm stays a small centred alert. No fixtures: each page
 * is rendered with made-up data inside the stand-in layout (tests/_support/Views/layouts/app.php), as in
 * StaffPagesResponsiveTest. The course workspace's dialogs are the instructor's (InstructorModalsResponsiveTest);
 * the calendar's forms and the "Edit as chairperson" confirm are drawn by script and checked as text here.
 *
 * @internal
 */
final class StaffModalsResponsiveTest extends CIUnitTestCase
{
    /** Dialogs with one short field (or a script-filled sheet with no form), which keep the bottom dialog on a phone. */
    private const BOTTOM = ['follow-up-pick', 'messages-new', 'messages-report', 'messages-prefs', 'messages-group-settings', 'messages-forward', 'messages-who'];

    /** The confirms (components/confirm, and the password step): small centred alerts at every size. */
    private const ALERTS = ['signoff-2', 'signoff-program-1', 'end-4', 'reauth-dialog'];

    protected function setUp(): void
    {
        parent::setUp();

        helper(['url', 'icon']);
    }

    /** @param array<string, mixed> $data */
    private function render(string $name, array $data = []): string
    {
        $user = new class () {
            public function getGroups(): array
            {
                return ['chairperson'];
            }
        };

        $view = new View(config('View'), TESTPATH . '_support/Views/', service('locator'), false);

        return $view->setData($data + ['user' => $user, 'previousPage' => 'dashboard', 'loadError' => false])->render($name, null, false);
    }

    /**
     * Every [data-dialog] of the staff pages, keyed "page: id". Each page is its own document, so a dialog id both the
     * chairperson's and the dean's page draw (follow-ups) is checked on each of them.
     *
     * @return array<string, DOMElement>
     */
    private function dialogs(): array
    {
        $pages = [
            'dean approvals' => $this->render('pages/dean/approvals', ['filters' => App\Services\Courses\Grades\OverrideApprovalFilters::fromInput([]), 'requests' => [$this->request(7)], 'pending' => 1, 'programs' => [], 'requesters' => []]),
            'dean signoff' => $this->render('pages/dean/signoff', ['board' => ['terms' => [['id' => 1, 'name' => '1st', 'academic_year' => '2026']], 'term' => 1, 'programs' => [[
                'id' => 1, 'name' => 'BSIT', 'counts' => ['ready' => 1, 'blocked' => 0, 'signed_off' => 1], 'own_ready' => 0,
                'sections' => [$this->section(2, 'ready'), $this->section(3, 'signed_off')],
            ]]]]),
            'dean announcements' => $this->render('pages/dean/announcements', ['tab' => 'active', 'scopes' => ['college:1' => 'The college'], 'list' => ['counts' => ['active' => 1, 'ended' => 0], 'rows' => [[
                'id' => 4, 'status' => 'active', 'showing' => true, 'title' => 'Exams', 'message' => 'Week 9.', 'scope_label' => 'The college', 'audience_label' => 'Everyone', 'reach' => 30, 'ends_on' => null, 'ended_at' => null,
            ]]]]),
            'chairperson override requests' => $this->render('pages/chairperson/override-requests', ['view' => 'pending', 'requests' => [['status' => 'awaiting_chair'] + $this->request(8)]]),
            'chairperson follow-ups' => $this->followUps('chairperson', ['received', 'sent'], [3 => [['id' => 6, 'name' => 'Ana', 'role' => 'instructor']]]),
            // The dean's page: the sent box only, nobody to pass on to.
            'dean follow-ups' => $this->followUps('dean', ['sent'], []),
            'messages' => $this->render('pages/messages/index', ['openId' => '', 'canStartGroups' => true, 'canReview' => false]),
            'grades' => $this->render('pages/grades/partials/chairperson/nudge-dialog', ['area' => 'chairperson']),
            'layout' => $this->render('layouts/partials/reauth-dialog'),
        ];

        $found = [];

        foreach ($pages as $page => $html) {
            $doc = new DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');

            foreach ((new DOMXPath($doc))->query('//*[@data-dialog][@id]') as $dialog) {
                $key = $page . ': ' . $dialog->getAttribute('id');
                $this->assertArrayNotHasKey($key, $found, "{$key} is drawn once");
                $found[$key] = $dialog;
            }
        }

        return $found;
    }

    /** The dialog id of a "page: id" key. */
    private function idOf(string $key): string
    {
        return substr($key, strpos($key, ': ') + 2);
    }

    private function followUps(string $area, array $boxes, array $passTo): string
    {
        return $this->render('pages/follow-ups/index', [
            'area' => $area, 'boxes' => $boxes, 'box' => $boxes[0], 'tab' => 'open', 'received' => 1, 'canSend' => true,
            'offerings' => [['id' => 1, 'label' => 'IT101 A']], 'recipients' => [['id' => 6, 'name' => 'Ana', 'role' => 'instructor']], 'passTo' => $passTo,
            'prefill' => ['offering' => 1, 'student' => 0, 'label' => 'IT101 A', 'student_name' => '', 'reason' => 'other', 'title' => 'About IT101 A'],
            'list' => ['counts' => ['open' => 1, 'overdue' => 0, 'done' => 0], 'rows' => [[
                'id' => 3, 'status' => 'open', 'overdue' => false, 'title' => 'Look at IT101', 'label' => 'IT101 A', 'student_name' => '', 'sender_name' => 'Dee Dean', 'sender_role' => 'dean',
                'recipient_name' => 'Carla Chair', 'recipient_role' => 'chairperson', 'due_on' => '2026-11-20', 'due_date' => '2026-11-20', 'passed_to' => null, 'offering_id' => 1, 'slug' => 'it101-a',
                'reason' => 'other', 'message' => 'Please check.', 'replies' => [['name' => 'Carla', 'created_at' => '2026-11-02 09:00:00', 'body' => 'On it.']],
            ]]],
        ]);
    }

    /** @return array<string, mixed> */
    private function request(int $id): array
    {
        return [
            'id' => $id, 'status' => 'awaiting_dean', 'student_name' => 'Stu Dent', 'course_code' => 'IT101', 'section_code' => 'A', 'program_name' => 'BSIT',
            'calculated_percentage' => 70.0, 'current_calculated' => 70.0, 'requested_percentage' => 75.0, 'reason' => 'Late work excused.', 'requested_by' => 99,
            'requester_name' => 'Ana Instructor', 'requester_role' => 'instructor', 'created_at' => '2026-11-01 10:00:00', 'decided_at' => null,
            'endorsed_by' => 3, 'endorser_name' => 'Carla Chair', 'endorse_comment' => null, 'breakdown' => [['name' => 'Quizzes', 'percentage' => 80.0]],
        ];
    }

    /** @return array<string, mixed> */
    private function section(int $id, string $state): array
    {
        return [
            'offering_id' => $id, 'state' => $state, 'course_code' => 'IT10' . $id, 'section_code' => 'A', 'course_title' => 'Computing', 'instructor' => 'Ana',
            'students' => 3, 'blockers' => [], 'grades_url' => '/dean/courses/' . $id, 'counts' => ['open_requests' => 0], 'signed_at' => '2026-11-01', 'signed_by_name' => 'Dee',
        ];
    }

    /** @return list<string> */
    private function classes(DOMElement $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
    }

    private function has(DOMElement $node, string $pattern): bool
    {
        return preg_grep($pattern, $this->classes($node)) !== [];
    }

    /** @return list<DOMElement> the fields a person fills in */
    private function fields(DOMElement $dialog): array
    {
        return iterator_to_array((new DOMXPath($dialog->ownerDocument))->query('.//select | .//textarea | .//input[not(@type="hidden")]', $dialog));
    }

    public function testEveryStaffDialogIsFound(): void
    {
        $keys = array_keys($this->dialogs());

        foreach ([
            'dean approvals: review-7', 'dean signoff: reopen-3', 'dean signoff: signoff-2', 'dean signoff: signoff-program-1',
            'dean announcements: announcement-new', 'dean announcements: edit-4', 'dean announcements: end-4',
            'chairperson override requests: endorse-8', 'chairperson override requests: send-back-8',
            'grades: nudge-dialog', 'layout: reauth-dialog',
            'messages: messages-new', 'messages: messages-report', 'messages: messages-prefs', 'messages: messages-forward', 'messages: messages-who',
        ] as $key) {
            $this->assertContains($key, $keys);
        }

        // The same ids on both follow-ups pages: each page's copy is checked.
        foreach (['chairperson', 'dean'] as $role) {
            foreach (['thread-3', 'follow-up-pick', 'follow-up-new'] as $id) {
                $this->assertContains("{$role} follow-ups: {$id}", $keys);
            }
        }
    }

    public function testEveryFieldHasAName(): void
    {
        $unnamed = [];

        foreach ($this->dialogs() as $id => $dialog) {
            $x = new DOMXPath($dialog->ownerDocument);

            foreach ($this->fields($dialog) as $field) {
                $for   = $field->getAttribute('id');
                $named = $field->getAttribute('aria-label') !== ''
                    || $field->getAttribute('aria-labelledby') !== ''
                    || $x->query('ancestor::label[normalize-space()]', $field)->length > 0
                    || ($for !== '' && $x->query('//label[@for="' . $for . '"][normalize-space()]')->length > 0);

                if (! $named) {
                    $unnamed[] = $id . ': ' . ($field->getAttribute('name') ?: $field->getAttribute('data-role') ?: $field->nodeName);
                }
            }
        }

        $this->assertSame([], $unnamed, 'fields with no accessible name (a placeholder is not one)');
    }

    public function testEveryFieldIs16pxOnAPhone(): void
    {
        $small = [];

        foreach ($this->dialogs() as $id => $dialog) {
            foreach ($this->fields($dialog) as $field) {
                if (! in_array($field->getAttribute('type'), ['checkbox', 'radio'], true) && ! $this->has($field, '/^(max-sm:)?text-base$/')) {
                    $small[] = $id . ': ' . ($field->getAttribute('name') ?: $field->getAttribute('data-role') ?: $field->nodeName);
                }
            }
        }

        $this->assertSame([], $small, 'fields under 16px on a phone (iOS zooms on focus)');
    }

    public function testEveryButtonAndCheckboxRowIs44pxOnTouch(): void
    {
        $short = [];

        foreach ($this->dialogs() as $id => $dialog) {
            $x = new DOMXPath($dialog->ownerDocument);

            foreach ($x->query('.//button | .//label[.//input[@type="checkbox" or @type="radio"]]', $dialog) as $control) {
                if (! $this->has($control, '/^(pointer-coarse:)?(min-h-11|h-11)$/')) {
                    $short[] = $id . ': ' . (trim(preg_replace('/\s+/', ' ', $control->textContent)) ?: $control->getAttribute('aria-label'));
                }
            }
        }

        $this->assertSame([], $short, 'buttons and checkbox rows under 44px tall on touch');
    }

    public function testOneShortFieldKeepsTheBottomDialogAndAFormFillsThePhone(): void
    {
        foreach ($this->dialogs() as $id => $dialog) {
            if (in_array($this->idOf($id), self::ALERTS, true) || $this->fields($dialog) === []) {
                continue;
            }

            if (in_array($this->idOf($id), self::BOTTOM, true)) {
                $this->assertSame('', $dialog->getAttribute('data-dialog-variant'), "{$id}: the bottom dialog on a phone");
            } else {
                $this->assertSame('fullscreen', $dialog->getAttribute('data-dialog-variant'), "{$id}: a form fills the phone screen");
            }
        }

        foreach (['chairperson', 'dean'] as $role) {
            $this->assertCount(1, $this->fields($this->dialogs()["{$role} follow-ups: follow-up-pick"]), 'follow-up-pick asks one thing: the course');
        }
        $this->assertStringContainsString("'variant' => 'default'", (string) file_get_contents(APPPATH . 'Views/pages/follow-ups/index.php'));
    }

    public function testTheConfirmsStaySmallCentredAlerts(): void
    {
        $alerts = array_filter($this->dialogs(), fn (string $key): bool => in_array($this->idOf($key), self::ALERTS, true), ARRAY_FILTER_USE_KEY);
        $this->assertEqualsCanonicalizing(self::ALERTS, array_values(array_unique(array_map($this->idOf(...), array_keys($alerts)))), 'every alert is found');

        foreach ($alerts as $id => $dialog) {
            $this->assertSame('', $dialog->getAttribute('data-dialog-variant'), "{$id}: no phone variant");
            $this->assertContains('items-center', $this->classes($dialog), "{$id}: centred on a phone too");
            $this->assertContains('p-4', $this->classes($dialog), $id);

            $panel = (new DOMXPath($dialog->ownerDocument))->query('./*[@data-dialog-panel]', $dialog)->item(0);
            $this->assertContains('sm:max-w-sm', $this->classes($panel), "{$id}: small");
            $this->assertLessThanOrEqual(1, count($this->fields($dialog)), "{$id}: at most the one field it asks for");
        }
    }

    public function testTheScriptDrawnStaffDialogsAreTouchSizedAndNamed(): void
    {
        // "Edit as chairperson" (grades/program.js): a confirm built in the page, its two buttons 44px on touch.
        $program = (string) file_get_contents(ROOTPATH . 'resources/js/pages/grades/program.js');
        $this->assertMatchesRegularExpression('/data-dialog-close class="[^"]*pointer-coarse:min-h-11[^"]*">Cancel/', $program);
        $this->assertMatchesRegularExpression('/data-edit-continue class="[^"]*pointer-coarse:min-h-11[^"]*">Continue/', $program);

        // The chairperson's calendar forms: every field inside its <label>, 16px on a phone and 44px on touch.
        $calendar = (string) file_get_contents(ROOTPATH . 'resources/js/pages/calendar/calendar.js');
        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/', $calendar, $fields);
        $this->assertNotEmpty($fields[0]);

        foreach ($fields[0] as $field) {
            $this->assertStringContainsString('text-base sm:text-sm pointer-coarse:min-h-11', $field);
        }

        // Messages' script-drawn rows in its dialogs: the picker's results and chips, forward choices, Unblock.
        $messages = (string) file_get_contents(ROOTPATH . 'resources/js/pages/messages/messages.js');
        // The picker's result row (it also picks group members since "+ New group" went): its touch height is pinned.
        $this->assertMatchesRegularExpression('/button\.className = "[^"]*w-full items-center gap-3 rounded-lg px-3 py-2 [^"]*pointer-coarse:min-h-11/', $messages);
        $this->assertMatchesRegularExpression('/remove\.className = "[^"]*pointer-coarse:min-h-11 pointer-coarse:min-w-11/', $messages);
        $this->assertMatchesRegularExpression('/label\.className = "[^"]*pointer-coarse:min-h-11/', $messages);
        $this->assertMatchesRegularExpression('/"rounded-full [^"]*pointer-coarse:min-h-11[^"]*", "Unblock"/', (string) file_get_contents(ROOTPATH . 'resources/js/pages/messages/settings.js'));
    }
}
