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

    public function testTheAppsMenuHasAMonitorDepartmentTile(): void
    {
        $this->assertStringContainsString('dean/monitor-department', $this->page());
    }
}
