<?php

declare(strict_types=1);

use App\Services\Courses\OverviewService;
use App\Services\Dashboard\DashboardService;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Query budgets for the pages every visit opens. The dashboard and the
 * course Overview may cost a few queries per course, but never one per
 * assessment, lesson or student: a course with ten times the content must
 * cost the same as a small one, and N courses must stay near N × a fixed
 * handful. A page that quietly grows an N+1 fails here before it is slow.
 *
 * Counts are taken on a repeat visit, once each course's grading scheme has
 * been created (the first visit also writes those).
 *
 * @internal
 */
final class DashboardQueryBudgetTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $teacher;

    private User $student;

    /** @var list<array{offering: int, module: int, unit: int}> */
    private array $courses = [];

    protected function setUp(): void
    {
        parent::setUp();

        GradeFactory::systemDefault();

        $this->teacher = $this->person('instructor');
        $this->student = $this->person('student');
    }

    private function person(string $group): User
    {
        $user = CourseFactory::user($group);
        $db = db_connect();
        $db->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => 'Pat', 'last_name' => 'Person']);
        $db->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => "p{$user->id}@example.test", 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    /** A course the student is in and the teacher teaches, with a little content. */
    private function addCourse(int $assessments = 2, int $lessons = 2, int $classmates = 1): void
    {
        $offering = CourseFactory::offering();
        $module = CourseFactory::module($offering);
        $unit = CourseFactory::unit($module);

        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->teacher);

        $this->courses[] = ['offering' => $offering, 'module' => $module, 'unit' => $unit];

        $this->addContent(count($this->courses) - 1, $assessments, $lessons, $classmates);
    }

    private function addContent(int $course, int $assessments, int $lessons, int $classmates): void
    {
        ['offering' => $offering, 'module' => $module, 'unit' => $unit] = $this->courses[$course];

        if ($lessons > 0) {
            CourseFactory::items($unit, array_fill(0, $lessons, ['type' => 'reading']));
        }

        for ($i = 0; $i < $assessments; $i++) {
            $id = CourseFactory::assessment($offering, $this->teacher, $module);
            db_connect()->table('assessments')->where('id', $id)->update(['status' => 'Published', 'due_date' => date('Y-m-d H:i:s', strtotime('+3 days'))]);
            AssessmentFactory::attempt($id, $this->student, 'Submitted');
        }

        for ($i = 0; $i < $classmates; $i++) {
            $mate = $this->person('student');
            CourseFactory::enroll($offering, $mate);
        }
    }

    /** Queries a callable runs. */
    private function queriesOf(callable $work): int
    {
        $queries = 0;

        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        $work();
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }

    /** A repeat visit: warm it once so one-off setup isn't counted. */
    private function steadyState(callable $work): int
    {
        $work();

        return $this->queriesOf($work);
    }

    private function studentDashboard(): int
    {
        return $this->steadyState(fn () => (new DashboardService())->getDashboardWidgets($this->student));
    }

    private function teacherDashboard(): int
    {
        return $this->steadyState(fn () => (new DashboardService())->getDashboardWidgets($this->teacher));
    }

    private function overview(User $as, int $course = 0): int
    {
        return $this->steadyState(fn () => (new OverviewService())->show($as, $this->courses[$course]['offering']));
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    public function testTheStudentDashboardCostsAFixedHandfulPerCourse(): void
    {
        $this->addCourse();
        $one = $this->studentDashboard();

        $this->addCourse();
        $this->addCourse();
        $this->addCourse();
        $four = $this->studentDashboard();

        // Measured: 18 for one course and 8 more for each further one.
        $this->assertLessThanOrEqual(20, $one, "one course: {$one} queries");
        $this->assertLessThanOrEqual($one + 8 * 3, $four, "four courses: {$four} queries");
    }

    public function testTheStudentDashboardDoesNotGrowWithAssessmentsLessonsOrClassmates(): void
    {
        $this->addCourse();
        $this->addCourse();
        $small = $this->studentDashboard();

        $this->addContent(0, 12, 15, 6);
        $this->addContent(1, 12, 15, 6);

        $this->assertSame($small, $this->studentDashboard(), 'ten times the content must cost the same queries');
    }

    public function testTheInstructorDashboardCostsAFixedHandfulPerSection(): void
    {
        $this->addCourse();
        $one = $this->teacherDashboard();

        $this->addCourse();
        $this->addCourse();
        $this->addCourse();
        $four = $this->teacherDashboard();

        // Measured: 13 for one section and 3 more for each further one.
        $this->assertLessThanOrEqual(15, $one, "one section: {$one} queries");
        $this->assertLessThanOrEqual($one + 3 * 3, $four, "four sections: {$four} queries");
    }

    public function testTheInstructorDashboardDoesNotGrowWithStudentsOrLessons(): void
    {
        $this->addCourse();
        $this->addCourse();
        $small = $this->teacherDashboard();

        $this->addContent(0, 0, 15, 12);
        $this->addContent(1, 0, 15, 12);

        $this->assertSame($small, $this->teacherDashboard());
    }

    /*
    |--------------------------------------------------------------------------
    | Overview
    |--------------------------------------------------------------------------
    */

    public function testTheCourseOverviewHasAFixedBudgetForBothRoles(): void
    {
        $this->addCourse();

        $student = $this->overview($this->student);
        $teacher = $this->overview($this->teacher);

        $this->assertLessThanOrEqual(23, $student, "student overview: {$student} queries");
        $this->assertLessThanOrEqual(23, $teacher, "instructor overview: {$teacher} queries");
    }

    public function testTheCourseOverviewDoesNotGrowWithTheSizeOfTheCourse(): void
    {
        $this->addCourse();
        $student = $this->overview($this->student);
        $teacher = $this->overview($this->teacher);

        $this->addContent(0, 15, 20, 10);

        $this->assertSame($student, $this->overview($this->student), 'student overview');
        $this->assertSame($teacher, $this->overview($this->teacher), 'instructor overview');
    }
}
