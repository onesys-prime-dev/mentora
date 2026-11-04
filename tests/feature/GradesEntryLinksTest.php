<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\GradeService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The ways into the Grades page (spec §10, phase 5 decisions) and back: each course's Grades tab, the parent's
 * course tab, the dashboard's grading-queue and at-risk cards, and Monitor Program / Monitor Department with
 * their back links. Each link carries the term the page it leaves shows, and none is drawn for someone whose
 * Grades page would not open for them.
 *
 * @internal
 */
final class GradesEntryLinksTest extends RouteTestCase
{
    private int $institution;

    private int $department;

    private int $sub;

    private int $program;

    private int $term;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        PermissionMatrix::flush();

        ['institution' => $this->institution, 'department' => $this->department] = DeanFactory::department('GEL');
        $this->sub      = DeanFactory::subDepartment($this->department, 'GEL');
        $this->program  = DeanFactory::program($this->sub, 'GEL');
        $this->term     = DeanFactory::term($this->institution);
        $this->offering = DeanFactory::offering($this->program, $this->term, 'GEL101');
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    /** The Grades tab's fragment of the course, as the signed-in person gets it. */
    private function tab(string $area, ?int $prefix = null): DOMXPath
    {
        $uri = $area === 'parent'
            ? "parent/children/{$prefix}/api/courses/{$this->offering}/grades"
            : "{$area}/api/courses/{$this->offering}/grades";

        $response = $this->get($uri);
        $response->assertStatus(200);

        return $this->xpath((string) json_decode((string) $response->getJSON(), true)['html']);
    }

    private function page(string $uri): DOMXPath
    {
        $response = $this->get($uri);
        $response->assertStatus(200);

        return $this->xpath((string) $response->getBody());
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** @return list<string> the hrefs of the links marked with $marker */
    private function hrefs(DOMXPath $x, string $marker): array
    {
        $out = [];

        foreach ($x->query("//a[@{$marker}]") as $node) {
            $out[] = html_entity_decode($node->getAttribute('href'));
        }

        return $out;
    }

    private function text(DOMXPath $x, string $marker): string
    {
        $node = $x->query("//a[@{$marker}]")->item(0);

        return $node === null ? '' : trim(preg_replace('/\s+/', ' ', $node->textContent));
    }

    private function clear(string $role, string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => $role, 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();
    }

    // ---- the course's Grades tab ----

    public function testAStudentsCourseTabLinksToTheirGradesOfTheCoursesTerm(): void
    {
        $student = PeopleFactory::person('student', 'Ada', 'Lovelace', null, $this->institution);
        CourseFactory::enroll($this->offering, $student);
        $this->loggedInAs($student);

        $x = $this->tab('student');

        $this->assertSame([site_url('student/grades?term=' . $this->term)], $this->hrefs($x, 'data-all-grades'));
        $this->assertSame('All my grades →', $this->text($x, 'data-all-grades'));
    }

    public function testAnInstructorsCourseTabLinksToTheirSectionOnTheGradesPage(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $teacher);
        $this->loggedInAs($teacher);

        $x = $this->tab('instructor');

        $this->assertSame([site_url('instructor/grades?term=' . $this->term . '&section=' . $this->offering)], $this->hrefs($x, 'data-all-grades'));
        $this->assertSame('All my sections →', $this->text($x, 'data-all-grades'));
    }

    public function testAnInstructorWhoseSectionIsNotOpenGetsNoLinkToAPageThatWouldRefuseIt(): void
    {
        // The instructor's tab itself is only served for active and completed sections, so the guard is
        // exercised on the link logic directly, with an open section as the control.
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $teacher);
        $this->loggedInAs($teacher);

        $link = new ReflectionMethod(GradeService::class, 'allGradesLink');
        $link->setAccessible(true);
        $service = new GradeService();
        $open    = ['id' => $this->offering, 'term_id' => $this->term, 'status' => 'active'];

        $this->assertNotNull($link->invoke($service, $teacher, $open), 'control: an open section gets its link');

