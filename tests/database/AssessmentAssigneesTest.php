<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * "Specific students": an assessment can be given to chosen students of its section only. Once that is on, a
 * student who is not chosen does not see it (not even by link), and the chosen set is kept by the server, limited
 * to students really enrolled in the section.
 *
 * @internal
 */
final class AssessmentAssigneesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentService $service;

    private int $offering;

    private int $assessment;

    private User $instructor;

    private User $ada;

    private User $bob;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new AssessmentService();
        $this->offering   = CourseFactory::offering();
        $module           = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->ada      = CourseFactory::user();
        $this->bob      = CourseFactory::user();
        $this->outsider = CourseFactory::user();
        CourseFactory::enroll($this->offering, $this->ada);
        CourseFactory::enroll($this->offering, $this->bob);

        $this->assessment = CourseFactory::assessment($this->offering, $this->instructor, $module, null, 'Assignment');
        db_connect()->table('assessments')->where('id', $this->assessment)->update(['status' => 'Published']);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->assessment, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function specificOnly(bool $on = true): void
    {
        db_connect()->table('assessment_settings')->where('assessment_id', $this->assessment)->update(['assign_to_specific_students' => $on ? 1 : 0]);
    }

    private function sees(User $student): bool
    {
        $row = db_connect()->table('assessments')->where('id', $this->assessment)->get()->getRowArray();

        return isset($this->service->studentVisibleIds([$row], (int) $student->id)[$this->assessment]);
    }

    public function testEveryEnrolledStudentSeesItWhileSpecificStudentsIsOff(): void
    {
        $this->assertTrue($this->sees($this->ada));
        $this->assertTrue($this->sees($this->bob));
    }

    public function testOnlyTheChosenStudentsSeeItOnceSpecificStudentsIsOn(): void
    {
        $this->specificOnly();
        $this->assertTrue($this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id])['success']);

        $this->assertTrue($this->sees($this->ada));
        $this->assertFalse($this->sees($this->bob));
    }

    public function testNobodySeesItWhenItIsOnAndNobodyIsChosen(): void
    {
        $this->specificOnly();

        $this->assertFalse($this->sees($this->ada));
    }

    public function testTheChosenSetIsReplacedNotAddedTo(): void
    {
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id, (int) $this->bob->id]);
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->bob->id]);

        $this->assertSame([(int) $this->bob->id], $this->service->assigneeIds($this->assessment));
        $this->assertSame(1, db_connect()->table('assessment_assignees')->where('assessment_id', $this->assessment)->countAllResults(), 'the old rows are gone, not kept beside');
    }

    public function testAStudentNotEnrolledInTheSectionCannotBeChosen(): void
    {
        $result = $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id, (int) $this->outsider->id]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->service->assigneeIds($this->assessment), 'nothing is saved when one is refused');
    }

    public function testADroppedStudentCannotBeChosen(): void
    {
        $dropped = CourseFactory::user();
        CourseFactory::enroll($this->offering, $dropped, 'dropped');

        $this->assertFalse($this->service->setAssignees($this->instructor, $this->assessment, [(int) $dropped->id])['success']);
    }

    public function testDuplicateAndJunkIdsAreHandled(): void
    {
        $result = $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id, (int) $this->ada->id, 0, -3]);

        $this->assertTrue($result['success']);
        $this->assertSame([(int) $this->ada->id], $this->service->assigneeIds($this->assessment));
    }

    public function testAnUnknownAssessmentIsRefused(): void
    {
        $this->assertFalse($this->service->setAssignees($this->instructor, 999999, [(int) $this->ada->id])['success']);
    }

    public function testTheRosterListsTheSectionsStudentsAndWhoIsChosen(): void
    {
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->bob->id]);

        $roster = $this->service->assignmentRoster($this->assessment);
        $byId   = array_column($roster, null, 'id');

        $this->assertCount(2, $roster, 'the outsider is not on it');
        $this->assertTrue($byId[(int) $this->bob->id]['selected']);
        $this->assertFalse($byId[(int) $this->ada->id]['selected']);
        $this->assertArrayHasKey('name', $roster[0]);
    }

    public function testChoosingStudentsDoesNotTurnTheSettingOn(): void
    {
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id]);

        $this->assertTrue($this->sees($this->bob), 'the toggle decides, the list is only who');
    }

    public function testIsAssignedToIsTrueForEveryoneUntilTheToggleIsOnThenOnlyForTheChosen(): void
    {
        $this->assertTrue($this->service->isAssignedTo($this->assessment, (int) $this->bob->id));

        $this->specificOnly();
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id]);

        $this->assertTrue($this->service->isAssignedTo($this->assessment, (int) $this->ada->id));
        $this->assertFalse($this->service->isAssignedTo($this->assessment, (int) $this->bob->id));
    }

    public function testAStudentWhoIsNotChosenCannotHandInTheAssignment(): void
    {
        $this->specificOnly();
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id]);

        $result = (new \App\Services\Courses\Assessments\AssignmentSubmissionService())->submit($this->bob, $this->assessment, ['submission_text' => 'my work']);

        $this->assertFalse($result['success']);
        $this->assertSame('Assignment not found.', $result['message']);
    }

    public function testTheCalendarShowsItOnlyToTheChosenStudents(): void
    {
        $this->specificOnly();
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id]);

        $titles = static fn (User $u): array => array_column(
            (new \App\Services\Calendar\CalendarEventService())->forRange($u, '2026-11-30', '2026-12-02')['events'],
            'title'
        );

        $title = db_connect()->table('assessments')->where('id', $this->assessment)->get()->getRowArray()['title'];

        $this->assertNotEmpty(array_filter($titles($this->ada), static fn ($t) => str_contains((string) $t, $title)));
        $this->assertEmpty(array_filter($titles($this->bob), static fn ($t) => str_contains((string) $t, $title)));
    }

    public function testTheGradingRosterListsOnlyTheChosenStudents(): void
    {
        foreach ([$this->ada, $this->bob] as $student) {
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => $student === $this->ada ? 'Zed' : 'Yan', 'last_name' => 'Tester', 'created_at' => date('Y-m-d H:i:s')]);
        }

        $this->specificOnly();
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id]);

        $html = (new \App\Services\Courses\Assessments\GradeWorkService())->gradeWork($this->instructor, $this->offering, $this->assessment)['html'];

        $this->assertStringContainsString('Zed Tester', $html);
        $this->assertStringNotContainsString('Yan Tester', $html);
    }

    public function testTheStudentsAssessmentsOverviewLeavesOutWhatIsNotTheirs(): void
    {
        $this->specificOnly();
        $this->service->setAssignees($this->instructor, $this->assessment, [(int) $this->ada->id]);

        $courseId = (int) db_connect()->table('assessments')->where('id', $this->assessment)->get()->getRowArray()['course_id'];
        $overview = new \App\Services\Courses\Assessments\AssessmentOverviewService();

        $titles = static fn (array $built): array => array_column($built['assessments'] ?? [], 'id');

        $this->assertContains($this->assessment, array_map('intval', $titles($overview->build($this->ada, $this->offering, $courseId))));
        $this->assertNotContains($this->assessment, array_map('intval', $titles($overview->build($this->bob, $this->offering, $courseId))));
    }
}
