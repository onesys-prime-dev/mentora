<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use App\Services\Institutions\InstitutionAnnouncement;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's Announcements page and the Announcements app everyone else reads: posting, editing and ending
 * from the dean's dialogs, who sees what (and who does not), the four levels in their fixed order, the
 * strip on recipients' dashboards, and that another role, college or read-only institution gets nothing.
 *
 * @internal
 */
final class CollegeAnnouncementsPageTest extends RouteTestCase
{
    private int $institution;
    private int $college;
    private int $departmentA;
    private int $departmentB;
    private int $offering;
    private User $dean;
    private User $chair;
    private User $teacher;
    private User $student;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        InstitutionAnnouncement::forget();
        (new PlatformSettings())->forget('announcement');   // the settings store is not rolled back between tests

        $home              = DeanFactory::college('A');
        $this->institution = $home['institution'];
        $this->college  = $home['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'A');
        $this->departmentB        = DeanFactory::department($this->college, 'B');
        $this->dean        = DeanFactory::dean($this->institution, $this->college);
        $this->chair       = DeanFactory::chair($this->departmentA, 'Maria');

        $this->offering = DeanFactory::offering(DeanFactory::program($this->departmentA, 'A'), DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');
        $this->teacher  = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->student  = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $elsewhere      = DeanFactory::college('E', $this->institution);
        $this->outsider = PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution);
        CourseFactory::enroll(DeanFactory::offering(DeanFactory::program(DeanFactory::department($elsewhere['college'], 'E'), 'E'), DeanFactory::term($this->institution, 'Other'), 'LAW900'), $this->outsider);
    }

    protected function tearDown(): void
    {
        InstitutionAnnouncement::forget();
        (new PlatformSettings())->forget('announcement');

        parent::tearDown();
    }

    private function page(string $uri, User $as): string
    {
        $this->loggedInAs($as);
        $response = $this->call('get', $uri)->response();

        $this->assertSame(200, $response->getStatusCode(), $uri);

        return html_entity_decode($response->getBody());
    }

    /** @param array<string, mixed> $data */
    private function send(string $uri, array $data = [], ?User $as = null): \CodeIgniter\Test\TestResponse
    {
        $this->loggedInAs($as ?? $this->dean);

        return $this->withHeaders(['Accept' => 'application/json'])->call('post', $uri, $data);
    }

    private function announce(array $over = []): int
    {
        $response = $this->send('dean/announcements', $over + ['scope' => 'college', 'audience' => 'everyone', 'title' => 'Enrolment week', 'message' => 'Please see the registrar.'])->response();

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        return (int) db_connect()->table('college_announcements')->selectMax('id')->get()->getRow('id');
    }

    public function testTheDeanPostsFromTheFormAndSeesItListedWithWhoItReaches(): void
    {
        $response = $this->send('dean/announcements', ['scope' => 'department:' . $this->departmentA, 'audience' => 'students', 'title' => 'Enrolment week', 'message' => 'Please see the registrar.'])->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Posted to 1 person', $response->getBody());

        $body = $this->page('dean/announcements', $this->dean);

        $this->assertStringContainsString('Enrolment week', $body);
        $this->assertStringContainsString('Department A', $body);
        $this->assertStringContainsString('Students', $body);
        $this->assertStringContainsString('1 person', $body);
        $this->assertStringContainsString('No end date', $body);
        $this->assertStringContainsString('New announcement', $body);
        $this->assertStringContainsString('Whole College A', $body, 'the form offers the whole college');
    }

    public function testABadPostIsRefusedWithTheMessageBesideTheField(): void
    {
        $response = $this->send('dean/announcements', ['scope' => 'college', 'audience' => 'everyone', 'title' => '', 'message' => 'Hello'])->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('title', json_decode($response->getBody(), true)['errors']);
        $this->assertSame(0, db_connect()->table('college_announcements')->countAllResults());
    }

    public function testEditingAndEndingWorkFromTheirDialogs(): void
    {
        $id = $this->announce();

        $this->assertSame(200, $this->send("dean/announcements/{$id}", ['title' => 'Moved', 'message' => 'To Friday.'])->response()->getStatusCode());
        $this->assertStringContainsString('Moved', $this->page('dean/announcements', $this->dean));

        $this->assertSame(422, $this->send("dean/announcements/{$id}", ['title' => '', 'message' => 'x'])->response()->getStatusCode());
        $this->assertSame(200, $this->send("dean/announcements/{$id}/end")->response()->getStatusCode());
        $this->assertStringContainsString('No announcements showing', $this->page('dean/announcements', $this->dean));
        $this->assertStringContainsString('Moved', $this->page('dean/announcements?tab=ended', $this->dean));
        $this->assertSame(422, $this->send("dean/announcements/{$id}/end")->response()->getStatusCode(), 'not twice');
    }