        foreach (['archived', 'draft', 'cancelled'] as $status) {
            $this->assertNull($link->invoke($service, $teacher, ['status' => $status] + $open), $status);
        }
    }

    public function testAChairpersonActingInACourseTheyDoNotTeachGetsNoLink(): void
    {
        $chair = DeanFactory::chair($this->sub);
        $this->loggedInAs($chair);

        $this->assertSame([], $this->hrefs($this->tab('chairperson'), 'data-all-grades'));
    }

    public function testAChairpersonWhoTeachesTheCourseButActsAsChairpersonGetsNoLink(): void
    {
        $chair = DeanFactory::chair($this->sub);
        $chair->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $chair);
        $this->loggedInAs($chair);
        $this->withSession(['active_role' => 'chairperson']);

        $this->assertSame([], $this->hrefs($this->tab('chairperson'), 'data-all-grades'));
    }

    public function testADeanReadingACourseGetsNoLink(): void
    {
        $dean = DeanFactory::dean($this->institution, $this->department);
        $this->loggedInAs($dean);

        $this->assertSame([], $this->hrefs($this->tab('dean'), 'data-all-grades'));
    }

    public function testAParentsCourseTabLinksToTheChildsGradesOfTheCoursesTerm(): void
    {
        $parent = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $child  = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        CourseFactory::enroll($this->offering, $child);
        $this->loggedInAs($parent);

        $x = $this->tab('parent', (int) $child->id);

        $this->assertSame([site_url("parent/children/{$child->id}/grades?term=" . $this->term)], $this->hrefs($x, 'data-all-grades'));
        $this->assertSame('All grades →', $this->text($x, 'data-all-grades'));
    }

    // ---- the dashboard's cards ----

    /** @return list<array<string, mixed>> */
    private function queue(): array
    {
        return [['assessment_id' => 5, 'offering_id' => $this->offering, 'slug' => 'course', 'course_code' => 'GEL101', 'section_code' => 'A', 'title' => 'Quiz 1', 'type' => 'quiz', 'ungraded' => 3, 'submitted' => 4, 'waiting_days' => 2]];
    }

    /** @return list<array<string, mixed>> */
    private function atRisk(): array
    {
        return [['offering_id' => $this->offering, 'slug' => 'course', 'student_id' => 9, 'name' => 'Ada Lovelace', 'section' => 'GEL101-A', 'last_active' => 'today', 'progress' => 10, 'reason' => 'behind']];
    }

    private function card(string $partial, User $as, array $data): DOMXPath
    {
        auth()->logout();
        $this->loggedInAs($as);

        return $this->xpath(view('pages/dashboard/partials/' . $partial, ['user' => $as, 'data' => $data], ['saveData' => false]));
    }

    public function testTheInstructorsGradingQueueSeesAllInToGrade(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $x       = $this->card('grading-queue', $teacher, ['queue' => $this->queue()]);

        $this->assertSame([site_url('instructor/grades?tab=to-grade')], $this->hrefs($x, 'data-see-all'));
        $this->assertSame('See all', $this->text($x, 'data-see-all'));
    }

    public function testTheInstructorsAtRiskCardSeesAllInAtRisk(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $x       = $this->card('at-risk-students', $teacher, ['students' => $this->atRisk()]);

        $this->assertSame([site_url('instructor/grades?tab=at-risk')], $this->hrefs($x, 'data-see-all'));
        $this->assertSame('See all', $this->text($x, 'data-see-all'));
    }

    public function testAnEmptyCardHasNothingToSeeAll(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);

        $this->assertSame([], $this->hrefs($this->card('grading-queue', $teacher, ['queue' => []]), 'data-see-all'));
        $this->assertSame([], $this->hrefs($this->card('at-risk-students', $teacher, ['students' => []]), 'data-see-all'));
    }

    public function testNoSeeAllWhereTheInstructorsGradesPageIsNotTheirs(): void
    {
        // A chairperson acting as chairperson would be sent to a page that is not the instructor's.
        $chair = DeanFactory::chair($this->sub);

        $this->assertSame([], $this->hrefs($this->card('grading-queue', $chair, ['queue' => $this->queue()]), 'data-see-all'));
        $this->assertSame([], $this->hrefs($this->card('at-risk-students', $chair, ['students' => $this->atRisk()]), 'data-see-all'));
    }

    public function testNoSeeAllWithoutCoursesViewForTheInstructor(): void
    {
        $this->clear('instructor', 'courses.view');
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);

        $this->assertSame([], $this->hrefs($this->card('grading-queue', $teacher, ['queue' => $this->queue()]), 'data-see-all'));
    }

    // ---- Monitor Program, Monitor Department and back ----

    public function testMonitorProgramLinksToTheProgramTabOfTheSameTerm(): void
    {
        $chair = DeanFactory::chair($this->sub);
        $this->loggedInAs($chair);

        $this->assertSame([site_url('chairperson/grades?tab=program&term=' . $this->term)], $this->hrefs($this->page('chairperson/monitor-program?term=' . $this->term), 'data-grade-records'));
        $this->assertSame('View grade records →', $this->text($this->page('chairperson/monitor-program?term=' . $this->term), 'data-grade-records'));
        $this->assertSame([site_url('chairperson/grades?tab=program')], $this->hrefs($this->page('chairperson/monitor-program'), 'data-grade-records'), 'any term: the Grades page picks its own');
    }

    public function testMonitorProgramDrawsNoGradeRecordsLinkWithoutCoursesViewAsChairperson(): void
    {
        $chair = DeanFactory::chair($this->sub);
        $this->clear('chairperson', 'courses.view');
        $this->loggedInAs($chair);

        $x = $this->page('chairperson/monitor-program?term=' . $this->term);

        $this->assertSame([], $this->hrefs($x, 'data-grade-records'), 'the Grades page would refuse them');
    }

    public function testMonitorDepartmentLinksToTheDepartmentViewOfTheSameTerm(): void
    {
        $dean = DeanFactory::dean($this->institution, $this->department);
        $this->loggedInAs($dean);

        $x = $this->page('dean/monitor-department?term=' . $this->term);

        $this->assertSame([site_url('dean/grades?term=' . $this->term)], $this->hrefs($x, 'data-grade-records'));
        $this->assertSame('View grade records →', $this->text($x, 'data-grade-records'));
        $this->assertSame([site_url('dean/grades')], $this->hrefs($this->page('dean/monitor-department'), 'data-grade-records'));
    }

    public function testTheDeansDrillDownIntoOneProgramLinksToTheDeansGradesNotTheChairpersons(): void
    {
        $dean = DeanFactory::dean($this->institution, $this->department);
        $this->loggedInAs($dean);

        $x = $this->page("dean/monitor-department/programs/{$this->program}?term=" . $this->term);

        $this->assertSame([site_url("dean/grades?program={$this->program}&term=" . $this->term)], $this->hrefs($x, 'data-grade-records'));
    }

    public function testTheProgramTabLinksBackToMonitorProgramWithItsTerm(): void
    {
        $chair = DeanFactory::chair($this->sub);
        $this->loggedInAs($chair);

        foreach (['', '&sub=at-risk'] as $sub) {
            $x = $this->page('chairperson/grades?tab=program&term=' . $this->term . $sub);

            $this->assertSame([site_url('chairperson/monitor-program?term=' . $this->term)], $this->hrefs($x, 'data-view-analytics'), $sub);
            $this->assertSame('View analytics →', $this->text($x, 'data-view-analytics'));
        }
    }

    public function testMyTeachingSectionsHaveNoAnalyticsLinkAndNeitherDoesAChairpersonWithoutMonitorProgram(): void
    {
        $chair = DeanFactory::chair($this->sub);
        $chair->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $chair);
        $this->loggedInAs($chair);

        $this->assertSame([], $this->hrefs($this->page('chairperson/grades?tab=mine&term=' . $this->term), 'data-view-analytics'), 'My sections is not analytics');

        $this->clear('chairperson', 'program.monitor');

        $this->assertSame([], $this->hrefs($this->page('chairperson/grades?term=' . $this->term), 'data-view-analytics'));
    }

    public function testTheDeansPageLinksBackToMonitorDepartmentWithItsTermAndKeepsItThere(): void
    {
        $dean = DeanFactory::dean($this->institution, $this->department);
        $this->loggedInAs($dean);

        $back = $this->hrefs($this->page('dean/grades?term=' . $this->term), 'data-view-analytics');

        $this->assertSame([site_url('dean/monitor-department?term=' . $this->term)], $back);

        // And from there the way forward carries the same term: the round trip keeps it.
        $forward = $this->hrefs($this->page('dean/monitor-department?term=' . $this->term), 'data-grade-records');

        $this->assertSame([site_url('dean/grades?term=' . $this->term)], $forward);
    }
}
