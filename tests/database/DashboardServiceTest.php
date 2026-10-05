<?php

declare(strict_types=1);

use App\Services\Dashboard\DashboardService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The dashboard: which widgets each role gets, and the data behind every
 * one — a student's metrics, tasks, courses and announcements; an
 * instructor's grading queue, at-risk students, load, progress and drafts.
 *
 * @internal
 */
final class DashboardServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DashboardService $service;

    private User $student;

    private User $teacher;

    /** @var array{id: int, module: int, unit: int} */
    private array $a;

    /** @var array{id: int, module: int, unit: int} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DashboardService();

        // A real install always has the system-default grading scheme; reading grades copies it per course.
        GradeFactory::systemDefault();
        $this->student = $this->person('student', 'Sam', 'Student');
        $this->teacher = $this->person('instructor', 'Tess', 'Teacher');

        $this->a = $this->course('A', 'ART 100', 'Art Basics');
        $this->b = $this->course('B', 'BIO 200', 'Biology Intro');

        CourseFactory::enroll($this->a['id'], $this->student);
        CourseFactory::enroll($this->b['id'], $this->student);
        CourseFactory::assignInstructor($this->a['id'], $this->teacher);
        CourseFactory::assignInstructor($this->b['id'], $this->teacher);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function person(string $group, string $first, string $last): User
    {
        $user = CourseFactory::user($group);
        $db = db_connect();
        $db->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);
        $db->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => strtolower($first) . $user->id . '@example.test', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    /** @return array{id: int, module: int, unit: int} */
    private function course(string $section, string $code, string $title): array
    {
        $id = CourseFactory::offering($section);
        $db = db_connect();
        $courseId = (int) $db->table('course_offerings')->where('id', $id)->get()->getRowArray()['course_id'];
        $db->table('courses')->where('id', $courseId)->update(['course_code' => $code, 'course_title' => $title]);
        $module = CourseFactory::module($id);

        return ['id' => $id, 'module' => $module, 'unit' => CourseFactory::unit($module)];
    }

    private function quiz(array $course, string $title, string $due, string $status = 'Published', ?User $by = null): int
    {
        $id = CourseFactory::assessment($course['id'], $by ?? $this->teacher, $course['module']);
        db_connect()->table('assessments')->where('id', $id)->update([
            'title' => $title, 'due_date' => $due, 'status' => $status, 'available_from' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ]);

        return $id;
    }

    private function in(string $interval): string
    {
        return date('Y-m-d H:i:s', strtotime($interval));
    }

    private function widgets(User $user): array
    {
        return $this->service->getDashboardWidgets($user);
    }

    private function widget(User $user, string $slug): array
    {
        return $this->widgets($user)[$slug];
    }

    /*
    |--------------------------------------------------------------------------
    | Which widgets
    |--------------------------------------------------------------------------
    */

    public function testAStudentGetsTheStudentWidgets(): void
    {
        $this->assertSame(
            ['profile-card', 'key-metrics', 'upcoming-tasks', 'my-courses', 'announcements', 'reminders', 'quick-access'],
            array_keys($this->widgets($this->student))
        );
    }

    public function testAnInstructorGetsTheInstructorWidgets(): void
    {
        $this->assertSame(
            ['profile-card', 'grading-queue', 'at-risk-students', 'teaching-load', 'class-progress', 'content-status', 'quick-access'],
            array_keys($this->widgets($this->teacher))
        );
    }

    public function testSomeoneInNeitherGroupGetsNothingAndAUserInBothGetsTheUnion(): void
    {
        db_connect()->table('users')->insert(['username' => 'norole' . uniqid(), 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $nobody = model(\CodeIgniter\Shield\Models\UserModel::class)->findById((int) db_connect()->insertID());
        $this->assertSame([], $this->widgets($nobody));

        $both = $this->person('student', 'Both', 'Roles');
        $both->addGroup('instructor');
        $keys = array_keys($this->widgets($both));

        $this->assertCount(12, $keys, '7 student + 7 instructor − the 2 they share');
        $this->assertSame(1, count(array_keys($keys, 'profile-card', true)));
    }

    public function testTheProfileCardIsTheUsersProfile(): void
    {
        $card = $this->widget($this->student, 'profile-card');

        $this->assertSame('Sam', $card['first_name']);
        $this->assertSame('Student', $card['last_name']);
    }

    /*
    |--------------------------------------------------------------------------
    | Student: key metrics
    |--------------------------------------------------------------------------
    */

    public function testKeyMetricsCountCoursesAndPendingWorkAcrossEveryCourse(): void
    {
        $done = $this->quiz($this->a, 'Done', $this->in('+3 days'));
        $this->quiz($this->a, 'Open A', $this->in('+4 days'));
        $this->quiz($this->b, 'Open B', $this->in('+5 days'));
        $this->quiz($this->b, 'Unreleased', $this->in('+5 days'), 'Draft');
        AssessmentFactory::attempt($done, $this->student, 'Submitted');

        $metrics = $this->widget($this->student, 'key-metrics');

        $this->assertSame(2, $metrics['courses_enrolled']);
        $this->assertSame(2, $metrics['pending_tasks']);
        $this->assertNull($metrics['average_grade'], 'no published scheme yet');
    }

    public function testTheAverageGradeUsesPublishedSchemesAndOverrides(): void
    {
        $schemeA = GradeFactory::scheme($this->a['id']);
        $schemeB = GradeFactory::scheme($this->b['id']);
        $db = db_connect();
        $db->table('grading_schemes')->where('id', $schemeA)->update(['status' => 'Published']);
        $db->table('grading_schemes')->where('id', $schemeB)->update(['status' => 'Published']);

        $record = static fn (int $offering, int $scheme, array $extra) => $extra + [
            'course_offering_id' => $offering, 'student_id' => 0, 'grading_scheme_id' => $scheme,
            'calculated_percentage' => 0, 'is_override' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ];
        $db->table('gradebook_records')->insert($record($this->a['id'], $schemeA, ['student_id' => $this->student->id, 'calculated_percentage' => 80]));
        $db->table('gradebook_records')->insert($record($this->b['id'], $schemeB, ['student_id' => $this->student->id, 'calculated_percentage' => 50, 'is_override' => 1, 'overridden_percentage' => 90]));

        $this->assertEqualsWithDelta(85.0, $this->widget($this->student, 'key-metrics')['average_grade'], 0.001);

        $db->table('grading_schemes')->where('id', $schemeB)->update(['status' => 'Active']);
        $this->assertEqualsWithDelta(80.0, $this->widget($this->student, 'key-metrics')['average_grade'], 0.001, 'an unpublished scheme is left out');
    }

    /*
    |--------------------------------------------------------------------------
    | Student: upcoming tasks
    |--------------------------------------------------------------------------
    */

    public function testUpcomingTasksAreSortedByDueDateWithUrgencyAndStatus(): void
    {
        $this->quiz($this->a, 'Later', $this->in('+20 days'));
        $this->quiz($this->a, 'This week', $this->in('+3 days'));
        $overdue = $this->quiz($this->b, 'Missed', $this->in('-2 days'));
        $submitted = $this->quiz($this->b, 'Handed in', $this->in('+2 days'));
        AssessmentFactory::attempt($submitted, $this->student, 'Submitted');

        $tasks = $this->widget($this->student, 'upcoming-tasks')['tasks'];

        $this->assertSame(['Missed', 'Handed in', 'This week', 'Later'], array_column($tasks, 'title'));
        $this->assertSame(['overdue', 'soon', 'soon', 'later'], array_column($tasks, 'urgency'));
        $this->assertSame([null, 'submitted', null, null], array_column($tasks, 'status'));
        $this->assertSame(['BIO 200', 'BIO 200', 'ART 100', 'ART 100'], array_column($tasks, 'code'));
        $this->assertSame('biology-intro', $tasks[0]['slug']);
        $this->assertSame($this->b['id'], $tasks[0]['offering_id']);
        unset($overdue);
    }

    public function testAnOverdueTaskThatWasHandedInIsNotOverdue(): void
    {
        $id = $this->quiz($this->a, 'Late but in', $this->in('-1 day'));
        AssessmentFactory::attempt($id, $this->student, 'Submitted');

        $task = $this->widget($this->student, 'upcoming-tasks')['tasks'][0];

        $this->assertNotSame('overdue', $task['urgency']);
        $this->assertSame('submitted', $task['status']);
    }

    public function testAGradedTaskIsMarkedGraded(): void
    {
        $id = $this->quiz($this->a, 'Marked', $this->in('+1 day'));
        AssessmentFactory::attempt($id, $this->student, 'Graded', 15.0);

        $this->assertSame('graded', $this->widget($this->student, 'upcoming-tasks')['tasks'][0]['status']);
    }

    public function testOnlyEightTasksAreShown(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->quiz($this->a, "Q{$i}", $this->in("+{$i} days"));
        }

        $tasks = $this->widget($this->student, 'upcoming-tasks')['tasks'];

        $this->assertCount(8, $tasks);
        $this->assertSame('Q1', $tasks[0]['title']);
    }

    /*
    |--------------------------------------------------------------------------
    | Student: courses and announcements
    |--------------------------------------------------------------------------
    */

    public function testMyCoursesShowProgressPerCourse(): void
    {
        $itemsA = CourseFactory::items($this->a['unit'], [['type' => 'reading'], ['type' => 'reading'], ['type' => 'reading']]);
        CourseFactory::items($this->b['unit'], [['type' => 'reading']]);
        CourseFactory::progress($itemsA[0], $this->student);

        // Classmates with other counts, so a mix-up with someone else's row shows whichever row comes last.
        foreach ([[$itemsA[1]], [$itemsA[1], $itemsA[2]]] as $n => $done) {
            $classmate = $this->person('student', 'Cam', "Mate{$n}");
            CourseFactory::enroll($this->a['id'], $classmate);
            foreach ($done as $item) {
                CourseFactory::progress($item, $classmate);
            }
        }

        $courses = array_column($this->widget($this->student, 'my-courses')['courses'], null, 'course_code');

        $this->assertSame(33, $courses['ART 100']['progress']);
        $this->assertSame(0, $courses['BIO 200']['progress']);
        $this->assertSame('art-basics', $courses['ART 100']['slug']);
        $this->assertSame('A', $courses['ART 100']['section_code']);
        $this->assertSame($this->a['id'], $courses['ART 100']['offering_id']);
    }

    public function testACourseWithNoPublishedContentIsZeroNotAnError(): void
    {
        $this->assertSame([0, 0], array_column($this->widget($this->student, 'my-courses')['courses'], 'progress'));
    }

    public function testAStudentInNoCourseGetsEmptyCourseAndAnnouncementWidgets(): void
    {
        $loner = $this->person('student', 'Lone', 'Wolf');

        $this->assertSame(['courses' => []], $this->widget($loner, 'my-courses'));
        $this->assertSame(['announcements' => []], $this->widget($loner, 'announcements'));
        $this->assertSame(['tasks' => []], $this->widget($loner, 'upcoming-tasks'));
    }

    private function announce(int $offering, string $subject, string $at, ?User $by = null): void
    {
        db_connect()->table('announcements')->insert([
            'offering_id' => $offering, 'author_id' => ($by ?? $this->teacher)->id, 'subject' => $subject,
            'message' => '{}', 'created_at' => $at,
        ]);
    }

    public function testAnnouncementsAreTheNewestAcrossTheStudentsCoursesCappedAtEight(): void
    {
        $other = $this->course('Z', 'ZZZ 999', 'Not Mine');

        for ($i = 1; $i <= 6; $i++) {
            $this->announce($this->a['id'], "A{$i}", $this->in("-{$i} days"));
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->announce($this->b['id'], "B{$i}", $this->in("-{$i} hours"));
        }
        $this->announce($other['id'], 'Elsewhere', $this->in('-1 minute'));

        $items = $this->widget($this->student, 'announcements')['announcements'];

        $this->assertCount(8, $items);
        $this->assertSame(['B1', 'B2', 'B3', 'B4', 'A1', 'A2', 'A3', 'A4'], array_column($items, 'title'));
        $this->assertSame('BIO 200 - B', $items[0]['code']);
        $this->assertSame('Tess Teacher', $items[0]['author']);
        $this->assertSame('biology-intro', $items[0]['slug']);
    }

    public function testAnAnnouncementWithoutAKnownAuthorNameSaysInstructor(): void
    {
        $ghost = CourseFactory::user('instructor');
        $this->announce($this->a['id'], 'Anonymous', $this->in('-1 day'), $ghost);

        $this->assertSame('Instructor', $this->widget($this->student, 'announcements')['announcements'][0]['author']);
    }

    public static function postedLabels(): array
    {
        return [
            ['-2 hours', 'today'],
            ['-1 day -1 hour', 'Yesterday'],
            ['-3 days -1 hour', '3 days ago'],
            ['-6 days -1 hour', '6 days ago'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('postedLabels')]
    public function testAnnouncementAgeIsLabelledForHumans(string $when, string $label): void
    {
        $this->announce($this->a['id'], 'X', $this->in($when));

        $this->assertSame($label, $this->widget($this->student, 'announcements')['announcements'][0]['posted']);
    }

    public function testAnOldAnnouncementShowsItsDate(): void
    {
        $at = $this->in('-20 days');
        $this->announce($this->a['id'], 'X', $at);

        $this->assertSame(date('M d', strtotime($at)), $this->widget($this->student, 'announcements')['announcements'][0]['posted']);
    }

    /*
    |--------------------------------------------------------------------------
    | Student: reminders
    |--------------------------------------------------------------------------
    */

    private function remind(User $user, string $title, string $at, string $status = 'pending'): void
    {
        db_connect()->table('reminders')->insert(['user_id' => $user->id, 'title' => $title, 'remind_at' => $at, 'status' => $status, 'created_at' => $at]);
    }

    public function testTheRemindersCardListsTheStudentsOwnPendingRemindersSoonestFirst(): void
    {
        $classmate = $this->person('student', 'Cam', 'Mate');
        $this->remind($this->student, 'Later this week', date('Y-m-d 18:00:00', strtotime('+3 days')));
        $this->remind($this->student, 'Tonight', date('Y-m-d 23:30:00'));
        $this->remind($this->student, 'Already done', date('Y-m-d 23:00:00'), 'done');
        $this->remind($classmate, 'Not mine', date('Y-m-d 23:00:00'));

        $reminders = $this->widget($this->student, 'reminders')['reminders'];

        $this->assertSame(['Tonight', 'Later this week'], array_column($reminders, 'title'));
        $this->assertSame('Today · 11:30 PM', $reminders[0]['due']);
        $this->assertSame(date('M j', strtotime('+3 days')) . ' · 6:00 PM', $reminders[1]['due']);
    }

    public function testPastRemindersLeaveTheCardAndOnlySixAreShown(): void
    {
        $this->remind($this->student, 'Yesterday', date('Y-m-d 09:00:00', strtotime('-1 day')));
        for ($i = 1; $i <= 8; $i++) {
            $this->remind($this->student, "R{$i}", date('Y-m-d 09:00:00', strtotime("+{$i} days")));
        }

        $reminders = $this->widget($this->student, 'reminders')['reminders'];

        $this->assertSame(['R1', 'R2', 'R3', 'R4', 'R5', 'R6'], array_column($reminders, 'title'));
    }

    public function testNoRemindersIsAnEmptyList(): void
    {
        $this->assertSame(['reminders' => []], $this->widget($this->student, 'reminders'));
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor: grading queue
    |--------------------------------------------------------------------------
    */

    public function testTheGradingQueueListsWorkWaitingAcrossSectionsOldestFirst(): void
    {
        $recent = $this->quiz($this->a, 'Recent essay', $this->in('+5 days'));
        $old = $this->quiz($this->b, 'Old essay', $this->in('+5 days'));
        $clear = $this->quiz($this->a, 'All graded', $this->in('+5 days'));
        foreach ([[$recent, 1], [$old, 6]] as [$quiz, $days]) {
            $q = AssessmentFactory::question($quiz, $this->teacher, 'Essay');
            $s = AssessmentFactory::attempt($quiz, $this->student, 'Submitted', 0.0, ['submitted_at' => $this->in("-{$days} days")]);
            AssessmentFactory::response($s, $q, ['response_text' => 'x']);
        }
        AssessmentFactory::attempt($clear, $this->student, 'Graded', 5.0);

        $queue = $this->widget($this->teacher, 'grading-queue')['queue'];

        $this->assertSame(['Old essay', 'Recent essay'], array_column($queue, 'title'));
        $this->assertSame([6, 1], array_column($queue, 'waiting_days'));
        $this->assertSame([1, 1], array_column($queue, 'ungraded'));
        $this->assertSame(['BIO 200', 'ART 100'], array_column($queue, 'course_code'));
        $this->assertSame(['B', 'A'], array_column($queue, 'section_code'));
        $this->assertSame('quiz', $queue[0]['type']);
        $this->assertSame($this->b['id'], $queue[0]['offering_id']);
    }

    public function testAnInstructorWithNoSectionsHasEmptyWidgets(): void
    {
        $idle = $this->person('instructor', 'Idle', 'Teacher');

        $widgets = $this->widgets($idle);

        $this->assertSame(['queue' => []], $widgets['grading-queue']);
        $this->assertSame(['students' => []], $widgets['at-risk-students']);
        $this->assertSame(['sections' => []], $widgets['teaching-load']);
        $this->assertSame(['progress' => []], $widgets['class-progress']);
        $this->assertSame(['drafts' => []], $widgets['content-status']);
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor: at risk, load, progress, drafts
    |--------------------------------------------------------------------------
    */

    public function testAtRiskStudentsAreShapedForTheWidget(): void
    {
        CourseFactory::items($this->a['unit'], [['type' => 'reading'], ['type' => 'reading']]);

        $students = $this->widget($this->teacher, 'at-risk-students')['students'];

        $this->assertCount(1, $students);
        $this->assertSame('Sam Student', $students[0]['name']);
        $this->assertSame('ART 100 - A', $students[0]['section']);
        $this->assertSame('art-basics', $students[0]['slug']);
        $this->assertSame('never', $students[0]['last_active']);
        $this->assertSame('inactive', $students[0]['reason']);
        $this->assertSame(0, $students[0]['progress']);
    }

    public static function lastActive(): array
    {
        return [[null, 'never'], [0, 'today'], [-3, 'today'], [1, 'yesterday'], [5, '5 days ago']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('lastActive')]
    public function testLastActiveIsPhrasedForHumans(?int $days, string $label): void
    {
        $m = new ReflectionMethod($this->service, 'describeLastActive');
        $m->setAccessible(true);

        $this->assertSame($label, $m->invoke($this->service, $days));
    }

    public function testTeachingLoadListsEachSectionWithRosterAndSchedule(): void
    {
        $db = db_connect();
        $db->table('course_schedules')->insert(['offering_id' => $this->a['id'], 'day_of_week' => 'mon', 'start_time' => '09:00:00', 'end_time' => '10:30:00', 'room' => 'R1', 'delivery_mode' => 'onsite']);
        $db->table('course_offerings')->where('id', $this->a['id'])->update(['capacity' => 40]);

        $sections = array_column($this->widget($this->teacher, 'teaching-load')['sections'], null, 'course_code');

        $this->assertSame(['ART 100', 'BIO 200'], array_keys($sections));
        $this->assertSame(1, $sections['ART 100']['enrolled']);
        $this->assertSame(40, $sections['ART 100']['capacity']);
        $this->assertSame('R1', $sections['ART 100']['room']);
        $this->assertStringContainsString('09:00', $sections['ART 100']['schedule']);
        $this->assertSame('No schedule set', $sections['BIO 200']['schedule']);
        $this->assertSame('--', $sections['BIO 200']['room']);
    }

    public function testClassProgressSkipsEmptySectionsAndComputesCompletion(): void
    {
        $items = CourseFactory::items($this->a['unit'], [['type' => 'reading'], ['type' => 'reading']]);
        CourseFactory::items($this->b['unit'], [['type' => 'reading']]);
        db_connect()->table('enrollments')->where('offering_id', $this->b['id'])->delete();
        CourseFactory::progress($items[0], $this->student);

        $progress = $this->widget($this->teacher, 'class-progress')['progress'];

        $this->assertCount(1, $progress, 'BIO has no enrolled students, so it is left off rather than shown at 0%');
        $this->assertSame('ART 100', $progress[0]['course_code']);
        $this->assertSame(50, $progress[0]['modules'][0]['completion']);
    }

    public function testContentStatusListsEveryKindOfDraftNewestFirst(): void
    {
        $db = db_connect();
        $db->table('course_modules')->insert(['offering_id' => $this->a['id'], 'title' => 'Draft module', 'order_index' => 8, 'status' => 'draft', 'created_at' => $this->in('-5 days'), 'updated_at' => $this->in('-5 days')]);
        $db->table('learning_units')->insert(['module_id' => $this->a['module'], 'title' => 'Draft unit', 'order_index' => 8, 'status' => 'draft', 'created_at' => $this->in('-3 days'), 'updated_at' => $this->in('-3 days')]);
        [$item] = CourseFactory::items($this->b['unit'], [['title' => 'Draft item', 'type' => 'pdf', 'status' => 'draft']]);
        $db->table('learning_items')->where('id', $item)->update(['updated_at' => $this->in('-1 day')]);

        $drafts = $this->widget($this->teacher, 'content-status')['drafts'];

        $this->assertSame(['Draft item', 'Draft unit', 'Draft module'], array_column($drafts, 'title'));
        $this->assertSame(['Item', 'Unit', 'Module'], array_column($drafts, 'type'));
        $this->assertSame('Pdf', $drafts[0]['detail']);
        $this->assertSame('BIO 200 - B', $drafts[0]['course_code']);
        $this->assertSame('biology-intro', $drafts[0]['slug']);
        $this->assertArrayNotHasKey('sort_key', $drafts[0]);
    }

    /*
    |--------------------------------------------------------------------------
    | Academic term
    |--------------------------------------------------------------------------
    */

    public function testTheAcademicTermIsTheActiveOneAtThePersonsInstitution(): void
    {
        $this->assertSame([], $this->service->getAcademicTerm($this->student), 'no institution on record');

        $db = db_connect();
        $db->table('institutions')->insert(['name' => 'Uni', 'code' => 'UNI' . uniqid()]);
        $institution = (int) $db->insertID();
        $db->table('institution_users')->insert(['user_id' => $this->student->id, 'institution_id' => $institution]);
        $db->table('academic_terms')->insert(['institution_id' => $institution, 'name' => 'Old term', 'academic_year' => '2025-2026', 'start_date' => '2025-08-01', 'end_date' => '2025-12-20', 'status' => 'completed']);
        $db->table('academic_terms')->insert(['institution_id' => $institution, 'name' => 'Current term', 'academic_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2026-12-20', 'status' => 'active']);

        $term = $this->service->getAcademicTerm($this->student);

        $this->assertSame('Current term', $term['name']);
        $this->assertSame('2026-2027', $term['academic_year']);
    }
}
