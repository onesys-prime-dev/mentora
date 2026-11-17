<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\RouteTestCase;

/**
 * Below lg the chairperson dashboard is split into Attention / Program / Updates phone tabs
 * (components/phone-tabs); from lg up the strip, rail and main column are unchanged.
 *
 * @internal
 */
final class ChairpersonDashboardTabsTest extends RouteTestCase
{
    /** Each widget, found by the comment its partial opens with, and its tab. */
    private const WIDGETS = [
        'needs-attention'     => ['attention', 'needs attention (chairperson)'],
        'quick-access'        => ['program', 'quick access (chairperson)'],
        'profile-card'        => ['program', 'profile card (chairperson)'],
        'program-snapshot'    => ['program', 'program snapshot (chairperson)'],
        'instructor-workload' => ['program', 'instructor workload (chairperson)'],
        'announcements'       => ['updates', 'announcements (chairperson)'],
        'audit-highlights'    => ['updates', 'audit highlights (chairperson)'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        GradeFactory::systemDefault();
    }

    private function dashboard(User $as, string $path, ?string $role = null): DOMXPath
    {
        $this->loggedInAs($as);
        if ($role !== null) {
            $this->withSession(['active_role' => $role]);
        }

        $result = $this->get($path);
        $result->assertStatus(200);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $result->response()->getBody());

        return new DOMXPath($doc);
    }

    private function chair(): User
    {
        $home = DeanFactory::college('A');

        return DeanFactory::chair(DeanFactory::department($home['college'], 'A'), 'Maria');
    }

    /** @param list<string> $keys */
    private function assertTabs(DOMXPath $x, array $keys, array $labels): void
    {
        $tabs = $x->query('//*[@data-phone-tabs]//button[@role="tab"]');
        $this->assertSame($keys, array_map(static fn ($t) => $t->getAttribute('data-phone-tab'), iterator_to_array($tabs)));
        $this->assertSame($labels, array_map(static fn ($t) => trim(explode('(', $t->textContent)[0]), iterator_to_array($tabs)));
        $this->assertSame('true', $tabs->item(0)->getAttribute('aria-selected'));
    }

    public function testTheChairpersonDashboardSplitsIntoAttentionProgramAndUpdates(): void
    {
        $x = $this->dashboard($this->chair(), 'chairperson/dashboard');

        $this->assertTabs($x, ['attention', 'program', 'updates'], ['Attention', 'Program', 'Updates']);
        // Nothing needs attention in an empty program, so no tab carries a dot.
        $this->assertSame(0, $x->query('//*[@data-phone-tab-dot]')->length);

        foreach (self::WIDGETS as $slug => [$tab, $comment]) {
            $marker = '//comment()[normalize-space()="' . $comment . '"]';
            $this->assertSame(1, $x->query('//*[@data-phone-panel="' . $tab . '"]' . $marker)->length, "{$slug} sits in the {$tab} panel");
            $this->assertSame(1, $x->query('//main' . $marker)->length, "{$slug} is printed once");
        }

        // Only the first tab's panel starts active (app.css hides the rest below lg).
        $this->assertSame(1, $x->query('//*[@data-phone-panel="attention"][@data-phone-active]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel][not(@data-phone-panel="attention")][@data-phone-active]')->length);

        // No dean posts: the strip prints nothing, and Updates still has its two cards (no extra empty card).
        $this->assertSame(0, $x->query('//*[@data-college-strip]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel="updates"][contains(concat(" ",@class," ")," lg:hidden ")]')->length);
    }

    public function testSomethingNeedingAttentionPutsTheDotOnTheAttentionTabOnly(): void
    {
        // A section in the chair's department with no activity at all is a quiet course: one Needs Attention item.
        $offering   = CourseFactory::offering();
        $department = (int) db_connect()->table('course_offerings co')->select('programs.department_id')
            ->join('courses', 'courses.id = co.course_id')->join('programs', 'programs.id = courses.program_id')
            ->where('co.id', $offering)->get()->getRow('department_id');

        $x = $this->dashboard(DeanFactory::chair($department), 'chairperson/dashboard');

        $this->assertSame(1, $x->query('//button[@data-phone-tab="attention"]//*[@data-phone-tab-dot]')->length);
        $this->assertSame(0, $x->query('//button[@data-phone-tab!="attention"]//*[@data-phone-tab-dot]')->length);
    }

    public function testTheTabsFollowTheActiveRoleOfAChairpersonWhoTeaches(): void
    {
        $chair = $this->chair();
        $chair->addGroup('instructor');

        $this->assertTabs($this->dashboard($chair, 'chairperson/dashboard', 'chairperson'), ['attention', 'program', 'updates'], ['Attention', 'Program', 'Updates']);
        $this->assertTabs($this->dashboard($chair, 'instructor/dashboard', 'instructor'), ['grade', 'sections', 'updates'], ['To grade', 'Sections', 'Updates']);
    }
}
