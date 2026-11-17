<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The chairperson's Program table and the dean's College table on a phone (responsive Phase 6, Task 4): the table
 * sits in the shared pinned scroller (shared/scroll-x.js) with the Section column pinned, the section's action bar is
 * an expandable action bar below lg with Nudge and Download in its panel, and the nudge dialog is a full-screen form
 * on a phone with named, 16px fields and a pinned Send. No fixtures: the partial is rendered with made-up rows.
 *
 * @internal
 */
final class ProgramGradesResponsiveTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['url', 'icon']);
    }

    private function row(int $id, string $title = 'Introduction to Computing'): array
    {
        return [
            'offering_id' => $id, 'course_code' => 'IT10' . $id, 'section_code' => 'A', 'slug' => 'course-it10' . $id,
            'course_title' => $title, 'state' => 'waiting', 'note' => '', 'has_scheme' => true, 'offering_status' => 'active',
            'recipients' => [['id' => 7, 'name' => 'Ana Reyes']], 'instructors' => [['id' => 7, 'name' => 'Ana Reyes']],
            'waiting' => 3, 'oldest_days' => 2, 'average' => 81.5, 'at_risk' => 1, 'signed_off' => false,
        ];
    }

    private function program(string $area): DOMXPath
    {
        $rows = [$this->row(1, str_repeat('A very long course title ', 6)), $this->row(2)];
        $tally = ['total' => 2, 'finalized' => 0, 'ready' => 0, 'blocked' => 0, 'signed_off' => 0, 'requests_waiting' => 0];

        $html = view('pages/grades/partials/chairperson/program', [
            'area'       => $area,
            'base'       => site_url($area . '/grades'),
            'termId'     => 1,
            'program'    => ['rows' => $rows, 'total' => 2, 'tracker' => $tally, 'strip' => $tally],
            'state'      => ['q' => '', 'nf' => false, 'sort' => null, 'dir' => null, 'page' => 1],
            'termEnd'    => '2026-12-15',
            'selected'   => $rows[1],
            'canManage'  => $area === 'chairperson',
            'canNudge'   => true,
            'link'       => static fn (array $params = []): string => site_url($area . '/grades') . '?' . http_build_query($params),
            'listQuery'  => ['term' => 1],
            'canOpen'    => true,
            'stripLinks' => [],
            'programId'  => null,
        ], ['saveData' => false]);

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

    public function testTheTableScrollsInThePinnedScrollerWithTheSectionColumnPinned(): void
    {
        foreach (['chairperson' => 'min-w-[46rem]', 'dean' => 'min-w-[52rem]'] as $area => $width) {
            $x = $this->program($area);

            $scroller = $x->query('//*[@data-scroll-x][@data-sticky-first][.//table[@data-program-table]]');
            $this->assertSame(1, $scroller->length, $area);
            $this->assertSame('0', $scroller->item(0)->getAttribute('tabindex'), $area);
            $this->assertSame('region', $scroller->item(0)->getAttribute('role'), $area);
            $this->assertNotSame('', $scroller->item(0)->getAttribute('aria-label'), $area);

            // The shared scroller is the overflow: no hand-written wrapper of its own.
            $this->assertSame(0, $x->query('//*[contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]')->length, $area);

            $this->assertContains($width, $this->classes($x->query('//table[@data-program-table]')->item(0)), $area);

            // A long course title cannot widen the pinned column: it truncates inside its cap.
            $title = $this->classes($x->query('//*[@data-program-row]/span[@title]')->item(0));
            foreach (['max-w-[13rem]', 'min-w-0', 'truncate'] as $class) {
                $this->assertContains($class, $title, "{$area}: {$class}");
            }

            // The selected row keeps its inset bar on the pinned cell.
            $this->assertSame(1, $x->query('//tbody//th[contains(@class, "shadow-[inset_3px_0_0_#0040A6]")]')->length, $area);
        }
    }

    public function testTheSectionsActionBarHoldsNudgeAndDownloadInItsPanelBelowLg(): void
    {
        foreach (['chairperson' => 'data-download-program', 'dean' => 'data-download-college'] as $area => $download) {
            $x = $this->program($area);

            $bar = $x->query('//*[@data-program-actions][@data-action-bar]');
            $this->assertSame(1, $bar->length, $area);

            $toggle = $x->query('//*[@data-program-actions]//button[@data-action-bar-toggle]')->item(0);
            $this->assertNotNull($toggle, $area);
            $this->assertContains('lg:hidden', $this->classes($toggle), $area);
            $this->assertSame('false', $toggle->getAttribute('aria-expanded'), $area);

            $panel = $x->query('//*[@data-program-actions]/*[@data-action-bar-panel][@id="' . $toggle->getAttribute('aria-controls') . '"]');
            $this->assertSame(1, $panel->length, $area);
            $this->assertContains('lg:hidden', $this->classes($panel->item(0)), $area);

            // Nudge and Download are drawn once, beside the pager; the page script moves them into the panel below lg.
            $this->assertSame(1, $x->query('//*[@data-program-tools-slot]')->length, $area);
            $this->assertSame(1, $x->query('//*[@data-program-tools-home]/*[@data-program-tools][.//button[@data-nudge]][.//a[@' . $download . ']]')->length, $area);
            $this->assertSame(1, $x->query('//*[@data-nudge]')->length, $area);
            $this->assertSame(1, $x->query('//*[@' . $download . ']')->length, $area);
        }
    }

    public function testTheNudgeDialogIsAFullScreenFormWithNamedFieldsAndAPinnedSend(): void
    {
        $x = $this->program('chairperson');

        $dialog = $x->query('//*[@data-nudge-dialog]')->item(0);
        $this->assertSame('fullscreen', $dialog->getAttribute('data-dialog-variant'));
        $this->assertSame(1, $x->query('//*[@data-nudge-dialog]/*[@data-dialog-panel]/*[@data-dialog-body][@data-dialog-pin-actions]/form[@data-nudge-form]')->length);

        foreach (['recipient_id' => 'select', 'title' => 'input', 'message' => 'textarea'] as $name => $tag) {
            $field = $x->query("//form[@data-nudge-form]//{$tag}[@name='{$name}']")->item(0);
            $this->assertNotNull($field, $name);
            $this->assertSame(1, $x->query('//label[@for="' . $field->getAttribute('id') . '"][normalize-space()]')->length, "{$name} has a label");
            $this->assertContains('text-base', $this->classes($field), $name);
            $this->assertContains('sm:text-sm', $this->classes($field), $name);
        }

        // The row with Send is the one app.css pins; Send is 44px on touch, disabled or not.
        $send = $x->query('//form[@data-nudge-form]/*/button[@type="submit"][@data-nudge-send]')->item(0);
        $this->assertNotNull($send);
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($send));
    }
}
