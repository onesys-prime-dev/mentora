<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The addresses behind "Copy to" and "Move to": the teacher lists where content can go and does it; a student, and
 * a teacher of another course, cannot.
 *
 * @internal
 */
final class ContentTransferRouteTest extends RouteTestCase
{
    private int $a;

    private int $b;

    private int $module;

    private int $unit;

    private int $item;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();

        $this->a          = CourseFactory::offering('A');
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->a, $this->instructor);

        $src = $db->table('course_offerings o')->select('c.program_id, o.term_id')->join('courses c', 'c.id = o.course_id')->where('o.id', $this->a)->get()->getRowArray();
        $db->table('courses')->insert(['program_id' => $src['program_id'], 'course_code' => 'SIB' . random_int(1000, 9999), 'course_title' => 'Sibling', 'units' => 3]);
        $db->table('course_offerings')->insert(['course_id' => (int) $db->insertID(), 'term_id' => $src['term_id'], 'section_code' => 'B']);
        $this->b = (int) $db->insertID();
        CourseFactory::assignInstructor($this->b, $this->instructor);

        $this->module = CourseFactory::module($this->a);
        $this->unit   = CourseFactory::unit($this->module);
        [$this->item] = CourseFactory::items($this->unit, [['title' => 'Read me']]);
    }

    private function base(string $role = 'instructor', ?int $offering = null): string
    {
        return "{$role}/api/courses/" . ($offering ?? $this->a) . '/content-transfer';
    }

    private function json(string $method, string $path, array $body = []): array
    {
        return json_decode($this->withBodyFormat('json')->{$method}($this->base() . $path, $body)->getJSON(), true);
    }

    public function testTheTeacherListsWhereContentCanGo(): void
    {
        $this->loggedInAs($this->instructor);

        $answer = $this->json('get', '/offerings');

        $this->assertEqualsCanonicalizing([$this->a, $this->b], array_column($answer['offerings'], 'id'));
    }

    public function testTheChoosingStepsListModulesUnitsAndATree(): void
    {
        $this->loggedInAs($this->instructor);
        $targetModule = CourseFactory::module($this->b);
        CourseFactory::unit($targetModule);

        $this->assertSame([$targetModule], array_column($this->json('get', "/offerings/{$this->b}/modules")['modules'], 'id'));
        $this->assertCount(1, $this->json('get', "/modules/{$targetModule}/units")['units']);
        $this->assertSame('Read me', $this->json('get', "/module/{$this->module}/tree")['units'][0]['items'][0]['title']);
        $this->assertSame([$this->item], array_column($this->json('get', "/unit/{$this->unit}/tree")['units'][0]['items'], 'id'));
    }

    public function testTheTeacherCopiesAndMoves(): void
    {
        $this->loggedInAs($this->instructor);

        $copied = $this->json('post', '/copy', ['kind' => 'module', 'id' => $this->module, 'target' => ['offering_id' => $this->b]]);
        $this->assertTrue($copied['success'], (string) $copied['message']);
        $this->assertSame(1, db_connect()->table('course_modules')->where('offering_id', $this->b)->countAllResults());

        $moved = $this->json('post', '/move', ['kind' => 'module', 'id' => $this->module, 'target' => ['offering_id' => $this->b]]);
        $this->assertTrue($moved['success'], (string) $moved['message']);
        $this->assertSame($this->b, (int) db_connect()->table('course_modules')->where('id', $this->module)->get()->getRowArray()['offering_id']);
    }

    public function testASelectionIsSentAsLists(): void
    {
        $this->loggedInAs($this->instructor);
        $second = CourseFactory::unit($this->module, 'published', 2);

        $copied = $this->json('post', '/copy', ['kind' => 'module', 'id' => $this->module, 'target' => ['offering_id' => $this->b], 'unit_ids' => [$this->unit], 'item_ids' => []]);

        $this->assertSame(1, $copied['units']);
        $this->assertSame(0, $copied['items']);
        $this->assertNotSame($second, $this->unit);
    }

    public function testAStudentCannotListCopyOrMove(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->a, $student);
        $this->loggedInAs($student);

        foreach ([['get', '/offerings'], ['post', '/copy'], ['post', '/move']] as [$method, $path]) {
            try {
                $this->withBodyFormat('json')->{$method}($this->base('student') . $path, ['kind' => 'module', 'id' => $this->module, 'target' => ['offering_id' => $this->b]]);
                $this->fail("a student reached {$path}");
            } catch (PageNotFoundException) {
                $this->assertSame(0, db_connect()->table('course_modules')->where('offering_id', $this->b)->countAllResults());
            }
        }
    }

    public function testATeacherOfAnotherCourseCannotUseThisOnesContent(): void
    {
        $other = CourseFactory::offering('Z');
        $them  = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($other, $them);
        $this->loggedInAs($them);

        $answer = json_decode($this->withBodyFormat('json')->post($this->base('instructor', $other) . '/copy', ['kind' => 'module', 'id' => $this->module, 'target' => ['offering_id' => $other]])->getJSON(), true);

        $this->assertFalse($answer['success']);
        $this->assertSame(0, db_connect()->table('course_modules')->where('offering_id', $other)->countAllResults());
    }

    public function testATargetOfferingTheTeacherDoesNotTeachIsNotListed(): void
    {
        $this->loggedInAs($this->instructor);
        $foreign = CourseFactory::offering('Q');

        $this->expectException(PageNotFoundException::class);
        $this->get($this->base() . "/offerings/{$foreign}/modules");
    }

    public function testATreeOfAnythingButAModuleOrAUnitIsNotFound(): void
    {
        $this->loggedInAs($this->instructor);

        $this->expectException(PageNotFoundException::class);
        $this->get($this->base() . "/item/{$this->item}/tree");
    }
}
