<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The addresses behind the Dependencies modal of a module and of a learning unit: the teacher of the course reads
 * and saves them; a student, and a teacher of another course, are refused as ever.
 *
 * @internal
 */
final class ContainerDependenciesRouteTest extends RouteTestCase
{
    private int $offering;

    private int $a;

    private int $b;

    private int $unitA;

    private int $unitB;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering   = CourseFactory::offering('A');
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $this->a     = CourseFactory::module($this->offering, 'published', 1);
        $this->b     = CourseFactory::module($this->offering, 'published', 2);
        $this->unitA = CourseFactory::unit($this->a, 'published', 1);
        $this->unitB = CourseFactory::unit($this->a, 'published', 2);
    }

    private function base(string $role = 'instructor', ?int $offering = null): string
    {
        return "{$role}/api/courses/" . ($offering ?? $this->offering);
    }

    private function mode(string $table, int $id): string
    {
        return (string) db_connect()->table($table)->where('id', $id)->get()->getRowArray()['prerequisite_mode'];
    }

    public function testTheTeacherReadsAndSavesAModulesDependencies(): void
    {
        $this->loggedInAs($this->instructor);

        $options = json_decode($this->get($this->base() . "/modules/{$this->b}/dependencies")->getJSON(), true);
        $this->assertSame($this->a, $options['previous']['id']);

        $saved = json_decode($this->withBodyFormat('json')->patch($this->base() . "/modules/{$this->b}/dependencies", ['mode' => 'custom', 'prerequisite_ids' => [$this->a]])->getJSON(), true);
        $this->assertTrue($saved['success'], (string) ($saved['message'] ?? ''));
        $this->assertSame('custom', $this->mode('course_modules', $this->b));
    }

    public function testTheTeacherReadsAndSavesALearningUnitsDependencies(): void
    {
        $this->loggedInAs($this->instructor);

        $options = json_decode($this->get($this->base() . "/learning-units/{$this->unitB}/dependencies")->getJSON(), true);
        $this->assertSame($this->unitA, $options['previous']['id']);

        $saved = json_decode($this->withBodyFormat('json')->patch($this->base() . "/learning-units/{$this->unitB}/dependencies", ['mode' => 'previous'])->getJSON(), true);
        $this->assertTrue($saved['success']);
        $this->assertSame('previous', $this->mode('learning_units', $this->unitB));
    }

    public function testAStudentCannotReadOrSave(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $student);
        $this->loggedInAs($student);

        foreach (["/modules/{$this->b}/dependencies", "/learning-units/{$this->unitB}/dependencies"] as $path) {
            foreach (['get', 'patch'] as $method) {
                try {
                    $this->withBodyFormat('json')->{$method}($this->base('student') . $path, ['mode' => 'previous']);
                    $this->fail("a student reached {$path} by {$method}");
                } catch (PageNotFoundException) {
                    $this->assertSame('none', $this->mode('course_modules', $this->b));
                    $this->assertSame('none', $this->mode('learning_units', $this->unitB));
                }
            }
        }
    }

    public function testATeacherOfAnotherCourseCannotChangeThem(): void
    {
        $other      = CourseFactory::offering('B');
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($other, $instructor);
        $this->loggedInAs($instructor);

        // Their own course's address, someone else's module: refused, nothing changes.
        $saved = json_decode($this->withBodyFormat('json')->patch($this->base('instructor', $other) . "/modules/{$this->b}/dependencies", ['mode' => 'previous'])->getJSON(), true);

        $this->assertFalse($saved['success']);
        $this->assertSame('none', $this->mode('course_modules', $this->b));
    }

    public function testAModuleOfAnotherCourseIsNotFoundEvenForItsOwnTeacher(): void
    {
        $other = CourseFactory::offering('B');
        $theirs = CourseFactory::module($other);
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($other, $instructor);
        $this->loggedInAs($this->instructor);

        $this->expectException(PageNotFoundException::class);
        $this->get($this->base() . "/modules/{$theirs}/dependencies");
    }
}
