<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's Teaching load pages: the instructors of the college with what they carry in a term, the
 * high-load marker, the sort and the filters in the address, one instructor's sections, and that nothing of
 * another college or role gets through. View only: there is nothing to submit.
 *
 * @internal
 */
final class TeachingLoadPageTest extends RouteTestCase
{
    private int $institution;
    private int $college;
    private int $departmentA;
    private int $term;
    private User $dean;
    private User $tess;
    private User $leo;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::college('L');
        $this->institution = $home['institution'];
        $this->college  = $home['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'A');
        $departmentB              = DeanFactory::department($this->college, 'B');
        $this->term        = DeanFactory::term($this->institution);
        $this->dean        = DeanFactory::dean($this->institution, $this->college);

        $this->tess = PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution);
        $this->leo  = PeopleFactory::person('instructor', 'Leo', 'Law', null, $this->institution);

        $it = DeanFactory::offering(DeanFactory::program($this->departmentA, 'A'), $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        $lw = DeanFactory::offering(DeanFactory::program($departmentB, 'B'), $this->term, 'LW200', 'A', 'active', 'Law Heavy');
        db_connect()->table('courses')->where('course_code', 'LW200')->update(['units' => 33]);

        CourseFactory::assignInstructor($it, $this->tess);
        CourseFactory::assignInstructor($lw, $this->leo);
        CourseFactory::enroll($it, CourseFactory::user('student'));
    }

    private function page(string $uri, ?User $as = null): string
    {
        $this->loggedInAs($as ?? $this->dean);
        $response = $this->call('get', $uri)->response();

        $this->assertSame(200, $response->getStatusCode(), $uri);

        return html_entity_decode($response->getBody());
    }

    public function testTheDeanSeesEachInstructorWithWhatTheyCarryAndAHighLoadIsMarked(): void
    {
        $body = $this->page('dean/teaching-load');

        $this->assertStringContainsString('Tess Tan', $body);
        $this->assertStringContainsString('Leo Law', $body);
        $this->assertStringContainsString('High load', $body);
        $this->assertStringContainsString('A high load is 30 units or more', $body);
        $this->assertSame(1, substr_count($body, 'High load</span>') , 'only Leo (33 units) is marked');
        $this->assertStringContainsString('Waiting to be graded', $body);
        $this->assertStringContainsString('dean/teaching-load/' . $this->leo->id, $body);
    }

    public function testTheHighLoadLineFollowsTheInstitutionsSetting(): void
    {
        db_connect()->table('institution_academic_defaults')->insert(['institution_id' => $this->institution, 'high_load_units' => 2]);

        $body = $this->page('dean/teaching-load');

        $this->assertStringContainsString('A high load is 2 units or more', $body);
        $this->assertSame(2, substr_count($body, 'High load</span>'), 'Tess (3 units) is marked now too');
    }

    public function testTheSortAndTheDepartmentAreInTheAddress(): void
    {
        $byName = $this->page('dean/teaching-load?sort=name');

        $this->assertLessThan(strpos($byName, 'Tess Tan'), strpos($byName, 'Leo Law'), 'A to Z by name');
        $this->assertStringContainsString('aria-sort', $byName);

        $narrow = $this->page('dean/teaching-load?department=' . $this->departmentA);

        $this->assertStringContainsString('Tess Tan', $narrow);
        $this->assertStringNotContainsString('Leo Law', $narrow);

        $this->assertStringNotContainsString('Tess Tan', $this->page('dean/teaching-load?department=999999'));
    }

    public function testAnInstructorsPageListsTheirSectionsAndOpensThemReadOnly(): void
    {
        $body = $this->page('dean/teaching-load/' . $this->tess->id);

        $this->assertStringContainsString('Tess Tan', $body);
        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('Intro to Computing', $body);
        $this->assertStringContainsString('dean/courses/', $body);
        $this->assertStringNotContainsString('LW200', $body);
        $this->assertStringContainsString('Back to teaching load', $body);
    }

    public function testAnInstructorWhoTeachesNothingThisTermStillHasAPageSayingSo(): void
    {
        $earlier = DeanFactory::term($this->institution, 'Earlier', '2026-01-10');
        $old     = DeanFactory::offering(DeanFactory::program($this->departmentA, 'Z'), $earlier, 'OLD100');
        $retired = PeopleFactory::person('instructor', 'Rita', 'Retired', null, $this->institution);
        CourseFactory::assignInstructor($old, $retired);

        $this->assertStringContainsString('No sections this term', $this->page('dean/teaching-load/' . $retired->id));
    }

    public function testAnInstructorOutsideTheCollegeIsNotFound(): void
    {
        $elsewhere = DeanFactory::college('X', $this->institution);
        $stranger  = PeopleFactory::person('instructor', 'Out', 'Sider', null, $this->institution);
        CourseFactory::assignInstructor(DeanFactory::offering(DeanFactory::program(DeanFactory::department($elsewhere['college'], 'X'), 'X'), $this->term, 'LAW900'), $stranger);

        $this->loggedInAs($this->dean);

        foreach ([$stranger->id, 999999] as $id) {
            try {
                $status = $this->call('get', 'dean/teaching-load/' . $id)->response()->getStatusCode();
                $this->fail("instructor {$id} must be a 404 (got {$status})");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItSaysSoWhenTheCollegeHasNoCoursesYet(): void
    {
        $empty = DeanFactory::college('E', $this->institution);
        $dean  = DeanFactory::dean($this->institution, $empty['college'], 'Eve');

        $body = $this->page('dean/teaching-load', $dean);

        $this->assertStringContainsString('No terms yet', $body);
        $this->assertStringNotContainsString('Tess Tan', $body);
    }

    public function testNobodyButADeanReachesIt(): void
    {
        foreach ([$this->tess, DeanFactory::chair($this->departmentA), PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution)] as $user) {
            $this->loggedInAs($user);

            foreach (['dean/teaching-load', 'dean/teaching-load/' . $this->tess->id] as $uri) {
                $this->assertNotSame(200, $this->call('get', $uri)->response()->getStatusCode(), $uri);
            }
        }
    }

    public function testADeanWithoutACollegeSeesTheExplanation(): void
    {
        $stray = PeopleFactory::person('dean', 'No', 'College', null, $this->institution);

        foreach (['dean/teaching-load', 'dean/teaching-load/' . $this->tess->id] as $uri) {
            $this->assertStringContainsString('not assigned to a college yet', $this->page($uri, $stray));
        }
    }

    public function testAReadOnlyInstitutionStillLetsTheDeanLook(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertStringContainsString('Tess Tan', $this->page('dean/teaching-load'));
    }

    public function testTheAppsMenuHasTheTile(): void
    {
        $this->assertStringContainsString('dean/teaching-load', $this->page('dean/dashboard'));
    }
}
