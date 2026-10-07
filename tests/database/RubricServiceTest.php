<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\RubricService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Grading rubrics: creating one (replacing the assessment's current rubric),
 * reusing another assessment's rubric from the course library, editing the
 * rubric and its criteria with the total always following, and deleting.
 *
 * @internal
 */
final class RubricServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private RubricService $service;
    private int $offering;
    private int $module;
    private int $assignment;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RubricService();
        $this->offering = CourseFactory::offering();
        $this->module = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->assignment = $this->assignment();
    }

    private function assignment(?int $offering = null): int
    {
        $offering ??= $this->offering;
        $module = $offering === $this->offering ? $this->module : CourseFactory::module($offering);

        return CourseFactory::assessment($offering, $this->instructor, $module, null, 'Assignment');
    }

    private function row(string $table, int $id): ?array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function rubricsOf(int $assessmentId): array
    {
        return db_connect()->table('assessment_rubrics')->where('assessment_id', $assessmentId)->get()->getResultArray();
    }

    private function criteriaOf(int $rubricId): array
    {
        return db_connect()->table('assessment_rubric_criteria')->where('rubric_id', $rubricId)->orderBy('order_index')->get()->getResultArray();
    }

    private function create(array $data, ?int $assessment = null): array
    {
        return $this->service->createRubric($this->instructor, $assessment ?? $this->assignment, $data);
    }

    /*
    |--------------------------------------------------------------------------
    | Creating
    |--------------------------------------------------------------------------
    */

    public function testCreatingARubricStoresItsCriteriaInOrderAndAddsUpThePoints(): void
    {
        $result = $this->create([
            'title' => 'Essay rubric',
            'description' => 'How essays are marked',
            'criteria' => [
                ['title' => 'Thesis', 'description' => 'Clear claim', 'max_points' => 10],
                ['title' => 'Evidence', 'max_points' => 7.5],
                ['title' => 'Style'],
            ],
        ]);

        $this->assertTrue($result['success']);
        $rubric = $result['rubric'];
        $this->assertSame('Essay rubric', $rubric['title']);
        $this->assertSame('How essays are marked', $rubric['description']);
        $this->assertEquals(17.5, $rubric['total_points']);
        $this->assertSame($this->assignment, (int) $rubric['assessment_id']);
        $this->assertNull($rubric['question_id']);
        $this->assertSame('Active', $rubric['status']);
        $this->assertSame($this->instructor->id, (int) $rubric['created_by']);
        $this->assertSame(['Thesis', 'Evidence', 'Style'], array_column($rubric['criteria'], 'criteria_title'));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($rubric['criteria'], 'order_index')));
        $this->assertEquals([10, 7.5, 0], array_column($rubric['criteria'], 'max_points'));
    }

    public function testARubricWithNoDetailsGetsADefaultTitle(): void
    {
        $result = $this->create([]);

        $this->assertSame('Untitled Rubric', $result['rubric']['title']);
        $this->assertSame([], $result['rubric']['criteria']);
        $this->assertEquals(0, $result['rubric']['total_points']);
    }

    public function testCreatingANewRubricReplacesTheAssessmentsCurrentOne(): void
    {
        $old = $this->create(['title' => 'Old', 'criteria' => [['title' => 'A', 'max_points' => 5]]])['rubric']['id'];

        $new = $this->create(['title' => 'New'])['rubric']['id'];

        $this->assertNull($this->row('assessment_rubrics', (int) $old));
        $this->assertSame([], $this->criteriaOf((int) $old), 'its criteria go with it');
        $this->assertCount(1, $this->rubricsOf($this->assignment));
        $this->assertSame((int) $new, (int) $this->rubricsOf($this->assignment)[0]['id']);
    }

    public function testCreatingARubricForAMissingAssessmentIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->create(['title' => 'X'], 999999));
    }

    /*
    |--------------------------------------------------------------------------
    | The course library
    |--------------------------------------------------------------------------
    */

    public function testTheLibraryListsTheCourseRubricsExceptTheAssessmentsOwn(): void
    {
        $other = $this->assignment();
        $this->create(['title' => 'Own', 'criteria' => [['title' => 'A', 'max_points' => 3]]]);
        $this->create(['title' => 'Shared', 'criteria' => [['title' => 'A', 'max_points' => 4], ['title' => 'B', 'max_points' => 6]]], $other);

        $list = $this->service->listRubrics($this->assignment);

        $this->assertTrue($list['success']);
        $this->assertSame(['Shared'], array_column($list['rubrics'], 'title'));
        $this->assertSame(2, (int) $list['rubrics'][0]['criteria_count']);
        $this->assertEquals(10, $list['rubrics'][0]['total_points']);
    }

    public function testTheLibraryNeverShowsAnotherCoursesRubrics(): void
    {
        $this->create(['title' => 'Own']);
        $otherCourse = $this->assignment(CourseFactory::offering('B'));
        $this->create(['title' => 'Elsewhere'], $otherCourse);

        $this->assertSame([], $this->service->listRubrics($this->assignment)['rubrics']);
    }

    public function testArchivedRubricsAreLeftOutOfTheLibrary(): void
    {
        $other = $this->assignment();
        $id = $this->create(['title' => 'Retired'], $other)['rubric']['id'];
        db_connect()->table('assessment_rubrics')->where('id', $id)->update(['status' => 'Archived']);

        $this->assertSame([], $this->service->listRubrics($this->assignment)['rubrics']);
    }

    public function testListingForAMissingAssessmentIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Assessment not found.'], $this->service->listRubrics(999999));
    }

    public function testSelectingARubricCopiesItWithItsCriteriaAndLeavesTheSourceAlone(): void
    {
        $other = $this->assignment();
        $source = $this->create(['title' => 'Shared', 'description' => 'D', 'criteria' => [['title' => 'A', 'description' => 'a', 'max_points' => 4], ['title' => 'B', 'max_points' => 6]]], $other)['rubric'];

        $result = $this->service->selectRubric($this->instructor, $this->assignment, (int) $source['id']);

        $this->assertTrue($result['success']);
        $copy = $result['rubric'];
        $this->assertNotSame((int) $source['id'], (int) $copy['id']);
        $this->assertSame($this->assignment, (int) $copy['assessment_id']);
        $this->assertSame('Shared', $copy['title']);
        $this->assertSame('D', $copy['description']);
        $this->assertEquals(10, $copy['total_points']);
        $this->assertSame(['A', 'B'], array_column($copy['criteria'], 'criteria_title'));
        $this->assertSame('a', $copy['criteria'][0]['description']);
        $this->assertEquals([4, 6], array_column($copy['criteria'], 'max_points'));
        $this->assertSame([1, 2], array_map('intval', array_column($copy['criteria'], 'order_index')));
        $this->assertCount(2, $this->criteriaOf((int) $source['id']), 'the source keeps its own criteria');
        $this->assertCount(1, $this->rubricsOf($other));
    }

    public function testSelectingReplacesTheAssessmentsCurrentRubric(): void
    {
        $other = $this->assignment();
        $source = $this->create(['title' => 'Shared'], $other)['rubric']['id'];
        $old = $this->create(['title' => 'Old'])['rubric']['id'];

        $this->service->selectRubric($this->instructor, $this->assignment, (int) $source);

        $this->assertNull($this->row('assessment_rubrics', (int) $old));
        $this->assertCount(1, $this->rubricsOf($this->assignment));
    }

    public function testEditingTheCopyDoesNotChangeTheSource(): void
    {
        $other = $this->assignment();
        $source = $this->create(['title' => 'Shared', 'criteria' => [['title' => 'A', 'max_points' => 4]]], $other)['rubric'];
        $copy = $this->service->selectRubric($this->instructor, $this->assignment, (int) $source['id'])['rubric'];

        $this->service->updateCriterion((int) $copy['criteria'][0]['id'], ['max_points' => 9]);

        $this->assertEquals(4, $this->criteriaOf((int) $source['id'])[0]['max_points']);
    }

    public function testSelectingNeedsARealAssessmentAndARealRubric(): void
    {
        $source = $this->create(['title' => 'Shared'], $this->assignment())['rubric']['id'];

        $this->assertSame('Assessment not found.', $this->service->selectRubric($this->instructor, 999999, (int) $source)['message']);
        $this->assertSame('Rubric not found.', $this->service->selectRubric($this->instructor, $this->assignment, 999999)['message']);
        $this->assertSame([], $this->rubricsOf($this->assignment));
    }

    public function testAMissingSourceRubricLeavesTheCurrentRubricInPlace(): void
    {
        $current = $this->create(['title' => 'Current'])['rubric']['id'];

        $this->service->selectRubric($this->instructor, $this->assignment, 999999);

        $this->assertNotNull($this->row('assessment_rubrics', (int) $current));
    }

    /*
    |--------------------------------------------------------------------------
    | Editing the rubric
    |--------------------------------------------------------------------------
    */

    public function testShowingARubricIncludesItsCriteria(): void
    {
        $id = (int) $this->create(['title' => 'R', 'criteria' => [['title' => 'A', 'max_points' => 1], ['title' => 'B', 'max_points' => 2]]])['rubric']['id'];

        $shown = $this->service->getRubric($id);

        $this->assertTrue($shown['success']);
        $this->assertCount(2, $shown['rubric']['criteria']);
        $this->assertSame(['success' => false, 'message' => 'Rubric not found.'], $this->service->getRubric(999999));
    }

    public function testEditingTheTitleAndDescriptionKeepsTheRestAndTheCriteria(): void
    {
        $id = (int) $this->create(['title' => 'R', 'description' => 'Keep me', 'criteria' => [['title' => 'A', 'max_points' => 5]]])['rubric']['id'];

        $this->service->updateRubric($this->instructor, $id, ['title' => 'Renamed']);
        $row = $this->row('assessment_rubrics', $id);

        $this->assertSame('Renamed', $row['title']);
        $this->assertSame('Keep me', $row['description']);
        $this->assertSame($this->instructor->id, (int) $row['updated_by']);
        $this->assertNotNull($row['updated_at']);
        $this->assertCount(1, $this->criteriaOf($id));
        $this->assertSame(['success' => false, 'message' => 'Rubric not found.'], $this->service->updateRubric($this->instructor, 999999, ['title' => 'X']));
    }

    public function testDeletingARubricTakesItsCriteriaWithIt(): void
    {
        $id = (int) $this->create(['title' => 'R', 'criteria' => [['title' => 'A', 'max_points' => 5]]])['rubric']['id'];

        $this->assertTrue($this->service->deleteRubric($id)['success']);

        $this->assertNull($this->row('assessment_rubrics', $id));
        $this->assertSame([], $this->criteriaOf($id));
        $this->assertSame(['success' => false, 'message' => 'Rubric not found.'], $this->service->deleteRubric($id));
    }

    /*
    |--------------------------------------------------------------------------
    | Criteria
    |--------------------------------------------------------------------------
    */

    public function testAddingACriterionAppendsItAndUpdatesTheTotal(): void
    {
        $id = (int) $this->create(['title' => 'R', 'criteria' => [['title' => 'A', 'max_points' => 5]]])['rubric']['id'];

        $result = $this->service->createCriterion($id, ['title' => 'B', 'description' => 'Second', 'max_points' => 3.5]);

        $this->assertTrue($result['success']);
        $this->assertSame('B', $result['criterion']['criteria_title']);
        $this->assertSame(2, (int) $result['criterion']['order_index']);
        $this->assertEquals(8.5, $result['rubric']['total_points']);
        $this->assertCount(2, $result['rubric']['criteria']);
    }

    private function assignmentTotal(): float
    {
        return (float) $this->row('assessments', $this->assignment)['total_points'];
    }

    public function testTheAssignmentIsWorthWhatItsRubricIsWorthWhateverChangesTheRubric(): void
    {
        $rubric = (int) $this->service->createRubric($this->instructor, $this->assignment, ['title' => 'R', 'criteria' => [['title' => 'A', 'max_points' => 5], ['title' => 'B', 'max_points' => 5]]])['rubric']['id'];
        $this->assertEquals(10, $this->assignmentTotal(), 'creating the rubric');

        $criterion = (int) $this->service->createCriterion($rubric, ['title' => 'C', 'max_points' => 4])['criterion']['id'];
        $this->assertEquals(14, $this->assignmentTotal(), 'adding a criterion');

        $this->service->updateCriterion($criterion, ['max_points' => 6]);
        $this->assertEquals(16, $this->assignmentTotal(), 'editing a criterion');

        $this->service->deleteCriterion($criterion);
        $this->assertEquals(10, $this->assignmentTotal(), 'deleting a criterion');
    }

    public function testSelectingARubricSetsTheAssignmentsTotalAndThePassingScore(): void
    {
        db_connect()->table('assessments')->where('id', $this->assignment)->update(['passing_percentage' => 50]);
        $other = $this->assignment();
        $source = (int) $this->service->createRubric($this->instructor, $other, ['title' => 'Shared', 'criteria' => [['title' => 'A', 'max_points' => 8], ['title' => 'B', 'max_points' => 12]]])['rubric']['id'];

        $this->service->selectRubric($this->instructor, $this->assignment, $source);

        $row = $this->row('assessments', $this->assignment);
        $this->assertEquals(20, $row['total_points']);
        $this->assertEquals(10, $row['passing_score']);
        $this->assertEquals(20, $this->row('assessments', $other)['total_points'], 'the source assignment is untouched');
    }

    public function testChangingOneAssignmentsRubricLeavesOthersAlone(): void
    {
        $other = $this->assignment();
        $this->service->createRubric($this->instructor, $other, ['title' => 'Theirs', 'criteria' => [['title' => 'A', 'max_points' => 7]]]);
        $mine = (int) $this->service->createRubric($this->instructor, $this->assignment, ['title' => 'Mine', 'criteria' => [['title' => 'A', 'max_points' => 3]]])['rubric']['id'];

        $this->service->createCriterion($mine, ['title' => 'B', 'max_points' => 2]);

        $this->assertEquals(5, $this->assignmentTotal());
        $this->assertEquals(7, $this->row('assessments', $other)['total_points']);
    }

    public function testAddingToAMissingRubricIsRefused(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Rubric not found.'], $this->service->createCriterion(999999, ['title' => 'X']));
    }

    public function testEditingACriterionChangesOnlyWhatIsSentAndUpdatesTheTotal(): void
    {
        $rubric = $this->create(['title' => 'R', 'criteria' => [['title' => 'A', 'description' => 'Keep', 'max_points' => 5], ['title' => 'B', 'max_points' => 5]]])['rubric'];
        $first = (int) $rubric['criteria'][0]['id'];

        $result = $this->service->updateCriterion($first, ['max_points' => 8]);

        $this->assertTrue($result['success']);
        $this->assertSame('A', $result['criterion']['criteria_title']);
        $this->assertSame('Keep', $result['criterion']['description']);
        $this->assertEquals(8, $result['criterion']['max_points']);
        $this->assertEquals(13, $result['rubric']['total_points']);

        $this->service->updateCriterion($first, ['title' => 'Renamed']);
        $this->assertSame('Renamed', $this->criteriaOf((int) $rubric['id'])[0]['criteria_title']);
        $this->assertSame(['success' => false, 'message' => 'Criteria not found.'], $this->service->updateCriterion(999999, ['title' => 'X']));
    }

    public function testDeletingACriterionUpdatesTheTotal(): void
    {
        $rubric = $this->create(['title' => 'R', 'criteria' => [['title' => 'A', 'max_points' => 5], ['title' => 'B', 'max_points' => 7]]])['rubric'];

        $result = $this->service->deleteCriterion((int) $rubric['criteria'][1]['id']);

        $this->assertTrue($result['success']);
        $this->assertEquals(5, $result['rubric']['total_points']);
        $this->assertCount(1, $result['rubric']['criteria']);
        $this->assertSame(['success' => false, 'message' => 'Criteria not found.'], $this->service->deleteCriterion(999999));
    }

    public function testRubricGradedWorkKeepsItsScoresWhenARubricIsReplaced(): void
    {
        // Replacing a rubric must not touch work already graded with it.
        $student = CourseFactory::user('student');
        $submission = AssessmentFactory::attempt($this->assignment, $student, 'Graded', 17, ['grading_mode' => 'Rubric', 'rubric_scores' => json_encode([1 => 17])]);
        $this->create(['title' => 'Old']);

        $this->create(['title' => 'New']);

        $row = $this->row('assessment_submissions', $submission);
        $this->assertEquals(17, $row['score']);
        $this->assertSame('Graded', $row['status']);
    }
}
