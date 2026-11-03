<?php

declare(strict_types=1);

use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The Grades page must not be slower than opening each course (spec §0): its
 * query count does not grow with the number of sections. Three sections and
 * fifteen sections of fifty students cost the same, in every tab.
 *
 * @internal
 */
final class GlobalGradesQueryBudgetTest extends RouteTestCase
{
    private const STUDENTS = 50;

    private User $instructor;

    private ?int $term = null;

    protected function setUp(): void
    {
        parent::setUp();

        $institution      = PeopleFactory::institution('QBG');
        $this->instructor = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $institution);
    }

    /** A published section of fifty students, each with an ungraded quiz, one failing and some never active. */
    private function addSection(): void
    {
        $db       = db_connect();
        $offering = CourseFactory::offering('A');

        if ($this->term === null) {
            $this->term = (int) $db->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
        } else {
            $db->table('course_offerings')->where('id', $offering)->update(['term_id' => $this->term]);
        }

        CourseFactory::assignInstructor($offering, $this->instructor);

        $scheme = GradeFactory::scheme($offering);
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100); // read by the batched publish check

        $module = CourseFactory::module($offering);
        CourseFactory::items(CourseFactory::unit($module), array_fill(0, 3, []));

        $quiz = CourseFactory::assessment($offering, $this->instructor, $module);
        $db->table('assessments')->where('id', $quiz)->update(['status' => 'Published']);

        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < self::STUDENTS; $i++) {
            $db->table('users')->insert(['username' => 'qb' . uniqid('', false) . $i, 'active' => 1, 'created_at' => $now]);
            $student = (int) $db->insertID();

            $db->table('auth_groups_users')->insert(['user_id' => $student, 'group' => 'student', 'created_at' => $now]);
            $db->table('user_profiles')->insert(['user_id' => $student, 'first_name' => 'Stu', 'last_name' => 'Dent' . $i, 'created_at' => $now]);
            $db->table('enrollments')->insert(['offering_id' => $offering, 'student_user_id' => $student, 'status' => 'enrolled', 'enrolled_at' => $now]);
            $db->table('assessment_submissions')->insert([
                'assessment_id' => $quiz, 'student_id' => $student, 'attempt_number' => 1, 'started_at' => $now,
                'submitted_at' => $now, 'score' => 0, 'is_graded' => 0, 'status' => 'Submitted',
            ]);

            if ($i === 0) {
                $db->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $student, 'grading_scheme_id' => $scheme, 'calculated_percentage' => 40, 'remarks' => 'Failed']);
            }
        }
    }

    private function sections(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->addSection();
        }
    }

    /** Queries one visit to the page costs, on a repeat visit. */
    private function cost(string $query): int
    {
        $this->loggedInAs($this->instructor);
        $this->get('instructor/grades?' . $query)->assertStatus(200);

        $queries  = 0;
        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        $this->get('instructor/grades?' . $query)->assertStatus(200);
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }

    public function testThePageCostsTheSameForThreeOrFifteenSections(): void
    {
        $tabs = ['tab=gradebook', 'tab=to-grade', 'tab=at-risk'];

        $this->sections(3);
        $three = array_map(fn (string $tab): int => $this->cost($tab), $tabs);

        $this->sections(12);
        $started = microtime(true);
        $fifteen = array_map(fn (string $tab): int => $this->cost($tab), $tabs);

        $this->assertSame(array_combine($tabs, $three), array_combine($tabs, $fifteen), 'fifteen sections must cost the same queries as three');
        $this->assertGreaterThan(0, $three[0]);
        // Six visits (each tab, warmed and measured). A loose bound for a test machine: about 2 s a visit.
        $this->assertLessThan(12.0, microtime(true) - $started, 'six visits on 750 students');
    }
}
