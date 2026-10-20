<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradingSchemeService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * An offering's grading scheme: copied from the system default the first time
 * it is needed, edited (categories, periods, items, passing mark, scale),
 * checked before publishing, and what each edit does to "needs recalculation".
 *
 * @internal
 */
final class GradingSchemeServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradingSchemeService $service;

    private int $offering;

    private int $templateId;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GradingSchemeService();

        // Quizzes 30% + Exams 70%, passing mark 75.
        $this->templateId = GradeFactory::systemDefault();

        $this->offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** The offering's scheme row (creating it the first time). */
    private function scheme(): array
    {
        return $this->service->forOffering($this->offering);
    }

    private function needsRecalculation(): bool
    {
        return (int) db_connect()->table('grading_schemes')
            ->where('course_offering_id', $this->offering)->get()->getRowArray()['needs_recalculation'] === 1;
    }

    private function markClean(): void
    {
        db_connect()->table('grading_schemes')->where('course_offering_id', $this->offering)->update(['needs_recalculation' => 0]);
    }

    private function categoryId(string $name): int
    {
        $schemeId = (int) $this->scheme()['id'];

        return (int) db_connect()->table('grade_categories')
            ->where('grading_scheme_id', $schemeId)->where('name', $name)->get()->getRowArray()['id'];
    }

    private function row(string $table, int $id): array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    /** A manual item in the Quizzes category, out of 20. */
    private function quizItem(): int
    {
        return (int) $this->service->createManualItem($this->instructor, $this->offering, $this->categoryId('Quizzes'), ['name' => 'Quiz 1', 'max_points' => 20])['item']['id'];
    }

    /*
    |--------------------------------------------------------------------------
    | The first time: copying the system default
    |--------------------------------------------------------------------------
    */

    public function testTheFirstUseCopiesTheSystemDefault(): void
    {
        $scheme = $this->scheme();

        $this->assertSame($this->offering, (int) $scheme['course_offering_id']);
        $this->assertSame(0, (int) $scheme['is_system_default']);
        $this->assertSame('Published', $scheme['status']);
        $this->assertSame(0, (int) $scheme['needs_recalculation']);
        $this->assertSame(75.0, (float) $scheme['passing_percentage']);

        $template = $this->row('grading_schemes', $this->templateId);
        $this->assertSame($template['grade_scale_id'], $scheme['grade_scale_id']);

        $categories = db_connect()->table('grade_categories')->where('grading_scheme_id', $scheme['id'])->orderBy('sort_order')->get()->getResultArray();
        $this->assertSame(['Quizzes', 'Exams'], array_column($categories, 'name'));
        $this->assertSame([30.0, 70.0], array_map(static fn (array $c) => (float) $c['weight_percentage'], $categories));
    }

    public function testAskingAgainReturnsTheSameSchemeWithoutCopyingTwice(): void
    {
        $first = $this->scheme();
        $second = $this->scheme();

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(2, db_connect()->table('grade_categories')->where('grading_scheme_id', $first['id'])->countAllResults());
    }

    public function testEditingAnOfferingsCopyNeverTouchesTheTemplate(): void
    {
        $this->service->updateCategory($this->offering, $this->categoryId('Quizzes'), ['name' => 'Renamed', 'weight_percentage' => 10]);

        $names = array_column(db_connect()->table('grade_categories')->where('grading_scheme_id', $this->templateId)->orderBy('sort_order')->get()->getResultArray(), 'name');
        $this->assertSame(['Quizzes', 'Exams'], $names);
    }

    public function testWithoutATemplateTheSchemeStartsEmptyWithTheDefaultPassingMark(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->templateId)->delete();

        $scheme = $this->scheme();

        $this->assertSame(75.0, (float) $scheme['passing_percentage']);
        $this->assertSame(0, db_connect()->table('grade_categories')->where('grading_scheme_id', $scheme['id'])->countAllResults());
    }

    public function testCreatingTheSchemeGradesEveryEnrolledStudentOnce(): void
    {
        $other = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $other);

        $this->scheme();

        $records = db_connect()->table('gradebook_records')->where('course_offering_id', $this->offering)->get()->getResultArray();
        $this->assertCount(2, $records);
        $this->assertSame(['Incomplete', 'Incomplete'], array_column($records, 'remarks'), 'no work yet');
    }

    /*
    |--------------------------------------------------------------------------
    | Can it be published?
    |--------------------------------------------------------------------------
    */

    public function testTheCopiedSchemeCanBePublished(): void
    {
        $this->assertNull($this->service->publishProblem((int) $this->scheme()['id']));
    }

    public function testASchemeWithNoCategoriesCannotBePublished(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->templateId)->delete();

        $this->assertSame('Add at least one category before publishing.', $this->service->publishProblem((int) $this->scheme()['id']));
    }

    public function testCategoryWeightsMustTotalOneHundred(): void
    {
        $this->service->updateCategory($this->offering, $this->categoryId('Exams'), ['weight_percentage' => 80]);

        $this->assertSame(
            'Category weights add up to 110% — they must total 100%.',
            $this->service->publishProblem((int) $this->scheme()['id'])
        );
    }

    public function testWithPeriodsEveryLevelMustTotalOneHundred(): void
    {
        $schemeId = (int) $this->scheme()['id'];
        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => true]);

        $this->assertNull($this->service->publishProblem($schemeId), 'the wrapped "Full Term" period is valid on its own');

        $second = $this->service->createPeriod($this->offering, ['name' => 'Second', 'weight_percentage' => 50])['period'];
        $this->assertSame('Period weights add up to 150% — they must total 100%.', $this->service->publishProblem($schemeId));

        $fullTerm = db_connect()->table('grading_periods')->where('name', 'Full Term')->get()->getRowArray();
        $this->service->updatePeriod($this->offering, (int) $fullTerm['id'], ['weight_percentage' => 50]);
        $this->assertSame('Add at least one category to "Second" before publishing.', $this->service->publishProblem($schemeId));

        $this->service->createCategory($this->offering, ['name' => 'Project', 'weight_percentage' => 40, 'period_id' => $second['id']]);
        $this->assertSame('"Second"\'s category weights add up to 40% — they must total 100%.', $this->service->publishProblem($schemeId));

        $this->service->updateCategory($this->offering, $this->categoryId('Project'), ['weight_percentage' => 100]);
        $this->assertNull($this->service->publishProblem($schemeId));
    }

    public function testPeriodsOnWithNoPeriodsCannotBePublished(): void
    {
        $schemeId = (int) $this->scheme()['id'];
        db_connect()->table('grading_schemes')->where('id', $schemeId)->update(['uses_periods' => 1]);

        $this->assertSame('Add at least one grading period before publishing.', $this->service->publishProblem($schemeId));
    }

    /*
    |--------------------------------------------------------------------------
    | Scheme settings
    |--------------------------------------------------------------------------
    */

    public function testAPassingMarkOutsideZeroToOneHundredIsRefused(): void
    {
        $this->scheme();

        foreach ([-1, 100.5] as $bad) {
            $result = $this->service->updateScheme($this->instructor, $this->offering, ['passing_percentage' => $bad]);

            $this->assertFalse($result['success']);
            $this->assertSame('The passing mark must be between 0 and 100.', $result['message']);
        }
    }

    public function testChangingThePassingMarkFlagsARecalculationButRepeatingItDoesNot(): void
    {
        $this->scheme();

        $this->service->updateScheme($this->instructor, $this->offering, ['passing_percentage' => 75]);
        $this->assertFalse($this->needsRecalculation(), 'same value, nothing changed');

        $this->service->updateScheme($this->instructor, $this->offering, ['passing_percentage' => 60]);
        $this->assertTrue($this->needsRecalculation());
        $this->assertSame(60.0, (float) $this->scheme()['passing_percentage']);
    }

    public function testChangingTheGradeScaleFlagsARecalculationAndUnknownScalesAreRefused(): void
    {
        $this->scheme();

        $missing = $this->service->updateScheme($this->instructor, $this->offering, ['grade_scale_id' => 999999]);
        $this->assertSame('Grade scale not found.', $missing['message']);
        $this->assertFalse($this->needsRecalculation());

        $this->service->updateScheme($this->instructor, $this->offering, ['grade_scale_id' => GradeFactory::scale(['A' => 50, 'F' => 0])]);
        $this->assertTrue($this->needsRecalculation());
    }

    public function testTheNameIsTrimmedAndABlankNameIsIgnored(): void
    {
        $this->scheme();

        $this->service->updateScheme($this->instructor, $this->offering, ['name' => '  Midterm scheme  ']);
        $this->assertSame('Midterm scheme', $this->scheme()['name']);

        $this->service->updateScheme($this->instructor, $this->offering, ['name' => '   ']);
        $this->assertSame('Midterm scheme', $this->scheme()['name']);
    }

    public function testPublishingIsRefusedWhileTheWeightsAreWrong(): void
    {
        $this->scheme();
        $this->service->updateScheme($this->instructor, $this->offering, ['status' => 'Draft']);
        $this->service->updateCategory($this->offering, $this->categoryId('Exams'), ['weight_percentage' => 50]);

        $result = $this->service->updateScheme($this->instructor, $this->offering, ['status' => 'Published']);

        $this->assertFalse($result['success']);
        $this->assertSame('Category weights add up to 80% — they must total 100%.', $result['message']);
        $this->assertSame('Draft', $this->scheme()['status']);
    }

    public function testPublishingCalculatesEveryonesGradeAndClearsTheFlag(): void
    {
        $itemId = $this->quizItem();
        $this->service->updateScheme($this->instructor, $this->offering, ['status' => 'Draft']);
        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 15);

        $this->service->updateScheme($this->instructor, $this->offering, ['status' => 'Published']);

        $record = db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->get()->getRowArray();
        $this->assertSame(75.0, (float) $record['calculated_percentage']);
        $this->assertSame('Passed', $record['remarks']);
        $this->assertFalse($this->needsRecalculation());
        $this->assertNotNull($this->scheme()['last_recalculated_at']);
    }

    public function testTurningPeriodsOnWrapsTheCategoriesWithoutChangingAnyGrade(): void
    {
        $this->scheme();

        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => true]);

        $periods = db_connect()->table('grading_periods')->get()->getResultArray();
        $this->assertCount(1, $periods);
        $this->assertSame('Full Term', $periods[0]['name']);
        $this->assertSame(100.0, (float) $periods[0]['weight_percentage']);

        $inside = db_connect()->table('grade_categories')->where('period_id', $periods[0]['id'])->countAllResults();
        $this->assertSame(2, $inside, 'both categories now sit in Full Term');
        $this->assertFalse($this->needsRecalculation(), 'this alone changes no number');
        $this->assertSame(1, (int) $this->scheme()['uses_periods']);
    }

    public function testTurningPeriodsOffAndOnAgainKeepsTheSamePeriod(): void
    {
        $this->scheme();

        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => true]);
        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => false]);
        $this->assertSame(0, (int) $this->scheme()['uses_periods']);
        $this->assertSame(1, db_connect()->table('grading_periods')->countAllResults(), 'nothing was deleted');

        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => true]);
        $this->assertSame(1, db_connect()->table('grading_periods')->countAllResults(), 'no second Full Term');
    }

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    public function testANewCategoryGoesLastAndFlagsARecalculation(): void
    {
        $this->scheme();

        $result = $this->service->createCategory($this->offering, ['name' => '  Labs  ', 'weight_percentage' => 10]);

        $this->assertTrue($result['success']);
        $this->assertSame('Labs', $result['category']['name']);
        $this->assertSame(3, (int) $result['category']['sort_order']);
        $this->assertTrue($this->needsRecalculation());
    }

    public function testInvalidCategoriesAreRefused(): void
    {
        $this->scheme();

        $this->assertSame('Category name is required.', $this->service->createCategory($this->offering, ['name' => '  ', 'weight_percentage' => 10])['message']);
        $this->assertSame('Weight must be between 0 and 100.', $this->service->createCategory($this->offering, ['name' => 'X', 'weight_percentage' => 101])['message']);
        $this->assertSame('Weight must be between 0 and 100.', $this->service->createCategory($this->offering, ['name' => 'X', 'weight_percentage' => -1])['message']);
        $this->assertSame('Grading period not found.', $this->service->createCategory($this->offering, ['name' => 'X', 'weight_percentage' => 10, 'period_id' => 999999])['message']);
    }

    public function testARenameDoesNotFlagARecalculationButAWeightChangeDoes(): void
    {
        $quizzes = $this->categoryId('Quizzes');

        $this->service->updateCategory($this->offering, $quizzes, ['name' => 'Short quizzes']);
        $this->assertFalse($this->needsRecalculation());

        $this->service->updateCategory($this->offering, $quizzes, ['weight_percentage' => 30]);
        $this->assertFalse($this->needsRecalculation(), 'same weight');

        $this->service->updateCategory($this->offering, $quizzes, ['weight_percentage' => 25]);
        $this->assertTrue($this->needsRecalculation());
    }

    public function testMovingACategoryToAnotherPeriodFlagsARecalculation(): void
    {
        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => true]);
        $period = $this->service->createPeriod($this->offering, ['name' => 'Finals', 'weight_percentage' => 0])['period'];
        $this->markClean();

        $this->service->updateCategory($this->offering, $this->categoryId('Quizzes'), ['period_id' => $period['id']]);

        $this->assertTrue($this->needsRecalculation());
    }

    public function testACategoryOfAnotherOfferingCannotBeChanged(): void
    {
        $this->scheme();
        $other = CourseFactory::offering('B');
        $otherSchemeId = (int) $this->service->forOffering($other)['id'];
        $foreign = (int) db_connect()->table('grade_categories')->where('grading_scheme_id', $otherSchemeId)->get()->getRowArray()['id'];

        $this->assertSame('Category not found.', $this->service->updateCategory($this->offering, $foreign, ['name' => 'Hijack'])['message']);
        $this->assertSame('Category not found.', $this->service->deleteCategory($this->offering, $foreign)['message']);
        $this->assertSame('Category not found.', $this->service->createManualItem($this->instructor, $this->offering, $foreign, ['name' => 'X', 'max_points' => 5])['message']);
    }

    public function testUpdatingNothingChangesNothing(): void
    {
        $result = $this->service->updateCategory($this->offering, $this->categoryId('Quizzes'), []);

        $this->assertSame('Nothing to update.', $result['message']);
        $this->assertFalse($this->needsRecalculation());
    }

    public function testRemovingACategoryArchivesItAndOrphansItsItems(): void
    {
        $itemId = $this->quizItem();
        $quizzes = $this->categoryId('Quizzes');

        $this->assertTrue($this->service->deleteCategory($this->offering, $quizzes)['success']);

        $this->assertSame('Archived', $this->row('grade_categories', $quizzes)['status']);
        $this->assertNull($this->row('grade_items', $itemId)['category_id'], 'the item stops counting but is kept');
        $this->assertTrue($this->needsRecalculation());
    }

    /*
    |--------------------------------------------------------------------------
    | Periods
    |--------------------------------------------------------------------------
    */

    public function testInvalidPeriodsAreRefused(): void
    {
        $this->scheme();

        $this->assertSame('Period name is required.', $this->service->createPeriod($this->offering, ['name' => '', 'weight_percentage' => 10])['message']);
        $this->assertSame('Weight must be between 0 and 100.', $this->service->createPeriod($this->offering, ['name' => 'P', 'weight_percentage' => 150])['message']);
        $this->assertSame('Grading period not found.', $this->service->updatePeriod($this->offering, 999999, ['name' => 'P'])['message']);
        $this->assertSame('Grading period not found.', $this->service->deletePeriod($this->offering, 999999)['message']);
    }

    public function testOnlyAPeriodWeightChangeFlagsARecalculation(): void
    {
        $period = $this->service->createPeriod($this->offering, ['name' => 'Midterm', 'weight_percentage' => 40])['period'];
        $this->markClean();

        $this->service->updatePeriod($this->offering, (int) $period['id'], ['name' => 'Prelims']);
        $this->assertFalse($this->needsRecalculation());

        $this->service->updatePeriod($this->offering, (int) $period['id'], ['weight_percentage' => 50]);
        $this->assertTrue($this->needsRecalculation());
    }

    public function testRemovingAPeriodArchivesItsCategoriesAndOrphansTheirItems(): void
    {
        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => true]);
        $fullTerm = (int) db_connect()->table('grading_periods')->get()->getRowArray()['id'];
        $itemId = $this->quizItem();
        $this->markClean();

        $this->assertTrue($this->service->deletePeriod($this->offering, $fullTerm)['success']);

        $this->assertSame('Archived', $this->row('grading_periods', $fullTerm)['status']);
        $this->assertSame(
            0,
            db_connect()->table('grade_categories')->where('grading_scheme_id', $this->scheme()['id'])->where('status', 'Active')->countAllResults()
        );
        $this->assertNull($this->row('grade_items', $itemId)['category_id']);
        $this->assertTrue($this->needsRecalculation());
    }

    /*
    |--------------------------------------------------------------------------
    | Grade items
    |--------------------------------------------------------------------------
    */

    public function testInvalidManualItemsAreRefusedAndAddingOneNeverFlagsARecalculation(): void
    {
        $quizzes = $this->categoryId('Quizzes');

        $this->assertSame('Item name is required.', $this->service->createManualItem($this->instructor, $this->offering, $quizzes, ['name' => ' ', 'max_points' => 10])['message']);
        $this->assertSame('Max points must be more than 0.', $this->service->createManualItem($this->instructor, $this->offering, $quizzes, ['name' => 'X', 'max_points' => 0])['message']);

        $created = $this->service->createManualItem($this->instructor, $this->offering, $quizzes, ['name' => 'Attendance', 'max_points' => 10]);
        $this->assertTrue($created['success']);
        $this->assertNull($created['item']['assessment_id']);
        $this->assertFalse($this->needsRecalculation(), 'nobody has a score on it yet');
    }

    public function testMovingAScoredItemBetweenCategoriesFlagsARecalculation(): void
    {
        $itemId = $this->quizItem();
        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 10);
        $this->markClean();

        $this->service->updateItem($this->instructor, $this->offering, $itemId, ['category_id' => $this->categoryId('Exams')]);

        $this->assertTrue($this->needsRecalculation());
    }

    public function testMovingAnItemNobodyHasScoredFlagsNothing(): void
    {
        $itemId = $this->quizItem();
        $this->markClean();

        $this->service->updateItem($this->instructor, $this->offering, $itemId, ['category_id' => $this->categoryId('Exams'), 'max_points' => 50]);

        $this->assertFalse($this->needsRecalculation());
        $this->assertSame(50.0, (float) $this->row('grade_items', $itemId)['max_points']);
    }

    public function testInvalidItemEditsAreRefused(): void
    {
        $itemId = $this->quizItem();

        $this->assertSame('Item name is required.', $this->service->updateItem($this->instructor, $this->offering, $itemId, ['name' => ''])['message']);
        $this->assertSame('Max points must be more than 0.', $this->service->updateItem($this->instructor, $this->offering, $itemId, ['max_points' => -3])['message']);
        $this->assertSame('Category not found.', $this->service->updateItem($this->instructor, $this->offering, $itemId, ['category_id' => 999999])['message']);
        $this->assertSame('Item not found.', $this->service->updateItem($this->instructor, $this->offering, 999999, ['name' => 'X'])['message']);
    }

    public function testAnAssessmentItemKeepsItsNameAndPointsButCanMoveCategory(): void
    {
        $schemeId = (int) $this->scheme()['id'];
        $assessmentId = CourseFactory::assessment($this->offering, $this->instructor);
        $itemId = GradeFactory::item($schemeId, $this->categoryId('Quizzes'), 'Midterm quiz', 40, 1, $assessmentId);

        $result = $this->service->updateItem($this->instructor, $this->offering, $itemId, [
            'name' => 'Renamed',
            'max_points' => 5,
            'category_id' => $this->categoryId('Exams'),
        ]);

        $this->assertTrue($result['success']);
        $item = $this->row('grade_items', $itemId);
        $this->assertSame('Midterm quiz', $item['name']);
        $this->assertSame(40.0, (float) $item['max_points']);
        $this->assertSame($this->categoryId('Exams'), (int) $item['category_id']);
    }

    public function testRemovingAnItemArchivesItAndFlagsOnlyWhenItHasScores(): void
    {
        $unscored = $this->quizItem();
        $this->markClean();

        $this->assertTrue($this->service->deleteItem($this->instructor, $this->offering, $unscored)['success']);
        $this->assertSame('Archived', $this->row('grade_items', $unscored)['status']);
        $this->assertFalse($this->needsRecalculation());

        $scored = $this->quizItem();
        $this->service->updateManualScore($this->instructor, $this->offering, $scored, $this->student->id, 5);
        $this->markClean();

        $this->service->deleteItem($this->instructor, $this->offering, $scored);

        $this->assertTrue($this->needsRecalculation());
        $this->assertSame(1, db_connect()->table('gradebook_item_scores')->where('grade_item_id', $scored)->countAllResults(), 'scores are kept for history');
        $this->assertSame('Item not found.', $this->service->deleteItem($this->instructor, $this->offering, $scored)['message'], 'an archived item is gone');
    }

    /*
    |--------------------------------------------------------------------------
    | Typing a score into the gradebook
    |--------------------------------------------------------------------------
    */

    public function testAScoreUpdatesTheFinalGradeLiveWhileThePublishedSchemeIsUpToDate(): void
    {
        $itemId = $this->quizItem();

        $result = $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 15);

        $this->assertTrue($result['success']);
        $this->assertSame('Score saved.', $result['message']);
        $this->assertSame(75.0, $result['percentage'], 'quizzes are the only graded category: 15/20');
    }

    public function testAScoreWhileARecalculationIsPendingStillUpdatesTheStudentsGrade(): void
    {
        $itemId = $this->quizItem();
        $this->service->updateCategory($this->offering, $this->categoryId('Exams'), ['weight_percentage' => 60]);
        $this->assertTrue($this->needsRecalculation());

        $result = $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 15);

        $this->assertSame('Score saved.', $result['message']);
        $this->assertNotNull($result['percentage']);
        $this->assertSame(1, db_connect()->table('gradebook_item_scores')->where('grade_item_id', $itemId)->countAllResults());
    }

    public function testAScoreOnADraftSchemeIsSavedButNotAppliedYet(): void
    {
        $itemId = $this->quizItem();
        $this->service->updateScheme($this->instructor, $this->offering, ['status' => 'Draft']);

        $result = $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 15);

        $this->assertSame('Score saved. Final grades update once the grading is published.', $result['message']);
    }

    /** @return list<array<string, mixed>> the grade audit entries of this section, oldest first */
    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')
            ->where('action', $action)
            ->where('target_id', (string) $this->offering)
            ->orderBy('id')
            ->get()->getResultArray();
    }

    public function testEveryChangedManualScoreIsRecordedWithTheOldAndNewValue(): void
    {
        $itemId = $this->quizItem();

        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 15);
        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 18);
        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 18);
        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, null);

        $entries = $this->audit('grades.manual_score');

        $this->assertCount(3, $entries, 'saving the same score again is not a change');

        $moves = array_map(static fn (array $e): array => [
            json_decode($e['old_values'], true)['score'],
            json_decode($e['new_values'], true)['score'],
        ], $entries);

        $this->assertEquals([[null, 15.0], [15.0, 18.0], [18.0, null]], $moves);
        $this->assertSame($this->instructor->id, (int) $entries[0]['actor_user_id']);
        $this->assertSame($this->student->id, json_decode($entries[0]['new_values'], true)['student_id']);
    }

    public function testARefusedScoreLeavesNoAuditEntry(): void
    {
        $itemId = $this->quizItem();

        $result = $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 999);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->audit('grades.manual_score'));
    }

    public function testAnOverrideAndItsRemovalAreRecordedWithTheReason(): void
    {
        $this->service->recalculate($this->offering);

        $this->service->overrideGrade($this->instructor, $this->offering, $this->student->id, 88.0, 'Excused absence');
        $this->service->overrideGrade($this->instructor, $this->offering, $this->student->id, null, '');

        $set = $this->audit('grades.override_set');
        $removed = $this->audit('grades.override_removed');

        $this->assertCount(1, $set);
        $this->assertSame('Excused absence', json_decode($set[0]['new_values'], true)['reason']);
        $this->assertEquals(88.0, json_decode($set[0]['new_values'], true)['percentage']);
        $this->assertCount(1, $removed);
    }

    public function testARecalculationIsRecordedWithHowManyStudentsItTouched(): void
    {
        $this->service->recalculate($this->offering);

        $entries = $this->audit('grades.recalculated');

        $this->assertCount(1, $entries);
        $this->assertSame(1, json_decode($entries[0]['new_values'], true)['students']);
    }

    public function testClearingAScoreLeavesTheItemUngraded(): void
    {
        $itemId = $this->quizItem();
        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 15);

        $result = $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, null);

        $this->assertTrue($result['success']);
        $this->assertNull($result['percentage'], 'nothing graded again means no percentage');
    }

    public function testInvalidScoresAreRefused(): void
    {
        $itemId = $this->quizItem();

        foreach ([21, -1] as $bad) {
            $result = $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, $bad);

            $this->assertFalse($result['success']);
            $this->assertSame('Score must be between 0 and 20.', $result['message']);
        }

        $stranger = CourseFactory::user('student');
        $this->assertSame(
            'Student is not enrolled in this section.',
            $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $stranger->id, 5)['message']
        );

        $this->assertSame(0, db_connect()->table('gradebook_item_scores')->countAllResults());
    }

    public function testAnAssessmentBackedItemCannotBeScoredByHand(): void
    {
        $schemeId = (int) $this->scheme()['id'];
        $assessmentId = CourseFactory::assessment($this->offering, $this->instructor);
        $itemId = GradeFactory::item($schemeId, $this->categoryId('Quizzes'), 'Quiz', 20, 1, $assessmentId);

        $result = $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 5);

        $this->assertFalse($result['success']);
        $this->assertSame('Only manual items can be scored here.', $result['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Recalculate, describe and roster
    |--------------------------------------------------------------------------
    */

    public function testRecalculateIsRefusedUntilTheWeightsAreRight(): void
    {
        $this->scheme();
        $this->service->createCategory($this->offering, ['name' => 'Extra', 'weight_percentage' => 10]);

        $result = $this->service->recalculate($this->offering);

        $this->assertFalse($result['success']);
        $this->assertSame('Category weights add up to 110% — they must total 100%.', $result['message']);
    }

    public function testRecalculateReportsHowManyStudentsItDid(): void
    {
        $this->scheme();
        $this->assertSame('Recalculated 1 student.', $this->service->recalculate($this->offering)['message']);

        CourseFactory::enroll($this->offering, CourseFactory::user('student'));
        $this->assertSame('Recalculated 2 students.', $this->service->recalculate($this->offering)['message']);
    }

    public function testDescribeGroupsItemsUnderTheirCategoriesAndTotalsTheWeights(): void
    {
        $itemId = $this->quizItem();
        $schemeId = (int) $this->scheme()['id'];
        $loose = GradeFactory::item($schemeId, null, 'Loose item', 10, 5);

        $description = $this->service->describe($this->offering);

        $this->assertFalse($description['uses_periods']);
        $this->assertSame(100.0, $description['total_weight']);
        $this->assertSame([$itemId], array_map(static fn (array $i) => (int) $i['id'], $description['categories'][0]['items']));
        $this->assertSame([$loose], array_map(static fn (array $i) => (int) $i['id'], $description['uncategorized']));
        $this->assertSame([], $description['periods']);
    }

    public function testDescribeWithPeriodsTotalsEachPeriodAndListsUnassignedCategories(): void
    {
        $this->scheme();
        $this->service->updateScheme($this->instructor, $this->offering, ['uses_periods' => true]);
        $this->service->createCategory($this->offering, ['name' => 'Stray', 'weight_percentage' => 5]);

        $description = $this->service->describe($this->offering);

        $this->assertTrue($description['uses_periods']);
        $this->assertSame(100.0, $description['total_weight'], 'Full Term is 100%');
        $this->assertSame(100.0, $description['periods'][0]['weight_total'], 'Quizzes 30 + Exams 70 inside Full Term');
        $this->assertSame(['Stray'], array_column($description['unassigned_categories'], 'name'));
    }

    public function testTheRosterListsEveryEnrolledStudentWithTheirScoresAndGrade(): void
    {
        $itemId = $this->quizItem();
        $this->service->updateManualScore($this->instructor, $this->offering, $itemId, $this->student->id, 15);
        $other = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $other);

        $roster = $this->service->roster($this->offering);

        $this->assertCount(2, $roster['students']);
        $this->assertSame([$itemId], array_map(static fn (array $i) => (int) $i['id'], $roster['items']));

        $byId = array_column($roster['students'], null, 'student_id');
        $this->assertSame(15.0, $byId[$this->student->id]['scores'][$itemId]);
        $this->assertSame(75.0, $byId[$this->student->id]['percentage']);
        $this->assertSame('Passed', $byId[$this->student->id]['remarks']);
        $this->assertNull($byId[$other->id]['scores'][$itemId]);
        $this->assertNull($byId[$other->id]['percentage']);
        $this->assertFalse($byId[$other->id]['is_override']);
    }

    public function testAnOverrideShowsOnTheRoster(): void
    {
        $this->quizItem();
        $this->service->overrideGrade($this->instructor, $this->offering, $this->student->id, 90.0, 'Excused absence');

        $row = $this->service->roster($this->offering)['students'][0];

        $this->assertTrue($row['is_override']);
        $this->assertSame(90.0, $row['percentage']);
        $this->assertSame('Excused absence', $row['override_reason']);
    }
}
