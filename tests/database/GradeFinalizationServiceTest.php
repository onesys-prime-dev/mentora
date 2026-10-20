<?php

declare(strict_types=1);

use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Courses\Grades\GradingEngineService;
use App\Services\Courses\Grades\GradingSchemeService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Finalizing a section's grades: what blocks it, missing work counting as 0,
 * the frozen final grade and tag, the lock on everything that could move it,
 * and reopening with a reason.
 *
 * @internal
 */
final class GradeFinalizationServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradeFinalizationService $service;

    private GradingEngineService $engine;

    private int $offering;

    private int $scheme;

    private int $quiz;

    private int $exam;

    private User $instructor;

    private User $strong;

    private User $weak;

    private User $none;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GradeFinalizationService();
        $this->engine = new GradingEngineService();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $this->scheme = GradeFactory::scheme($this->offering);
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $quizzes = GradeFactory::category($this->scheme, 'Quizzes', 40, null, 1);
        $exams = GradeFactory::category($this->scheme, 'Exams', 60, null, 2);
        $this->quiz = GradeFactory::item($this->scheme, $quizzes, 'Quiz 1', 20);
        $this->exam = GradeFactory::item($this->scheme, $exams, 'Midterm', 100);

        $this->strong = CourseFactory::user('student');
        $this->weak = CourseFactory::user('student');
        $this->none = CourseFactory::user('student');

        foreach ([$this->strong, $this->weak, $this->none] as $student) {
            CourseFactory::enroll($this->offering, $student);
        }

        GradeFactory::score($this->offering, $this->strong, $this->quiz, 18);
        GradeFactory::score($this->offering, $this->strong, $this->exam, 70);
        GradeFactory::score($this->offering, $this->weak, $this->quiz, 10);
        // The weak student never took the midterm; the third has no work at all.
        $this->engine->recalculateOffering($this->offering);
    }

    private function record(User $student): array
    {
        return db_connect()->table('gradebook_records')
            ->where('course_offering_id', $this->offering)->where('student_id', $student->id)
            ->get()->getRowArray();
    }

    private function finalize(): array
    {
        return $this->service->finalize($this->instructor, $this->offering);
    }

    /*
    |--------------------------------------------------------------------------
    | Readiness
    |--------------------------------------------------------------------------
    */

    public function testAReadyGradebookCanBeFinalizedAndSaysWhatWillCountAsZero(): void
    {
        $readiness = $this->service->readiness($this->offering);

        $this->assertTrue($readiness['can_finalize']);
        $this->assertSame([], $readiness['blockers']);
        $this->assertSame(3, $readiness['counts']['students']);
        $this->assertSame(3, $readiness['counts']['missing_scores'], 'weak: exam; none: quiz and exam; strong has both');
        $this->assertStringContainsString('count as 0', $readiness['notes'][0]);
    }

    public function testADraftSchemeBlocksFinalizing(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Draft']);

        $readiness = $this->service->readiness($this->offering);

        $this->assertFalse($readiness['can_finalize']);
        $this->assertStringContainsString('Publish it first', $readiness['blockers'][0]);
        $this->assertFalse($this->finalize()['success']);
    }

    public function testWeightsThatDoNotAddUpBlockFinalizing(): void
    {
        db_connect()->table('grade_categories')->where('grading_scheme_id', $this->scheme)->where('name', 'Exams')->update(['weight_percentage' => 50]);

        $this->assertFalse($this->service->readiness($this->offering)['can_finalize']);
    }

    public function testHandedInWorkStillWaitingToBeGradedBlocksFinalizing(): void
    {
        $assessment = CourseFactory::assessment($this->offering, $this->instructor, CourseFactory::module($this->offering));
        db_connect()->table('assessments')->where('id', $assessment)->update(['status' => 'Published']);
        AssessmentFactory::attempt($assessment, $this->strong, 'Submitted');

        $readiness = $this->service->readiness($this->offering);

        $this->assertFalse($readiness['can_finalize']);
        $this->assertSame(1, $readiness['counts']['ungraded_work']);
        $this->assertStringContainsString('waiting to be graded', implode(' ', $readiness['blockers']));
    }

    public function testAnOpenOverrideRequestBlocksFinalizing(): void
    {
        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $this->offering, 'student_id' => $this->weak->id, 'requested_percentage' => 80,
            'reason' => 'Appeal', 'requested_by' => $this->instructor->id, 'requester_role' => 'instructor',
            'status' => 'awaiting_dean', 'open_marker' => 1,
        ]);

        $readiness = $this->service->readiness($this->offering);

        $this->assertFalse($readiness['can_finalize']);
        $this->assertSame(1, $readiness['counts']['open_requests']);
    }

    /*
    |--------------------------------------------------------------------------
    | Finalizing
    |--------------------------------------------------------------------------
    */

    public function testFinalGradesCountMissingWorkAsZeroAndSetThePassOrFailTag(): void
    {
        $result = $this->finalize();

        $this->assertTrue($result['success'], $result['message'] ?? '');

        $strong = $this->record($this->strong);
        $this->assertEquals(78.0, (float) $strong['final_percentage']);
        $this->assertSame('Passed', $strong['remarks']);
        $this->assertNotNull($strong['finalized_at']);

        // 10/20 quiz at 40% + no midterm (0/100) at 60% = 20%
        $weak = $this->record($this->weak);
        $this->assertEquals(20.0, (float) $weak['final_percentage']);
        $this->assertSame('Failed', $weak['remarks']);

        $none = $this->record($this->none);
        $this->assertEquals(0.0, (float) $none['final_percentage']);
        $this->assertSame('Failed', $none['remarks']);
    }

    public function testFinalizingTwiceIsRefusedAndOneFinalizationIsKept(): void
    {
        $this->finalize();

        $this->assertFalse($this->finalize()['success']);
        $this->assertSame(1, db_connect()->table('grade_finalizations')->where('course_offering_id', $this->offering)->countAllResults());
        $this->assertTrue($this->service->isFinalized($this->offering));
        $this->assertSame($this->instructor->id, (int) $this->service->current($this->offering)['finalized_by']);
    }

    public function testAnIncompleteStaysIncompleteWhenTheGradesAreFinalized(): void
    {
        $this->engine->markIncomplete($this->instructor, $this->offering, $this->weak->id, 'Medical leave');

        $this->finalize();

        $this->assertSame('INC', $this->record($this->weak)['remarks']);
    }

    public function testAnOverrideStillDecidesTheFinalTag(): void
    {
        $this->engine->overrideFinalGrade($this->instructor, $this->offering, $this->weak->id, 90.0, 'Re-evaluated');

        $this->finalize();

        $weak = $this->record($this->weak);
        $this->assertSame('Passed', $weak['remarks']);
        $this->assertEquals(90.0, GradebookRecordModel::effectivePercentage($weak));
    }

    public function testTheFinalGradeIsFrozenWhileTheLiveCalculationKeepsMoving(): void
    {
        $this->finalize();

        GradeFactory::score($this->offering, $this->weak, $this->exam, 100);
        $this->engine->recalculateStudent($this->offering, $this->weak->id);

        $weak = $this->record($this->weak);
        $this->assertEquals(20.0, GradebookRecordModel::effectivePercentage($weak), 'the final grade did not move');
        $this->assertGreaterThan(20.0, (float) $weak['calculated_percentage'], 'the running calculation did');
        $this->assertSame('Failed', $weak['remarks']);
    }

    /*
    |--------------------------------------------------------------------------
    | The lock
    |--------------------------------------------------------------------------
    */

    public function testEverythingThatCouldMoveAGradeIsRefusedWhileFinalized(): void
    {
        $schemes = new GradingSchemeService();
        $manual = (int) $schemes->createManualItem($this->instructor, $this->offering, (int) db_connect()->table('grade_categories')->where('grading_scheme_id', $this->scheme)->where('name', 'Quizzes')->get()->getRowArray()['id'], ['name' => 'Attendance', 'max_points' => 10])['item']['id'];

        $this->finalize();

        $refusals = [
            $schemes->updateManualScore($this->instructor, $this->offering, $manual, $this->strong->id, 5),
            $schemes->updateScheme($this->instructor, $this->offering, ['passing_percentage' => 60]),
            $schemes->createCategory($this->offering, ['name' => 'Projects', 'weight_percentage' => 10]),
            $schemes->updateItem($this->instructor, $this->offering, $manual, ['max_points' => 20]),
            $schemes->deleteItem($this->instructor, $this->offering, $manual),
            $schemes->recalculate($this->offering),
        ];

        foreach ($refusals as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $result['message']);
        }
    }

    public function testAFinalizedGradeCanStillBeOverriddenAndIncompleteStillSet(): void
    {
        $this->finalize();

        $this->assertTrue($this->engine->overrideFinalGrade($this->instructor, $this->offering, $this->weak->id, 80.0, 'Appeal granted')['success']);
        $this->assertTrue($this->engine->markIncomplete($this->instructor, $this->offering, $this->none->id, 'Illness')['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Reopening
    |--------------------------------------------------------------------------
    */

    public function testReopeningNeedsAReasonAndAFinalizedSection(): void
    {
        $this->assertFalse($this->service->reopen($this->instructor, $this->offering, 'Why')['success'], 'nothing to reopen yet');

        $this->finalize();

        $this->assertFalse($this->service->reopen($this->instructor, $this->offering, '   ')['success']);
        $this->assertTrue($this->service->isFinalized($this->offering));
    }

    public function testReopeningLiftsTheLockAndBringsBackRunningGrades(): void
    {
        $this->finalize();

        $result = $this->service->reopen($this->instructor, $this->offering, 'A score was entered wrongly');

        $this->assertTrue($result['success']);
        $this->assertFalse($this->service->isFinalized($this->offering));

        $weak = $this->record($this->weak);
        $this->assertNull($weak['finalized_at']);
        $this->assertNull($weak['final_percentage']);
        $this->assertEquals(50.0, GradebookRecordModel::effectivePercentage($weak), 'the running grade (over graded work) is back');

        $history = db_connect()->table('grade_finalizations')->where('course_offering_id', $this->offering)->get()->getRowArray();
        $this->assertSame('A score was entered wrongly', $history['reopen_reason']);
        $this->assertSame($this->instructor->id, (int) $history['reopened_by']);
        $this->assertNull($history['open_marker']);

        $this->assertNull($this->service->lockRefusal($this->offering));
    }

    public function testASectionCanBeFinalizedAgainAfterAReopen(): void
    {
        $this->finalize();
        $this->service->reopen($this->instructor, $this->offering, 'Fixing a score');
        GradeFactory::score($this->offering, $this->weak, $this->exam, 100);
        $this->engine->recalculateOffering($this->offering);

        $this->assertTrue($this->finalize()['success']);

        $this->assertEquals(80.0, (float) $this->record($this->weak)['final_percentage'], '50% at weight 40 + 100% at weight 60');
        $this->assertSame(2, db_connect()->table('grade_finalizations')->where('course_offering_id', $this->offering)->countAllResults(), 'the first one stays as history');
    }

    public function testEveryStepIsInTheAuditLog(): void
    {
        $this->finalize();
        $this->service->reopen($this->instructor, $this->offering, 'Fixing a score');

        foreach (['grades.finalized', 'grades.reopened'] as $action) {
            $this->assertSame(1, db_connect()->table('audit_logs')->where('action', $action)->where('target_id', (string) $this->offering)->countAllResults(), $action);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hand-off to the student information system
    |--------------------------------------------------------------------------
    */

    public function testASectionNotSentYetHasNoHandoff(): void
    {
        $this->finalize();

        $this->assertNull($this->service->handoff($this->offering));
    }

    public function testTheHandoffSaysHowManyWereSentAndWhenTheLastWent(): void
    {
        $this->finalize();

        $institution = (int) db_connect()->table('course_offerings o')
            ->select('d.institution_id AS id')->join('courses c', 'c.id = o.course_id')->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')->join('departments d', 'd.id = sd.department_id')
            ->where('o.id', $this->offering)->get()->getRowArray()['id'];

        foreach ([[$this->strong, '2026-10-20 08:00:00'], [$this->weak, '2026-10-21 09:30:00']] as [$student, $at]) {
            db_connect()->table('sis_grade_exports')->insert([
                'institution_id' => $institution, 'offering_id' => $this->offering, 'student_id' => $student->id,
                'percentage' => 70, 'remarks' => 'Passed', 'batch_key' => 'g1-x', 'sent_at' => $at,
            ]);
        }

        $handoff = $this->service->handoff($this->offering);

        $this->assertSame(2, $handoff['students']);
        $this->assertSame('2026-10-21 09:30:00', $handoff['last_sent_at']);
    }

    public function testReopeningGradesAlreadySentTellsTheInstructorWhatHappensNext(): void
    {
        $this->finalize();

        $institution = (int) db_connect()->table('course_offerings o')
            ->select('d.institution_id AS id')->join('courses c', 'c.id = o.course_id')->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')->join('departments d', 'd.id = sd.department_id')
            ->where('o.id', $this->offering)->get()->getRowArray()['id'];

        db_connect()->table('sis_grade_exports')->insert([
            'institution_id' => $institution, 'offering_id' => $this->offering, 'student_id' => $this->strong->id,
            'percentage' => 78, 'remarks' => 'Passed', 'batch_key' => 'g1-x', 'sent_at' => '2026-10-20 08:00:00',
        ]);

        $result = $this->service->reopen($this->instructor, $this->offering, 'A score was wrong');

        $this->assertStringContainsString('already sent to the SIS', $result['message']);
        $this->assertStringContainsString('only what changed is sent', $result['message']);
    }

    public function testReopeningGradesNeverSentSaysNothingAboutTheSis(): void
    {
        $this->finalize();

        $this->assertStringNotContainsString('SIS', $this->service->reopen($this->instructor, $this->offering, 'A score was wrong')['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Excused
    |--------------------------------------------------------------------------
    */

    public function testAnExcusedItemIsLeftOutOfAFinalGradeInsteadOfCountingAsZero(): void
    {
        // The weak student is excused from the midterm they never took: only the quiz counts.
        GradeFactory::score($this->offering, $this->weak, $this->exam, null, true);

        $this->finalize();

        $this->assertEquals(50.0, (float) $this->record($this->weak)['final_percentage'], '10 of 20 on the quiz alone');
        $this->assertEquals(0.0, (float) $this->record($this->none)['final_percentage'], 'not excused: both count as zero');
    }

    public function testAnExcusedItemIsNotCountedAsMissingWorkInTheChecklist(): void
    {
        GradeFactory::score($this->offering, $this->weak, $this->exam, null, true);

        $this->assertSame(2, $this->service->readiness($this->offering)['counts']['missing_scores'], 'only the third student\'s two');
    }

    public function testExcusingAndUnexcusingAScoreThroughTheGradebook(): void
    {
        $schemes = new GradingSchemeService();
        $manual = (int) $schemes->createManualItem($this->instructor, $this->offering, (int) db_connect()->table('grade_categories')->where('grading_scheme_id', $this->scheme)->where('name', 'Quizzes')->get()->getRowArray()['id'], ['name' => 'Attendance', 'max_points' => 10])['item']['id'];

        $this->assertTrue($schemes->updateManualScore($this->instructor, $this->offering, $manual, $this->weak->id, null, true)['success']);

        $row = db_connect()->table('gradebook_item_scores')->where('grade_item_id', $manual)->where('student_id', $this->weak->id)->get()->getRowArray();
        $this->assertSame(1, (int) $row['is_missing']);
        $this->assertNull($row['raw_score']);

        $this->assertTrue($schemes->updateManualScore($this->instructor, $this->offering, $manual, $this->weak->id, 7)['success']);

        $row = db_connect()->table('gradebook_item_scores')->where('grade_item_id', $manual)->where('student_id', $this->weak->id)->get()->getRowArray();
        $this->assertSame(0, (int) $row['is_missing'], 'a score replaces the excuse');
        $this->assertEquals(7.0, (float) $row['raw_score']);

        $audit = db_connect()->table('audit_logs')->where('action', 'grades.manual_score')->orderBy('id')->get()->getResultArray();
        $this->assertTrue(json_decode($audit[0]['new_values'], true)['excused']);
        $this->assertFalse(json_decode($audit[1]['new_values'], true)['excused']);
    }

    /*
    |--------------------------------------------------------------------------
    | Names on screen
    |--------------------------------------------------------------------------
    */

    public function testBeforeFinalizingTheStandingReadsOnTrackOrAtRisk(): void
    {
        $this->assertSame('On track', GradingEngineService::displayTag('Passed', false));
        $this->assertSame('At risk', GradingEngineService::displayTag('Failed', false));
        $this->assertSame('INC', GradingEngineService::displayTag('INC', false));
        $this->assertSame('Withdrawn', GradingEngineService::displayTag('Withdrawn', false));
        $this->assertSame('Passed', GradingEngineService::displayTag('Passed', true));
        $this->assertSame('Failed', GradingEngineService::displayTag('Failed', true));
    }
}
