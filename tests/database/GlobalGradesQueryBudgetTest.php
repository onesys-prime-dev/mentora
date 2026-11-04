<?php

declare(strict_types=1);

use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
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

    /**
     * A section of the chairperson's program: published, fifty students with an ungraded quiz, one failing, two
     * instructors (the chairperson among them, so My sections has it too).
     */
    private function addProgramSection(User $chair, int $program, int $term, int $n): void
    {
        $db       = db_connect();
        $offering = DeanFactory::offering($program, $term, sprintf('IT%03d', $n));

        CourseFactory::assignInstructor($offering, $chair);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $scheme = GradeFactory::scheme($offering);
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        $module = CourseFactory::module($offering);
        $quiz   = CourseFactory::assessment($offering, $this->instructor, $module);
        $db->table('assessments')->where('id', $quiz)->update(['status' => 'Published']);

        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < self::STUDENTS; $i++) {
            $db->table('users')->insert(['username' => 'qc' . uniqid('', false) . $i, 'active' => 1, 'created_at' => $now]);
            $student = (int) $db->insertID();

            $db->table('auth_groups_users')->insert(['user_id' => $student, 'group' => 'student', 'created_at' => $now]);
            $db->table('user_profiles')->insert(['user_id' => $student, 'first_name' => 'Stu', 'last_name' => 'Dent' . $i, 'created_at' => $now]);
            $db->table('enrollments')->insert(['offering_id' => $offering, 'student_user_id' => $student, 'status' => 'enrolled', 'enrolled_at' => $now]);
            $db->table('assessment_submissions')->insert([
                'assessment_id' => $quiz, 'student_id' => $student, 'attempt_number' => 1, 'started_at' => $now,
                'submitted_at' => $now, 'score' => 0, 'is_graded' => 0, 'status' => 'Submitted',
            ]);
            $db->table('gradebook_records')->insert([
                'course_offering_id' => $offering, 'student_id' => $student, 'grading_scheme_id' => $scheme,
                'calculated_percentage' => $i === 0 ? 40 : 85, 'remarks' => $i === 0 ? 'Failed' : 'Passed',
            ]);
        }
    }

    public function testTheChairpersonPageCostsTheSameForThreeOrFifteenSections(): void
    {
        $unit    = DeanFactory::department('QCH');
        $sub     = DeanFactory::subDepartment($unit['department'], 'QCH');
        $program = DeanFactory::program($sub, 'QCH');
        $term    = DeanFactory::term($unit['institution']);
        $chair   = DeanFactory::chair($sub);

        $views = ['tab=mine', 'tab=mine&sub=to-grade', 'tab=mine&sub=at-risk', 'tab=program', 'tab=program&sub=at-risk', 'tab=program&sort=average&dir=desc&q=IT'];

        $cost = function (string $query) use ($chair): int {
            $this->loggedInAs($chair);
            $this->get('chairperson/grades?' . $query)->assertStatus(200);

            $queries  = 0;
            $listener = static function () use (&$queries): void {
                $queries++;
            };

            Events::on('DBQuery', $listener);
            $result = $this->get('chairperson/grades?' . $query);
            Events::removeListener('DBQuery', $listener);

            $result->assertStatus(200);

            return $queries;
        };

        for ($n = 1; $n <= 3; $n++) {
            $this->addProgramSection($chair, $program, $term, $n);
        }

        $three = array_map($cost, $views);

        for ($n = 4; $n <= 15; $n++) {
            $this->addProgramSection($chair, $program, $term, $n);
        }

        $this->assertSame(array_combine($views, $three), array_combine($views, array_map($cost, $views)), 'fifteen sections must cost the same queries as three');
        $this->assertGreaterThan(0, $three[0]);
    }

    public function testTheDeanPageCostsTheSameForThreeOrFifteenSections(): void
    {
        $unit     = DeanFactory::department('QDN');
        $programA = DeanFactory::program(DeanFactory::subDepartment($unit['department'], 'QDA'), 'QDA');
        $programB = DeanFactory::program(DeanFactory::subDepartment($unit['department'], 'QDB'), 'QDB');
        $term     = DeanFactory::term($unit['institution']);
        $chair    = DeanFactory::chair((int) db_connect()->table('programs')->where('id', $programA)->get()->getRow()->sub_department_id);
        $dean     = DeanFactory::dean($unit['institution'], $unit['department']);

        $views = ['', 'sub=at-risk', 'program=' . $programA, 'sort=signed_off&dir=desc&q=IT&nf=1'];

        $cost = function (string $query) use ($dean): int {
            $this->loggedInAs($dean);
            $this->get('dean/grades?' . $query)->assertStatus(200);

            $queries  = 0;
            $listener = static function () use (&$queries): void {
                $queries++;
            };

            Events::on('DBQuery', $listener);
            $result = $this->get('dean/grades?' . $query);
            Events::removeListener('DBQuery', $listener);

            $result->assertStatus(200);

            return $queries;
        };

        // The sections alternate between the two programs, so a program's share grows as well.
        for ($n = 1; $n <= 3; $n++) {
            $this->addProgramSection($chair, $n % 2 === 0 ? $programB : $programA, $term, $n);
        }

        $three = array_map($cost, $views);

        for ($n = 4; $n <= 15; $n++) {
            $this->addProgramSection($chair, $n % 2 === 0 ? $programB : $programA, $term, $n);
        }

        $this->assertSame(array_combine($views, $three), array_combine($views, array_map($cost, $views)), 'fifteen sections must cost the same queries as three');
        $this->assertGreaterThan(0, $three[0]);
    }

    /** A course of the student's term with a published scheme, a running grade, an instructor and a band. */
    private function addStudentCourse(User $student, ?int &$term): void
    {
        $db       = db_connect();
        $offering = CourseFactory::offering('A');

        if ($term === null) {
            $term = (int) $db->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
        } else {
            $db->table('course_offerings')->where('id', $offering)->update(['term_id' => $term]);
        }

        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $scheme = GradeFactory::scheme($offering);
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        $band = (int) $db->table('grade_scale_items')->where('grade_scale_id', $db->table('grading_schemes')->where('id', $scheme)->get()->getRow()->grade_scale_id)->get()->getRow()->id;

        $db->table('gradebook_records')->insert([
            'course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => $scheme,
            'calculated_percentage' => 91, 'calculated_grade_scale_item_id' => $band, 'remarks' => 'Passed',
            'last_calculated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testTheStudentPageCostsTheSameForThreeOrFifteenCourses(): void
    {
        $student = PeopleFactory::person('student', 'Ada', 'Lovelace', null, PeopleFactory::institution('QBS'));
        $term    = null;

        $cost = function () use ($student): int {
            $this->loggedInAs($student);
            $this->get('student/grades')->assertStatus(200);

            $queries  = 0;
            $listener = static function () use (&$queries): void {
                $queries++;
            };

            Events::on('DBQuery', $listener);
            $result = $this->get('student/grades');
            Events::removeListener('DBQuery', $listener);

            $result->assertStatus(200);
            $this->assertStringContainsString('data-grades-row', $result->getBody());

            return $queries;
        };

        for ($i = 0; $i < 3; $i++) {
            $this->addStudentCourse($student, $term);
        }

        $three = $cost();

        for ($i = 0; $i < 12; $i++) {
            $this->addStudentCourse($student, $term);
        }

        $this->assertSame($three, $cost(), 'fifteen courses must cost the same queries as three');
        $this->assertGreaterThan(0, $three);
    }

    public function testTheParentPageCostsTheSameForThreeOrFifteenCourses(): void
    {
        $institution = PeopleFactory::institution('QBP');
        $student     = PeopleFactory::person('student', 'Ada', 'Lovelace', null, $institution);
        $parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $institution);
        $term        = null;

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $path = 'parent/children/' . $student->id . '/grades';
        $cost = function () use ($parent, $path): int {
            $this->loggedInAs($parent);
            $this->get($path)->assertStatus(200);

            $queries  = 0;
            $listener = static function () use (&$queries): void {
                $queries++;
            };

            Events::on('DBQuery', $listener);
            $result = $this->get($path);
            Events::removeListener('DBQuery', $listener);

            $result->assertStatus(200);
            $this->assertStringContainsString('data-grades-row', $result->getBody());

            return $queries;
        };

        for ($i = 0; $i < 3; $i++) {
            $this->addStudentCourse($student, $term);
        }

        $three = $cost();

        for ($i = 0; $i < 12; $i++) {
            $this->addStudentCourse($student, $term);
        }

        $this->assertSame($three, $cost(), 'fifteen courses must cost the same queries as three');
        $this->assertGreaterThan(0, $three);
    }
}
