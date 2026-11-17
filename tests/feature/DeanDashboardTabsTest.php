<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;

/**
 * Below lg the dean dashboard is split into Attention / College / Updates phone tabs (components/phone-tabs);
 * from lg up the strip, rail and main column are unchanged. No fixtures: the template is rendered with its data
 * inside a stand-in layout (tests/_support/Views/layouts/app.php); only the strip's Messages flag is read.
 *
 * @internal
 */
final class DeanDashboardTabsTest extends CIUnitTestCase
{
    /** Each widget, found by the comment its partial opens with, and its tab. */
    private const WIDGETS = [
        'needs-attention'      => ['attention', 'needs attention (dean)'],
        'quick-access'         => ['college', 'quick access (chairperson)'],
        'profile-card'         => ['college', 'profile card (chairperson)'],
        'college-snapshot'     => ['college', 'college snapshot (dean)'],
        'chairperson-activity' => ['updates', 'chairperson activity (dean)'],
        'announcements'        => ['updates', 'college announcements (dean)'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        helper(['url', 'icon']);
    }

    /** @param list<array<string, mixed>> $attention */
    private function overview(array $attention = []): array
    {
        return [
            'college'              => ['name' => 'College D'],
            'snapshot'             => ['departments' => 1, 'programs' => 1, 'courses' => 1, 'instructors' => 1, 'students' => 1],
            'attention'            => $attention,
            'chairperson_activity' => ['to_review' => 0, 'entries' => []],
            'announcements'        => [],
        ];
    }

    private function dashboard(?array $overview, bool $loadError = false): DOMXPath
    {
        // The quick-access strip only asks the user for its groups.
        $user = new class () {
            public function getGroups(): array
            {
                return ['dean'];
            }
        };

        $view = new View(config('View'), TESTPATH . '_support/Views/', service('locator'), false);
        $html = $view->setData([
            'user' => $user, 'overview' => $overview, 'profile' => ['first_name' => 'Dana', 'last_name' => 'Dean'], 'loadError' => $loadError,
        ])->render('pages/dean/dashboard', null, false);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    public function testTheDeanDashboardSplitsIntoAttentionCollegeAndUpdates(): void
    {
        $x = $this->dashboard($this->overview());

        $tabs = iterator_to_array($x->query('//*[@data-phone-tabs]//button[@role="tab"]'));
        $this->assertSame(['attention', 'college', 'updates'], array_map(static fn ($t) => $t->getAttribute('data-phone-tab'), $tabs));
        $this->assertSame(['Attention', 'College', 'Updates'], array_map(static fn ($t) => trim(explode('(', $t->textContent)[0]), $tabs));
        $this->assertSame('true', $tabs[0]->getAttribute('aria-selected'));
        // Nothing needs attention, so no tab carries a dot.
        $this->assertSame(0, $x->query('//*[@data-phone-tab-dot]')->length);

        foreach (self::WIDGETS as $slug => [$tab, $comment]) {
            $marker = '//comment()[normalize-space()="' . $comment . '"]';
            $this->assertSame(1, $x->query('//*[@data-phone-panel="' . $tab . '"]' . $marker)->length, "{$slug} sits in the {$tab} panel");
            $this->assertSame(1, $x->query('//main' . $marker)->length, "{$slug} is printed once");
        }

        // Only the first tab's panels start active (app.css hides the rest below lg).
        $this->assertGreaterThan(0, $x->query('//*[@data-phone-panel="attention"][@data-phone-active]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel][not(@data-phone-panel="attention")][@data-phone-active]')->length);
    }

    public function testSomethingNeedingAttentionPutsTheDotOnTheAttentionTabOnly(): void
    {
        $x = $this->dashboard($this->overview([
            ['key' => 'overrides', 'tone' => 'warning', 'count' => 1, 'title' => '1 grade override to decide', 'detail' => 'Waiting on you', 'url' => site_url('dean/approvals')],
        ]));

        $this->assertSame(1, $x->query('//button[@data-phone-tab="attention"]//*[@data-phone-tab-dot]')->length);
        $this->assertSame(0, $x->query('//button[@data-phone-tab!="attention"]//*[@data-phone-tab-dot]')->length);
    }

    public function testTheLoadErrorStateHasNoTabsSoTheErrorIsNeverHidden(): void
    {
        $x = $this->dashboard(null, true);

        $this->assertSame(1, $x->query('//*[@data-load-error]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-tabs]')->length, 'no tab bar');
        $this->assertSame(0, $x->query('//*[@data-phone-panel]')->length, 'nothing sits in a panel a tab could hide');
        $this->assertSame(1, $x->query('//main//comment()[normalize-space()="profile card (chairperson)"]')->length, 'the dean\'s card is still there');
        $this->assertStringContainsString('pointer-coarse:min-h-11', $x->query('//*[@data-load-error]//a')->item(0)->getAttribute('class'), 'Try again is 44px on touch');
    }
}
