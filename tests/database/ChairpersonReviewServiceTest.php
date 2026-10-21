<?php

declare(strict_types=1);

use App\Services\Courses\ChairpersonReviewFilters;
use App\Services\Courses\ChairpersonReviewService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The dean's review of chairperson changes: only the department's courses, only what the chairperson
 * did not do as the course's own instructor, the filters, the paging, the dean's own "reviewed" marks
 * (a later dean starts with none), and that marking changes nothing in the audit entry itself.
 *
 * @internal
 */
final class ChairpersonReviewServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ChairpersonReviewService $service;
    private int $institution;
    private int $department;
    private int $subA;
    private int $subB;
    private int $itA;
    private int $lawB;
    private User $dean;
    private User $chairA;
    private User $chairB;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home              = DeanFactory::department('R');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'A');
        $this->subB        = DeanFactory::subDepartment($this->department, 'B');
        $term              = DeanFactory::term($this->institution);

        $this->itA  = DeanFactory::offering(DeanFactory::program($this->subA, 'A'), $term, 'IT101', 'A', 'active', 'Intro to Computing');
        $this->lawB = DeanFactory::offering(DeanFactory::program($this->subB, 'B'), $term, 'LW100', 'A', 'active', 'Intro to Law');

        $this->dean    = DeanFactory::dean($this->institution, $this->department);
        $this->chairA  = DeanFactory::chair($this->subA, 'Ana');
        $this->chairB  = DeanFactory::chair($this->subB, 'Ben');
        $this->service = new ChairpersonReviewService();
    }

    private function action(User $chair, int $offering, string $method = 'POST', string $area = 'announcements', string $at = '2026-10-10 09:00:00', string $name = 'course.chairperson_action'): int
    {
        Services::resetSingle('auditLogger');

        $id = service('auditLogger')->log($name, $chair, [
            'target_type' => 'course_offering', 'target_id' => $offering, 'acting_role' => 'chairperson',
            'new'         => ['method' => $method, 'path' => "/chairperson/api/courses/{$offering}/{$area}"],
        ]);

        db_connect()->table('audit_logs')->where('id', $id)->update(['created_at' => $at]);

        return $id;
    }

    /** @return list<int> */
    private function ids(array $result): array
    {
        return array_column($result['rows'], 'id');
    }

    private function filters(array $input = []): ChairpersonReviewFilters
    {
        return ChairpersonReviewFilters::fromInput($input);
    }

    public function testItListsChairpersonChangesInTheDepartmentsCoursesNewestFirst(): void
    {
        $first  = $this->action($this->chairA, $this->itA, at: '2026-10-10 09:00:00');
        $second = $this->action($this->chairB, $this->lawB, 'PATCH', 'assessments', '2026-10-11 09:00:00');

        $result = $this->service->entries($this->dean, $this->filters());

        $this->assertSame([$second, $first], $this->ids($result));
        $this->assertSame(2, $result['total']);
        $this->assertSame(['unreviewed' => 2, 'reviewed' => 0], $result['counts']);

        $row = $result['rows'][1];

        $this->assertSame('IT101', $row['course_code']);
        $this->assertSame('Sub A', $row['sub_department']);
        $this->assertSame('Created an announcement', $row['description']);
        $this->assertSame('Updated an assessment', $result['rows'][0]['description']);
        $this->assertStringContainsString('ana.chair', (string) $row['actor']);
    }

    public function testNothingFromAnotherDepartmentOrAnotherKindOfEntryIsListed(): void
    {
        $elsewhere = DeanFactory::department('X', $this->institution);
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X'), DeanFactory::term($this->institution, 'Other'), 'LAW900');
        $mine      = $this->action($this->chairA, $this->itA);

        $this->action($this->chairA, $foreign);
        $this->action($this->chairA, $this->itA, name: 'grades.override_requested');

        $this->assertSame([$mine], $this->ids($this->service->entries($this->dean, $this->filters())));

        $other = DeanFactory::dean($this->institution, $elsewhere['department'], 'Eve');

        $this->assertSame(1, $this->service->entries($other, $this->filters())['total'], 'the other dean sees only their own department\'s');
    }

    public function testWhatAChairpersonDidInACourseTheyTeachIsLeftOut(): void
    {
        $this->action($this->chairA, $this->itA);
        CourseFactory::assignInstructor($this->itA, $this->chairA);

        $this->assertSame(0, $this->service->entries($this->dean, $this->filters())['total']);
        $this->assertSame(0, $this->service->pendingCount($this->dean));
    }

    public function testTheFiltersNarrowByChairpersonSubDepartmentAndDates(): void
    {
        $a = $this->action($this->chairA, $this->itA, at: '2026-10-05 09:00:00');
        $b = $this->action($this->chairB, $this->lawB, at: '2026-10-20 23:30:00');

        $this->assertSame([$a], $this->ids($this->service->entries($this->dean, $this->filters(['chairperson' => (string) $this->chairA->id]))));
        $this->assertSame([$b], $this->ids($this->service->entries($this->dean, $this->filters(['sub_department' => (string) $this->subB]))));
        $this->assertSame([$b], $this->ids($this->service->entries($this->dean, $this->filters(['from' => '2026-10-10']))));
        $this->assertSame([$b, $a], $this->ids($this->service->entries($this->dean, $this->filters(['to' => '2026-10-20']))), 'the whole last day counts');
        $this->assertSame([$a], $this->ids($this->service->entries($this->dean, $this->filters(['to' => '2026-10-19']))));
        $this->assertSame([], $this->ids($this->service->entries($this->dean, $this->filters(['chairperson' => (string) $this->chairA->id, 'sub_department' => (string) $this->subB]))));
    }

    public function testASubDepartmentOrChairpersonOutsideTheDepartmentFindsNothingNotEverything(): void
    {
        $this->action($this->chairA, $this->itA);
        $elsewhere = DeanFactory::department('X', $this->institution);
        $foreignSub = DeanFactory::subDepartment($elsewhere['department'], 'X');

        $this->assertSame(0, $this->service->entries($this->dean, $this->filters(['sub_department' => (string) $foreignSub]))['total']);
        $this->assertSame(0, $this->service->entries($this->dean, $this->filters(['chairperson' => '999999']))['total']);
    }

    public function testItPagesAndKeepsTheTotal(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->action($this->chairA, $this->itA, at: "2026-10-0{$i} 09:00:00");
        }

        $first = $this->service->entries($this->dean, $this->filters(), 1, 2);
        $last  = $this->service->entries($this->dean, $this->filters(), 3, 2);

        $this->assertSame(5, $first['total']);
        $this->assertCount(2, $first['rows']);
        $this->assertCount(1, $last['rows']);
        $this->assertSame('2026-10-05 09:00:00', $first['rows'][0]['created_at']);
    }

    public function testTheDeanMarksEntriesAsReviewedAndTheyMoveBetweenTheTabs(): void
    {
        $a = $this->action($this->chairA, $this->itA);
        $b = $this->action($this->chairB, $this->lawB);

        $result = $this->service->markReviewed($this->dean, [$a]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['marked']);
        $this->assertSame([$b], $this->ids($this->service->entries($this->dean, $this->filters())));
        $this->assertSame([$a], $this->ids($this->service->entries($this->dean, $this->filters(['view' => 'reviewed']))));
        $this->assertEqualsCanonicalizing([$a, $b], $this->ids($this->service->entries($this->dean, $this->filters(['view' => 'all']))));
        $this->assertSame(['unreviewed' => 1, 'reviewed' => 1], $this->service->entries($this->dean, $this->filters())['counts']);
        $this->assertSame(1, $this->service->pendingCount($this->dean));

        $this->assertFalse($this->service->markReviewed($this->dean, [$a])['success'], 'already marked');
        $this->assertSame(1, db_connect()->table('chairperson_action_reviews')->countAllResults());
    }

    public function testMarkingChangesNothingInTheEntryAndIsAudited(): void
    {
        $id     = $this->action($this->chairA, $this->itA);
        $before = db_connect()->table('audit_logs')->where('id', $id)->get()->getRowArray();

        $this->service->markReviewed($this->dean, [$id]);

        $this->assertSame($before, db_connect()->table('audit_logs')->where('id', $id)->get()->getRowArray());

        $audit = db_connect()->table('audit_logs')->where('action', 'chairperson_review.marked')->get()->getRowArray();

        $this->assertSame('dean', $audit['acting_role']);
        $this->assertStringContainsString((string) $id, (string) $audit['new_values']);
    }

    public function testOnlyEntriesOfTheDeansOwnDepartmentCanBeMarked(): void
    {
        $elsewhere = DeanFactory::department('X', $this->institution);
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X'), DeanFactory::term($this->institution, 'Other'), 'LAW900');
        $theirs    = $this->action($this->chairA, $foreign);
        $mine      = $this->action($this->chairA, $this->itA);

        $result = $this->service->markReviewed($this->dean, [$theirs, $mine, 999999]);

        $this->assertSame(1, $result['marked'], 'the foreign and the made-up ids are ignored');
        $this->assertFalse($this->service->markReviewed($this->dean, [$theirs])['success']);
        $this->assertFalse($this->service->markReviewed($this->dean, [])['success']);
        $this->assertSame(1, db_connect()->table('chairperson_action_reviews')->countAllResults());
    }

    public function testNobodyButTheDeanMarksAnything(): void
    {
        $id = $this->action($this->chairA, $this->itA);

        $this->assertFalse($this->service->markReviewed($this->chairA, [$id])['success']);
        $this->assertFalse($this->service->markReviewed(PeopleFactory::person('dean', 'No', 'Department', null, $this->institution), [$id])['success']);
        $this->assertSame(0, db_connect()->table('chairperson_action_reviews')->countAllResults());
    }

    public function testTheMarksAreTheDeansOwnAndANewDeanStartsWithNone(): void
    {
        $id = $this->action($this->chairA, $this->itA);
        $this->service->markReviewed($this->dean, [$id]);

        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->update(['status' => 'ended', 'active_marker' => null]);
        $next = DeanFactory::dean($this->institution, $this->department, 'Nora');

        $this->assertSame([$id], $this->ids($this->service->entries($next, $this->filters())), 'unreviewed for the new dean');
        $this->assertSame(0, $this->service->entries($this->dean, $this->filters())['total'], 'and the old dean no longer reaches anything');
    }

    public function testADeanWithoutADepartmentSeesNothing(): void
    {
        $this->action($this->chairA, $this->itA);
        $stray = PeopleFactory::person('dean', 'No', 'Department', null, $this->institution);

        $this->assertSame(['rows' => [], 'total' => 0, 'counts' => ['unreviewed' => 0, 'reviewed' => 0]], $this->service->entries($stray, $this->filters()));
        $this->assertSame([], $this->service->chairpersons($stray));
    }

    public function testTheChairpersonFilterListsTheDepartmentsChairpersons(): void
    {
        $names = array_column($this->service->chairpersons($this->dean), 'name');

        $this->assertSame(['Ana Chair', 'Ben Chair'], $names);
    }

    public function testTheDatabaseKeepsASecondMarkByTheSameDeanOut(): void
    {
        $id = $this->action($this->chairA, $this->itA);
        $this->service->markReviewed($this->dean, [$id]);

        try {
            db_connect()->table('chairperson_action_reviews')->insert(['audit_log_id' => $id, 'reviewer_id' => $this->dean->id, 'reviewed_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            // The driver throws or returns false, depending on its settings.
        }

        $this->assertSame(1, db_connect()->table('chairperson_action_reviews')->countAllResults());
    }

    /** @return iterable<string, array{0: ?string, 1: ?string, 2: string}> */
    public static function changes(): iterable
    {
        yield 'new announcement' => ['POST', '/chairperson/api/courses/12/announcements', 'Created an announcement'];
        yield 'edited module' => ['PATCH', '/chairperson/api/courses/12/modules/3', 'Updated course content'];
        yield 'deleted assessment' => ['DELETE', '/chairperson/api/courses/12/assessments/9', 'Deleted an assessment'];
        yield 'any grade change' => ['POST', '/chairperson/api/courses/12/grades/override', 'Changed grades'];
        yield 'a discussion' => ['PUT', '/chairperson/api/courses/12/discussions/2', 'Updated a discussion'];
        yield 'something unknown' => ['POST', '/chairperson/api/courses/12/whatever', 'Created something in the course'];
        yield 'no path recorded' => ['DELETE', null, 'Deleted something in the course'];
        yield 'no method recorded' => [null, '/chairperson/api/courses/12/modules', 'Changed course content'];
    }

    #[DataProvider('changes')]
    public function testAChangeIsDescribedInWords(?string $method, ?string $path, string $expected): void
    {
        $this->assertSame($expected, ChairpersonReviewService::describe($method, $path));
    }
}
