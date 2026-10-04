<?php

declare(strict_types=1);

use App\Services\Courses\ContentDeletionService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Deleting a module, unit, item or assessment must remove everything that
 * hangs off it, keep the numbering contiguous, leave other courses alone, and
 * purge uploaded files only once nothing points at them.
 *
 * @internal
 */
final class ContentDeletionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ContentDeletionService $service;

    private int $offeringId;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ContentDeletionService();

        $this->offeringId = CourseFactory::offering();
        $this->student = CourseFactory::user('student');
        $this->instructor = CourseFactory::user('instructor');

        CourseFactory::enroll($this->offeringId, $this->student);
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    private function rowsWhere(string $table, string $column, int $value): int
    {
        return db_connect()->table($table)->where($column, $value)->countAllResults();
    }

    /**
     * @return list<int> the ids of the rows in $table under $parent, in order
     */
    private function ordered(string $table, string $parentColumn, int $parentId): array
    {
        $rows = db_connect()->table($table)->select(['id', 'order_index'])
            ->where($parentColumn, $parentId)->orderBy('order_index', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $row) => (int) $row['id'], $rows);
    }

    private function orderIndexes(string $table, string $parentColumn, int $parentId): array
    {
        $rows = db_connect()->table($table)->select('order_index')
            ->where($parentColumn, $parentId)->orderBy('order_index', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $row) => (int) $row['order_index'], $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    */

    public function testDeletingAModuleRemovesItsUnitsItemsAndEverythingAttached(): void
    {
        $moduleId = CourseFactory::module($this->offeringId);
        $unitA = CourseFactory::unit($moduleId, 'published', 1);
        $unitB = CourseFactory::unit($moduleId, 'published', 2);
        [$a1, $a2] = CourseFactory::items($unitA, [[], ['mode' => 'custom']]);
        [$b1] = CourseFactory::items($unitB, [[]]);

        CourseFactory::requires($a2, $a1);
        CourseFactory::progress($a1, $this->student);
        CourseFactory::resource($b1, 'link', 'https://example.com');

        $this->assertTrue($this->service->deleteModule($moduleId));

        $this->assertSame(0, $this->rowsWhere('course_modules', 'id', $moduleId));
        $this->assertSame(0, $this->rowsWhere('learning_units', 'module_id', $moduleId));
        $this->assertSame(0, db_connect()->table('learning_items')->whereIn('id', [$a1, $a2, $b1])->countAllResults());
        $this->assertSame(0, $this->rowsWhere('learning_completions', 'learning_item_id', $a1));
        $this->assertSame(0, $this->rowsWhere('learning_item_prerequisites', 'learning_item_id', $a2));
        $this->assertSame(0, $this->rowsWhere('learning_resources', 'learning_item_id', $b1));
    }

    public function testDeletingAModuleKeepsTheRemainingModulesNumberedWithoutGaps(): void
    {
        $first = CourseFactory::module($this->offeringId, 'published', 1);
        $second = CourseFactory::module($this->offeringId, 'published', 2);
        $third = CourseFactory::module($this->offeringId, 'published', 3);

        $this->service->deleteModule($second);

        $this->assertSame([$first, $third], $this->ordered('course_modules', 'offering_id', $this->offeringId));
        $this->assertSame([1, 2], $this->orderIndexes('course_modules', 'offering_id', $this->offeringId));
    }

    public function testDeletingAModuleNeverTouchesAnotherOffering(): void
    {
        $otherOffering = CourseFactory::offering('B');
        $otherModule = CourseFactory::module($otherOffering, 'published', 5);
        $otherUnit = CourseFactory::unit($otherModule);
        CourseFactory::items($otherUnit, [[]]);

        $mine = CourseFactory::module($this->offeringId);
        $this->service->deleteModule($mine);

        $this->assertSame(1, $this->rowsWhere('course_modules', 'id', $otherModule));
        $this->assertSame(1, $this->rowsWhere('learning_units', 'module_id', $otherModule));
        $this->assertSame([5], $this->orderIndexes('course_modules', 'offering_id', $otherOffering), 'only the deleted module\'s own offering is renumbered');
    }

    /*
    |--------------------------------------------------------------------------
    | Units
    |--------------------------------------------------------------------------
    */

    public function testDeletingAUnitRemovesItsItemsAndRenumbersItsSiblings(): void
    {
        $moduleId = CourseFactory::module($this->offeringId);
        $first = CourseFactory::unit($moduleId, 'published', 1);
        $second = CourseFactory::unit($moduleId, 'published', 2);
        $third = CourseFactory::unit($moduleId, 'published', 3);
        [$item] = CourseFactory::items($second, [[]]);
        CourseFactory::progress($item, $this->student);

        $this->assertTrue($this->service->deleteLearningUnit($second));

        $this->assertSame(0, $this->rowsWhere('learning_items', 'id', $item));
        $this->assertSame(0, $this->rowsWhere('learning_completions', 'learning_item_id', $item));
        $this->assertSame([$first, $third], $this->ordered('learning_units', 'module_id', $moduleId));
        $this->assertSame([1, 2], $this->orderIndexes('learning_units', 'module_id', $moduleId));
    }

    /*
    |--------------------------------------------------------------------------
    | Items
    |--------------------------------------------------------------------------
    */

    public function testDeletingAnItemRenumbersItsSiblings(): void
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offeringId));
        [$a, $b, $c] = CourseFactory::items($unit, [[], [], []]);

        $this->assertTrue($this->service->deleteLearningItem($b));

        $this->assertSame([$a, $c], $this->ordered('learning_items', 'learning_unit_id', $unit));
        $this->assertSame([1, 2], $this->orderIndexes('learning_items', 'learning_unit_id', $unit));
    }

    public function testDeletingAnItemOthersWaitOnReleasesThem(): void
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offeringId));
        [$a, $b, $c] = CourseFactory::items($unit, [[], [], ['mode' => 'custom']]);
        CourseFactory::requires($c, $a);

        $this->service->deleteLearningItem($a);

        $dependent = db_connect()->table('learning_items')->where('id', $c)->get()->getRowArray();
        $this->assertSame('none', $dependent['prerequisite_mode'], 'C had only A as a prerequisite');
        $this->assertSame(0, $this->rowsWhere('learning_item_prerequisites', 'learning_item_id', $c));
        $this->assertSame(1, $this->rowsWhere('learning_items', 'id', $b));
    }

    public function testDeletingADiscussionItemKeepsItsThreadAsAGeneralDiscussion(): void
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offeringId));
        [$item] = CourseFactory::items($unit, [['type' => 'discussion']]);
        $threadId = CourseFactory::thread($this->offeringId, $this->instructor, $item);

        $this->service->deleteLearningItem($item);

        $thread = db_connect()->table('discussion_threads')->where('id', $threadId)->get()->getRowArray();

        $this->assertNotNull($thread, 'the conversation must survive');
        $this->assertNull($thread['learning_item_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Assessments
    |--------------------------------------------------------------------------
    */

    public function testDeletingAnItemAlsoDeletesItsAssessment(): void
    {
        $moduleId = CourseFactory::module($this->offeringId);
        $unit = CourseFactory::unit($moduleId);
        [$item] = CourseFactory::items($unit, [['type' => 'quiz']]);
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $moduleId, $item);

        $this->service->deleteLearningItem($item);

        $this->assertSame(0, $this->rowsWhere('assessments', 'id', $assessmentId));
    }

    public function testDeletingAnAssessmentAlsoDeletesItsLearningItem(): void
    {
        $moduleId = CourseFactory::module($this->offeringId);
        $unit = CourseFactory::unit($moduleId);
        [$first, $quizItem, $last] = CourseFactory::items($unit, [[], ['type' => 'quiz'], []]);
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $moduleId, $quizItem);

        $this->assertTrue($this->service->deleteAssessment($assessmentId));

        $this->assertSame(0, $this->rowsWhere('assessments', 'id', $assessmentId));
        $this->assertSame(0, $this->rowsWhere('learning_items', 'id', $quizItem));
        $this->assertSame([$first, $last], $this->ordered('learning_items', 'learning_unit_id', $unit));
        $this->assertSame([1, 2], $this->orderIndexes('learning_items', 'learning_unit_id', $unit));
    }

    public function testDeletingAnAssessmentWithoutAnItemWorks(): void
    {
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor);

        $this->assertTrue($this->service->deleteAssessment($assessmentId));
        $this->assertSame(0, $this->rowsWhere('assessments', 'id', $assessmentId));
    }

    public function testDeletingAModuleAlsoDeletesTheAssessmentsOfItsItems(): void
    {
        $moduleId = CourseFactory::module($this->offeringId);
        $unit = CourseFactory::unit($moduleId);
        [$item] = CourseFactory::items($unit, [['type' => 'assignment']]);
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $moduleId, $item, 'Assignment');

        $this->service->deleteModule($moduleId);

        $this->assertSame(0, $this->rowsWhere('assessments', 'id', $assessmentId));
    }

    /*
    |--------------------------------------------------------------------------
    | Uploaded files
    |--------------------------------------------------------------------------
    */

    public function testDeletingAnItemRemovesItsUploadedFileFromDatabaseAndDisk(): void
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offeringId));
        [$item] = CourseFactory::items($unit, [['type' => 'pdf']]);
        $file = CourseFactory::file($this->instructor);
        CourseFactory::resource($item, 'pdf', null, $file['id']);

        $this->assertFileExists($file['path']);

        $this->service->deleteLearningItem($item);

        $this->assertSame(0, $this->rowsWhere('files', 'id', $file['id']));
        $this->assertFileDoesNotExist($file['path']);
    }

    public function testAFileStillUsedByAnotherItemIsKept(): void
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offeringId));
        [$first, $second] = CourseFactory::items($unit, [['type' => 'pdf'], ['type' => 'pdf']]);
        $file = CourseFactory::file($this->instructor);
        CourseFactory::resource($first, 'pdf', null, $file['id']);
        CourseFactory::resource($second, 'pdf', null, $file['id']);

        $this->service->deleteLearningItem($first);

        $this->assertSame(1, $this->rowsWhere('files', 'id', $file['id']), 'the second item still shows this file');
        $this->assertFileExists($file['path']);

        $this->service->deleteLearningItem($second);

        $this->assertSame(0, $this->rowsWhere('files', 'id', $file['id']));
        $this->assertFileDoesNotExist($file['path']);
    }

    /*
    |--------------------------------------------------------------------------
    | Things that do not exist
    |--------------------------------------------------------------------------
    */

    public function testDeletingSomethingThatDoesNotExistReturnsFalse(): void
    {
        $this->assertFalse($this->service->deleteModule(999999));
        $this->assertFalse($this->service->deleteLearningUnit(999999));
        $this->assertFalse($this->service->deleteLearningItem(999999));
        $this->assertFalse($this->service->deleteAssessment(999999));
    }
}
