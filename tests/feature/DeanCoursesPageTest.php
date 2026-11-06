<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's Courses page as a dean sees it: their college's courses,
 * filters and paging in the address, the empty and no-assignment states, and
 * no way into a course yet.
 *
 * @internal
 */
final class DeanCoursesPageTest extends RouteTestCase
{
    private int $institution;
    private int $college;
    private int $departmentA;
    private int $programA;
    private int $programB;
    private int $term;
    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::college('H');
        $this->institution = $home['institution'];
        $this->college  = $home['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'A');
        $this->programA    = DeanFactory::program($this->departmentA, 'A');
        $this->programB    = DeanFactory::program(DeanFactory::department($this->college, 'B'), 'B');
        $this->term        = DeanFactory::term($this->institution);
        $this->dean        = DeanFactory::dean($this->institution, $this->college);
    }

    private function page(string $query = ''): string
    {
        $this->loggedInAs($this->dean);

        $response = $this->call('GET', '/dean/courses' . $query)->response();

        $this->assertSame(200, $response->getStatusCode());

        return $response->getBody();
    }

    public function testTheDeanSeesTheirCollegesCoursesAndNoOthers(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        DeanFactory::offering($this->programB, $this->term, 'CS201', 'A', 'active', 'Algorithms');

        $elsewhere = DeanFactory::college('X');
        DeanFactory::offering(DeanFactory::program(DeanFactory::department($elsewhere['college'], 'X'), 'X'), DeanFactory::term($elsewhere['institution']), 'ZZ999', 'A', 'active', 'Foreign Course');

        $body = $this->page();

        $this->assertStringContainsString('Intro to Computing', $body);
        $this->assertStringContainsString('Algorithms', $body);
        $this->assertStringNotContainsString('Foreign Course', $body);
        $this->assertStringContainsString('2 courses', $body);
    }

    public function testACardNamesTheInstructorAndTheChairpersonAndOpensTheViewOnlyCourse(): void
    {
        $offering = DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        CourseFactory::assignInstructor($offering, PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution));
        DeanFactory::chair($this->departmentA, 'Maria');

        $body = html_entity_decode($this->page());

        $this->assertStringContainsString('Instr. Tess Tan', $body);
        $this->assertStringContainsString('Chair Maria Chair', $body);
        $this->assertStringContainsString('href="' . site_url("dean/courses/{$offering}/intro-to-computing") . '"', $body);
    }

    public function testFiltersInTheAddressNarrowTheListAndShowAClearLink(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        DeanFactory::offering($this->programB, $this->term, 'CS201', 'A', 'active', 'Algorithms');

        $body = $this->page('?program=' . $this->programB);

        $this->assertStringContainsString('Algorithms', $body);
        $this->assertStringNotContainsString('Intro to Computing', $body);
        $this->assertStringContainsString('1 course match', $body);
        $this->assertStringContainsString('Clear', $body);
    }

    public function testNoMatchAndNoCoursesAreDifferentEmptyStates(): void
    {
        $this->assertStringContainsString('No courses yet', $this->page());

        DeanFactory::offering($this->programA, $this->term, 'IT101');

        $this->assertStringContainsString('No courses match these filters', $this->page('?q=nothinglikethis'));
    }

    public function testPagingLinksKeepTheFilters(): void
    {
        foreach (['IT101', 'IT102', 'IT103'] as $code) {
            DeanFactory::offering($this->programA, $this->term, $code);
        }

        $body = html_entity_decode($this->page('?per_page=2&sort=name&dir=desc'));

        $this->assertStringContainsString('Page 1 of 2', $body);
        $this->assertMatchesRegularExpression('/href="[^"]*dean\/courses\?[^"]*page=2/', $body);
        $this->assertMatchesRegularExpression('/href="[^"]*dean\/courses\?[^"]*dir=desc[^"]*page=2/', $body);
    }

    public function testNonsenseInTheAddressIsIgnoredNotAnError(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');

        $body = $this->page('?sort=password&dir=sideways&page=-3&term=abc&status=deleted&per_page=999999');

        $this->assertStringContainsString('Intro to Computing', $body);
    }

    public function testAnotherCollegesProgramInTheAddressShowsNothing(): void
    {
        DeanFactory::offering($this->programA, $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        $elsewhere = DeanFactory::college('X');
        $foreign   = DeanFactory::program(DeanFactory::department($elsewhere['college'], 'X'), 'X');
        DeanFactory::offering($foreign, DeanFactory::term($elsewhere['institution']), 'ZZ999', 'A', 'active', 'Foreign Course');

        $body = $this->page('?program=' . $foreign);

        $this->assertStringNotContainsString('Foreign Course', $body);
        $this->assertStringNotContainsString('Intro to Computing', $body);
    }

    public function testADeanWithoutACollegeSeesTheExplanationNotTheList(): void
    {
        $this->loggedInAs(CourseFactory::user('dean'));

        $response = $this->call('GET', '/dean/courses')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not assigned to a college yet', $response->getBody());
    }

    public function testTheAppsMenuHasACoursesTileForTheDean(): void
    {
        $this->assertStringContainsString('dean/courses', $this->page());
    }
}
