<?php

declare(strict_types=1);

use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\Grades\GradeAdjustmentService;
use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Grading on a curve inside a section: the preview, applying and undoing,
 * that entered scores are never changed, and how overrides, excused work,
 * finalizing and the audit trail behave.
 *
 * @internal
 */
final class GradeAdjustmentServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradeAdjustmentService $service;

    private GradingEngineService $engine;

    private int $offering;

    private int $scheme;

    private int $exam;

    private int $quiz;

    private User $instructor;

    private User $ann;

    private User $ben;

    private User $cy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GradeAdjustmentService();
        $this->engine = new GradingEngineService();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $this->scheme = GradeFactory::scheme($this->offering, 75.0);
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $quizzes = GradeFactory::category($this->scheme, 'Quizzes', 40, null, 1);
        $exams = GradeFactory::category($this->scheme, 'Exams', 60, null, 2);
        $this->quiz = GradeFactory::item($this->scheme, $quizzes, 'Quiz 1', 20);
        $this->exam = GradeFactory::item($this->scheme, $exams, 'Midterm', 100);

        $this->ann = CourseFactory::user('student');
        $this->ben = CourseFactory::user('student');
        $this->cy = CourseFactory::user('student');

        foreach ([$this->ann, $this->ben, $this->cy] as $student) {
            CourseFactory::enroll($this->offering, $student);
        }

        // A hard midterm: 80, 60, 40 out of 100. Quiz: 16, 12, 8 out of 20.
        foreach ([[$this->ann, 80, 16], [$this->ben, 60, 12], [$this->cy, 40, 8]] as [$student, $exam, $quiz]) {
            GradeFactory::score($this->offering, $student, $this->exam, $exam);
            GradeFactory::score($this->offering, $student, $this->quiz, $quiz);
        }

        $this->engine->recalculateOffering($this->offering);
    }

    private function percentage(User $student): ?float
    {
        $record = db_connect()->table('gradebook_records')->where('course_offering_id', $this->offering)->where('student_id', $student->id)->get()->getRowArray();

        return GradebookRecordModel::effectivePercentage($record);
    }

    private function rawScore(User $student, int $item): float
    {
        return (float) db_connect()->table('gradebook_item_scores')->where('grade_item_id', $item)->where('student_id', $student->id)->get()->getRowArray()['raw_score'];
    }

    private function applyOnExam(string $method, array $input, string $note = ''): array
    {
        return $this->service->apply($this->instructor, $this->offering, 'item', $this->exam, $method, $input, $note);
    }

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    */

    public function testThePreviewShowsBeforeAndAfterAndChangesNothing(): void
    {
        $before = $this->percentage($this->cy);

        $preview = $this->service->preview($this->offering, 'item', $this->exam, 'add_points', ['points' => 10]);

        $this->assertTrue($preview['success']);
        $this->assertSame('Add 10 points for everyone', $preview['label']);
        $this->assertSame(3, $preview['summary']['students']);
        $this->assertSame(3, $preview['summary']['raised']);
        $this->assertSame(0, db_connect()->table('grade_adjustments')->countAllResults(), 'nothing saved');
        $this->assertSame($before, $this->percentage($this->cy), 'nobody\'s grade moved');

        $cy = array_values(array_filter($preview['rows'], fn (array $r): bool => $r['student_id'] === (int) $this->cy->id))[0];
        $this->assertSame(40.0, $cy['item_before']);
        $this->assertSame(50.0, $cy['item_after']);
        $this->assertGreaterThan($cy['final_before'], $cy['final_after']);
    }

    public function testThePreviewCountsWhoWouldPassAndWhoReachesFullMarks(): void
    {
        // The midterm's top score is 80; scaling gives 100 to Ann.
        $preview = $this->service->preview($this->offering, 'item', $this->exam, 'scale_to_max', []);

        $this->assertSame(1, $preview['summary']['at_full_marks']);
        $this->assertGreaterThanOrEqual($preview['summary']['passing_before'], $preview['summary']['passing_after']);
        $this->assertGreaterThan($preview['summary']['mean_before'], $preview['summary']['mean_after']);
    }

    public function testThePreviewRefusesWhatCannotBeCurved(): void
    {
        $this->assertFalse($this->service->preview($this->offering, 'item', 999999, 'add_points', ['points' => 5])['success']);
        $this->assertFalse($this->service->preview($this->offering, 'item', $this->exam, 'add_points', ['points' => 0])['success']);
        $this->assertFalse($this->service->preview($this->offering, 'galaxy', $this->exam, 'add_points', ['points' => 5])['success']);
        $this->assertFalse($this->service->preview($this->offering, 'item', $this->exam, 'magic', [])['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Applying
    |--------------------------------------------------------------------------
    */

    public function testACurveOnAnItemChangesTheGradesButNeverTheEnteredScores(): void
    {
        $before = $this->percentage($this->cy);

        $result = $this->applyOnExam('add_points', ['points' => 10], 'The midterm ran long');

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertGreaterThan($before, $this->percentage($this->cy), 'the grade followed the curve at once');
        $this->assertSame(40.0, $this->rawScore($this->cy, $this->exam), 'the entered score is untouched');
    }

    public function testTheMidtermCurveMovesTheWeightedFinalAsExpected(): void
    {
        $this->applyOnExam('add_points', ['points' => 10]);

        // Cy: quiz 8/20 = 40%, midterm now 50/100 = 50%: 0.4*40 + 0.6*50 = 46.
        $this->assertEquals(46.0, $this->percentage($this->cy));
    }

    public function testACurveOnTheFinalGradeAddsPercentagePoints(): void
    {
        $before = $this->percentage($this->ben);

        $this->assertTrue($this->service->apply($this->instructor, $this->offering, 'final', null, 'add_points', ['points' => 5])['success']);

        $this->assertEquals($before + 5, $this->percentage($this->ben));
    }

    public function testAFinalCurveNeverPassesFullMarks(): void
    {
        $this->service->apply($this->instructor, $this->offering, 'final', null, 'add_points', ['points' => 90]);

        $this->assertEquals(100.0, $this->percentage($this->ann));
    }

    public function testAnOverriddenGradeIsNeverCurved(): void
    {
        $this->engine->overrideFinalGrade($this->instructor, $this->offering, $this->cy->id, 61.0, 'Appeal');

        $this->service->apply($this->instructor, $this->offering, 'final', null, 'add_points', ['points' => 10]);

        $this->assertEquals(61.0, $this->percentage($this->cy), 'the override wins');
    }

    public function testExcusedWorkIsNotCurved(): void
    {
        GradeFactory::score($this->offering, $this->cy, $this->exam, null, true);
        $this->engine->recalculateOffering($this->offering);
        $before = $this->percentage($this->cy);

        $this->applyOnExam('add_points', ['points' => 10]);

        $this->assertEquals($before, $this->percentage($this->cy), 'only the quiz counts for Cy, and it was not curved');
    }

    public function testACurveIsKeptAsWorkedOutWhenLaterScoresChange(): void
    {
        $this->applyOnExam('scale_to_max', []);
        $row = db_connect()->table('grade_adjustments')->get()->getRowArray();
        $factor = json_decode($row['params'], true)['factor'];

        $this->assertEqualsWithDelta(100 / 80, $factor, 0.0001, 'the top score of 80 becomes 100');

        GradeFactory::score($this->offering, $this->ann, $this->exam, 95);
        $this->engine->recalculateOffering($this->offering);

        $again = json_decode(db_connect()->table('grade_adjustments')->get()->getRowArray()['params'], true)['factor'];
        $this->assertSame($factor, $again, 'the numbers were fixed when it was applied');
    }

    public function testANewCurveOnTheSameItemReplacesTheOldOne(): void
    {
        $this->applyOnExam('add_points', ['points' => 10]);
        $this->applyOnExam('add_points', ['points' => 5]);

        $live = $this->service->describe($this->offering)['live'];

        $this->assertCount(1, $live);
        $this->assertStringContainsString('Add 5 points', $live[0]['label']);
        $this->assertSame(2, db_connect()->table('grade_adjustments')->countAllResults(), 'the first stays as history');
        $this->assertEquals(0.4 * 40 + 0.6 * 45, $this->percentage($this->cy));
    }

    public function testTwoDifferentItemsCanEachHaveACurve(): void
    {
        $this->applyOnExam('add_points', ['points' => 10]);
        $this->assertTrue($this->service->apply($this->instructor, $this->offering, 'item', $this->quiz, 'add_points', ['points' => 2])['success']);

        $this->assertCount(2, $this->service->describe($this->offering)['live']);
    }

    /*
    |--------------------------------------------------------------------------
    | Undo
    |--------------------------------------------------------------------------
    */

    public function testUndoPutsEveryGradeBackAsItWas(): void
    {
        $before = $this->percentage($this->cy);
        $this->applyOnExam('add_points', ['points' => 10]);
        $id = (int) $this->service->describe($this->offering)['live'][0]['id'];

        $result = $this->service->undo($this->instructor, $this->offering, $id);

        $this->assertTrue($result['success']);
        $this->assertEquals($before, $this->percentage($this->cy));
        $this->assertSame([], $this->service->describe($this->offering)['live']);
        $this->assertFalse($this->service->undo($this->instructor, $this->offering, $id)['success'], 'already off');
    }

    public function testACurveOfAnotherSectionCannotBeUndone(): void
    {
        $this->applyOnExam('add_points', ['points' => 10]);
        $id = (int) $this->service->describe($this->offering)['live'][0]['id'];

        $this->assertFalse($this->service->undo($this->instructor, CourseFactory::offering('B'), $id)['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | The lock and the trail
    |--------------------------------------------------------------------------
    */

    public function testFinalizedGradesCannotBeCurvedUntilReopened(): void
    {
        $this->applyOnExam('add_points', ['points' => 10]);
        (new GradeFinalizationService())->finalize($this->instructor, $this->offering);

        $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $this->applyOnExam('add_points', ['points' => 3])['message']);

        $id = (int) $this->service->describe($this->offering)['live'][0]['id'];
        $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $this->service->undo($this->instructor, $this->offering, $id)['message']);
    }

    public function testApplyingAndUndoingAreInTheAuditLog(): void
    {
        $this->applyOnExam('add_points', ['points' => 10], 'Fair to everyone');
        $this->service->undo($this->instructor, $this->offering, (int) $this->service->describe($this->offering)['live'][0]['id']);

        $applied = db_connect()->table('audit_logs')->where('action', 'grades.curve_applied')->where('target_id', (string) $this->offering)->get()->getRowArray();
        $this->assertNotNull($applied);
        $new = json_decode($applied['new_values'], true);
        $this->assertSame('add_points', $new['method']);
        $this->assertSame('Fair to everyone', $new['note']);
        $this->assertSame(3, $new['students_raised']);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'grades.curve_undone')->where('target_id', (string) $this->offering)->countAllResults());
    }

    public function testADraftSchemeIsFlaggedInsteadOfRecalculated(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Draft']);
        $before = $this->percentage($this->cy);

        $this->applyOnExam('add_points', ['points' => 10]);

        $this->assertEquals($before, $this->percentage($this->cy), 'nobody sees a change until the grading is published');
        $this->assertSame(1, (int) db_connect()->table('grading_schemes')->where('id', $this->scheme)->get()->getRowArray()['needs_recalculation']);
    }

    public function testDescribeListsTheItemsAndWhatIsApplied(): void
    {
        $this->applyOnExam('add_points', ['points' => 10]);

        $described = $this->service->describe($this->offering);

        $this->assertEqualsCanonicalizing(['Quiz 1', 'Midterm'], array_column($described['items'], 'name'));
        $this->assertSame('Midterm', $described['live'][0]['target']);
        $this->assertSame('item', $described['live'][0]['scope']);
    }
}
