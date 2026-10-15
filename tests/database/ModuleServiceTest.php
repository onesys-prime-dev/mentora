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
 * Building a course's content: modules, learning units and learning items
 * (create, edit, publish, reorder, delete), the instructor-must-teach-this-
 * section rule, the quiz/assignment/discussion items that bring a second
 * record with them, link validation, and the module picker.
 *
 * @internal
 */
final class ModuleServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ModuleService $service;
    private int $offeringId;
    private User $instructor;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ModuleService();
        $this->offeringId = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offeringId, $this->instructor);
        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $this->student);
    }

    private function request(array $post): IncomingRequest
    {
        $request = new IncomingRequest(config('App'), new SiteURI(config('App')), null, new UserAgent());
        $request->setGlobal('post', $post);

        return $request;
    }

    private function row(string $table, int $id): ?array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function rows(string $table, array $where): int
    {
        return db_connect()->table($table)->where($where)->countAllResults();
    }

    /** A module, unit and item in this offering. @return array{0:int,1:int,2:int} */
    private function tree(string $itemType = 'reading', string $status = 'published'): array
    {
        $module = CourseFactory::module($this->offeringId, $status);
        $unit = CourseFactory::unit($module, $status);
        [$item] = CourseFactory::items($unit, [['type' => $itemType, 'status' => $status]]);

        return [$module, $unit, $item];
    }

    private function assertRefused(array $result, string $message): void
    {
        $this->assertFalse($result['success']);
        $this->assertSame($message, $result['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may edit
    |--------------------------------------------------------------------------
    */

    public function testAStudentCannotEditCourseContent(): void
    {
        $result = $this->service->storeModule($this->student, $this->offeringId, $this->request(['title' => 'X', 'description' => '']));

        $this->assertRefused($result, 'Not authorized.');
        $this->assertSame(0, $this->rows('course_modules', ['offering_id' => $this->offeringId]));
    }

    public function testAnInstructorOfAnotherSectionCannotEditThisOne(): void
    {
        $other = CourseFactory::user('instructor');
        CourseFactory::assignInstructor(CourseFactory::offering('B'), $other);

        $result = $this->service->storeModule($other, $this->offeringId, $this->request(['title' => 'X', 'description' => '']));

        $this->assertRefused($result, 'You are not assigned to this course offering.');
        $this->assertSame(0, $this->rows('course_modules', ['offering_id' => $this->offeringId]));
    }

    public function testEveryWriteMethodChecksWhoIsAskingFirst(): void
    {
        [$module, $unit, $item] = $this->tree();
        $stranger = $this->student;
        $post = $this->request(['title' => 'X', 'description' => '']);
        $offering = $this->offeringId;

        $calls = [
            fn () => $this->service->updateModule($stranger, $offering, $module, $post),
            fn () => $this->service->updateModuleStatus($stranger, $offering, $module, 'draft'),
            fn () => $this->service->reorderModules($stranger, $offering, []),
            fn () => $this->service->storeLearningUnit($stranger, $offering, $module, $post),
            fn () => $this->service->updateLearningUnit($stranger, $offering, $module, $unit, $post),
            fn () => $this->service->updateLearningUnitStatus($stranger, $offering, $unit, ['status' => 'draft']),
            fn () => $this->service->reorderLearningUnits($stranger, $offering, []),
            fn () => $this->service->storeLearningItem($stranger, $offering, $module, $unit, $post),
            fn () => $this->service->updateLearningItem($stranger, $offering, $item, ['title' => 'X']),
            fn () => $this->service->deleteLearningItem($stranger, $offering, $item),
            fn () => $this->service->deleteModule($stranger, $offering, $module),
            fn () => $this->service->deleteLearningUnit($stranger, $offering, $module, $unit),
            fn () => $this->service->reorderLearningItems($stranger, $offering, []),
        ];

        foreach ($calls as $index => $call) {
            $result = $call();
            $this->assertFalse($result['success'], "call #{$index} let a student through");
            $this->assertSame('Not authorized.', $result['message'], "call #{$index}");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    */

    public function testANewModuleStartsAsADraftAtTheEnd(): void
    {
        CourseFactory::module($this->offeringId, 'published', 4);

        $result = $this->service->storeModule($this->instructor, $this->offeringId, $this->request(['title' => '  Week 1  ', 'description' => ' Intro ']));

        $this->assertTrue($result['success']);
        $module = db_connect()->table('course_modules')->where('title', 'Week 1')->get()->getRowArray();
        $this->assertSame('draft', $module['status']);
        $this->assertSame('Intro', $module['description']);
        $this->assertSame(5, (int) $module['order_index']);
        $this->assertSame($this->offeringId, (int) $module['offering_id']);
    }

    public function testTheFirstModuleIsNumberedOne(): void
    {
        $this->service->storeModule($this->instructor, $this->offeringId, $this->request(['title' => 'First', 'description' => '']));

        $this->assertSame(1, (int) db_connect()->table('course_modules')->where('title', 'First')->get()->getRowArray()['order_index']);
    }

    public function testAModuleNeedsATitle(): void
    {
        $result = $this->service->storeModule($this->instructor, $this->offeringId, $this->request(['title' => '   ', 'description' => '']));

        $this->assertRefused($result, 'Module title is required.');
    }

    public function testEditingAModule(): void
    {
        [$module] = $this->tree();

        $result = $this->service->updateModule($this->instructor, $this->offeringId, $module, $this->request(['title' => ' New ', 'description' => ' Text ']));

        $this->assertTrue($result['success']);
        $row = $this->row('course_modules', $module);
        $this->assertSame('New', $row['title']);
        $this->assertSame('Text', $row['description']);
    }

    public function testEditingAModuleNeedsATitleAndARealModuleOfThisSection(): void
    {
        [$module] = $this->tree();
        $elsewhere = CourseFactory::module(CourseFactory::offering('B'));

        $this->assertRefused($this->service->updateModule($this->instructor, $this->offeringId, $module, $this->request(['title' => '', 'description' => ''])), 'Module title is required.');
        $this->assertRefused($this->service->updateModule($this->instructor, $this->offeringId, 999999, $this->request(['title' => 'X', 'description' => ''])), 'Module not found.');
        $this->assertRefused($this->service->updateModule($this->instructor, $this->offeringId, $elsewhere, $this->request(['title' => 'Hijacked', 'description' => ''])), 'Invalid module.');
        $this->assertNotSame('Hijacked', $this->row('course_modules', $elsewhere)['title']);
    }

    public function testPublishingAModule(): void
    {
        $module = CourseFactory::module($this->offeringId, 'draft');

        $result = $this->service->updateModuleStatus($this->instructor, $this->offeringId, $module, 'published');

        $this->assertTrue($result['success']);
        $this->assertSame('published', $this->row('course_modules', $module)['status']);
    }

    public function testModuleStatusChangesRefuseMissingAndForeignModules(): void
    {
        $elsewhere = CourseFactory::module(CourseFactory::offering('B'), 'draft');

        $this->assertRefused($this->service->updateModuleStatus($this->instructor, $this->offeringId, 999999, 'published'), 'Module not found.');
        $this->assertRefused($this->service->updateModuleStatus($this->instructor, $this->offeringId, $elsewhere, 'published'), 'Invalid module.');
        $this->assertSame('draft', $this->row('course_modules', $elsewhere)['status']);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function badStatuses(): iterable
    {
        yield 'unknown word' => ['banana'];
        yield 'wrong case' => ['Published'];
        yield 'empty' => [''];
        yield 'null' => [null];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('badStatuses')]
    public function testAModuleStatusMustBeOneTheDatabaseKnows(mixed $status): void
    {
        $module = CourseFactory::module($this->offeringId, 'draft');

        $result = $this->service->updateModuleStatus($this->instructor, $this->offeringId, $module, (string) $status);

        $this->assertRefused($result, 'Choose a valid status.');
        $this->assertSame('draft', $this->row('course_modules', $module)['status']);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('badStatuses')]
    public function testAUnitStatusMustBeOneTheDatabaseKnows(mixed $status): void
    {
        [, $unit] = $this->tree('reading', 'draft');

        $result = $this->service->updateLearningUnitStatus($this->instructor, $this->offeringId, $unit, ['status' => $status]);

        $this->assertRefused($result, 'Choose a valid status.');
        $this->assertSame('draft', $this->row('learning_units', $unit)['status']);
    }

    public function testAUnitStatusRequestWithoutAStatusIsRefused(): void
    {
        [, $unit] = $this->tree('reading', 'draft');

        $this->assertRefused($this->service->updateLearningUnitStatus($this->instructor, $this->offeringId, $unit, []), 'Choose a valid status.');
    }

    #[PHPUnit\Framework\Attributes\DataProvider('badStatuses')]
    public function testAnItemStatusMustBeOneTheDatabaseKnows(mixed $status): void
    {
        [, , $item] = $this->tree('reading', 'draft');

        $result = $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['title' => 'Changed', 'status' => $status]);

        $this->assertRefused($result, 'Choose a valid status.');
        $row = $this->row('learning_items', $item);
        $this->assertSame('draft', $row['status']);
        $this->assertNotSame('Changed', $row['title'], 'nothing is saved when the request is refused');
    }

    public function testEveryStatusTheDatabaseKnowsIsAccepted(): void
    {
        foreach (['published', 'inactive', 'draft'] as $status) {
            [$module, $unit, $item] = $this->tree('reading', 'draft');

            $this->assertTrue($this->service->updateModuleStatus($this->instructor, $this->offeringId, $module, $status)['success'], $status);
            $this->assertTrue($this->service->updateLearningUnitStatus($this->instructor, $this->offeringId, $unit, ['status' => $status])['success'], $status);
            $this->assertTrue($this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['status' => $status])['success'], $status);

            $this->assertSame($status, $this->row('course_modules', $module)['status']);
            $this->assertSame($status, $this->row('learning_units', $unit)['status']);
            $this->assertSame($status, $this->row('learning_items', $item)['status']);
        }
    }

    public function testAnInactiveQuizItemTurnsItsAssessmentIntoADraft(): void
    {
        [$module, , $item] = $this->tree('quiz', 'published');
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $module, $item);
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['status' => 'Published']);

        $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['status' => 'inactive']);

        $this->assertSame('Draft', $this->row('assessments', $assessmentId)['status']);
    }

    public function testReorderingModules(): void
    {
        $a = CourseFactory::module($this->offeringId, 'published', 1);
        $b = CourseFactory::module($this->offeringId, 'published', 2);

        $result = $this->service->reorderModules($this->instructor, $this->offeringId, [
            ['id' => $a, 'order_index' => 2],
            ['id' => $b, 'order_index' => 1],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, (int) $this->row('course_modules', $a)['order_index']);
        $this->assertSame(1, (int) $this->row('course_modules', $b)['order_index']);
    }

    public function testDeletingAModuleRemovesItsContent(): void
    {
        [$module, $unit, $item] = $this->tree();

        $result = $this->service->deleteModule($this->instructor, $this->offeringId, $module);

        $this->assertTrue($result['success']);
        $this->assertNull($this->row('course_modules', $module));
        $this->assertNull($this->row('learning_units', $unit));
        $this->assertNull($this->row('learning_items', $item));
    }

    public function testDeletingAModuleOfAnotherSectionOrNoneAtAll(): void
    {
        $elsewhere = CourseFactory::module(CourseFactory::offering('B'));

        $this->assertRefused($this->service->deleteModule($this->instructor, $this->offeringId, $elsewhere), 'Module not found.');
        $this->assertRefused($this->service->deleteModule($this->instructor, $this->offeringId, 999999), 'Module not found.');
        $this->assertNotNull($this->row('course_modules', $elsewhere));
    }

    /*
    |--------------------------------------------------------------------------
    | Learning units
    |--------------------------------------------------------------------------
    */

    public function testANewUnitStartsAsADraftAtTheEndOfItsModule(): void
    {
        $module = CourseFactory::module($this->offeringId);
        CourseFactory::unit($module, 'published', 3);

        $result = $this->service->storeLearningUnit($this->instructor, $this->offeringId, $module, $this->request(['title' => ' Basics ', 'description' => '']));

        $this->assertTrue($result['success']);
        $unit = db_connect()->table('learning_units')->where('title', 'Basics')->get()->getRowArray();
        $this->assertSame('draft', $unit['status']);
        $this->assertSame(4, (int) $unit['order_index']);
        $this->assertSame($module, (int) $unit['module_id']);
    }

    public function testAUnitNeedsATitle(): void
    {
        $module = CourseFactory::module($this->offeringId);

        $this->assertRefused(
            $this->service->storeLearningUnit($this->instructor, $this->offeringId, $module, $this->request(['title' => '', 'description' => ''])),
            'Learning unit title is required.'
        );
    }

    public function testEditingAUnit(): void
    {
        [$module, $unit] = $this->tree();

        $result = $this->service->updateLearningUnit($this->instructor, $this->offeringId, $module, $unit, $this->request(['title' => ' Renamed ', 'description' => 'D']));

        $this->assertTrue($result['success']);
        $this->assertSame('Renamed', $this->row('learning_units', $unit)['title']);
        $this->assertRefused($this->service->updateLearningUnit($this->instructor, $this->offeringId, $module, $unit, $this->request(['title' => '', 'description' => ''])), 'Learning unit title is required.');
        $this->assertRefused($this->service->updateLearningUnit($this->instructor, $this->offeringId, $module, 999999, $this->request(['title' => 'X', 'description' => ''])), 'Learning unit not found.');
    }

    public function testPublishingAUnitAndAMissingUnit(): void
    {
        [, $unit] = $this->tree('reading', 'draft');

        $this->assertTrue($this->service->updateLearningUnitStatus($this->instructor, $this->offeringId, $unit, ['status' => 'published'])['success']);
        $this->assertSame('published', $this->row('learning_units', $unit)['status']);

        $this->expectException(PageNotFoundException::class);
        $this->service->updateLearningUnitStatus($this->instructor, $this->offeringId, 999999, ['status' => 'published']);
    }

    public function testReorderingUnitsSkipsMalformedRowsAndUnitsOfOtherSections(): void
    {
        $module = CourseFactory::module($this->offeringId);
        $a = CourseFactory::unit($module, 'published', 1);
        $b = CourseFactory::unit($module, 'published', 2);
        $foreign = CourseFactory::unit(CourseFactory::module(CourseFactory::offering('B')), 'published', 1);

        $result = $this->service->reorderLearningUnits($this->instructor, $this->offeringId, [
            ['id' => $a, 'order_index' => 2],
            ['id' => $b, 'order_index' => 1],
            ['id' => $foreign, 'order_index' => 9],
            ['id' => $a],
            ['order_index' => 5],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, (int) $this->row('learning_units', $a)['order_index']);
        $this->assertSame(1, (int) $this->row('learning_units', $b)['order_index']);
        $this->assertSame(1, (int) $this->row('learning_units', $foreign)['order_index'], 'another section is untouched');
    }

    public function testDeletingAUnit(): void
    {
        [$module, $unit, $item] = $this->tree();

        $result = $this->service->deleteLearningUnit($this->instructor, $this->offeringId, $module, $unit);

        $this->assertTrue($result['success']);
        $this->assertNull($this->row('learning_units', $unit));
        $this->assertNull($this->row('learning_items', $item));
        $this->assertNotNull($this->row('course_modules', $module), 'the module stays');
    }

    public function testDeletingAUnitThatIsNotInThatModuleOrSection(): void
    {
        [$module] = $this->tree();
        [$otherModule, $otherUnit] = $this->tree();
        $foreignUnit = CourseFactory::unit(CourseFactory::module(CourseFactory::offering('B')));

        $this->assertRefused($this->service->deleteLearningUnit($this->instructor, $this->offeringId, $module, $otherUnit), 'Learning unit not found.');
        $this->assertRefused($this->service->deleteLearningUnit($this->instructor, $this->offeringId, $otherModule, 999999), 'Learning unit not found.');
        $this->assertRefused($this->service->deleteLearningUnit($this->instructor, $this->offeringId, $module, $foreignUnit), 'Learning unit not found.');
        $this->assertNotNull($this->row('learning_units', $otherUnit));
        $this->assertNotNull($this->row('learning_units', $foreignUnit));
    }

    /*
    |--------------------------------------------------------------------------
    | Learning items
    |--------------------------------------------------------------------------
    */

    private function storeItem(int $module, int $unit, string $title, string $type): array
    {
        return $this->service->storeLearningItem($this->instructor, $this->offeringId, $module, $unit, $this->request(['title' => $title, 'item_type' => $type]));
    }

    public function testANewItemStartsAsADraftAtTheEndOfItsUnit(): void
    {
        [$module, $unit] = $this->tree();

        $result = $this->storeItem($module, $unit, ' Chapter 1 ', 'reading');

        $this->assertTrue($result['success']);
        $item = $this->row('learning_items', $result['learning_item_id']);
        $this->assertSame('Chapter 1', $item['title']);
        $this->assertSame('reading', $item['item_type']);
        $this->assertSame('draft', $item['status']);
        $this->assertSame(2, (int) $item['order_index']);
        $this->assertSame($unit, (int) $item['learning_unit_id']);
        $this->assertSame(0, $this->rows('assessments', ['learning_item_id' => $item['id']]));
        $this->assertSame(0, $this->rows('discussion_threads', ['learning_item_id' => $item['id']]));
    }

    public function testAnItemNeedsATitleAndAUnitInThisModule(): void
    {
        [$module, $unit] = $this->tree();
        [, $otherUnit] = $this->tree();
        $foreignModule = CourseFactory::module(CourseFactory::offering('B'));

        $this->assertRefused($this->storeItem($module, $unit, '', 'reading'), 'Learning item title is required.');
        $this->assertRefused($this->storeItem(999999, $unit, 'X', 'reading'), 'Module not found.');
        $this->assertRefused($this->storeItem($foreignModule, $unit, 'X', 'reading'), 'Invalid module.');
        $this->assertRefused($this->storeItem($module, 999999, 'X', 'reading'), 'Learning unit not found.');
        $this->assertRefused($this->storeItem($module, $otherUnit, 'X', 'reading'), 'Invalid learning unit.');
    }

    public function testAQuizItemBringsItsOwnAssessment(): void
    {
        db_connect()->table('assessment_types')->insert(['name' => 'Quiz', 'created_at' => date('Y-m-d H:i:s')]);
        [$module, $unit] = $this->tree();

        $result = $this->storeItem($module, $unit, 'Week 1 quiz', 'quiz');

        $assessment = db_connect()->table('assessments')->where('learning_item_id', $result['learning_item_id'])->get()->getRowArray();
        $this->assertNotNull($assessment);
        $this->assertSame('Week 1 quiz', $assessment['title']);
        $this->assertSame($module, (int) $assessment['module_id']);
        $this->assertSame('Draft', $assessment['status']);
    }

    public function testADiscussionItemBringsItsOwnThread(): void
    {
        [$module, $unit] = $this->tree();

        $result = $this->storeItem($module, $unit, 'Introduce yourself', 'discussion');

        $thread = db_connect()->table('discussion_threads')->where('learning_item_id', $result['learning_item_id'])->get()->getRowArray();
        $this->assertNotNull($thread);
        $this->assertSame('Introduce yourself', $thread['title']);
        $this->assertSame($this->offeringId, (int) $thread['offering_id']);
        $this->assertSame($this->instructor->id, (int) $thread['author_id']);
    }

    public function testEditingAnItemsTitleStatusAndContent(): void
    {
        [, , $item] = $this->tree('reading', 'draft');

        $result = $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, [
            'title' => ' Better title ',
            'status' => 'published',
            'content' => ['body' => 'héllo'],
        ]);

        $this->assertTrue($result['success']);
        $row = $this->row('learning_items', $item);
        $this->assertSame('Better title', $row['title']);
        $this->assertSame('published', $row['status']);
        $this->assertSame('{"body":"héllo"}', $row['content'], 'stored as JSON, accents left readable');
    }

    public function testAnItemThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->updateLearningItem($this->instructor, $this->offeringId, 999999, ['title' => 'X']);
    }

    public function testEstimatedMinutes(): void
    {
        [, , $item] = $this->tree();

        $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['estimated_minutes' => '25']);
        $this->assertSame(25, (int) $this->row('learning_items', $item)['estimated_minutes']);

        $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['estimated_minutes' => -5]);
        $this->assertSame(0, (int) $this->row('learning_items', $item)['estimated_minutes'], 'never negative');

        $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['estimated_minutes' => '']);
        $this->assertNull($this->row('learning_items', $item)['estimated_minutes']);
    }

    public function testAQuizsEstimatedTimeComesFromItsDurationNotFromHere(): void
    {
        [, , $item] = $this->tree('quiz');

        $result = $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['estimated_minutes' => 30]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('duration setting', $result['message']);
        $this->assertNull($this->row('learning_items', $item)['estimated_minutes']);
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function linkUrls(): iterable
    {
        yield 'https' => ['https://example.com/page?x=1', true];
        yield 'http' => ['http://example.com', true];
        yield 'upper-case scheme' => ['HTTPS://example.com', true];
        yield 'empty clears the link' => ['', true];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', false];
        yield 'ftp' => ['ftp://example.com/file', false];
        yield 'no scheme' => ['example.com', false];
        yield 'not a url' => ['not a url', false];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('linkUrls')]
    public function testLinkItemsOnlyAcceptWebLinks(string $url, bool $accepted): void
    {
        [, , $item] = $this->tree('link');

        $result = $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['content' => ['url' => $url, 'description' => ' note ']]);

        $this->assertSame($accepted, $result['success'], $url);

        $stored = json_decode((string) $this->row('learning_items', $item)['content'], true);

        if ($accepted) {
            $this->assertSame(['url' => trim($url), 'description' => 'note'], $stored);
        } else {
            $this->assertStringContainsString('http://', $result['message']);
            $this->assertNull($stored, 'nothing was saved');
        }
    }

    public function testPublishingAQuizItemPublishesItsAssessmentAndAddsItToTheGradebook(): void
    {
        [$module, , $item] = $this->tree('quiz', 'draft');
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $module, $item);
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['total_points' => 20]);
        $schemeId = \Tests\Support\GradeFactory::scheme($this->offeringId);

        $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['status' => 'published']);

        $this->assertSame('Published', $this->row('assessments', $assessmentId)['status']);
        $gradeItem = db_connect()->table('grade_items')->where('assessment_id', $assessmentId)->get()->getRowArray();
        $this->assertNotNull($gradeItem);
        $this->assertSame($schemeId, (int) $gradeItem['grading_scheme_id']);
    }

    public function testDraftingOrRenamingAnItemFollowsToItsAssessment(): void
    {
        [$module, , $item] = $this->tree('quiz', 'published');
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $module, $item);
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['status' => 'Published']);

        $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['status' => 'draft', 'title' => 'Renamed quiz']);

        $assessment = $this->row('assessments', $assessmentId);
        $this->assertSame('Draft', $assessment['status']);
        $this->assertSame('Renamed quiz', $assessment['title']);
    }

    public function testAnEmptyTitleDoesNotRenameTheAssessment(): void
    {
        [$module, , $item] = $this->tree('quiz');
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $module, $item);
        $before = $this->row('assessments', $assessmentId)['title'];

        $this->service->updateLearningItem($this->instructor, $this->offeringId, $item, ['title' => '   ']);

        $this->assertSame($before, $this->row('assessments', $assessmentId)['title']);
    }

    public function testDeletingAnItemRemovesItsLinkedAssessmentToo(): void
    {
        [$module, , $item] = $this->tree('quiz');
        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, $module, $item);

        $result = $this->service->deleteLearningItem($this->instructor, $this->offeringId, $item);

        $this->assertTrue($result['success']);
        $this->assertNull($this->row('learning_items', $item));
        $this->assertNull($this->row('assessments', $assessmentId));
    }

    public function testDeletingAnItemOfAnotherSectionOrNoneIsNotFound(): void
    {
        $foreignUnit = CourseFactory::unit(CourseFactory::module(CourseFactory::offering('B')));
        [$foreignItem] = CourseFactory::items($foreignUnit, [[]]);

        try {
            $this->service->deleteLearningItem($this->instructor, $this->offeringId, $foreignItem);
            $this->fail('expected a 404');
        } catch (PageNotFoundException) {
            $this->assertNotNull($this->row('learning_items', $foreignItem));
        }

        $this->expectException(PageNotFoundException::class);
        $this->service->deleteLearningItem($this->instructor, $this->offeringId, 999999);
    }

    public function testReorderingItemsPrunesPrerequisitesThatNowComeLater(): void
    {
        $unit = CourseFactory::unit(CourseFactory::module($this->offeringId));
        [$a, $b] = CourseFactory::items($unit, [[], ['mode' => 'custom']]);
        CourseFactory::requires($b, $a);

        // b now comes before a: a can no longer be its prerequisite.
        $result = $this->service->reorderLearningItems($this->instructor, $this->offeringId, [
            ['id' => $a, 'order_index' => 2],
            ['id' => $b, 'order_index' => 1],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, (int) $this->row('learning_items', $a)['order_index']);
        $this->assertSame(1, (int) $this->row('learning_items', $b)['order_index']);
        $this->assertSame(0, $this->rows('learning_item_prerequisites', ['learning_item_id' => $b]));
    }

    /*
    |--------------------------------------------------------------------------
    | Video and PDF items
    |--------------------------------------------------------------------------
    */

    public function testAVideoLinkIsSavedWithItsPlayerSettings(): void
    {
        [, , $item] = $this->tree('video');

        $result = $this->service->updateVideoItem($this->instructor, $this->offeringId, $item, $this->request([
            'source' => 'link',
            'resource_url' => ' https://youtu.be/abc123 ',
            'autoplay' => 'true',
            'show_controls' => '0',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(
            ['source' => 'link', 'autoplay' => true, 'show_controls' => false],
            json_decode($this->row('learning_items', $item)['content'], true)
        );
        $resource = db_connect()->table('learning_resources')->where('learning_item_id', $item)->get()->getRowArray();
        $this->assertSame('video', $resource['resource_type']);
        $this->assertSame('https://youtu.be/abc123', $resource['resource_url']);
        $this->assertNull($resource['file_id']);
    }

    public function testChangingAVideoLinkUpdatesTheSameResource(): void
    {
        [, , $item] = $this->tree('video');
        $post = ['source' => 'link', 'resource_url' => 'https://example.com/one.mp4'];
        $this->service->updateVideoItem($this->instructor, $this->offeringId, $item, $this->request($post));

        $this->service->updateVideoItem($this->instructor, $this->offeringId, $item, $this->request(['resource_url' => 'https://example.com/two.mp4'] + $post));

        $this->assertSame(1, $this->rows('learning_resources', ['learning_item_id' => $item]));
        $this->assertSame('https://example.com/two.mp4', db_connect()->table('learning_resources')->where('learning_item_id', $item)->get()->getRowArray()['resource_url']);
    }

    public function testAMissingVideoItemIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->updateVideoItem($this->instructor, $this->offeringId, 999999, $this->request(['source' => 'link', 'resource_url' => 'x']));
    }

    public function testThePdfDownloadSettingIsSavedWithoutUploadingAFile(): void
    {
        [, , $item] = $this->tree('pdf');

        $on = $this->service->updatePdfItem($this->instructor, $this->offeringId, $item, $this->request(['allow_download' => '1']));

        $this->assertTrue($on['success']);
        $this->assertTrue($on['allowDownload']);
        $this->assertNull($on['file']);
        $this->assertSame(['display_mode' => 'embed', 'allow_download' => true], json_decode($this->row('learning_items', $item)['content'], true));

        $off = $this->service->updatePdfItem($this->instructor, $this->offeringId, $item, $this->request(['allow_download' => 'false']));
        $this->assertFalse($off['allowDownload']);

        $untouched = $this->service->updatePdfItem($this->instructor, $this->offeringId, $item, $this->request([]));
        $this->assertFalse($untouched['allowDownload'], 'leaving the field out keeps the saved value');
    }

    public function testThePdfEditorRefusesOtherItemTypesAndOtherSections(): void
    {
        [, , $reading] = $this->tree('reading');
        $foreignUnit = CourseFactory::unit(CourseFactory::module(CourseFactory::offering('B')));
        [$foreignPdf] = CourseFactory::items($foreignUnit, [['type' => 'pdf']]);

        foreach ([$reading, $foreignPdf, 999999] as $id) {
            $this->assertRefused($this->service->updatePdfItem($this->instructor, $this->offeringId, $id, $this->request(['allow_download' => '1'])), 'PDF item not found.');
        }

        $this->assertNull($this->row('learning_items', $foreignPdf)['content']);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function testTheModulePickerListsModulesWithTheirUnitsForInstructors(): void
    {
        $draftModule = CourseFactory::module($this->offeringId, 'draft', 2);
        $draftUnit = CourseFactory::unit($draftModule, 'draft');
        $module = CourseFactory::module($this->offeringId, 'published', 1);
        $unit = CourseFactory::unit($module);

        $picker = $this->service->listForPicker($this->instructor, $this->offeringId);

        $this->assertSame([$module, $draftModule], array_column($picker, 'id'), 'in module order');
        $this->assertSame([['id' => $unit, 'title' => $this->row('learning_units', $unit)['title']]], $picker[0]['learning_units']);
        $this->assertSame($draftUnit, $picker[1]['learning_units'][0]['id']);
    }

    public function testStudentsOnlyPickFromPublishedModules(): void
    {
        $published = CourseFactory::module($this->offeringId, 'published', 1);
        CourseFactory::module($this->offeringId, 'draft', 2);

        $this->assertSame([$published], array_column($this->service->listForPicker($this->student, $this->offeringId), 'id'));
    }

    public function testAnOfferingWithNoModulesHasAnEmptyPicker(): void
    {
        $this->assertSame([], $this->service->listForPicker($this->instructor, $this->offeringId));
    }

    public function testAModuleOfAnotherSectionIsNotFoundOnItsPage(): void
    {
        $elsewhere = CourseFactory::module(CourseFactory::offering('B'));

        $this->expectException(PageNotFoundException::class);

        $this->service->showModule($this->instructor, $this->offeringId, $elsewhere);
    }

    public function testAMissingModuleIsNotFoundOnItsPage(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->showModule($this->student, $this->offeringId, 999999);
    }

    public function testStudentsCannotOpenADraftModule(): void
    {
        $draft = CourseFactory::module($this->offeringId, 'draft');

        $this->expectException(PageNotFoundException::class);

        $this->service->showModule($this->student, $this->offeringId, $draft);
    }

    public function testTheModulesPagesRenderForBothRoles(): void
    {
        [$module] = $this->tree();

        foreach ([$this->instructor, $this->student] as $user) {
            $this->assertIsString($this->service->show($user, $this->offeringId)['html']);
            $this->assertIsString($this->service->showModule($user, $this->offeringId, $module)['html']);
        }
    }

    public function testALockedItemRefusalNamesTheFirstMissingPrerequisite(): void
    {
        $refusal = $this->service->lockedItemRefusal([
            'title' => 'Chapter 2',
            'locked_by' => [['id' => 7, 'title' => 'Chapter 1']],
        ]);

        $this->assertFalse($refusal['success']);
        $this->assertTrue($refusal['locked']);
        $this->assertSame(7, $refusal['unlock_item_id']);
        $this->assertSame('Chapter 1', $refusal['unlock_item_title']);
        $this->assertSame('Chapter 2', $refusal['item_title']);
        $this->assertStringContainsString('Chapter 1', $refusal['message']);
        $this->assertStringContainsString('Chapter 2', $refusal['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | The instructor's feedback shows on the student's item page
    |--------------------------------------------------------------------------
    */

    /** A published assignment or quiz item with its assessment (settings included), as Modules builds them. @return array{int, int} item id, assessment id */
    private function gradedWork(string $type): array
    {
        $module = CourseFactory::module($this->offeringId);
        [$item] = CourseFactory::items(CourseFactory::unit($module), [['type' => $type]]);
        $assessment = CourseFactory::assessment($this->offeringId, $this->instructor, $module, $item, ucfirst($type));
        Tests\Support\AssessmentFactory::open($assessment, ['total_points' => 30]);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $assessment, 'allow_text_entry' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        return [$item, $assessment];
    }

    /** Hands in work for a student, graded, with an instructor comment on it. */
    private function gradedWithComment(int $assessment, User $student, string $comment): void
    {
        $submission = Tests\Support\AssessmentFactory::attempt($assessment, $student, 'Graded', 24.0);
        (new App\Services\Courses\Assessments\AssessmentFeedbackService())->appendInstructorMessage($this->instructor, $submission, $comment);
    }

    public function testAStudentSeesTheFeedbackOnAGradedAssignmentOnItsItemPage(): void
    {
        [$item, $assessment] = $this->gradedWork('assignment');
        $this->gradedWithComment($assessment, $this->student, 'Good logic. Add more test cases.');

        $html = $this->service->viewLearningItem($this->student, $this->offeringId, $item)['html'];

        $this->assertStringContainsString('Good logic. Add more test cases.', $html);
        $this->assertStringNotContainsString('No Feedback Yet', $html);
    }

    public function testAStudentSeesTheFeedbackOnAGradedQuizOnItsItemPage(): void
    {
        [$item, $assessment] = $this->gradedWork('quiz');
        $this->gradedWithComment($assessment, $this->student, 'Nice work on the essay.');

        $html = $this->service->viewLearningItem($this->student, $this->offeringId, $item)['html'];

        $this->assertStringContainsString('Nice work on the essay.', $html);
    }

    public function testAStudentOnlySeesFeedbackOnTheirOwnWork(): void
    {
        [$item, $assessment] = $this->gradedWork('assignment');
        $other = CourseFactory::user('student');
        CourseFactory::enroll($this->offeringId, $other);
        $this->gradedWithComment($assessment, $other, 'Private note for the other student.');

        $html = $this->service->viewLearningItem($this->student, $this->offeringId, $item)['html'];

        $this->assertStringNotContainsString('Private note for the other student.', $html);
    }

    public function testWithoutAnyFeedbackThePageSaysSo(): void
    {
        [$item, $assessment] = $this->gradedWork('assignment');
        Tests\Support\AssessmentFactory::attempt($assessment, $this->student, 'Graded', 24.0);

        $html = $this->service->viewLearningItem($this->student, $this->offeringId, $item)['html'];

        $this->assertStringContainsString('No Feedback Yet', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | A video upload that did not arrive is an error, not a silent "updated"
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function uploadVideo(int $item, array $file, array $post = []): array
    {
        // The framework reads uploads through its superglobals service, not $_FILES directly.
        service('superglobals')->setFilesArray(['video' => $file]);

        try {
            return $this->service->updateVideoItem($this->instructor, $this->offeringId, $item, $this->request(['source' => 'upload'] + $post));
        } finally {
            service('superglobals')->setFilesArray([]);
        }
    }

    public function testAVideoTooLargeForTheServerIsReportedWithItsLimit(): void
    {
        [, , $item] = $this->tree('video');

        $result = $this->uploadVideo($item, ['name' => 'lecture.mp4', 'type' => 'video/mp4', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('larger than this server accepts', $result['message']);
        $this->assertStringContainsString((string) ini_get('upload_max_filesize'), $result['message']);
        $this->assertSame(0, $this->rows('files', []), 'nothing was stored');
    }

    public function testAnInterruptedVideoUploadIsReported(): void
    {
        [, , $item] = $this->tree('video');

        $result = $this->uploadVideo($item, ['name' => 'lecture.mp4', 'type' => 'video/mp4', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('could not be uploaded', $result['message']);
    }

    public function testChoosingUploadWithoutAFileIsReportedWhenNothingWasUploadedBefore(): void
    {
        [, , $item] = $this->tree('video');

        $result = $this->service->updateVideoItem($this->instructor, $this->offeringId, $item, $this->request(['source' => 'upload']));

        $this->assertFalse($result['success']);
        $this->assertSame('Choose a video file to upload.', $result['message']);
    }
}
