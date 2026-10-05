<?php

declare(strict_types=1);

use App\Services\Courses\ModuleService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * An instructor can only change the content of the section they are assigned
 * to. Each write method is pointed at another section's module, unit or item
 * and must refuse without changing anything; the same call on the instructor's
 * own section still works.
 *
 * @internal
 */
final class ModuleServiceSectionGuardTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ModuleService $service;
    private int $mine;
    private int $theirs;
    private User $instructor;
    private User $student;

    /** The other section's content, all drafts. */
    private int $theirModule;
    private int $theirUnit;
    private int $theirItem;

    /** This section's content. */
    private int $myModule;
    private int $myUnit;
    private int $myItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ModuleService();
        $this->mine = CourseFactory::offering('A');
        $this->theirs = CourseFactory::offering('B');
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $this->instructor);
        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine, $this->student);

        $this->theirModule = CourseFactory::module($this->theirs, 'draft', 1);
        $this->theirUnit = CourseFactory::unit($this->theirModule, 'draft');
        [$this->theirItem] = CourseFactory::items($this->theirUnit, [['type' => 'reading', 'status' => 'draft']]);

        $this->myModule = CourseFactory::module($this->mine, 'draft', 1);
        $this->myUnit = CourseFactory::unit($this->myModule, 'draft');
        [$this->myItem] = CourseFactory::items($this->myUnit, [['type' => 'reading', 'status' => 'draft']]);
    }

    private function request(array $post): IncomingRequest
    {
        $request = new IncomingRequest(config('App'), new SiteURI(config('App')), null, new UserAgent());
        $request->setGlobal('post', $post);

        return $request;
    }

    private function field(string $table, int $id, string $column): mixed
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray()[$column];
    }

    private function refused(callable $call): bool
    {
        try {
            $result = $call();
        } catch (PageNotFoundException) {
            return true;
        }

        return is_array($result) && ($result['success'] ?? true) === false;
    }

    /*
    |--------------------------------------------------------------------------
    | Units
    |--------------------------------------------------------------------------
    */

    public function testCannotAddAUnitToAnotherSectionsModule(): void
    {
        $post = $this->request(['title' => 'Planted', 'description' => '']);

        $this->assertTrue($this->refused(fn () => $this->service->storeLearningUnit($this->instructor, $this->mine, $this->theirModule, $post)));
        $this->assertSame(0, db_connect()->table('learning_units')->where('title', 'Planted')->countAllResults());
    }

    public function testCannotAddAUnitToAModuleThatDoesNotExist(): void
    {
        $post = $this->request(['title' => 'Planted', 'description' => '']);

        $this->assertTrue($this->refused(fn () => $this->service->storeLearningUnit($this->instructor, $this->mine, 999999, $post)));
        $this->assertSame(0, db_connect()->table('learning_units')->where('title', 'Planted')->countAllResults());
    }

    public function testCanStillAddAUnitToOwnModule(): void
    {
        $post = $this->request(['title' => 'Mine', 'description' => '']);

        $this->assertTrue($this->service->storeLearningUnit($this->instructor, $this->mine, $this->myModule, $post)['success']);
    }

    public function testCannotEditAnotherSectionsUnit(): void
    {
        $post = $this->request(['title' => 'HACKED', 'description' => 'HACKED']);
        $before = $this->field('learning_units', $this->theirUnit, 'title');

        // Both with the other section's module in the URL and with one of ours.
        $this->assertTrue($this->refused(fn () => $this->service->updateLearningUnit($this->instructor, $this->mine, $this->theirModule, $this->theirUnit, $post)));
        $this->assertTrue($this->refused(fn () => $this->service->updateLearningUnit($this->instructor, $this->mine, $this->myModule, $this->theirUnit, $post)));
        $this->assertSame($before, $this->field('learning_units', $this->theirUnit, 'title'));
    }

    public function testCannotEditAUnitThroughTheWrongModuleOfOwnSection(): void
    {
        $otherOfMine = CourseFactory::module($this->mine, 'draft', 2);
        $post = $this->request(['title' => 'HACKED', 'description' => '']);

        $this->assertTrue($this->refused(fn () => $this->service->updateLearningUnit($this->instructor, $this->mine, $otherOfMine, $this->myUnit, $post)));
        $this->assertNotSame('HACKED', $this->field('learning_units', $this->myUnit, 'title'));
    }

    public function testCanStillEditOwnUnit(): void
    {
        $post = $this->request(['title' => 'Renamed', 'description' => '']);

        $this->assertTrue($this->service->updateLearningUnit($this->instructor, $this->mine, $this->myModule, $this->myUnit, $post)['success']);
        $this->assertSame('Renamed', $this->field('learning_units', $this->myUnit, 'title'));
    }

    public function testCannotPublishAnotherSectionsUnit(): void
    {
        $this->assertTrue($this->refused(fn () => $this->service->updateLearningUnitStatus($this->instructor, $this->mine, $this->theirUnit, ['status' => 'published'])));
        $this->assertSame('draft', $this->field('learning_units', $this->theirUnit, 'status'));
    }

    public function testCanStillPublishOwnUnit(): void
    {
        $this->assertTrue($this->service->updateLearningUnitStatus($this->instructor, $this->mine, $this->myUnit, ['status' => 'published'])['success']);
        $this->assertSame('published', $this->field('learning_units', $this->myUnit, 'status'));
    }

    /*
    |--------------------------------------------------------------------------
    | Reordering
    |--------------------------------------------------------------------------
    */

    public function testReorderingModulesLeavesAnotherSectionsModulesAlone(): void
    {
        $this->service->reorderModules($this->instructor, $this->mine, [
            ['id' => $this->theirModule, 'order_index' => 99],
            ['id' => $this->myModule, 'order_index' => 7],
        ]);

        $this->assertSame(1, (int) $this->field('course_modules', $this->theirModule, 'order_index'));
        $this->assertSame(7, (int) $this->field('course_modules', $this->myModule, 'order_index'), 'our own module is still reordered');
    }

    public function testReorderingModulesSkipsMalformedRows(): void
    {
        $result = $this->service->reorderModules($this->instructor, $this->mine, [
            ['id' => $this->myModule],
            ['order_index' => 5],
            ['id' => $this->myModule, 'order_index' => 3],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, (int) $this->field('course_modules', $this->myModule, 'order_index'));
    }

    public function testReorderingItemsLeavesAnotherSectionsItemsAlone(): void
    {
        $this->service->reorderLearningItems($this->instructor, $this->mine, [
            ['id' => $this->theirItem, 'order_index' => 99],
            ['id' => $this->myItem, 'order_index' => 7],
        ]);

        $this->assertSame(1, (int) $this->field('learning_items', $this->theirItem, 'order_index'));
        $this->assertSame(7, (int) $this->field('learning_items', $this->myItem, 'order_index'), 'our own item is still reordered');
    }

    public function testReorderingItemsSkipsMalformedRowsAndMissingItems(): void
    {
        $result = $this->service->reorderLearningItems($this->instructor, $this->mine, [
            ['id' => $this->myItem],
            ['order_index' => 5],
            ['id' => 999999, 'order_index' => 5],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) $this->field('learning_items', $this->myItem, 'order_index'));
    }

    /*
    |--------------------------------------------------------------------------
    | Items
    |--------------------------------------------------------------------------
    */

    public function testCannotEditAnotherSectionsItem(): void
    {
        $this->assertTrue($this->refused(fn () => $this->service->updateLearningItem($this->instructor, $this->mine, $this->theirItem, ['title' => 'HACKED', 'status' => 'published'])));
        $this->assertNotSame('HACKED', $this->field('learning_items', $this->theirItem, 'title'));
        $this->assertSame('draft', $this->field('learning_items', $this->theirItem, 'status'));
    }

    public function testCanStillEditOwnItem(): void
    {
        $this->assertTrue($this->service->updateLearningItem($this->instructor, $this->mine, $this->myItem, ['title' => 'Renamed'])['success']);
        $this->assertSame('Renamed', $this->field('learning_items', $this->myItem, 'title'));
    }

    public function testCannotChangeAnotherSectionsVideoItem(): void
    {
        $post = $this->request(['source' => 'link', 'resource_url' => 'https://evil.test/x']);

        $this->assertTrue($this->refused(fn () => $this->service->updateVideoItem($this->instructor, $this->mine, $this->theirItem, $post)));
        $this->assertSame(0, db_connect()->table('learning_resources')->where('learning_item_id', $this->theirItem)->countAllResults());
        $this->assertNull($this->field('learning_items', $this->theirItem, 'content'));
    }

    public function testAStudentCannotChangeAVideoItem(): void
    {
        $post = $this->request(['source' => 'link', 'resource_url' => 'https://evil.test/x']);

        $result = $this->service->updateVideoItem($this->student, $this->mine, $this->myItem, $post);

        $this->assertFalse($result['success']);
        $this->assertSame(0, db_connect()->table('learning_resources')->where('learning_item_id', $this->myItem)->countAllResults());
    }

    public function testCanStillChangeOwnVideoItem(): void
    {
        $post = $this->request(['source' => 'link', 'resource_url' => 'https://example.com/v.mp4']);

        $this->assertTrue($this->service->updateVideoItem($this->instructor, $this->mine, $this->myItem, $post)['success']);
        $this->assertSame(1, db_connect()->table('learning_resources')->where('learning_item_id', $this->myItem)->countAllResults());
    }
}
