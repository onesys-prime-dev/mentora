<?php

declare(strict_types=1);

use App\Services\Courses\LearningProgressService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Dependencies for modules and learning units, the way learning items have them: none, the previous one, or a custom
 * list of earlier ones. A module (unit) is completed when the student has completed every published item in it. A
 * locked module or unit locks the items inside it, and the server says so when one is opened.
 *
 * @internal
 */
final class ContainerDependenciesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private LearningProgressService $service;

    private int $offering;

    private User $ada;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new LearningProgressService();
        $this->offering   = CourseFactory::offering();
        $this->ada        = CourseFactory::user('student');
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->ada);
    }

    /** @return array{0: int, 1: int, 2: int} module, unit, item */
    private function chain(int $order = 1): array
    {
        $module = CourseFactory::module($this->offering, 'published', $order);
        $unit   = CourseFactory::unit($module);
        [$item] = CourseFactory::items($unit, [['type' => 'reading']]);

        return [$module, $unit, $item];
    }

    private function mode(string $table, int $id): string
    {
        return (string) db_connect()->table($table)->where('id', $id)->get()->getRowArray()['prerequisite_mode'];
    }

    /*
    |--------------------------------------------------------------------------
    | The modal's data
    |--------------------------------------------------------------------------
    */

    public function testAUnitOffersTheEarlierUnitsOfItsOwnModule(): void
    {
        $module = CourseFactory::module($this->offering);
        $first  = CourseFactory::unit($module, 'published', 1);
        $second = CourseFactory::unit($module, 'draft', 2);
        $third  = CourseFactory::unit($module, 'published', 3);
        $other  = CourseFactory::unit(CourseFactory::module($this->offering, 'published', 2));

        $options = $this->service->containerDependencyOptions('unit', $this->offering, $third);

        $this->assertSame([$first, $second], array_column($options['candidates'], 'id'));
        $this->assertSame($second, $options['previous']['id']);
        $this->assertSame('none', $options['mode']);
        $this->assertNotContains($other, array_column($options['candidates'], 'id'));
        $this->assertSame('draft', $options['candidates'][1]['status']);
    }

    public function testTheFirstUnitHasNothingBeforeIt(): void
    {
        $module = CourseFactory::module($this->offering);
        $first  = CourseFactory::unit($module, 'published', 1);

        $options = $this->service->containerDependencyOptions('unit', $this->offering, $first);

        $this->assertSame([], $options['candidates']);
        $this->assertNull($options['previous']);
    }

    public function testAModuleOffersTheEarlierModulesOfTheCourse(): void
    {
        $a = CourseFactory::module($this->offering, 'published', 1);
        $b = CourseFactory::module($this->offering, 'published', 2);
        CourseFactory::module(CourseFactory::offering('B'), 'published', 1);

        $options = $this->service->containerDependencyOptions('module', $this->offering, $b);

        $this->assertSame([$a], array_column($options['candidates'], 'id'));
        $this->assertSame($a, $options['previous']['id']);
    }

    public function testAnItemOfAnotherCourseIsNotFound(): void
    {
        $foreign = CourseFactory::module(CourseFactory::offering('B'));

        $this->assertNull($this->service->containerDependencyOptions('module', $this->offering, $foreign));
        $this->assertFalse($this->service->saveContainerDependencies('module', $this->offering, $foreign, 'none', [])['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    public function testAUnitSavesPreviousCustomAndNone(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        $c      = CourseFactory::unit($module, 'published', 3);

        $this->assertTrue($this->service->saveContainerDependencies('unit', $this->offering, $c, 'previous', [])['success']);
        $this->assertSame('previous', $this->mode('learning_units', $c));

        $result = $this->service->saveContainerDependencies('unit', $this->offering, $c, 'custom', [$a, $b]);
        $this->assertTrue($result['success']);
        $this->assertSame('custom', $this->mode('learning_units', $c));
        $this->assertSame([$a, $b], array_map('intval', array_column(db_connect()->table('learning_unit_prerequisites')->where('learning_unit_id', $c)->orderBy('prerequisite_unit_id')->get()->getResultArray(), 'prerequisite_unit_id')));

        $this->assertTrue($this->service->saveContainerDependencies('unit', $this->offering, $c, 'none', [])['success']);
        $this->assertSame('none', $this->mode('learning_units', $c));
        $this->assertSame(0, db_connect()->table('learning_unit_prerequisites')->where('learning_unit_id', $c)->countAllResults(), 'the links go with it');
    }

    public function testAModuleSavesCustomDependencies(): void
    {
        $a = CourseFactory::module($this->offering, 'published', 1);
        $b = CourseFactory::module($this->offering, 'published', 2);

        $this->assertTrue($this->service->saveContainerDependencies('module', $this->offering, $b, 'custom', [$a])['success']);

        $this->assertSame('custom', $this->mode('course_modules', $b));
        $this->assertSame(1, db_connect()->table('course_module_prerequisites')->where(['module_id' => $b, 'prerequisite_module_id' => $a])->countAllResults());
    }

    public function testThereIsNoPreviousForTheFirstOne(): void
    {
        $a = CourseFactory::module($this->offering, 'published', 1);

        $result = $this->service->saveContainerDependencies('module', $this->offering, $a, 'previous', []);

        $this->assertFalse($result['success']);
        $this->assertSame('none', $this->mode('course_modules', $a));
    }

    public function testOnlyEarlierSiblingsCountAsPrerequisites(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        $other  = CourseFactory::unit(CourseFactory::module($this->offering, 'published', 2));

        // $b is after $a, and $other is in another module: only the earlier sibling is kept; with none left it is "none".
        $this->assertSame([$a], $this->service->saveContainerDependencies('unit', $this->offering, $b, 'custom', [$a, $b, $other])['prerequisiteIds']);

        $result = $this->service->saveContainerDependencies('unit', $this->offering, $a, 'custom', [$b, $other]);
        $this->assertSame('none', $result['mode']);
        $this->assertSame('none', $this->mode('learning_units', $a));
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $a = CourseFactory::module($this->offering);

        $this->assertFalse($this->service->saveContainerDependencies('module', $this->offering, $a, 'sometimes', [])['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | What students meet
    |--------------------------------------------------------------------------
    */

    public function testAUnitWaitsForThePreviousUnitToBeCompleted(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        [$a1, $a2] = CourseFactory::items($a, [['title' => 'A1'], ['title' => 'A2']]);
        [$b1]      = CourseFactory::items($b, [['title' => 'B1']]);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'previous', []);

        $lock = $this->service->lockFor($this->ada, $b1);
        $this->assertNotNull($lock);
        $this->assertSame([['id' => $a, 'title' => (string) db_connect()->table('learning_units')->where('id', $a)->get()->getRowArray()['title']]], $lock['prerequisites']);

        CourseFactory::progress($a1, $this->ada);
        $this->assertNotNull($this->service->lockFor($this->ada, $b1), 'half of it is not enough');

        CourseFactory::progress($a2, $this->ada);
        $this->assertNull($this->service->lockFor($this->ada, $b1));
    }

    public function testADraftItemDoesNotHoldTheUnitBack(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        [$a1]   = CourseFactory::items($a, [['title' => 'A1'], ['title' => 'Hidden', 'status' => 'draft']]);
        [$b1]   = CourseFactory::items($b, [['title' => 'B1']]);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'previous', []);

        CourseFactory::progress($a1, $this->ada);

        $this->assertNull($this->service->lockFor($this->ada, $b1));
    }

    public function testAnEmptyUnitNeverHoldsAnythingBack(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        [$b1]   = CourseFactory::items($b, [['title' => 'B1']]);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'custom', [$a]);

        $this->assertNull($this->service->lockFor($this->ada, $b1));
    }

    public function testAModuleLocksTheItemsInsideIt(): void
    {
        [$m1, , $item1] = $this->chain(1);
        [$m2, , $item2] = $this->chain(2);
        $this->service->saveContainerDependencies('module', $this->offering, $m2, 'previous', []);

        $lock = $this->service->lockFor($this->ada, $item2);
        $this->assertNotNull($lock);
        $this->assertStringContainsString('first', $lock['message']);
        $this->assertSame($m1, $lock['prerequisites'][0]['id']);

        CourseFactory::progress($item1, $this->ada);
        $this->assertNull($this->service->lockFor($this->ada, $item2));
    }

    public function testACustomModuleDependencyNeedsEveryListedModule(): void
    {
        [$m1, , $i1] = $this->chain(1);
        [$m2, , $i2] = $this->chain(2);
        [$m3, , $i3] = $this->chain(3);
        $this->service->saveContainerDependencies('module', $this->offering, $m3, 'custom', [$m1, $m2]);

        CourseFactory::progress($i1, $this->ada);
        $this->assertNotNull($this->service->lockFor($this->ada, $i3));

        CourseFactory::progress($i2, $this->ada);
        $this->assertNull($this->service->lockFor($this->ada, $i3));
    }

    public function testAnInstructorIsNeverLocked(): void
    {
        $this->chain(1);
        [$m2, , $i2] = $this->chain(2);
        $this->service->saveContainerDependencies('module', $this->offering, $m2, 'previous', []);

        $this->assertNull($this->service->lockFor($this->instructor, $i2));
    }

    public function testTheBatchLookupAgreesWithTheSingleOne(): void
    {
        [, , $i1]    = $this->chain(1);
        [$m2, , $i2] = $this->chain(2);
        $this->service->saveContainerDependencies('module', $this->offering, $m2, 'previous', []);

        $locks = $this->service->locksFor($this->ada, [$i1, $i2]);

        $this->assertNull($locks[$i1]);
        $this->assertNotNull($locks[$i2]);
        $this->assertSame($this->service->lockFor($this->ada, $i2)['message'], $locks[$i2]['message']);
    }

    public function testTheTreeSaysWhichUnitsAndModulesAreLocked(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        CourseFactory::items($a, [['title' => 'A1']]);
        CourseFactory::items($b, [['title' => 'B1']]);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'previous', []);

        $tree = [
            'id'             => $module,
            'learning_units' => [
                ['id' => $a, 'title' => 'A', 'learning_items' => db_connect()->table('learning_items')->where('learning_unit_id', $a)->get()->getResultArray()],
                ['id' => $b, 'title' => 'B', 'learning_items' => db_connect()->table('learning_items')->where('learning_unit_id', $b)->get()->getResultArray()],
            ],
        ];

        $this->service->annotateTree($this->ada, $tree);

        $this->assertFalse($tree['learning_units'][0]['is_locked']);
        $this->assertTrue($tree['learning_units'][1]['is_locked']);
        $this->assertSame($a, $tree['learning_units'][1]['locked_by'][0]['id']);
        $this->assertTrue($tree['learning_units'][1]['learning_items'][0]['is_locked'], 'its items are locked with it');
        $this->assertFalse($tree['learning_units'][0]['learning_items'][0]['is_locked']);
    }

    public function testTheInstructorsTreeLabelsWhatEachUnitWaitsFor(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'previous', []);

        $tree = ['id' => $module, 'learning_units' => [
            ['id' => $a, 'title' => 'First unit', 'learning_items' => []],
            ['id' => $b, 'title' => 'Second unit', 'learning_items' => []],
        ]];

        $this->service->annotateTree($this->instructor, $tree);

        $this->assertSame([], $tree['learning_units'][0]['dependency_labels']);
        $this->assertSame(['First unit'], $tree['learning_units'][1]['dependency_labels']);
    }

    /*
    |--------------------------------------------------------------------------
    | Reordering
    |--------------------------------------------------------------------------
    */

    public function testAReorderDropsADependencyOnSomethingThatIsNowLater(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'custom', [$a]);

        // b moves above a.
        db_connect()->table('learning_units')->where('id', $b)->update(['order_index' => 0]);
        $this->service->pruneContainerDependencies('unit', $module);

        $this->assertSame('none', $this->mode('learning_units', $b));
        $this->assertSame(0, db_connect()->table('learning_unit_prerequisites')->where('learning_unit_id', $b)->countAllResults());
    }

    public function testAReorderOfModulesDoesTheSame(): void
    {
        $a = CourseFactory::module($this->offering, 'published', 1);
        $b = CourseFactory::module($this->offering, 'published', 2);
        $this->service->saveContainerDependencies('module', $this->offering, $b, 'previous', []);
        $this->service->saveContainerDependencies('module', $this->offering, $b, 'custom', [$a]);

        db_connect()->table('course_modules')->where('id', $b)->update(['order_index' => 0]);
        $this->service->pruneContainerDependencies('module', $this->offering);

        $this->assertSame('none', $this->mode('course_modules', $b));
    }

    public function testAPreviousDependencyFollowsTheOrderWithoutPruning(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'previous', []);

        $this->service->pruneContainerDependencies('unit', $module);

        $this->assertSame('previous', $this->mode('learning_units', $b));
    }

    public function testReorderingUnitsThroughTheServiceDropsTheStaleDependency(): void
    {
        $module = CourseFactory::module($this->offering);
        $a      = CourseFactory::unit($module, 'published', 1);
        $b      = CourseFactory::unit($module, 'published', 2);
        $this->service->saveContainerDependencies('unit', $this->offering, $b, 'custom', [$a]);
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        (new \App\Services\Courses\ModuleService())->reorderLearningUnits($this->instructor, $this->offering, [
            ['id' => $b, 'order_index' => 1],
            ['id' => $a, 'order_index' => 2],
        ]);

        $this->assertSame('none', $this->mode('learning_units', $b));
    }

    public function testDeletingAModuleLeavesNothingWaitingOnIt(): void
    {
        $a = CourseFactory::module($this->offering, 'published', 1);
        $b = CourseFactory::module($this->offering, 'published', 2);
        $this->service->saveContainerDependencies('module', $this->offering, $b, 'custom', [$a]);

        (new \App\Services\Courses\ContentDeletionService())->deleteModule($a);

        $this->assertSame('none', $this->mode('course_modules', $b));
    }
}
