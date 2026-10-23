<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's home page: the department in numbers, what needs attention (each item opening a screen that
 * works), what chairpersons changed, the dean's announcements, and its empty, error and no-assignment states.
 * The chairperson's own dashboard, which shares some of its cards, is left as it was.
 *
 * @internal
 */
final class DeanDashboardPageTest extends RouteTestCase
{
    private int $institution;
    private int $department;
    private int $subA;
    private int $offering;
    private User $dean;
    private User $chair;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('D');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'A');
        $this->dean        = DeanFactory::dean($this->institution, $this->department);
        $this->chair       = DeanFactory::chair($this->subA, 'Maria');
        $this->teacher     = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);

        $this->offering = DeanFactory::offering(DeanFactory::program($this->subA, 'A'), DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        // Quiet for a month, with a request waiting and nothing finalized.
        $module = CourseFactory::module($this->offering);
        db_connect()->table('course_modules')->where('id', $module)->update(['updated_at' => date('Y-m-d H:i:s', strtotime('-30 days'))]);

        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        CourseFactory::enroll($this->offering, $student);
        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $this->offering, 'student_id' => $student->id, 'requested_percentage' => 90, 'calculated_percentage' => 80, 'reason' => 'Excused',
            'requested_by' => $this->teacher->id, 'requester_role' => 'instructor', 'status' => 'awaiting_dean', 'open_marker' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Services::resetSingle('auditLogger');
        service('auditLogger')->log('course.chairperson_action', $this->chair, [
            'target_type' => 'course_offering', 'target_id' => $this->offering, 'acting_role' => 'chairperson',
            'new'         => ['method' => 'PATCH', 'path' => "/chairperson/api/courses/{$this->offering}/modules/1"],
        ]);
    }

    private function page(?User $as = null): string
    {
        $this->loggedInAs($as ?? $this->dean);
        $response = $this->call('get', 'dean/dashboard')->response();

        $this->assertSame(200, $response->getStatusCode());

        return html_entity_decode($response->getBody());
    }

    public function testEveryTileOfTheQuickAccessStripShowsItsIcon(): void
    {
        $body = $this->page();

        // Every tile of the strip, with the colour its icon was given.
        preg_match_all('#<span class="flex"><svg[^>]*class="([^"]*)"#', $body, $icons);

        $this->assertGreaterThanOrEqual(8, count($icons[1]), 'one icon for each tile of the strip');

        foreach ($icons[1] as $class) {
            $this->assertStringContainsString('text-[#0040A6]', $class, 'an icon registered in white would vanish on the white tile');
            $this->assertStringNotContainsString('text-white', $class);
        }
    }

    public function testTheDeanSeesTheirDepartmentAndTheirCard(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('Dean dashboard, College D', $body);
        $this->assertStringContainsString('Dana Dean', $body);
        $this->assertStringContainsString('DEAN', $body);
        $this->assertStringNotContainsString('CHAIRPERSON', $body);
        $this->assertStringContainsString('data-widget="snapshot"', $body);
        $this->assertStringContainsString('Courses running', $body);
        $this->assertStringContainsString('Sub-departments', $body);
    }

    public function testTheSnapshotCountsMatchTheDepartment(): void
    {
        $body = preg_replace('/\s+/', ' ', $this->page());

        $this->assertMatchesRegularExpression('/Sub-departments\s*<\/dt>\s*<dd[^>]*>\s*1\s*<\/dd>/', $body);
        $this->assertMatchesRegularExpression('/Courses running\s*<\/dt>\s*<dd[^>]*>\s*1\s*<\/dd>/', $body);
        $this->assertMatchesRegularExpression('/Instructors\s*<\/dt>\s*<dd[^>]*>\s*1\s*<\/dd>/', $body);
        $this->assertMatchesRegularExpression('/Students\s*<\/dt>\s*<dd[^>]*>\s*1\s*<\/dd>/', $body);
    }

    public function testWhatNeedsAttentionIsListedAndEveryItemOpensAScreenThatWorks(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('1 grade override to decide', $body);
        $this->assertStringContainsString('1 section holding up sign-off', $body);
        $this->assertStringContainsString('1 quiet course', $body);
        $this->assertStringNotContainsString("You're all caught up", $body);

        preg_match_all('/<a\s+class="[^"]*"\s+href="([^"]+)"\s+data-attention=/s', $body, $links);

        $this->assertNotEmpty($links[1], 'the attention items are links');

        foreach (array_unique($links[1]) as $url) {
            // The address relative to the app, whatever the base URL is (it differs between machines and CI).
            $prefix = rtrim(site_url(), '/') . '/';
            $path   = preg_replace('/#.*$/', '', str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : $url);

            $this->loggedInAs($this->dean);
            $this->assertSame(200, $this->call('get', $path)->response()->getStatusCode(), "the item linking to {$url} must open");
        }
    }

    public function testWhatTheChairpersonChangedAndTheAnnouncementsAreShown(): void
    {
        (new \App\Services\Courses\DepartmentAnnouncementService())->post($this->dean, ['scope' => 'department', 'audience' => 'everyone', 'title' => 'Enrolment week', 'message' => 'See the registrar.']);

        $body = $this->page();

        $this->assertStringContainsString('Chairperson activity', $body);
        $this->assertStringContainsString('Updated course content', $body);
        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('1 to review', $body);
        $this->assertStringContainsString('dean/courses/' . $this->offering . '/intro-to-computing', $body);
        $this->assertStringContainsString('Department announcements', $body);
        $this->assertStringContainsString('Enrolment week', $body);
        $this->assertStringContainsString('College D', $body);
    }

    public function testQuickAccessCarriesTheDeansTools(): void
    {
        $body = $this->page();

        foreach (['dean/courses', 'dean/approvals', 'dean/signoff', 'dean/monitor-department', 'dean/follow-ups', 'dean/announcements', 'dean/teaching-load', 'dean/chairperson-review'] as $tool) {
            $this->assertStringContainsString($tool, $body, $tool);
        }
    }

    public function testAQuietDepartmentSaysSoInEachCard(): void
    {
        $quiet = DeanFactory::department('Q', $this->institution);
        $body  = $this->page(DeanFactory::dean($this->institution, $quiet['department'], 'Quin'));

        $this->assertStringContainsString("You're all caught up", $body);
        $this->assertStringContainsString('data-activity-empty', $body);
        $this->assertStringContainsString('data-announcements-empty', $body);
        $this->assertStringContainsString('New announcement', $body);
    }

    public function testADeanWithoutADepartmentSeesTheExplanationNotTheDashboard(): void
    {
        $body = $this->page(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution));

        $this->assertStringContainsString('not assigned to a department yet', $body);
        $this->assertStringNotContainsString('data-widget="snapshot"', $body);
    }

    public function testWhenTheNumbersCannotBeWorkedOutThePageSaysSoAndOffersToTryAgain(): void
    {
        $this->loggedInAs($this->dean);

        $body = html_entity_decode(view('pages/dean/dashboard', [
            'title' => 'Dashboard', 'page' => 'dashboard', 'user' => $this->dean, 'overview' => null, 'profile' => ['first_name' => 'Dana', 'last_name' => 'Dean'], 'loadError' => true,
        ]));

        $this->assertStringContainsString('We could not load your department\'s numbers', $body);
        $this->assertStringContainsString('Try again', $body);
        $this->assertStringContainsString('data-load-error', $body);
        $this->assertStringContainsString('Dana Dean', $body, 'the dean\'s card and the quick access are still there');
        $this->assertStringNotContainsString('data-widget="needs-attention"', $body);
    }

    public function testAnotherDeansDepartmentIsNotShown(): void
    {
        $elsewhere = DeanFactory::department('E', $this->institution);
        DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'E'), 'E'), DeanFactory::term($this->institution, 'Other'), 'LAW900');

        $body = $this->page(DeanFactory::dean($this->institution, $elsewhere['department'], 'Eve'));

        $this->assertStringContainsString('College E', $body);
        $this->assertStringNotContainsString('IT101', $body);
        $this->assertStringNotContainsString('grade override', $body);
    }

    public function testNobodyButADeanReachesIt(): void
    {
        foreach ([$this->chair, $this->teacher, PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution)] as $user) {
            $this->loggedInAs($user);
            $this->assertNotSame(200, $this->call('get', 'dean/dashboard')->response()->getStatusCode());
        }
    }

    public function testTheChairpersonsOwnDashboardStillSaysChairperson(): void
    {
        $this->loggedInAs($this->chair);
        $body = html_entity_decode($this->call('get', 'chairperson/dashboard')->response()->getBody());

        $this->assertStringContainsString('CHAIRPERSON', $body);
        $this->assertDoesNotMatchRegularExpression('/>\s*DEAN\s*</', $body);
    }

    public function testAReadOnlyInstitutionStillShowsTheDashboard(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertStringContainsString('1 grade override to decide', $this->page());
    }
}
