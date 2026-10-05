<?php

declare(strict_types=1);

use App\Services\Courses\ScheduleService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * A person's weekly timetable: which classes appear (their own only), how
 * each is labelled, and the grid maths: the shared time axis, the hour
 * marks, and each class's column, starting row and length.
 *
 * @internal
 */
final class ScheduleServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ScheduleService $service;
    private User $student;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ScheduleService();
        $this->student = $this->person('student');
        $this->teacher = $this->person('instructor');
    }

    private function person(string $group): User
    {
        $user = CourseFactory::user($group);
        $db = db_connect();
        $db->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => 'Pat', 'last_name' => 'Person']);
        $db->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => "p{$user->id}@example.test", 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    /** An offering taught by $teacher (and joined by $student) with a course title and code. */
    private function course(string $code, string $title, string $section = 'A', bool $join = true): int
    {
        $offering = CourseFactory::offering($section);
        $courseId = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'];
        db_connect()->table('courses')->where('id', $courseId)->update(['course_code' => $code, 'course_title' => $title]);
        CourseFactory::assignInstructor($offering, $this->teacher);

        if ($join) {
            CourseFactory::enroll($offering, $this->student);
        }

        return $offering;
    }

    private function block(int $offering, string $day, string $start, string $end, ?string $room = 'Room 1', string $mode = 'onsite'): void
    {
        db_connect()->table('course_schedules')->insert([
            'offering_id' => $offering, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'room' => $room, 'delivery_mode' => $mode,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The page's header data
    |--------------------------------------------------------------------------
    */

    public function testTheViewGetsTheRoleTodayAndTheWeek(): void
    {
        $page = $this->service->forUser($this->student);

        $this->assertSame('student', $page['role']);
        $this->assertSame($this->student, $page['user']);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $page['days']);
        $this->assertSame('Monday', $page['dayLabels']['mon']);
        $this->assertSame(strtolower(date('D')), $page['today']);
        $this->assertSame($page['dayLabels'][$page['today']] . ', ' . date('F j'), $page['todayLabel']);
        $this->assertSame('instructor', $this->service->forUser($this->teacher)['role']);
    }

    public function testNoCoursesAndNoClassesAreToldApart(): void
    {
        $none = $this->service->forUser($this->student);
        $this->assertFalse($none['hasAnyCourses']);
        $this->assertFalse($none['hasAnyClasses']);
        $this->assertSame(0, $none['grid']['total_rows']);
        $this->assertSame([], $none['grid']['hour_marks']);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], array_keys($none['grid']['by_day']));

        $this->course('ART 100', 'Drawing');
        $enrolledNoBlocks = $this->service->forUser($this->student);
        $this->assertTrue($enrolledNoBlocks['hasAnyCourses']);
        $this->assertFalse($enrolledNoBlocks['hasAnyClasses']);
    }

    /*
    |--------------------------------------------------------------------------
    | A class on the grid
    |--------------------------------------------------------------------------
    */

    public function testAClassHasItsLabelsTimeRoomModeAndLink(): void
    {
        $offering = $this->course('ART 100', 'Life Drawing', 'B');
        $this->block($offering, 'tue', '09:00:00', '10:30:00', 'Studio 4');

        $class = $this->service->forUser($this->student)['grid']['by_day']['tue'][0];

        $this->assertSame($offering, $class['offering_id']);
        $this->assertSame('ART 100', $class['course_code']);
        $this->assertSame('Life Drawing', $class['course_title']);
        $this->assertSame('B', $class['section_code']);
        $this->assertSame('9:00 AM – 10:30 AM', $class['time']);
        $this->assertSame('Studio 4', $class['room']);
        $this->assertSame('On-site', $class['mode']);
        $this->assertStringEndsWith("student/courses/{$offering}/life-drawing", $class['url']);
    }

    public function testAnInstructorsLinkGoesToTheirSideOfTheSite(): void
    {
        $offering = $this->course('ART 100', 'Life Drawing');
        $this->block($offering, 'tue', '09:00:00', '10:00:00');

        $class = $this->service->forUser($this->teacher)['grid']['by_day']['tue'][0];

        $this->assertStringEndsWith("instructor/courses/{$offering}/life-drawing", $class['url']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function modes(): iterable
    {
        yield 'onsite' => ['onsite', 'On-site'];
        yield 'online' => ['online', 'Online'];
        yield 'hybrid' => ['hybrid', 'Hybrid'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testTheDeliveryModeIsLabelled(string $mode, string $label): void
    {
        $offering = $this->course('ART 100', 'Drawing');
        $this->block($offering, 'mon', '09:00:00', '10:00:00', null, $mode);

        $class = $this->service->forUser($this->student)['grid']['by_day']['mon'][0];

        $this->assertSame($label, $class['mode']);
        $this->assertSame('', $class['room'], 'no room is an empty string, not null');
    }

    public function testAClassIsPlacedInItsDayColumnStartingRowAndLength(): void
    {
        $offering = $this->course('ART 100', 'Drawing');
        $this->block($offering, 'thu', '09:30:00', '11:00:00');

        $page = $this->service->forUser($this->student);
        $class = $page['grid']['by_day']['thu'][0];

        // The axis runs 9:00 to 11:00 in half-hour rows: 4 rows.
        $this->assertSame(4, $page['grid']['total_rows']);
        $this->assertSame(4, $class['column'], 'Monday is column 1');
        $this->assertSame(2, $class['row_start'], 'half an hour after the axis starts');
        $this->assertSame(3, $class['row_span'], 'an hour and a half');
        $this->assertSame(570, $class['start_minutes']);
        $this->assertSame(660, $class['end_minutes']);
    }

    public function testTheTimeAxisSpansTheEarliestStartToTheLatestEndRoundedOutToTheHour(): void
    {
        $one = $this->course('ART 100', 'Drawing');
        $two = $this->course('BIO 200', 'Biology');
        $this->block($one, 'mon', '08:15:00', '09:45:00');
        $this->block($two, 'fri', '13:00:00', '14:20:00');

        $grid = $this->service->forUser($this->student)['grid'];

        // 8:00 to 15:00 = 7 hours = 14 rows.
        $this->assertSame(14, $grid['total_rows']);
        $this->assertSame(
            [['row' => 1, 'label' => '8 AM'], ['row' => 3, 'label' => '9 AM'], ['row' => 5, 'label' => '10 AM'], ['row' => 7, 'label' => '11 AM'], ['row' => 9, 'label' => '12 PM'], ['row' => 11, 'label' => '1 PM'], ['row' => 13, 'label' => '2 PM']],
            $grid['hour_marks']
        );
        $early = $grid['by_day']['mon'][0];
        $this->assertSame(1, $early['row_start']);
        $this->assertSame(3, $early['row_span'], '8:15 to 9:45 is 90 minutes');
        $late = $grid['by_day']['fri'][0];
        $this->assertSame(11, $late['row_start'], '13:00 is five hours after 8:00');
        $this->assertSame(2, $late['row_span'], '80 minutes rounds down to two rows');
    }

    public function testAVeryShortClassStillTakesOneRow(): void
    {
        $offering = $this->course('ART 100', 'Drawing');
        $this->block($offering, 'mon', '09:00:00', '09:10:00');

        $this->assertSame(1, $this->service->forUser($this->student)['grid']['by_day']['mon'][0]['row_span']);
    }

    public function testSeveralClassesOnADayKeepTheirWeekOrderOfStartTimes(): void
    {
        $one = $this->course('ART 100', 'Drawing');
        $two = $this->course('BIO 200', 'Biology');
        $this->block($two, 'wed', '13:00:00', '14:00:00');
        $this->block($one, 'wed', '08:00:00', '09:00:00');

        $day = $this->service->forUser($this->student)['grid']['by_day']['wed'];

        $this->assertSame(['ART 100', 'BIO 200'], array_column($day, 'course_code'));
    }

    public function testClassesFillTheirOwnDaysAndTheOthersStayEmpty(): void
    {
        $offering = $this->course('ART 100', 'Drawing');
        foreach (['mon', 'wed', 'fri'] as $day) {
            $this->block($offering, $day, '09:00:00', '10:00:00');
        }

        $byDay = $this->service->forUser($this->student)['grid']['by_day'];

        $this->assertSame([1, 0, 1, 0, 1, 0, 0], array_values(array_map('count', $byDay)));
    }

    /*
    |--------------------------------------------------------------------------
    | Whose classes
    |--------------------------------------------------------------------------
    */

    public function testOnlyTheStudentsOwnClassesAppear(): void
    {
        $mine = $this->course('ART 100', 'Drawing');
        $notMine = $this->course('BIO 200', 'Biology', 'A', false);
        $this->block($mine, 'mon', '09:00:00', '10:00:00');
        $this->block($notMine, 'mon', '11:00:00', '12:00:00');

        $day = $this->service->forUser($this->student)['grid']['by_day']['mon'];

        $this->assertSame(['ART 100'], array_column($day, 'course_code'));
    }

    public function testADroppedCourseLeavesTheTimetable(): void
    {
        $offering = $this->course('ART 100', 'Drawing');
        $this->block($offering, 'mon', '09:00:00', '10:00:00');
        db_connect()->table('enrollments')->where('student_user_id', $this->student->id)->update(['status' => 'dropped']);

        $page = $this->service->forUser($this->student);

        $this->assertFalse($page['hasAnyCourses']);
        $this->assertSame([], $page['grid']['by_day']['mon']);
    }

    public function testAnInstructorSeesEverySectionTheyTeach(): void
    {
        $one = $this->course('ART 100', 'Drawing', 'A', false);
        $two = $this->course('ART 100', 'Drawing', 'B', false);
        $this->block($one, 'mon', '09:00:00', '10:00:00');
        $this->block($two, 'mon', '13:00:00', '14:00:00');

        $day = $this->service->forUser($this->teacher)['grid']['by_day']['mon'];

        $this->assertSame(['A', 'B'], array_column($day, 'section_code'));
    }

    public function testAnotherInstructorsClassesAreNotShown(): void
    {
        $this->course('ART 100', 'Drawing', 'A', false);
        $other = $this->person('instructor');
        $theirs = CourseFactory::offering('Z');
        CourseFactory::assignInstructor($theirs, $other);
        $this->block($theirs, 'mon', '09:00:00', '10:00:00');

        $page = $this->service->forUser($this->teacher);

        $this->assertSame([], $page['grid']['by_day']['mon']);
    }
}
