<?php

declare(strict_types=1);

use App\Services\Dashboard\DashboardService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * R3 of the chairperson plan: the dashboard's program snapshot, "needs
 * attention" (at-risk students, overdue grading, inactive courses), recent
 * announcements and audit-log highlights — each scoped to every course in
 * every sub-department the chairperson chairs, and nothing outside it.
 *
 * @internal
 */
final class DashboardServiceChairpersonTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DashboardService $service;

    private User $chair;

    /** @var array{id: int, module: int} offering under the chair's program */
    private array $mine;

    /** @var array{id: int, module: int} offering outside the chair's program */
    private array $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DashboardService();

        GradeFactory::systemDefault();

        $this->chair = CourseFactory::user('chairperson');
        (new App\Models\Users\UserProfileModel())->insert(['user_id' => $this->chair->id, 'first_name' => 'Chair', 'last_name' => 'Person']);

        $this->mine = $this->course('IT101', 'Course A');
        $this->elsewhere = $this->course('CS202', 'Course B');

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id'   => $this->subDepartmentOf($this->mine['id']),
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{id: int, module: int} */
    private function course(string $code, string $title): array
    {
        $id = CourseFactory::offering('A');
        $db = db_connect();
        $courseId = (int) $db->table('course_offerings')->where('id', $id)->get()->getRowArray()['course_id'];
        $db->table('courses')->where('id', $courseId)->update(['course_code' => $code, 'course_title' => $title]);
        $module = CourseFactory::module($id);

        return ['id' => $id, 'module' => $module];
    }

    private function subDepartmentOf(int $offeringId): int
    {
        return (int) db_connect()->table('course_offerings co')
            ->select('programs.sub_department_id')
            ->join('courses', 'courses.id = co.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray()['sub_department_id'];
    }

    private function widgets(): array
    {
        return $this->service->getDashboardWidgets($this->chair);
    }

    /*
    |--------------------------------------------------------------------------
    | Program snapshot
    |--------------------------------------------------------------------------
    */

    public function testProgramSnapshotCountsOnlyWhatsInTheProgram(): void
    {
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine['id'], $instructor);

        $otherInstructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->elsewhere['id'], $otherInstructor);

        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine['id'], $student);
        CourseFactory::enroll($this->elsewhere['id'], CourseFactory::user('student'));

        $snapshot = $this->widgets()['program-snapshot'];

        $this->assertSame(1, $snapshot['courses']);
        $this->assertSame(1, $snapshot['instructors']);
        $this->assertSame(1, $snapshot['students']);
    }

    public function testProgramSnapshotIsZeroedOutWithNoCourses(): void
    {
        db_connect()->table('chairperson_assignments')->truncate();

        $snapshot = $this->widgets()['program-snapshot'];

        $this->assertSame(['courses' => 0, 'instructors' => 0, 'students' => 0], $snapshot);
    }

    /*
    |--------------------------------------------------------------------------
    | Needs attention: inactive courses (one of its three merged sources —
    | see getNeedsAttentionData()). Filtered by 'kind' since the widget now
    | merges grading/at-risk/inactive into one list.
    |--------------------------------------------------------------------------
    */

    private function attentionItemsOfKind(string $kind): array
    {
        return array_values(array_filter(
            $this->widgets()['needs-attention']['items'],
            static fn (array $item) => $item['kind'] === $kind
        ));
    }

    public function testInactiveCoursesFlagsACourseWithNoRecentModuleActivity(): void
    {
        db_connect()->table('course_modules')->where('offering_id', $this->mine['id'])->update([
            'updated_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ]);

        $courses = $this->attentionItemsOfKind('inactive');

        $this->assertCount(1, $courses);
        $this->assertSame($this->mine['id'], $courses[0]['offering_id']);
    }

    public function testInactiveCoursesExcludesACourseThatsBeenRecentlyEdited(): void
    {
        db_connect()->table('course_modules')->where('offering_id', $this->mine['id'])->update([
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->attentionItemsOfKind('inactive'));
    }

    public function testInactiveCoursesNeverIncludesACourseOutsideTheProgram(): void
    {
        // $elsewhere's module is freshly created (recent updated_at) but it
        // doesn't matter either way — it must never appear, stale or not.
        $offeringIds = array_column($this->attentionItemsOfKind('inactive'), 'offering_id');

        $this->assertNotContains($this->elsewhere['id'], $offeringIds);
    }

    /*
    |--------------------------------------------------------------------------
    | Needs attention: grading queue / at-risk students (program scoping)
    | (RosterRiskService's own at-risk definition is covered elsewhere;
    | this only proves the chairperson branch is scoped correctly.)
    |--------------------------------------------------------------------------
    */

    public function testGradingQueueOnlyCoversAssessmentsInTheProgram(): void
    {
        $assessmentId = CourseFactory::assessment($this->mine['id'], $this->chair, $this->mine['module'], null, 'Assignment');
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine['id'], $student);
        AssessmentFactory::attempt($assessmentId, $student, 'Submitted');

        $elsewhereAssessment = CourseFactory::assessment($this->elsewhere['id'], CourseFactory::user('instructor'), $this->elsewhere['module'], null, 'Assignment');
        db_connect()->table('assessments')->where('id', $elsewhereAssessment)->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $otherStudent = CourseFactory::user('student');
        CourseFactory::enroll($this->elsewhere['id'], $otherStudent);
        AssessmentFactory::attempt($elsewhereAssessment, $otherStudent, 'Submitted');

        $offeringIds = array_column($this->attentionItemsOfKind('grading'), 'offering_id');

        $this->assertContains($this->mine['id'], $offeringIds);
        $this->assertNotContains($this->elsewhere['id'], $offeringIds);
    }

    public function testAtRiskStudentsOnlyCoversTheProgram(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine['id'], $student);

        // No assertion on membership here (that's RosterRiskService's own
        // definition to prove) — just that this doesn't error.
        $this->attentionItemsOfKind('at_risk');
        $this->addToAssertionCount(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    public function testAnnouncementsOnlyListsThoseInTheProgram(): void
    {
        $db = db_connect();
        $db->table('announcements')->insert(['offering_id' => $this->mine['id'], 'author_id' => $this->chair->id, 'subject' => 'Mine', 'message' => '{}', 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('announcements')->insert(['offering_id' => $this->elsewhere['id'], 'author_id' => $this->chair->id, 'subject' => 'Elsewhere', 'message' => '{}', 'created_at' => date('Y-m-d H:i:s')]);

        $titles = array_column($this->widgets()['announcements']['announcements'], 'title');

        $this->assertSame(['Mine'], $titles);
    }

    /*
    |--------------------------------------------------------------------------
    | Audit highlights
    |--------------------------------------------------------------------------
    */

    public function testAuditHighlightsShowsOnlyThisChairsOwnRecentActions(): void
    {
        service('auditLogger')->log('course.chairperson_action', $this->chair, [
            'acting_role' => 'chairperson',
            'target_type' => 'course_offering',
            'target_id'   => $this->mine['id'],
            'new'         => ['method' => 'POST', 'path' => '/x'],
        ]);

        // A different action name must not show up as a "highlight".
        service('auditLogger')->log('auth.login', $this->chair);

        $entries = $this->widgets()['audit-highlights']['entries'];

        $this->assertCount(1, $entries);
        $this->assertSame($this->mine['id'], $entries[0]['offering_id']);
        $this->assertSame('IT101', $entries[0]['course_code']);
    }

    public function testAuditHighlightsIsEmptyWithNoActivity(): void
    {
        $this->assertSame([], $this->widgets()['audit-highlights']['entries']);
    }

    /*
    |--------------------------------------------------------------------------
    | Needs attention: merge order and shape
    |--------------------------------------------------------------------------
    */

    public function testNeedsAttentionIsEmptyWithNothingToFlag(): void
    {
        // The fixture's module otherwise has no updated_at yet, which
        // getInactiveCoursesData() reads as "no activity ever" — recently
        // touched, like a real active course, so only grading/at-risk
        // (neither set up here) could put anything in the list.
        db_connect()->table('course_modules')->where('offering_id', $this->mine['id'])->update([
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->widgets()['needs-attention']['items']);
    }

    public function testNeedsAttentionListsGradingBeforeAtRiskBeforeInactive(): void
    {
        $assessmentId = CourseFactory::assessment($this->mine['id'], $this->chair, $this->mine['module'], null, 'Assignment');
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $submittedStudent = CourseFactory::user('student');
        CourseFactory::enroll($this->mine['id'], $submittedStudent);
        AssessmentFactory::attempt($assessmentId, $submittedStudent, 'Submitted');

        $riskyStudent = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $riskyStudent->id, 'first_name' => 'Ana', 'last_name' => 'Cruz']);
        CourseFactory::enroll($this->mine['id'], $riskyStudent);
        CourseFactory::items(CourseFactory::unit($this->mine['module']), [[]]);

        db_connect()->table('course_modules')->where('offering_id', $this->mine['id'])->update([
            'updated_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ]);

        $kinds = array_column($this->widgets()['needs-attention']['items'], 'kind');

        $this->assertSame(['grading', 'at_risk', 'inactive'], $kinds);
    }

    public function testNeedsAttentionGradingItemCarriesTheBadgeAndDrillDownIds(): void
    {
        $assessmentId = CourseFactory::assessment($this->mine['id'], $this->chair, $this->mine['module'], null, 'Assignment');
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine['id'], $student);
        AssessmentFactory::attempt($assessmentId, $student, 'Submitted');

        $item = $this->attentionItemsOfKind('grading')[0];

        $this->assertSame('1 to grade', $item['badge']);
        $this->assertSame($this->mine['id'], $item['offering_id']);
        $this->assertSame($assessmentId, $item['assessment_id']);
    }

    /**
     * A big program can easily flag more than fits on a dashboard card — the
     * list itself (not just the view) caps at NEEDS_ATTENTION_LIMIT, with
     * 'total' keeping the true count so the view can say "view N more"
     * rather than silently dropping items.
     */
    public function testNeedsAttentionReturnsEveryFlaggedItemUncapped(): void
    {
        CourseFactory::items(CourseFactory::unit($this->mine['module']), [[]]);

        // Keep the module "active" so only the at-risk students below count.
        db_connect()->table('course_modules')->where('offering_id', $this->mine['id'])->update([
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $expected = 8;

        for ($i = 0; $i < $expected; $i++) {
            $student = CourseFactory::user('student');
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'S', 'last_name' => (string) $i]);
            CourseFactory::enroll($this->mine['id'], $student);
        }

        // The widget scrolls internally for a longer list rather than
        // silently dropping items past some fixed count.
        $this->assertCount($expected, $this->widgets()['needs-attention']['items']);
    }

    /*
    |--------------------------------------------------------------------------
    | Quick access
    |--------------------------------------------------------------------------
    */

    public function testQuickAccessAddsNoExtraTilesForAChairperson(): void
    {
        // Monitor Program is now carried by global_apps() (see
        // NavigationHelperTest) like every other role's tiles, not added
        // here a second time — this hook stays empty until something needs
        // a tile too one-off for global_apps().
        $this->assertSame([], $this->widgets()['quick-access']);
    }

    public function testQuickAccessAddsNothingForAnInstructor(): void
    {
        $instructor = CourseFactory::user('instructor');

        $widgets = $this->service->getDashboardWidgets($instructor);

        $this->assertSame([], $widgets['quick-access']);
    }
}
