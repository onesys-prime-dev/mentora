<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\RouteTestCase;

/**
 * Below lg the instructor dashboard is split into To grade / Sections / Updates phone tabs
 * (components/phone-tabs); from lg up the grid is unchanged. The chairperson's own tabs are in ChairpersonDashboardTabsTest.
 *
 * @internal
 */
final class InstructorDashboardTabsTest extends RouteTestCase
{
    /** Each widget, found by what it always prints (card headings are the text-xl spans), and its tab. */
    private const HEADING = '//span[contains(concat(" ",@class," ")," text-xl ")][normalize-space()="%s"]';

    private const WIDGETS = [
        'grading-queue'    => ['grade', 'Grading Queue'],
        'at-risk-students' => ['grade', 'Needs Your Attention'],
        'profile-card'     => ['sections', '//*[@data-avatar]'],
        'teaching-load'    => ['sections', 'Teaching Load'],
        'class-progress'   => ['sections', 'Class Progress'],
        'content-status'   => ['sections', 'Unpublished'],
        'quick-access'     => ['sections', 'Quick Access'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        GradeFactory::systemDefault();
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    private function dashboard(User $as, string $path): DOMXPath
    {
        $this->loggedInAs($as);

        $result = $this->get($path);
        $result->assertStatus(200);

        return $this->xpath($result->response()->getBody());
    }

    private function assertTabs(DOMXPath $x, bool $gradeDot): void
    {
        $tabs = $x->query('//*[@data-phone-tabs]//button[@role="tab"]');
        $this->assertSame(3, $tabs->length);
        $this->assertSame(['grade', 'sections', 'updates'], array_map(static fn ($t) => $t->getAttribute('data-phone-tab'), iterator_to_array($tabs)));
        $this->assertSame(['To grade', 'Sections', 'Updates'], array_map(static fn ($t) => trim(explode('(', $t->textContent)[0]), iterator_to_array($tabs)));
        $this->assertSame('true', $tabs->item(0)->getAttribute('aria-selected'));

        $this->assertSame($gradeDot ? 1 : 0, $x->query('//button[@data-phone-tab="grade"]//*[@data-phone-tab-dot]')->length);
        $this->assertSame(0, $x->query('//button[@data-phone-tab!="grade"]//*[@data-phone-tab-dot]')->length);
    }

    private function assertEveryWidgetInItsPanel(DOMXPath $x): void
    {
        foreach (self::WIDGETS as $slug => [$tab, $marker]) {
            $marker = str_starts_with($marker, '/') ? $marker : sprintf(self::HEADING, $marker);
            $this->assertSame(1, $x->query('//*[@data-phone-panel="' . $tab . '"]' . $marker)->length, "{$slug} sits in the {$tab} panel");
            $this->assertSame(1, $x->query('//main' . $marker)->length, "{$slug} is printed once");
        }

        // Only the first tab's panels start active (app.css hides the rest below lg).
        $this->assertSame(2, $x->query('//*[@data-phone-panel="grade"][@data-phone-active]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel][not(@data-phone-panel="grade")][@data-phone-active]')->length);
    }

    public function testTheInstructorDashboardSplitsIntoToGradeSectionsAndUpdates(): void
    {
        $teacher  = CourseFactory::user('instructor');
        $offering = CourseFactory::offering();
        CourseFactory::assignInstructor($offering, $teacher);
        CourseFactory::enroll($offering, CourseFactory::user('student'));

        $x = $this->dashboard($teacher, 'instructor/dashboard');

        $this->assertTabs($x, gradeDot: false);
        $this->assertEveryWidgetInItsPanel($x);
    }

    public function testAnInstructorWithNothingToGradeGetsNoDotAndEachEmptyState(): void
    {
        $x = $this->dashboard(CourseFactory::user('instructor'), 'instructor/dashboard');

        $this->assertTabs($x, gradeDot: false);
        $this->assertEveryWidgetInItsPanel($x);

        $copy = [
            'grade'    => ['Nothing to grade', "Everyone's on track"],
            'sections' => ['Nothing to measure yet', "Everything's published"],
            'updates'  => ['No announcements'],
        ];

        foreach ($copy as $tab => $lines) {
            foreach ($lines as $line) {
                $this->assertSame(1, $x->query('//*[@data-phone-panel="' . $tab . '"]//span[normalize-space()="' . $line . '"]')->length, "\"{$line}\" in {$tab}");
            }
        }

        // With no dean posts the Updates tab holds a phone-only empty card, not an empty panel.
        $this->assertSame(0, $x->query('//*[@data-college-strip]')->length);
        $this->assertSame(1, $x->query('//*[@data-phone-panel="updates"][contains(concat(" ",@class," ")," lg:hidden ")]')->length);
    }

    public function testTheChairpersonDashboardHasItsOwnTabsNotTheInstructors(): void
    {
        $home  = DeanFactory::college('A');
        $chair = DeanFactory::chair(DeanFactory::department($home['college'], 'A'), 'Maria');

        $x = $this->dashboard($chair, 'chairperson/dashboard');

        // Its Attention / Program / Updates tabs are in ChairpersonDashboardTabsTest.
        $this->assertSame(0, $x->query('//*[@data-phone-tab="grade"]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel="sections"]')->length);
    }

    public function testTheGradingQueueAndAtRiskListsCapAtFiveBelowLgWithViewAll(): void
    {
        $teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor(CourseFactory::offering(), $teacher);
        $this->loggedInAs($teacher);

        $queue = array_map(static fn ($i) => [
            'offering_id' => 1, 'slug' => 'c', 'assessment_id' => $i, 'course_code' => 'IT1', 'section_code' => 'A', 'title' => "Q{$i}",
            'type' => 'quiz', 'ungraded' => 2, 'submitted' => 4, 'waiting_days' => 1,
        ], range(1, 6));
        $students = array_map(static fn ($i) => [
            'offering_id' => 1, 'slug' => 'c', 'student_id' => $i, 'name' => "S{$i}", 'reason' => 'behind',
            'section' => 'A', 'last_active' => 'today', 'progress' => 10,
        ], range(1, 6));

        $cases = [
            ['grading-queue', ['queue' => $queue], 'data-queue-row', 'to-grade'],
            ['at-risk-students', ['students' => $students], 'data-at-risk-row', 'at-risk'],
        ];

        foreach ($cases as [$partial, $data, $row, $tab]) {
            $x = $this->xpath(view('pages/dashboard/partials/' . $partial, ['user' => $teacher, 'data' => $data], ['saveData' => false]));

            $hidden = array_map(static fn ($el) => str_contains(' ' . $el->getAttribute('class') . ' ', ' max-lg:hidden '), iterator_to_array($x->query("//*[@{$row}]")));
            $this->assertSame([false, false, false, false, false, true], $hidden, $partial);

            $all = $x->query('//a[@data-view-all]');
            $this->assertSame(1, $all->length, $partial);
            $this->assertSame(site_url('instructor/grades?tab=' . $tab), $all->item(0)->getAttribute('href'));
            $this->assertStringContainsString('lg:hidden', $all->item(0)->getAttribute('class'));
        }
    }
}
