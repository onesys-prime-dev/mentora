<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradebookSyncService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Copying the counted assessment score into the gradebook: which attempt
 * counts (best quiz attempt, latest graded assignment), clearing the row
 * when nothing counts any more, manual scores, and when a student's final
 * grade is refreshed (only on a published scheme that is not waiting on a
 * recalculation).
 *
 * @internal
 */
final class GradebookSyncServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradebookSyncService $sync;
    private int $offeringId;
    private int $schemeId;
    private User $student;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = new GradebookSyncService();
        $this->offeringId = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $this->student);
        $this->schemeId = GradeFactory::scheme($this->offeringId);
    }

    /** An assessment with a grade item of 20 points. @return array{0:int,1:int} assessment id, item id */
    private function gradedItem(string $type = 'Quiz', float $max = 20.0): array
    {
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, CourseFactory::module($this->offeringId), null, $type);
        $category = GradeFactory::category($this->schemeId, 'Work', 100);
        $itemId = GradeFactory::item($this->schemeId, $category, 'Item', $max, 1, $assessmentId);

        return [$assessmentId, $itemId];
    }

    private function attempt(int $assessmentId, float $score, string $status = 'Graded', bool $graded = true, ?User $student = null): int
    {
        $db = db_connect();
        $number = (int) $db->table('assessment_submissions')->where('assessment_id', $assessmentId)->countAllResults() + 1;

        $db->table('assessment_submissions')->insert([
            'assessment_id' => $assessmentId,
            'student_id' => ($student ?? $this->student)->id,
            'attempt_number' => $number,
            'score' => $score,
            'is_graded' => $graded ? 1 : 0,
            'status' => $status,
            'graded_by' => $graded ? $this->instructor->id : null,
            'graded_at' => $graded ? '2026-10-01 10:00:00' : null,
        ]);

        return (int) $db->insertID();
    }

    private function scoreRow(int $itemId, ?User $student = null): ?array
    {
        return db_connect()->table('gradebook_item_scores')
            ->where('grade_item_id', $itemId)
            ->where('student_id', ($student ?? $this->student)->id)
            ->get()->getRowArray();
    }

    public function testAQuizCountsTheBestGradedAttempt(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem('Quiz');
        $this->attempt($assessmentId, 12);
        $best = $this->attempt($assessmentId, 17);
        $this->attempt($assessmentId, 9);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $row = $this->scoreRow($itemId);
        $this->assertEquals(17, $row['raw_score']);
        $this->assertEquals(85.0, $row['raw_percentage']);
        $this->assertSame($best, (int) $row['assessment_submission_id']);
        $this->assertSame($this->offeringId, (int) $row['course_offering_id']);
        $this->assertSame(0, (int) $row['is_missing']);
    }

    public function testAnAssignmentCountsTheLatestGradedAttempt(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem('Assignment');
        $this->attempt($assessmentId, 18);
        $this->attempt($assessmentId, 11);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertEquals(11, $this->scoreRow($itemId)['raw_score']);
    }

    public function testAnUngradedAttemptIsNotCopied(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem();
        $this->attempt($assessmentId, 0, 'Submitted', false);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertNull($this->scoreRow($itemId));
    }

    public function testSyncingTwiceUpdatesTheSameRow(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem();
        $this->attempt($assessmentId, 10);
        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->attempt($assessmentId, 15);
        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertSame(1, db_connect()->table('gradebook_item_scores')->where('grade_item_id', $itemId)->countAllResults());
        $this->assertEquals(15, $this->scoreRow($itemId)['raw_score']);
    }

    public function testTheScoreIsRemovedWhenNothingCountsAnyMore(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem();
        $attemptId = $this->attempt($assessmentId, 14);
        $this->sync->syncItemScore($assessmentId, $this->student->id);
        $this->assertNotNull($this->scoreRow($itemId));

        // The grade is reopened.
        db_connect()->table('assessment_submissions')->where('id', $attemptId)->update(['is_graded' => 0, 'status' => 'Submitted']);
        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertNull($this->scoreRow($itemId));
    }

    public function testAnAssessmentWithoutAGradeItemIsIgnored(): void
    {
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, CourseFactory::module($this->offeringId));
        $this->attempt($assessmentId, 10);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertSame(0, db_connect()->table('gradebook_item_scores')->countAllResults());
    }

    public function testAnArchivedItemIsIgnored(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem();
        db_connect()->table('grade_items')->where('id', $itemId)->update(['status' => 'Archived']);
        $this->attempt($assessmentId, 10);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertNull($this->scoreRow($itemId));
    }

    public function testAZeroPointItemHasNoPercentage(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem('Quiz', 0.0);
        $this->attempt($assessmentId, 5);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $row = $this->scoreRow($itemId);
        $this->assertEquals(5, $row['raw_score']);
        $this->assertNull($row['raw_percentage']);
    }

    /*
    |--------------------------------------------------------------------------
    | When the final grade is refreshed
    |--------------------------------------------------------------------------
    */

    private function finalGradeRows(): int
    {
        return db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->countAllResults();
    }

    public function testAPublishedSchemeRefreshesTheStudentsFinalGrade(): void
    {
        [$assessmentId] = $this->gradedItem();
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update(['status' => 'Published', 'needs_recalculation' => 0]);
        $this->attempt($assessmentId, 20);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertSame(1, $this->finalGradeRows());
    }

    public function testASchemeWaitingOnARecalculationIsStillRefreshedPerStudent(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem();
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update(['status' => 'Published', 'needs_recalculation' => 1]);
        $this->attempt($assessmentId, 20);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertNotNull($this->scoreRow($itemId), 'the score is copied');
        $this->assertSame(1, $this->finalGradeRows(), 'and the final grade follows, so it never freezes');
    }

    public function testAnUnpublishedSchemeIsNotRefreshed(): void
    {
        [$assessmentId] = $this->gradedItem();
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update(['status' => 'Active', 'needs_recalculation' => 0]);
        $this->attempt($assessmentId, 20);

        $this->sync->syncItemScore($assessmentId, $this->student->id);

        $this->assertSame(0, $this->finalGradeRows());
    }

    /*
    |--------------------------------------------------------------------------
    | Whole assessment
    |--------------------------------------------------------------------------
    */

    public function testSyncAssessmentScoresWritesOneRowPerStudentWithCountedWork(): void
    {
        [$assessmentId, $itemId] = $this->gradedItem();
        $other = CourseFactory::user('student');
        $ungraded = CourseFactory::user('student');

        $this->attempt($assessmentId, 10);
        $this->attempt($assessmentId, 14);
        $this->attempt($assessmentId, 18, 'Graded', true, $other);
        $this->attempt($assessmentId, 0, 'Submitted', false, $ungraded);

        $written = $this->sync->syncAssessmentScores($assessmentId);

        $this->assertSame(2, $written);
        $this->assertEquals(14, $this->scoreRow($itemId)['raw_score']);
        $this->assertEquals(18, $this->scoreRow($itemId, $other)['raw_score']);
        $this->assertNull($this->scoreRow($itemId, $ungraded));
    }

    public function testSyncAssessmentScoresDoesNotRefreshFinalGrades(): void
    {
        [$assessmentId] = $this->gradedItem();
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update(['status' => 'Published', 'needs_recalculation' => 0]);
        $this->attempt($assessmentId, 20);

        $this->sync->syncAssessmentScores($assessmentId);

        $this->assertSame(0, $this->finalGradeRows());
    }

    public function testSyncAssessmentScoresWithoutAnItemWritesNothing(): void
    {
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, CourseFactory::module($this->offeringId));
        $this->attempt($assessmentId, 10);

        $this->assertSame(0, $this->sync->syncAssessmentScores($assessmentId));
    }

    /*
    |--------------------------------------------------------------------------
    | Manual scores
    |--------------------------------------------------------------------------
    */

    public function testAManualScoreIsStoredWithItsPercentage(): void
    {
        $category = GradeFactory::category($this->schemeId, 'Work', 100);
        $itemId = GradeFactory::item($this->schemeId, $category, 'Lab', 40);

        $this->sync->setManualScore($this->offeringId, $itemId, $this->student->id, 30.0, $this->instructor->id);

        $row = $this->scoreRow($itemId);
        $this->assertEquals(30, $row['raw_score']);
        $this->assertEquals(75.0, $row['raw_percentage']);
        $this->assertNull($row['assessment_submission_id']);
        $this->assertSame($this->instructor->id, (int) $row['graded_by']);
    }

    public function testAManualScoreOverwritesAndNullClearsIt(): void
    {
        $category = GradeFactory::category($this->schemeId, 'Work', 100);
        $itemId = GradeFactory::item($this->schemeId, $category, 'Lab', 40);

        $this->sync->setManualScore($this->offeringId, $itemId, $this->student->id, 30.0, $this->instructor->id);
        $this->sync->setManualScore($this->offeringId, $itemId, $this->student->id, 36.0, $this->instructor->id);

        $this->assertSame(1, db_connect()->table('gradebook_item_scores')->where('grade_item_id', $itemId)->countAllResults());
        $this->assertEquals(36, $this->scoreRow($itemId)['raw_score']);

        $this->sync->setManualScore($this->offeringId, $itemId, $this->student->id, null, $this->instructor->id);

        $this->assertNull($this->scoreRow($itemId));
    }
}