    public function testAnotherCollegesDeanCannotEditOrEndOrSeeIt(): void
    {
        $id    = $this->announce();
        $other = DeanFactory::dean($this->institution, (int) db_connect()->table('colleges')->where('id !=', $this->college)->get()->getRow('id'), 'Eve');

        $this->assertSame(422, $this->send("dean/announcements/{$id}", ['title' => 'Hijack', 'message' => 'x'], $other)->response()->getStatusCode());
        $this->assertSame(422, $this->send("dean/announcements/{$id}/end", [], $other)->response()->getStatusCode());
        $this->assertStringNotContainsString('Enrolment week', $this->page('dean/announcements', $other));
    }

    public function testNobodyButADeanReachesTheDeansPage(): void
    {
        $id = $this->announce();

        foreach ([$this->chair, $this->teacher, $this->student] as $user) {
            $this->loggedInAs($user);
            $this->assertNotSame(200, $this->call('get', 'dean/announcements')->response()->getStatusCode());
            $this->assertNotSame(200, $this->send('dean/announcements', ['scope' => 'college', 'audience' => 'everyone', 'title' => 'Mine', 'message' => 'x'], $user)->response()->getStatusCode());
            $this->assertNotSame(200, $this->send("dean/announcements/{$id}/end", [], $user)->response()->getStatusCode());
        }

        $this->assertSame(1, db_connect()->table('college_announcements')->countAllResults());
    }

    public function testADeanWithoutACollegeSeesTheExplanation(): void
    {
        $this->assertStringContainsString('not assigned to a college yet', $this->page('dean/announcements', PeopleFactory::person('dean', 'No', 'College', null, $this->institution)));
    }

    public function testAReadOnlyInstitutionLetsTheDeanLookButNotPostEditOrEnd(): void
    {
        $id = $this->announce();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertStringContainsString('Enrolment week', $this->page('dean/announcements', $this->dean));
        $this->assertSame(403, $this->send('dean/announcements', ['scope' => 'college', 'audience' => 'everyone', 'title' => 'T', 'message' => 'M'])->response()->getStatusCode());
        $this->assertSame(403, $this->send("dean/announcements/{$id}", ['title' => 'T', 'message' => 'M'])->response()->getStatusCode());
        $this->assertSame(403, $this->send("dean/announcements/{$id}/end")->response()->getStatusCode());
        $this->assertSame('active', db_connect()->table('college_announcements')->where('id', $id)->get()->getRow('status'));
    }

    public function testEachRecipientReadsItInTheirOwnAnnouncementsAndOthersDoNot(): void
    {
        $this->announce(['scope' => 'department:' . $this->departmentA]);

        foreach ([[$this->student, 'student'], [$this->teacher, 'instructor'], [$this->chair, 'chairperson']] as [$user, $area]) {
            $body = $this->page("{$area}/announcements?level=college", $user);

            $this->assertStringContainsString('Enrolment week', $body, $area);
            $this->assertStringContainsString('Please see the registrar.', $body, $area);
            $this->assertStringContainsString('Department A', $body, $area);
        }

        $this->assertStringNotContainsString('Enrolment week', $this->page('student/announcements?level=college', $this->outsider), 'another college');
        $this->assertStringContainsString('Nothing from your college right now', $this->page('student/announcements?level=college', $this->outsider));
    }

    public function testTheRoleFilterKeepsStaffPostsFromStudentsAndTheOtherWayRound(): void
    {
        $this->announce(['audience' => 'staff', 'title' => 'Staff meeting']);
        $this->announce(['audience' => 'students', 'title' => 'Student fair']);

        $student = $this->page('student/announcements', $this->student);
        $teacher = $this->page('instructor/announcements', $this->teacher);

        $this->assertStringContainsString('Student fair', $student);
        $this->assertStringNotContainsString('Staff meeting', $student);
        $this->assertStringContainsString('Staff meeting', $teacher);
        $this->assertStringNotContainsString('Student fair', $teacher);
    }

    public function testAnEndedAnnouncementDisappearsForEveryone(): void
    {
        $id = $this->announce();
        $this->send("dean/announcements/{$id}/end");

        $this->assertStringNotContainsString('Enrolment week', $this->page('student/announcements', $this->student));
        $this->assertStringNotContainsString('data-college-strip', $this->page('student/dashboard', $this->student));
    }

