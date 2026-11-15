<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\RouteTestCase;

/**
 * Below lg the student dashboard is split into Today / Courses / Updates phone tabs
 * (components/phone-tabs); from lg up the grid is unchanged. The instructor tabs are in InstructorDashboardTabsTest.
 *
 * @internal
 */
final class StudentDashboardTabsTest extends RouteTestCase
{
    /** Each student widget, found by what it always prints (card headings are the text-xl spans), and its tab. */
    private const HEADING = '//main//span[contains(concat(" ",@class," ")," text-xl ")][normalize-space()="%s"]';

    private const WIDGETS = [
        'key-metrics'    => ['today', '//main//span[normalize-space()="Courses Enrolled"]'],
        'upcoming-tasks' => ['today', 'Pending Tasks'],
        'reminders'      => ['today', 'Reminders'],
        'profile-card'   => ['courses', '//main//img[starts-with(@alt,"Profile photo")]'],
        'my-courses'     => ['courses', 'My Courses'],
        'quick-access'   => ['courses', 'Quick Access'],
        'announcements'  => ['updates', 'Announcements'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A real install always has the system-default grading scheme; reading grades copies it per course.
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

    private function overdueQuiz(int $offering, User $by): void
    {
        $id = CourseFactory::assessment($offering, $by, CourseFactory::module($offering));

        db_connect()->table('assessments')->where('id', $id)->update([
            'title' => 'Missed', 'status' => 'Published',
            'available_from' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'due_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
        ]);
    }

    /** The tab keys in order, and which carry a dot. */
    private function assertTabs(DOMXPath $x, bool $todayDot): void
    {
        $tabs = $x->query('//*[@data-phone-tabs]//button[@role="tab"]');
        $this->assertSame(3, $tabs->length);
        $this->assertSame(['today', 'courses', 'updates'], array_map(static fn ($t) => $t->getAttribute('data-phone-tab'), iterator_to_array($tabs)));
        $this->assertSame(['Today', 'Courses', 'Updates'], array_map(static fn ($t) => trim(explode('(', $t->textContent)[0]), iterator_to_array($tabs)));
        $this->assertSame('true', $tabs->item(0)->getAttribute('aria-selected'));

        $this->assertSame($todayDot ? 1 : 0, $x->query('//button[@data-phone-tab="today"]//*[@data-phone-tab-dot]')->length);
        $this->assertSame(0, $x->query('//button[@data-phone-tab!="today"]//*[@data-phone-tab-dot]')->length, 'no widget says what is unseen, so Updates has no dot');
    }

    private function assertEveryWidgetInItsPanel(DOMXPath $x): void
    {
        foreach (self::WIDGETS as $slug => [$tab, $marker]) {
            $marker = str_starts_with($marker, '/') ? $marker : sprintf(self::HEADING, $marker);
            $this->assertSame(1, $x->query('//*[@data-phone-panel="' . $tab . '"]' . substr($marker, strlen('//main')))->length, "{$slug} sits in the {$tab} panel");
            $this->assertSame(1, $x->query($marker)->length, "{$slug} is printed once");
        }

        // Only the first tab's panels start active (app.css hides the rest below lg).
        $this->assertSame(3, $x->query('//*[@data-phone-panel="today"][@data-phone-active]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel][not(@data-phone-panel="today")][@data-phone-active]')->length);
    }

    public function testTheStudentDashboardSplitsIntoTodayCoursesAndUpdates(): void
    {
        $student = CourseFactory::user('student');
        $teacher = CourseFactory::user('instructor');
        $offering = CourseFactory::offering();
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $teacher);
        $this->overdueQuiz($offering, $teacher);

        $x = $this->dashboard($student, 'student/dashboard');

        $this->assertTabs($x, todayDot: true);
        $this->assertEveryWidgetInItsPanel($x);
        $this->assertSame(1, $x->query('//*[@data-phone-panel="today"]//*[@data-task-row][contains(.,"Missed")]')->length);
    }

    public function testAStudentWithNoTasksCoursesOrTermStillGetsTheTabsAndEachEmptyState(): void
    {
        $x = $this->dashboard(CourseFactory::user('student'), 'student/dashboard');

        $this->assertTabs($x, todayDot: false);
        $this->assertEveryWidgetInItsPanel($x);

        $copy = [
            'today'   => ['Nothing pending right now', "You're all caught up", 'All caught up'],
            'courses' => ['No courses yet'],
            'updates' => ['No announcements'],
        ];

        foreach ($copy as $tab => $lines) {
            foreach ($lines as $line) {
                $this->assertSame(1, $x->query('//*[@data-phone-panel="' . $tab . '"]//span[normalize-space()="' . $line . '"]')->length, "\"{$line}\" in {$tab}");
            }
        }

        // No dean posts: the college strip prints nothing, and leaves no empty Updates panel behind.
        $this->assertSame(0, $x->query('//*[@data-college-strip]')->length);
        $this->assertSame(1, $x->query('//*[@data-phone-panel="updates"]')->length);
    }

    public function testTheChairpersonDashboardHasNoPhoneTabsAndKeepsTheCollegeStripUnwrapped(): void
    {
        // Chairperson has no entry in the dashboard layouts; it must not inherit the student's tabs (Phase 6 owns it).
        $home  = DeanFactory::college('A');
        $dean  = DeanFactory::dean($home['institution'], $home['college']);
        $chair = DeanFactory::chair(DeanFactory::department($home['college'], 'A'), 'Maria');

        $this->loggedInAs($dean);
        $this->withHeaders(['Accept' => 'application/json'])
            ->call('post', 'dean/announcements', ['scope' => 'college', 'audience' => 'everyone', 'title' => 'Enrolment week', 'message' => 'Please see the registrar.'])
            ->assertStatus(200);

        $x = $this->dashboard($chair, 'chairperson/dashboard');

        $this->assertSame(1, $x->query('//*[@data-college-strip]')->length, 'the dean post reaches the chairperson');
        $this->assertSame(0, $x->query('//*[@data-phone-tabs]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel]')->length);
    }

    public function testAnnouncementsCapAtFiveBelowLgWithViewAllToTheAnnouncementsPage(): void
    {
        $student = CourseFactory::user('student');
        $this->loggedInAs($student);

        $rows = array_map(static fn ($i) => ['id' => $i, 'offering_id' => 1, 'slug' => 'c', 'code' => 'IT1 - A', 'title' => "A{$i}", 'author' => 'X', 'posted' => 'now'], range(1, 6));
        $x = $this->xpath(view('pages/dashboard/partials/announcements', ['user' => $student, 'data' => ['announcements' => $rows]], ['saveData' => false]));

        $hidden = array_map(static fn ($el) => str_contains(' ' . $el->getAttribute('class') . ' ', ' max-lg:hidden '), iterator_to_array($x->query('//*[@data-announcement-row]')));
        $this->assertSame([false, false, false, false, false, true], $hidden);

        $all = $x->query('//a[@data-view-all]');
        $this->assertSame(1, $all->length);
        $this->assertSame(site_url('student/announcements'), $all->item(0)->getAttribute('href'));
        $this->assertStringContainsString('lg:hidden', $all->item(0)->getAttribute('class'));
    }
}
