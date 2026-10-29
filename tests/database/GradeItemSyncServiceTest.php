<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeItemSyncService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Publishing an assessment makes it a grade item (once, in the category named
 * after its type), points are a snapshot that goes "out of sync" when the
 * assessment changes, and deleting an assessment archives its item.
 *
 * @internal
 */
final class GradeItemSyncServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradeItemSyncService $sync;
    private int $offeringId;
    private int $moduleId;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = new GradeItemSyncService();
        $this->offeringId = CourseFactory::offering();
        $this->moduleId = CourseFactory::module($this->offeringId);
        $this->instructor = CourseFactory::user('instructor');
    }

    private function assessment(string $type = 'Quiz', string $status = 'Published', float $points = 25.0): int
    {
        $id = CourseFactory::assessment($this->offeringId, $this->instructor, $this->moduleId, null, $type);

        db_connect()->table('assessments')->where('id', $id)->update(['status' => $status, 'total_points' => $points]);

        return $id;
    }

    private function itemFor(int $assessmentId): ?array
    {
        return db_connect()->table('grade_items')->where('assessment_id', $assessmentId)->get()->getRowArray();
    }

    private function scheme(): int
    {
        return GradeFactory::scheme($this->offeringId);
    }

    private function needsRecalculation(int $schemeId): bool
    {
        return (bool) db_connect()->table('grading_schemes')->where('id', $schemeId)->get()->getRowArray()['needs_recalculation'];
    }

    private function clearRecalculationFlag(int $schemeId): void
    {
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['needs_recalculation' => 0]);
    }

    public function testAPublishedAssessmentBecomesAGradeItemInTheMatchingCategory(): void
    {
        $schemeId = $this->scheme();
        $quizzes = GradeFactory::category($schemeId, 'Quizzes', 40);
        GradeFactory::category($schemeId, 'Exams', 60, null, 2);
        $assessmentId = $this->assessment('Quiz', 'Published', 25);

        $this->sync->syncFromAssessment($assessmentId);

        $item = $this->itemFor($assessmentId);
        $this->assertNotNull($item);
        $this->assertSame($schemeId, (int) $item['grading_scheme_id']);
        $this->assertSame($quizzes, (int) $item['category_id']);
        $this->assertEquals(25, $item['max_points']);
        $this->assertSame('Active', $item['status']);
    }

    public function testAClosedAssessmentCountsToo(): void
    {
        $this->scheme();
        $assessmentId = $this->assessment('Quiz', 'Closed');

        $this->sync->syncFromAssessment($assessmentId);

        $this->assertNotNull($this->itemFor($assessmentId));
    }

    public function testDraftAndArchivedAssessmentsAreNotGradeItems(): void
    {
        $this->scheme();
        $draft = $this->assessment('Quiz', 'Draft');
        $archived = $this->assessment('Quiz', 'Archived');

        $this->sync->syncFromAssessment($draft);
        $this->sync->syncFromAssessment($archived);

        $this->assertNull($this->itemFor($draft));
        $this->assertNull($this->itemFor($archived));
    }

    public function testAMissingAssessmentIsIgnored(): void
    {
        $this->sync->syncFromAssessment(999999);

        $this->assertSame(0, db_connect()->table('grade_items')->countAllResults());
    }

    public function testSyncingTwiceKeepsOneItem(): void
    {
        $this->scheme();
        $assessmentId = $this->assessment();

        $this->sync->syncFromAssessment($assessmentId);
        $this->sync->syncFromAssessment($assessmentId);

        $this->assertSame(1, db_connect()->table('grade_items')->where('assessment_id', $assessmentId)->countAllResults());
    }

    public function testAnExistingItemIsLeftAsTheInstructorSetIt(): void
    {
        $schemeId = $this->scheme();
        $assessmentId = $this->assessment('Quiz', 'Published', 25);
        $itemId = GradeFactory::item($schemeId, null, 'Renamed', 10, 1, $assessmentId);

        $this->sync->syncFromAssessment($assessmentId);

        $item = $this->itemFor($assessmentId);
        $this->assertSame($itemId, (int) $item['id']);
        $this->assertSame('Renamed', $item['name']);
        $this->assertEquals(10, $item['max_points']);
    }

    public function testAnAssessmentWithoutAModuleHasNoOffering(): void
    {
        $this->scheme();
        $assessmentId = $this->assessment();
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['module_id' => null]);

        $this->sync->syncFromAssessment($assessmentId);

        $this->assertNull($this->itemFor($assessmentId));
        $this->assertNull($this->sync->offeringOf(['module_id' => null]));
    }

    public function testOfferingOfFindsTheModulesOffering(): void
    {
        $this->assertSame($this->offeringId, $this->sync->offeringOf(['module_id' => $this->moduleId]));
    }

    public function testATypeWithoutAMatchingCategoryIsLeftUncategorized(): void
    {
        $schemeId = $this->scheme();
        GradeFactory::category($schemeId, 'Quizzes', 100);
        $assessmentId = $this->assessment('Assignment');

        $this->sync->syncFromAssessment($assessmentId);

        $this->assertNull($this->itemFor($assessmentId)['category_id']);
    }

    public function testCategoryNamesMatchSingularPluralAndCase(): void
    {
        $schemeId = $this->scheme();
        $assignments = GradeFactory::category($schemeId, ' ASSIGNMENTS ', 100);
        $assessmentId = $this->assessment('Assignment');

        $this->sync->syncFromAssessment($assessmentId);

        $this->assertSame($assignments, (int) $this->itemFor($assessmentId)['category_id']);
    }

    public function testNewItemsAreAppendedToTheEndOfTheSortOrder(): void
    {
        $schemeId = $this->scheme();
        GradeFactory::item($schemeId, null, 'Existing', 10, 4);
        $assessmentId = $this->assessment();

        $this->sync->syncFromAssessment($assessmentId);

        $this->assertSame(5, (int) $this->itemFor($assessmentId)['sort_order']);
    }

    public function testPublishingAssessmentWithGradedWorkFlagsARecalculation(): void
    {
        $schemeId = $this->scheme();
        $this->clearRecalculationFlag($schemeId);
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $student);
        $assessmentId = $this->assessment();
        db_connect()->table('assessment_submissions')->insert([
            'assessment_id' => $assessmentId, 'student_id' => $student->id, 'score' => 20,
            'is_graded' => 1, 'status' => 'Graded',
        ]);

        $this->sync->syncFromAssessment($assessmentId);

        $this->assertTrue($this->needsRecalculation($schemeId));
    }

    public function testPublishingAssessmentWithNoGradedWorkLeavesGradesAlone(): void
    {
        $schemeId = $this->scheme();
        $this->clearRecalculationFlag($schemeId);
        $assessmentId = $this->assessment();

        $this->sync->syncFromAssessment($assessmentId);

        $this->assertFalse($this->needsRecalculation($schemeId));
    }

    /** A published scheme with one counted quiz the student scored 15/20 on. @return array{0: int, 1: User} assessment id and student */
    private function countedQuiz(): array
    {
        $schemeId = $this->scheme();
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['status' => 'Published']);
        GradeFactory::category($schemeId, 'Quizzes', 100);
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $student);
        $assessmentId = $this->assessment('Quiz', 'Published', 20);

        $this->sync->syncFromAssessment($assessmentId);
        GradeFactory::score($this->offeringId, $student, (int) $this->itemFor($assessmentId)['id'], 15);
        (new \App\Services\Courses\Grades\GradingEngineService())->recalculateOffering($this->offeringId);

        return [$assessmentId, $student];
    }

    private function calculated(User $student): ?float
    {
        $row = db_connect()->table('gradebook_records')->where('student_id', $student->id)->get()->getRowArray();

        return $row['calculated_percentage'] === null ? null : (float) $row['calculated_percentage'];
    }

    public function testAnAssessmentThatIsNoLongerPublishedStopsCountingAndCountsAgainOnRepublish(): void
    {
        [$assessmentId, $student] = $this->countedQuiz();
        $this->assertEquals(75.0, $this->calculated($student));

        db_connect()->table('assessments')->where('id', $assessmentId)->update(['status' => 'Draft']);
        $this->sync->syncFromAssessment($assessmentId);

        $this->assertNull($this->calculated($student), 'a Draft quiz is not part of the grade');

        db_connect()->table('assessments')->where('id', $assessmentId)->update(['status' => 'Published']);
        $this->sync->syncFromAssessment($assessmentId);

        $this->assertEquals(75.0, $this->calculated($student));
    }

    public function testAnArchivedAssessmentStopsCounting(): void
    {
        [$assessmentId, $student] = $this->countedQuiz();

        db_connect()->table('assessments')->where('id', $assessmentId)->update(['status' => 'Archived']);
        $this->sync->assessmentStatusChanged($assessmentId);

        $this->assertNull($this->calculated($student));
    }

    public function testAStatusChangeOnADraftSchemeOnlyFlagsARecalculation(): void
    {
        $schemeId = $this->scheme();
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['status' => 'Draft', 'needs_recalculation' => 0]);
        $assessmentId = $this->assessment('Quiz', 'Published', 20);
        $this->sync->syncFromAssessment($assessmentId);
        $this->clearRecalculationFlag($schemeId);

        db_connect()->table('assessments')->where('id', $assessmentId)->update(['status' => 'Draft']);
        $this->sync->assessmentStatusChanged($assessmentId);

        $this->assertTrue($this->needsRecalculation($schemeId));
    }

    public function testAnOfferingWithoutASchemeGetsOneCopiedFromTheDefaultWhenAnAssessmentIsPublished(): void
    {
        GradeFactory::systemDefault();
        $assessmentId = $this->assessment('Quiz');

        $this->sync->syncFromAssessment($assessmentId);

        $scheme = db_connect()->table('grading_schemes')->where('course_offering_id', $this->offeringId)->get()->getRowArray();
        $this->assertNotNull($scheme);
        $item = $this->itemFor($assessmentId);
        $this->assertNotNull($item);
        $this->assertSame(1, db_connect()->table('grade_items')->where('assessment_id', $assessmentId)->countAllResults());
        $this->assertSame((int) $scheme['id'], (int) $item['grading_scheme_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | syncOffering
    |--------------------------------------------------------------------------
    */

    public function testSyncOfferingCreatesItemsForCountedAssessmentsOnly(): void
    {
        $this->scheme();
        $published = $this->assessment('Quiz', 'Published');
        $closed = $this->assessment('Quiz', 'Closed');
        $draft = $this->assessment('Quiz', 'Draft');

        $this->sync->syncOffering($this->offeringId);

        $this->assertNotNull($this->itemFor($published));
        $this->assertNotNull($this->itemFor($closed));
        $this->assertNull($this->itemFor($draft));
    }

    public function testSyncOfferingSkipsExistingItemsAndOtherOfferings(): void
    {
        $schemeId = $this->scheme();
        $existing = $this->assessment();
        GradeFactory::item($schemeId, null, 'Mine', 5, 1, $existing);

        $otherOffering = CourseFactory::offering('B');
        $otherModule = CourseFactory::module($otherOffering);
        $otherId = CourseFactory::assessment($otherOffering, $this->instructor, $otherModule);
        db_connect()->table('assessments')->where('id', $otherId)->update(['status' => 'Published']);

        $this->sync->syncOffering($this->offeringId);

        $this->assertSame(1, db_connect()->table('grade_items')->where('assessment_id', $existing)->countAllResults());
        $this->assertNull($this->itemFor($otherId));
    }

    public function testSyncOfferingWithoutASchemeDoesNothing(): void
    {
        $assessmentId = $this->assessment();

        $this->sync->syncOffering($this->offeringId);

        $this->assertNull($this->itemFor($assessmentId));
    }

    /*
    |--------------------------------------------------------------------------
    | Archive, sync check and resync
    |--------------------------------------------------------------------------
    */

    public function testDeletingAnAssessmentArchivesItsItemAndFlagsARecalculation(): void
    {
        $schemeId = $this->scheme();
        $assessmentId = $this->assessment();
        $itemId = GradeFactory::item($schemeId, null, 'Item', 25, 1, $assessmentId);
        $this->clearRecalculationFlag($schemeId);

        $this->sync->archiveForAssessment($assessmentId);

        $this->assertSame('Archived', $this->itemFor($assessmentId)['status']);
        $this->assertSame($itemId, (int) $this->itemFor($assessmentId)['id'], 'the item is kept, not deleted');
        $this->assertTrue($this->needsRecalculation($schemeId));
    }

    public function testArchivingTwiceOrWithoutAnItemChangesNothing(): void
    {
        $schemeId = $this->scheme();
        $assessmentId = $this->assessment();
        GradeFactory::item($schemeId, null, 'Item', 25, 1, $assessmentId);
        $this->sync->archiveForAssessment($assessmentId);
        $this->clearRecalculationFlag($schemeId);

        $this->sync->archiveForAssessment($assessmentId);
        $this->sync->archiveForAssessment($this->assessment());

        $this->assertFalse($this->needsRecalculation($schemeId));
    }

    public function testOutOfSyncComparesTheSnapshotWithTheCurrentPoints(): void
    {
        $this->assertTrue($this->sync->isOutOfSync(['assessment_id' => 1, 'max_points' => 20, 'current_points' => 25]));
        $this->assertFalse($this->sync->isOutOfSync(['assessment_id' => 1, 'max_points' => 20, 'current_points' => 20]));
        $this->assertFalse($this->sync->isOutOfSync(['assessment_id' => 1, 'max_points' => 20, 'current_points' => 20.0004]), 'rounding noise is not a change');
        $this->assertTrue($this->sync->isOutOfSync(['assessment_id' => 1, 'max_points' => 20, 'current_points' => 20.01]));
    }

    public function testOutOfSyncIsFalseForManualItemsAndMissingPoints(): void
    {
        $this->assertFalse($this->sync->isOutOfSync(['assessment_id' => null, 'max_points' => 20, 'current_points' => 99]));
        $this->assertFalse($this->sync->isOutOfSync(['max_points' => 20, 'current_points' => 99]));
        $this->assertFalse($this->sync->isOutOfSync(['assessment_id' => 1, 'max_points' => 20]));
        $this->assertFalse($this->sync->isOutOfSync(['assessment_id' => 1, 'max_points' => 20, 'current_points' => null]));
    }

    public function testResyncAcceptsTheAssessmentsCurrentTotal(): void
    {
        $schemeId = $this->scheme();
        $assessmentId = $this->assessment('Quiz', 'Published', 25);
        $itemId = GradeFactory::item($schemeId, null, 'Item', 25, 1, $assessmentId);
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['total_points' => 40]);
        $this->clearRecalculationFlag($schemeId);

        $result = $this->sync->resync($this->instructor, $itemId);

        $this->assertTrue($result['success']);
        $this->assertEquals(40, $result['item']['max_points']);
        $this->assertSame($this->instructor->id, (int) $result['item']['updated_by']);
        $this->assertTrue($this->needsRecalculation($schemeId));
    }

    public function testResyncRefusesManualItemsAndMissingOnes(): void
    {
        $schemeId = $this->scheme();
        $manual = GradeFactory::item($schemeId, null, 'Manual', 10);

        $this->assertFalse($this->sync->resync($this->instructor, $manual)['success']);
        $this->assertFalse($this->sync->resync($this->instructor, 999999)['success']);
    }

    public function testResyncWhenTheAssessmentIsGone(): void
    {
        $schemeId = $this->scheme();
        // A dangling reference can't be inserted with foreign keys on, so
        // switch them off for this one row.
        db_connect()->query('SET FOREIGN_KEY_CHECKS = 0');
        $item = GradeFactory::item($schemeId, null, 'Item', 10, 1, 999999);
        db_connect()->query('SET FOREIGN_KEY_CHECKS = 1');

        $result = $this->sync->resync($this->instructor, $item);

        $this->assertFalse($result['success']);
    }
}