    public function testTheFourLevelsAreAlwaysInOrderPlatformInstitutionCollegeCourses(): void
    {
        (new PlatformSettings())->set('announcement', 'Platform maintenance tonight');
        (new InstitutionAnnouncement())->save($this->institution, 'Institution closed on Friday', '');
        $this->announce();

        db_connect()->table('announcements')->insert([
            'offering_id' => $this->offering, 'author_id' => $this->teacher->id, 'subject' => 'Quiz moved', 'message' => '{"version":1,"blocks":[]}', 'is_pinned' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $default = $this->page('student/announcements', $this->student);
        $this->assertStringContainsString('data-level="platform"', $default, 'it opens on the first level that has something');

        $filter = [];
        preg_match_all('#data-level-count="(\w+)">(\d+)<#', $default, $found, PREG_SET_ORDER);

        foreach ($found as [, $key, $count]) {
            $filter[$key] = (int) $count;
        }

        $this->assertSame(['platform' => 1, 'institution' => 1, 'college' => 1, 'course' => 1], $filter, 'each filter counts its level');
        $this->assertStringNotContainsString('data-level-count="all"', $default);

        foreach (['institution' => 'Institution closed on Friday', 'college' => 'Enrolment week', 'course' => 'Quiz moved'] as $level => $text) {
            $this->assertStringContainsString($text, $this->page('student/announcements?level=' . $level, $this->student), $level);
        }

        $this->assertStringContainsString('student/courses/' . $this->offering . '/intro-to-computing/announcements', $this->page('student/announcements?level=course', $this->student));
    }

    public function testALevelWithNothingToSayStillHasItsPlaceInTheSameOrder(): void
    {
        foreach (['platform' => 'Nothing from the platform right now', 'institution' => 'Nothing from your institution right now', 'college' => 'Nothing from your college right now', 'course' => 'No posts in your courses yet'] as $level => $text) {
            $this->assertStringContainsString($text, $this->page('student/announcements?level=' . $level, $this->student), $level);
        }
    }

    public function testTheLevelFilterNarrowsTheListToOneLevel(): void
    {
        $this->assertStringContainsString('data-level-filter', $this->page('student/announcements', $this->student));

        foreach (['platform', 'institution', 'college', 'course'] as $level) {
            $body = $this->page('student/announcements?level=' . $level, $this->student);

            $this->assertSame(1, substr_count($body, 'data-level="'), $level . ' alone');
            $this->assertStringContainsString('data-level="' . $level . '"', $body);
        }

        $this->assertSame(1, substr_count($this->page('student/announcements?level=nonsense', $this->student), 'data-level="'), 'an unknown level falls back to one');
    }

    public function testSearchKeepsOnlyWhatMatchesAndKeepsTheFilter(): void
    {
        $this->announce();
        db_connect()->table('announcements')->insert([
            'offering_id' => $this->offering, 'author_id' => $this->teacher->id, 'subject' => 'Quiz moved', 'message' => '{"version":1,"blocks":[]}', 'is_pinned' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $hit = $this->page('student/announcements?level=course&q=quiz', $this->student);
        $this->assertStringContainsString('data-course-announcement', $hit);
        $this->assertStringContainsString('data-announcement-search', $hit);
        $this->assertStringNotContainsString('Search</button>', $hit, 'it searches as you type, there is no button');

        $miss = $this->page('student/announcements?level=course&q=zzzqqq', $this->student);
        $this->assertMatchesRegularExpression('#data-level-count="course">1<#', $miss, 'a search does not change the counts');
        $this->assertStringNotContainsString('data-course-announcement', $miss);
        $this->assertStringNotContainsString('data-college-announcement', $miss);
        $this->assertStringContainsString('Nothing here matches', $miss);

        $kept = $this->page('student/announcements?level=course&q=quiz', $this->student);
        $this->assertStringContainsString('name="level" value="course"', $kept);
        $this->assertStringContainsString('level=college&q=quiz', $kept);
    }

    public function testRecipientsSeeAStripOnTheirDashboardAndOthersDoNot(): void
    {
        $this->announce();

        foreach ([[$this->student, 'student'], [$this->teacher, 'instructor'], [$this->chair, 'chairperson']] as [$user, $area]) {
            $body = $this->page("{$area}/dashboard", $user);

            $this->assertStringContainsString('data-college-strip', $body, $area);
            $this->assertStringContainsString('Enrolment week', $body, $area);
            $this->assertStringContainsString("{$area}/announcements", $body, $area);
        }

        $this->assertStringNotContainsString('data-college-strip', $this->page('student/dashboard', $this->outsider));
    }

    public function testTheStripShowsAtMostTheThreeNewest(): void
    {
        foreach (['One', 'Two', 'Three', 'Four'] as $title) {
            $this->announce(['title' => $title]);
        }

        $body = $this->page('student/dashboard', $this->student);

        $this->assertStringContainsString('Four', $body);
        $this->assertStringContainsString('Two', $body);
        $this->assertStringNotContainsString('>One<', $body);
    }

    public function testTheAppsMenuLinksAnnouncementsForEachRoleThatHasThem(): void
    {
        foreach ([[$this->dean, 'dean'], [$this->student, 'student'], [$this->teacher, 'instructor'], [$this->chair, 'chairperson']] as [$user, $area]) {
            $this->assertStringContainsString("{$area}/announcements", $this->page("{$area}/dashboard", $user), $area);
        }
    }
}
