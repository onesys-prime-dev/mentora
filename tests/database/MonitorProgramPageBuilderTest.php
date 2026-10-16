<?php

declare(strict_types=1);

use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramPage;
use App\Services\Courses\MonitorProgramService;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * MonitorProgramPage: the figures of the Monitor Program page, built from a
 * list of offerings. The chairperson's page and the dean's page for one
 * program both come from here, so the builder must give exactly what the
 * service gives for the same offerings, and present an empty list as an empty
 * page, not an error.
 *
 * @internal
 */
final class MonitorProgramPageBuilderTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private MonitorProgramService $monitor;
    private MonitorProgramPage $builder;
    private int $program;
    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitor = new MonitorProgramService();
        $this->builder = new MonitorProgramPage($this->monitor);

        $home           = DeanFactory::department('B');
        $this->program  = DeanFactory::program(DeanFactory::subDepartment($home['department'], 'B'), 'B');
        $term           = DeanFactory::term($home['institution']);
        $this->offering = DeanFactory::offering($this->program, $term, 'IT101', 'A', 'active', 'Intro to Computing');

        // Two published items and one student who has done neither: silent, so at risk.
        CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->offering)), [['title' => 'One'], ['title' => 'Two']]);
        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
        CourseFactory::enroll($this->offering, $student);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->monitor->offeringRowsForPrograms([$this->program], new MonitorProgramFilters());
    }

    public function testItGivesWhatTheServiceGivesForTheSameOfferings(): void
    {
        $rows = $this->rows();
        $page = $this->builder->build($rows, new MonitorProgramFilters(), 'course_code', 'asc', 1, 10, null);

        $this->assertSame($this->monitor->snapshot($rows), $page['snapshot']);
        $this->assertSame(['courses' => 1, 'instructors' => 0, 'students' => 1], $page['snapshot']);
        $this->assertCount(1, $page['comparison']);
        $this->assertSame('IT101', $page['comparison'][0]['course_code']);
        $this->assertSame('intro-to-computing', $page['comparison'][0]['slug'], 'the address a course card links to');
        $this->assertSame('course_code', $page['sort']);
        $this->assertSame('asc', $page['dir']);
    }

    public function testEachAtRiskStudentIsLabelledWithTheirCourse(): void
    {
        $page = $this->builder->build($this->rows(), new MonitorProgramFilters(), 'course_code', 'asc', 1, 10, null);

        $this->assertCount(1, $page['atRisk']);
        $this->assertSame('IT101', $page['atRisk'][0]['course_code']);
        $this->assertSame('A', $page['atRisk'][0]['section_code']);
        $this->assertSame('intro-to-computing', $page['atRisk'][0]['slug']);
        $this->assertSame('inactive', $page['atRisk'][0]['reason']);
    }

    public function testTheAuditLogIsOneChairpersonsOrEveryoneInScope(): void
    {
        $chair = PeopleFactory::person('chairperson', 'Maria', 'Chair', 'maria@page.example');
        $other = PeopleFactory::person('chairperson', 'Olga', 'Other', 'olga@page.example');

        foreach ([$chair, $other, $other] as $actor) {
            \CodeIgniter\Config\Services::resetSingle('auditLogger');
            service('auditLogger')->log('course.chairperson_action', $actor, ['target_type' => 'offering', 'target_id' => $this->offering, 'acting_role' => 'chairperson', 'new' => ['method' => 'POST']]);
        }

        $mine     = $this->builder->build($this->rows(), new MonitorProgramFilters(), 'course_code', 'asc', 1, 2, (int) $chair->id);
        $everyone = $this->builder->build($this->rows(), new MonitorProgramFilters(), 'course_code', 'asc', 1, 2, null);

        $this->assertSame(1, $mine['audit']['total'], "one chairperson's own actions");
        $this->assertSame(1, $mine['auditPages']);
        $this->assertSame(3, $everyone['audit']['total'], "everyone's, within the offerings in scope");
        $this->assertSame(2, $everyone['auditPages'], 'three entries, two a page');
        $this->assertSame($everyone['audit']['rows'][0]['actor'], 'olga@page.example');
    }

    public function testTheGradeDistributionHasEveryBucketEvenWithNoGrades(): void
    {
        $page = $this->builder->build($this->rows(), new MonitorProgramFilters(), 'course_code', 'asc', 1, 10, null);

        $this->assertSame(array_keys(MonitorProgramService::GRADE_BUCKETS), array_keys($page['gradeDistribution']));
        $this->assertSame(0, array_sum($page['gradeDistribution']));
    }

    public function testNoOfferingsIsAnEmptyPageNotAnError(): void
    {
        $page = $this->builder->build([], new MonitorProgramFilters(), 'course_code', 'asc', 1, 10, null);

        $this->assertSame(['courses' => 0, 'instructors' => 0, 'students' => 0], $page['snapshot']);
        $this->assertSame([], $page['comparison']);
        $this->assertSame([], $page['atRisk']);
        $this->assertSame(['rows' => [], 'total' => 0], $page['audit']);
        $this->assertSame(1, $page['auditPages'], 'always at least one page');
    }
}
