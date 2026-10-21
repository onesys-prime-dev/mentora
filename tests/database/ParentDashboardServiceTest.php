<?php

declare(strict_types=1);

use App\Services\Parents\ParentDashboardService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * What a parent's dashboard is built from, for one linked child: the courses
 * with the standing the student sees, past terms, what needs attention and
 * the next fourteen days of deadlines. Never a score for a single item.
 *
 * @internal
 */
final class ParentDashboardServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private User $parent;

    private User $child;

    private User $teacher;

    private ParentDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = PeopleFactory::institution('PDS');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);
        $this->service     = new ParentDashboardService();

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A course the child is in, with a published scheme worth 100% in one quiz
     * category and an assessment-backed item.
     *
     * @return array{offering: int, scheme: int, item: int, module: int, assessment: int}
     */
    private function course(string $section = 'A', string $enrolment = 'enrolled', string $offeringStatus = 'active', ?float $score = 10.0, bool $published = true): array
    {
        $offering = CourseFactory::offering($section);
        $module   = CourseFactory::module($offering);

        db_connect()->table('course_offerings')->where('id', $offering)->update(['status' => $offeringStatus]);
        CourseFactory::assignInstructor($offering, $this->teacher);
        CourseFactory::enroll($offering, $this->child, $enrolment);

        $scheme   = GradeFactory::scheme($offering, 75.0);
        $category = GradeFactory::category($scheme, 'Quizzes', 100);
        $quiz     = $this->assessment($offering, $module, 'Published', time() + 30 * 86400, 'Gradebook quiz');
        $item     = GradeFactory::item($scheme, $category, 'Quiz', 10, 1, $quiz);

        if ($score !== null) {
            GradeFactory::score($offering, $this->child, $item, $score);
        }

        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $published ? 'Published' : 'Active', 'needs_recalculation' => 0]);

        return ['offering' => $offering, 'scheme' => $scheme, 'item' => $item, 'module' => $module, 'assessment' => $quiz];
    }

    private function assessment(int $offering, int $module, string $status, int $due, string $title): int
    {
        $id = CourseFactory::assessment($offering, $this->teacher, $module);

        db_connect()->table('assessments')->where('id', $id)->update([
            'status' => $status, 'total_points' => 10, 'title' => $title, 'due_date' => date('Y-m-d H:i:s', $due),
            'available_from' => date('Y-m-d H:i:s', time() - 60 * 86400), 'available_until' => null,
        ]);

        return $id;
    }

    private function handIn(int $assessment): void
    {
        db_connect()->table('assessment_submissions')->insert([
            'assessment_id' => $assessment, 'student_id' => $this->child->id, 'attempt_number' => 1, 'score' => 0,
            'is_graded' => 0, 'status' => 'Submitted',
        ]);
    }

    private function record(int $offering): array
    {
        return db_connect()->table('gradebook_records')->where(['course_offering_id' => $offering, 'student_id' => $this->child->id])->get()->getRowArray();
    }

    private function page(): array
    {
        $page = $this->service->forChild($this->parent, (int) $this->child->id);

        $this->assertNotNull($page);

        return $page;
    }

    public function testACurrentCourseCarriesTheStandingTheStudentSees(): void
    {
        $this->course('A', 'enrolled', 'active', 10.0);

        $page = $this->page();

        $this->assertSame('Ann Lee', $page['child']['name']);
        $this->assertCount(1, $page['courses']);
        $this->assertSame([], $page['past']);

        $card = $page['courses'][0];
        $this->assertSame(100.0, $card['percentage']);
        $this->assertSame('A', $card['band']);
        $this->assertSame('On track', $card['tag']);
        $this->assertFalse($card['is_final']);
        $this->assertTrue($card['graded']);
        $this->assertSame('Tina Tan', $card['instructor_name']);
        $this->assertNotSame('', $card['course_code']);
    }

    public function testAnUnpublishedSchemeShowsNoPercentageOrTag(): void
    {
        $this->course('A', 'enrolled', 'active', 10.0, false);

        $card = $this->page()['courses'][0];

        $this->assertNull($card['percentage']);
        $this->assertNull($card['band']);
        $this->assertSame('Incomplete', $card['tag']);
        $this->assertFalse($card['graded']);
    }

    public function testDroppedWithdrawnAndCompletedCoursesArePast(): void
    {
        $this->course('A');
        $this->course('B', 'dropped');
        $this->course('C', 'withdrawn');
        $this->course('D', 'completed', 'completed');
        $this->course('E', 'enrolled', 'completed');

        $page = $this->page();

        $this->assertCount(1, $page['courses']);
        $this->assertEqualsCanonicalizing(['dropped', 'withdrawn', 'completed', 'enrolled'], array_column($page['past'], 'enrollment_status'));
        $this->assertCount(4, $page['past']);
    }

    public function testAnAtRiskCourseIsFlaggedAndAPassingOneIsNot(): void
    {
        $weak   = $this->course('A', 'enrolled', 'active', 5.0);
        $strong = $this->course('B', 'enrolled', 'active', 10.0);

        $page = $this->page();

        $atRisk = array_values(array_filter($page['attention'], static fn (array $a): bool => $a['kind'] === 'at_risk'));

        $this->assertCount(1, $atRisk);
        $this->assertSame($weak['offering'], $atRisk[0]['offering_id']);
        $this->assertSame('warning', $atRisk[0]['tone']);
        $this->assertSame('At risk', array_column($page['courses'], 'tag', 'offering_id')[$weak['offering']]);
        $this->assertSame('On track', array_column($page['courses'], 'tag', 'offering_id')[$strong['offering']]);
    }

    public function testIncAndWithdrawnAreNeverAtRisk(): void
    {
        $inc       = $this->course('A', 'enrolled', 'active', 5.0);
        $withdrawn = $this->course('B', 'enrolled', 'active', 5.0);
        $this->page(); // calculates both records

        db_connect()->table('gradebook_records')->where('id', $this->record($inc['offering'])['id'])->update(['remarks' => 'INC', 'inc_reason' => 'Medical']);
        db_connect()->table('gradebook_records')->where('id', $this->record($withdrawn['offering'])['id'])->update(['remarks' => 'Withdrawn']);

        $page = $this->page();
        $tags = array_column($page['courses'], 'tag', 'offering_id');

        $this->assertSame('INC', $tags[$inc['offering']]);
        $this->assertSame('Withdrawn', $tags[$withdrawn['offering']]);
        $this->assertSame([], array_filter($page['attention'], static fn (array $a): bool => $a['kind'] === 'at_risk'));
    }

    public function testAPastDueAssessmentIsMissingOnceAndAHandedInOneIsNot(): void
    {
        $course = $this->course('A');
        $this->assessment($course['offering'], $course['module'], 'Published', time() - 3 * 86400, 'Never handed in');
        $done = $this->assessment($course['offering'], $course['module'], 'Published', time() - 3 * 86400, 'Handed in late');
        $this->handIn($done);
        $this->assessment($course['offering'], $course['module'], 'Published', time() + 3 * 86400, 'Still to come');

        $missing = array_values(array_filter($this->page()['attention'], static fn (array $a): bool => $a['kind'] === 'missing'));

        $this->assertCount(1, $missing);
        $this->assertSame('danger', $missing[0]['tone']);
        $this->assertStringContainsString('Never handed in', $missing[0]['text']);
    }

    public function testAMissedAssessmentOfAPastCourseDoesNotCount(): void
    {
        $old = $this->course('A', 'completed', 'completed');
        $this->assessment($old['offering'], $old['module'], 'Published', time() - 3 * 86400, 'Old and missed');

        $this->assertSame([], array_filter($this->page()['attention'], static fn (array $a): bool => $a['kind'] === 'missing'));
    }

    public function testAnExcusedAssessmentIsNotMissing(): void
    {
        $course = $this->course('A');
        $excused = $this->assessment($course['offering'], $course['module'], 'Published', time() - 3 * 86400, 'Excused one');
        $item    = GradeFactory::item($course['scheme'], null, 'Excused one', 10, 2, $excused);
        GradeFactory::score($course['offering'], $this->child, $item, null, true);

        $this->assertSame([], array_filter($this->page()['attention'], static fn (array $a): bool => $a['kind'] === 'missing'));
    }

    public function testAFreshFinalGradeIsAnAttentionItemForFourteenDays(): void
    {
        $recent = $this->course('A');
        $old    = $this->course('B');
        $this->page(); // calculates the records

        db_connect()->table('gradebook_records')->where('id', $this->record($recent['offering'])['id'])->update(['remarks' => 'Passed', 'finalized_at' => date('Y-m-d H:i:s', time() - 2 * 86400)]);
        db_connect()->table('gradebook_records')->where('id', $this->record($old['offering'])['id'])->update(['remarks' => 'Passed', 'finalized_at' => date('Y-m-d H:i:s', time() - 20 * 86400)]);

        $page  = $this->page();
        $final = array_values(array_filter($page['attention'], static fn (array $a): bool => $a['kind'] === 'final'));

        $this->assertCount(1, $final);
        $this->assertSame($recent['offering'], $final[0]['offering_id']);
        $this->assertSame('success', $final[0]['tone']);
        $this->assertTrue(array_column($page['courses'], 'is_final', 'offering_id')[$old['offering']]);
    }

    public function testDeadlinesAreTheNextFourteenDaysSoonestFirstWithoutScores(): void
    {
        $course = $this->course('A');
        $this->assessment($course['offering'], $course['module'], 'Published', time() + 10 * 86400, 'Later');
        $this->assessment($course['offering'], $course['module'], 'Published', time() + 2 * 86400, 'Sooner');
        $this->assessment($course['offering'], $course['module'], 'Published', time() + 20 * 86400, 'Too far');
        $this->assessment($course['offering'], $course['module'], 'Published', time() - 1 * 86400, 'Already past');
        $this->assessment($course['offering'], $course['module'], 'Draft', time() + 3 * 86400, 'A draft');

        $deadlines = $this->page()['deadlines'];

        $this->assertSame(['Sooner', 'Later'], array_column($deadlines, 'title'));
        $this->assertSame(['title', 'course_code', 'due_ts', 'status'], array_keys($deadlines[0]));
        $this->assertIsString($deadlines[0]['status']);
    }

    public function testAChildWithNoCoursesGivesEmptyLists(): void
    {
        $page = $this->page();

        $this->assertSame([], $page['courses']);
        $this->assertSame([], $page['past']);
        $this->assertSame([], $page['attention']);
        $this->assertSame([], $page['deadlines']);
    }

    public function testAChildWhoIsAlsoStaffGetsTheirCoursesButNoGradeOrAssessmentData(): void
    {
        $this->child->addGroup('chairperson');
        $course = $this->course('A', 'enrolled', 'active', 5.0);
        $this->assessment($course['offering'], $course['module'], 'Published', time() - 3 * 86400, 'Would be missing');

        $page = $this->page();

        $this->assertCount(1, $page['courses']);
        $this->assertNull($page['courses'][0]['percentage']);
        $this->assertSame('Incomplete', $page['courses'][0]['tag']);
        $this->assertSame([], $page['attention']);
        $this->assertSame([], $page['deadlines']);
    }

    public function testLookingAtAChildNeverChangesTheParentsActiveRole(): void
    {
        $this->child->addGroup('dean');
        $this->course('A');
        $_SESSION['active_role'] = 'parent';

        $this->page();

        $this->assertSame('parent', $_SESSION['active_role']);
        $this->assertNull(db_connect()->table('user_profiles')->where('user_id', $this->child->id)->get()->getRowArray()['active_role'] ?? null);
    }

    public function testAnUnreachableChildGivesNull(): void
    {
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $other    = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);

        $this->assertNull($this->service->forChild($this->parent, (int) $stranger->id));
        $this->assertNull($this->service->forChild($other, (int) $this->child->id));
        $this->assertNull($this->service->forChild($this->parent, 0));
    }
}
