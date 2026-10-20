<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's Monitor Department page: the department's figures, the
 * comparison per sub-department and per program, filters and sorting in the
 * address, the empty and no-assignment states, and nothing from another
 * department.
 *
 * @internal
 */
final class MonitorDepartmentPageTest extends RouteTestCase
{
    private int $institution;
    private int $department;
    private int $subA;
    private int $subB;
    private int $programA;
    private int $programB;
    private int $term;
    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('H');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'A');
        $this->subB        = DeanFactory::subDepartment($this->department, 'B');
        $this->programA    = DeanFactory::program($this->subA, 'A');
        $this->programB    = DeanFactory::program($this->subB, 'B');
        $this->term        = DeanFactory::term($this->institution);
        $this->dean        = DeanFactory::dean($this->institution, $this->department);
    }

    private function page(string $query = ''): string
    {
        $this->loggedInAs($this->dean);

        $response = $this->call('GET', '/dean/monitor-department' . $query)->response();

        $this->assertSame(200, $response->getStatusCode());

        return $response->getBody();
    }

    private function enrolled(int $offering, int $students): void
    {
        // Something published to do, so a student who has done none of it is silent (at risk).
        CourseFactory::items(CourseFactory::unit(CourseFactory::module($offering)), [['title' => 'One'], ['title' => 'Two']]);

        for ($i = 0; $i < $students; $i++) {
            $student = CourseFactory::user('student');
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'Stu', 'last_name' => 'Dent' . $student->id]);
            CourseFactory::enroll($offering, $student);
        }
    }

    public function testTheDeanSeesTheDepartmentsTotalsComparisonAndAtRiskStudents(): void
    {
        $it = DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        $lw = DeanFactory::offering($this->programB, $this->term, 'LW100', 'A', 'active', 'Intro to Law');
        $this->enrolled($it, 2);
        $this->enrolled($lw, 3);

        $body = html_entity_decode($this->page());

        $this->assertStringContainsString('College H', $body);
        $this->assertStringContainsString('data-section="totals"', $body);
        $this->assertStringContainsString('2 courses', $body);
        $this->assertStringContainsString('Sub A', $body);
        $this->assertStringContainsString('Sub B', $body);
        $this->assertStringContainsString('data-section="at-risk"', $body);
        $this->assertStringContainsString('never active', strtolower($body), 'enrolled students with no activity are at risk');
        $this->assertStringContainsString('href="' . site_url("dean/courses/{$it}/intro-to-computing") . '"', $body, 'at-risk students link to the read-only course');
        $this->assertStringContainsString('id="monitor-program-charts"', $body);
    }

    public function testNothingFromAnotherDepartmentIsShown(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');

        $other = DeanFactory::department('N', $this->institution);
        $offer = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($other['department'], 'N'), 'N'), $this->term, 'LAW900', 'A', 'active', 'Foreign Law');
        $this->enrolled($offer, 4);

        $body = $this->page();

        $this->assertStringNotContainsString('Foreign Law', $body);
        $this->assertStringNotContainsString('College N', $body);
        $this->assertStringNotContainsString('Sub N', $body);
    }

    public function testComparingByProgramAndSortingKeepTheFilters(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');
        DeanFactory::offering($this->programB, $this->term, 'LW100');

        $body = html_entity_decode($this->page('?compare=program&sort=students&dir=desc&sub_department=' . $this->subA));

        $this->assertStringContainsString('data-group="' . $this->programA . '"', $body);
        $this->assertStringNotContainsString('data-group="' . $this->programB . '"', $body, 'the table is narrowed to sub-department A (the filter list still offers every program)');
        preg_match_all('/href="([^"]*monitor-department\?[^"]*)"/', $body, $links);
        $keeping = array_filter($links[1], fn (string $href): bool => str_contains($href, 'compare=program') && str_contains($href, 'sub_department=' . $this->subA));
        $this->assertNotEmpty($keeping, 'sort links keep the grouping and the sub-department filter');
        $this->assertStringContainsString('aria-sort="descending"', $body);
        $this->assertStringContainsString('aria-current="true"', $body);
    }

    public function testNoCoursesAndNoMatchAreDifferentEmptyStates(): void
    {
        $this->assertStringContainsString('Nothing to monitor yet', $this->page());

        DeanFactory::offering($this->programA, $this->term, 'IT101');

        $this->assertStringContainsString('No courses match these filters', $this->page('?program=' . $this->programB));
    }

    public function testNonsenseInTheAddressIsIgnoredNotAnError(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');

        $body = $this->page('?term=abc&from=never&to=-1&sub_department=0&program=x&compare=all&sort=password&dir=up');

        $this->assertStringContainsString('data-section="totals"', $body);
    }

    public function testAnotherDepartmentsProgramInTheAddressShowsNothingOfIt(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        $other   = DeanFactory::department('N', $this->institution);
        $foreign = DeanFactory::program(DeanFactory::subDepartment($other['department'], 'N'), 'N');
        $offer   = DeanFactory::offering($foreign, $this->term, 'LAW900', 'A', 'active', 'Foreign Law');
        $this->enrolled($offer, 2);

        $body = $this->page('?program=' . $foreign);

        $this->assertStringContainsString('No courses match these filters', $body);
        $this->assertStringNotContainsString('Foreign Law', $body);
    }

    public function testADeanWithoutADepartmentSeesTheExplanation(): void
    {
        $this->loggedInAs(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution));

        $response = $this->call('GET', '/dean/monitor-department')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not assigned to a department yet', $response->getBody());
    }

    private function chairAction(string $label, int $offering, string $method, string $at): void
    {
        \CodeIgniter\Config\Services::resetSingle('auditLogger');

        $chair = PeopleFactory::person('chairperson', 'Maria', 'Chair', $label, $this->institution);
        $id    = service('auditLogger')->log('course.chairperson_action', $chair, [
            'target_type' => 'offering', 'target_id' => $offering, 'acting_role' => 'chairperson', 'new' => ['method' => $method],
        ]);

        db_connect()->table('audit_logs')->where('id', $id)->update(['created_at' => $at]);
    }

    public function testTheAuditLogShowsWhoWhatWhenAndAsWhichRole(): void
    {
        $it = DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        $this->chairAction('maria@dept.example', $it, 'PATCH', '2026-09-02 09:00:00');

        $elsewhere = DeanFactory::department('N', $this->institution);
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'N'), 'N'), $this->term, 'LAW900', 'A', 'active', 'Foreign Law');
        $this->chairAction('ines@elsewhere.example', $foreign, 'DELETE', '2026-09-03 09:00:00');

        $body = html_entity_decode($this->page());

        $this->assertStringContainsString('data-section="audit"', $body);
        $this->assertStringContainsString('maria@dept.example', $body);
        $this->assertStringContainsString('Updated something', $body);
        $this->assertStringContainsString('Chairperson', $body);
        $this->assertStringContainsString('2026-09-02 09:00:00', $body);
        $this->assertStringContainsString('href="' . site_url("dean/courses/{$it}/intro-to-computing") . '"', $body);
        $this->assertStringNotContainsString('ines@elsewhere.example', $body, 'another department is not in this log');
        $this->assertSame(1, substr_count($body, 'data-audit-entry'));
    }

    public function testTheAuditLogPagesAndKeepsTheFilters(): void
    {
        $it = DeanFactory::offering($this->programA, $this->term, 'IT101');

        for ($i = 1; $i <= 12; $i++) {
            $this->chairAction("chair{$i}@dept.example", $it, 'POST', sprintf('2026-09-%02d 09:00:00', $i));
        }

        $first  = html_entity_decode($this->page('?sub_department=' . $this->subA));
        $second = html_entity_decode($this->page('?sub_department=' . $this->subA . '&audit_page=2'));

        $this->assertSame(10, substr_count($first, 'data-audit-entry'));
        $this->assertStringContainsString('Page 1 of 2', $first);
        $this->assertMatchesRegularExpression('/href="[^"]*sub_department=' . $this->subA . '[^"]*audit_page=2[^"]*"/', $first);
        $this->assertSame(2, substr_count($second, 'data-audit-entry'));
        $this->assertStringContainsString('Previous', $second);
    }

    public function testAnEmptyAuditLogSaysSo(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');

        $this->assertStringContainsString('No changes recorded yet', $this->page());
    }

    public function testInProgramModeEachProgramLinksToItsPageCarryingTheFilters(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');

        $body = html_entity_decode($this->page('?compare=program&term=' . $this->term));

        $this->assertStringContainsString('data-program-link', $body);
        $this->assertStringContainsString("dean/monitor-department/programs/{$this->programA}?term={$this->term}", $body);

        $subs = html_entity_decode($this->page());
        $this->assertStringNotContainsString('data-program-link', $subs, 'a sub-department row has no drill-down');
    }

    public function testAProgramPageShowsThatProgramReadOnlyForTheDean(): void
    {
        $it = DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        DeanFactory::offering($this->programB, $this->term, 'LW100', 'A', 'active', 'Intro to Law');
        $this->enrolled($it, 2);
        $this->chairAction('maria@dept.example', $it, 'POST', '2026-09-02 09:00:00');

        $this->loggedInAs($this->dean);
        $response = $this->call('GET', "/dean/monitor-department/programs/{$this->programA}")->response();
        $body     = html_entity_decode($response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Program A', $body);
        $this->assertStringContainsString('College H · Sub A', $body);
        $this->assertStringContainsString('IT101', $body);
        $this->assertStringNotContainsString('LW100', $body, 'only this program');
        $this->assertStringContainsString('href="' . site_url("dean/courses/{$it}/intro-to-computing") . '"', $body, 'courses open in the dean read-only view');
        $this->assertStringNotContainsString('chairperson/courses', $body);
        $this->assertStringContainsString('Recent chairperson actions in this program', $body);
        $this->assertStringContainsString('maria@dept.example', $body, 'the dean sees who');
        $this->assertStringContainsString(site_url('dean/monitor-department?compare=program'), $body);
        $this->assertStringContainsString('id="monitor-program-charts"', $body);
    }

    public function testAProgramPageKeepsItsOwnFiltersInItsLinks(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101');

        $this->loggedInAs($this->dean);
        $body = html_entity_decode($this->call('GET', "/dean/monitor-department/programs/{$this->programA}?term={$this->term}")->response()->getBody());

        $this->assertMatchesRegularExpression('#dean/monitor-department/programs/' . $this->programA . '\?[^"]*sort=#', $body, 'sort links stay on the program page');
        $this->assertStringContainsString('name="term"', $body);
    }

    public function testAnotherDepartmentsProgramIsNotFound(): void
    {
        $other   = DeanFactory::department('N', $this->institution);
        $foreign = DeanFactory::program(DeanFactory::subDepartment($other['department'], 'N'), 'N');
        DeanFactory::offering($foreign, $this->term, 'LAW900');
        $this->loggedInAs($this->dean);

        foreach ([$foreign, 999999] as $id) {
            try {
                $status = $this->call('GET', "/dean/monitor-department/programs/{$id}")->response()->getStatusCode();
                $this->fail("program {$id} must be a 404 (got {$status})");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testADeanWithoutADepartmentSeesTheExplanationOnAProgramPageToo(): void
    {
        $this->loggedInAs(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution));

        $response = $this->call('GET', "/dean/monitor-department/programs/{$this->programA}")->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not assigned to a department yet', $response->getBody());
    }

    public function testTheChairpersonsOwnMonitorProgramPageIsUnchanged(): void
    {
        // One PHP process renders every test, and the view renderer keeps data between renders;
        // a real request is a fresh process. Start this page from a clean renderer.
        \CodeIgniter\Config\Services::resetSingle('renderer');

        $chair = CourseFactory::user('chairperson');
        $this->loggedInAs($chair);

        $body = $this->call('GET', '/chairperson/monitor-program')->response()->getBody();

        $this->assertStringContainsString('Monitor Program', $body);
        $this->assertStringContainsString('How the programs you chair are performing.', $body);
        $this->assertStringContainsString('chairperson/monitor-program', $body);
        $this->assertStringNotContainsString('dean/', $body);
    }

    public function testTheAppsMenuHasAMonitorDepartmentTile(): void
    {
        $this->assertStringContainsString('dean/monitor-department', $this->page());
    }
}
