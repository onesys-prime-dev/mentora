<?php

declare(strict_types=1);

use App\Services\Courses\CourseService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The course lists and single course lookups: who sees which offerings
 * (enrolled active ones for a student, assigned ones for an instructor), the
 * card details (instructor, schedule, room), the progress number each role
 * sees, and that one offering is never listed twice.
 *
 * @internal
 */
final class CourseServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private CourseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CourseService();
    }

    /** A person with the profile and email login the course queries join on. */
    private function person(string $group, string $first = 'Pat', string $last = 'Person'): User
    {
        $user = CourseFactory::user($group);
        $db = db_connect();
        $db->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);
        $db->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => strtolower($first) . $user->id . '@example.test', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    /** An offering with a teacher, and the given section code and course code. */
    private function offering(string $section, string $courseCode, ?User $teacher = null): array
    {
        $offering = CourseFactory::offering($section);
        db_connect()->table('courses')->where('id', db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'])->update(['course_code' => $courseCode]);
        $teacher ??= $this->person('instructor', 'Tess', 'Teacher');
        CourseFactory::assignInstructor($offering, $teacher);

        return ['id' => $offering, 'teacher' => $teacher];
    }

    private function block(int $offering, string $day, string $start, string $end, ?string $room = null, string $mode = 'onsite'): void
    {
        db_connect()->table('course_schedules')->insert([
            'offering_id' => $offering, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'room' => $room, 'delivery_mode' => $mode,
        ]);
    }

    private function ids(array $courses): array
    {
        return array_map('intval', array_column($courses, 'offering_id'));
    }

    /*
    |--------------------------------------------------------------------------
    | A student's list
    |--------------------------------------------------------------------------
    */

    public function testAStudentSeesTheirEnrolledActiveCoursesInCourseCodeOrder(): void
    {
        $student = $this->person('student');
        $b = $this->offering('A', 'BIO 200');
        $a = $this->offering('A', 'ART 100');
        $c = $this->offering('A', 'CHM 300');
        foreach ([$b, $a, $c] as $o) {
            CourseFactory::enroll($o['id'], $student);
        }

        $courses = $this->service->getCourseOfferings($student);

        $this->assertSame([$a['id'], $b['id'], $c['id']], $this->ids($courses));
        $this->assertSame(['ART 100', 'BIO 200', 'CHM 300'], array_column($courses, 'course_code'));
    }

    public function testACardCarriesTheInstructorAndCourseDetails(): void
    {
        $student = $this->person('student');
        $teacher = $this->person('instructor', 'Tess', 'Teacher');
        $o = $this->offering('B', 'ART 100', $teacher);
        CourseFactory::enroll($o['id'], $student);

        $card = $this->service->getCourseOfferings($student)[0];

        $this->assertSame('Tess Teacher', $card['instructor_name']);
        $this->assertSame('tess' . $teacher->id . '@example.test', $card['instructor_email']);
        $this->assertSame('B', $card['section_code']);
        $this->assertNotEmpty($card['course_title']);
        $this->assertNotEmpty($card['term_name']);
        $this->assertNotEmpty($card['institution_name']);
    }

    public function testOnlyEnrolledStudentsSeeAnOffering(): void
    {
        $student = $this->person('student');
        $enrolled = $this->offering('A', 'ART 100');
        $dropped = $this->offering('A', 'BIO 200');
        $never = $this->offering('A', 'CHM 300');
        CourseFactory::enroll($enrolled['id'], $student);
        CourseFactory::enroll($dropped['id'], $student, 'dropped');

        $this->assertSame([$enrolled['id']], $this->ids($this->service->getCourseOfferings($student)));
        $this->assertNotNull($never);
    }

    public function testFinishedOfferingsLeaveTheList(): void
    {
        $student = $this->person('student');
        $teacher = $this->person('instructor');
        $active = $this->offering('A', 'ART 100', $teacher);
        $done = $this->offering('A', 'BIO 200', $teacher);
        $old = $this->offering('A', 'CHM 300', $teacher);
        foreach ([$active, $done, $old] as $o) {
            CourseFactory::enroll($o['id'], $student);
        }
        db_connect()->table('course_offerings')->where('id', $done['id'])->update(['status' => 'completed']);
        db_connect()->table('course_offerings')->where('id', $old['id'])->update(['status' => 'archived']);

        $this->assertSame([$active['id']], $this->ids($this->service->getCourseOfferings($student)));
        $this->assertSame([$active['id']], $this->ids($this->service->getCourseOfferings($teacher)));
    }

    public function testAStudentWithNoCoursesGetsAnEmptyList(): void
    {
        $this->assertSame([], $this->service->getCourseOfferings($this->person('student')));
    }

    public function testSomeoneInNeitherGroupGetsAnEmptyList(): void
    {
        db_connect()->table('users')->insert(['username' => 'norole' . uniqid(), 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $nobody = model(\CodeIgniter\Shield\Models\UserModel::class)->findById((int) db_connect()->insertID());

        $this->assertSame([], $this->service->getCourseOfferings($nobody));
        $this->assertNull($this->service->getCourseOffering($nobody, 1));
    }

    public function testACourseWithTwoInstructorsIsListedOnceForAStudent(): void
    {
        $student = $this->person('student');
        $first = $this->person('instructor', 'Ann', 'First');
        $second = $this->person('instructor', 'Ben', 'Second');
        $o = $this->offering('A', 'ART 100', $first);
        CourseFactory::assignInstructor($o['id'], $second);
        CourseFactory::enroll($o['id'], $student);

        $courses = $this->service->getCourseOfferings($student);
        $this->assertSame([$o['id']], $this->ids($courses));
        $this->assertSame('Ann First', $courses[0]['instructor_name'], 'the first-assigned instructor is shown');
    }

    public function testACourseWithNoInstructorYetStillAppearsForItsStudents(): void
    {
        $student = $this->person('student');
        $o = CourseFactory::offering('A');
        CourseFactory::enroll($o, $student);

        $courses = $this->service->getCourseOfferings($student);

        $this->assertSame([$o], $this->ids($courses));
        $this->assertNull($courses[0]['instructor_name']);
    }

    /*
    |--------------------------------------------------------------------------
    | Schedule and room on the card
    |--------------------------------------------------------------------------
    */

    public function testACardShowsItsScheduleFoldedIntoOneLineAndTheRoom(): void
    {
        $student = $this->person('student');
        $o = $this->offering('A', 'ART 100');
        CourseFactory::enroll($o['id'], $student);
        foreach (['mon', 'wed', 'fri'] as $day) {
            $this->block($o['id'], $day, '08:00:00', '09:30:00', 'Room 12');
        }
        $this->block($o['id'], 'thu', '13:00:00', '14:00:00', 'Lab 3');

        $card = $this->service->getCourseOfferings($student)[0];

        $this->assertSame('MWF 08:00-09:30 / Th 13:00-14:00', $card['schedule']);
        $this->assertSame('Room 12', $card['room'], 'the first block\'s room');
    }

    public function testACourseWithoutAScheduleSaysSo(): void
    {
        $student = $this->person('student');
        $o = $this->offering('A', 'ART 100');
        CourseFactory::enroll($o['id'], $student);

        $card = $this->service->getCourseOfferings($student)[0];

        $this->assertSame('No schedule set', $card['schedule']);
        $this->assertSame('--', $card['room']);
    }

    public function testEachCardGetsItsOwnSchedule(): void
    {
        $student = $this->person('student');
        $teacher = $this->person('instructor');
        $one = $this->offering('A', 'ART 100', $teacher);
        $two = $this->offering('A', 'BIO 200', $teacher);
        CourseFactory::enroll($one['id'], $student);
        CourseFactory::enroll($two['id'], $student);
        $this->block($one['id'], 'tue', '10:00:00', '11:00:00');

        $cards = array_column($this->service->getCourseOfferings($student), null, 'offering_id');

        $this->assertSame('T 10:00-11:00', $cards[$one['id']]['schedule']);
        $this->assertSame('No schedule set', $cards[$two['id']]['schedule']);
    }

    /*
    |--------------------------------------------------------------------------
    | Progress on the card
    |--------------------------------------------------------------------------
    */

    /** A published module and unit with $count published items. @return list<int> item ids */
    private function content(int $offering, int $count): array
    {
        $unit = CourseFactory::unit(CourseFactory::module($offering));

        return CourseFactory::items($unit, array_fill(0, $count, []));
    }

    public function testAStudentsProgressIsTheirCompletedShareOfThePublishedItems(): void
    {
        $student = $this->person('student');
        $o = $this->offering('A', 'ART 100');
        CourseFactory::enroll($o['id'], $student);
        $items = $this->content($o['id'], 3);
        CourseFactory::progress($items[0], $student);

        $this->assertSame(33, $this->service->getCourseOfferings($student)[0]['progress']);

        CourseFactory::progress($items[1], $student);
        $this->assertSame(67, $this->service->getCourseOfferings($student)[0]['progress']);
    }

    public function testInProgressItemsAndOtherStudentsWorkDoNotCount(): void
    {
        $student = $this->person('student');
        $classmate = $this->person('student');
        $o = $this->offering('A', 'ART 100');
        CourseFactory::enroll($o['id'], $student);
        CourseFactory::enroll($o['id'], $classmate);
        $items = $this->content($o['id'], 2);
        CourseFactory::progress($items[0], $student, 'in_progress', 40);
        CourseFactory::progress($items[0], $classmate);
        CourseFactory::progress($items[1], $classmate);

        $this->assertSame(0, $this->service->getCourseOfferings($student)[0]['progress']);
        $this->assertSame(100, $this->service->getCourseOfferings($classmate)[0]['progress']);
    }

    public function testACourseWithNothingPublishedHasZeroProgress(): void
    {
        $student = $this->person('student');
        $o = $this->offering('A', 'ART 100');
        CourseFactory::enroll($o['id'], $student);
        $unit = CourseFactory::unit(CourseFactory::module($o['id']));
        CourseFactory::items($unit, [['status' => 'draft']]);

        $this->assertSame(0, $this->service->getCourseOfferings($student)[0]['progress']);
    }

    /*
    |--------------------------------------------------------------------------
    | An instructor's list
    |--------------------------------------------------------------------------
    */

    public function testAnInstructorSeesTheirAssignedOfferingsWithTheClassSize(): void
    {
        $teacher = $this->person('instructor');
        $mine = $this->offering('A', 'ART 100', $teacher);
        $other = $this->offering('A', 'BIO 200');
        foreach (range(1, 3) as $n) {
            CourseFactory::enroll($mine['id'], $this->person('student'));
        }
        CourseFactory::enroll($mine['id'], $this->person('student'), 'dropped');

        $courses = $this->service->getCourseOfferings($teacher);

        $this->assertSame([$mine['id']], $this->ids($courses));
        $this->assertSame(3, (int) $courses[0]['enrolled'], 'only enrolled students');
        $this->assertNotNull($other);
    }

    public function testAnInstructorsProgressIsTheClassAverage(): void
    {
        $teacher = $this->person('instructor');
        $o = $this->offering('A', 'ART 100', $teacher);
        $ada = $this->person('student');
        $bob = $this->person('student');
        CourseFactory::enroll($o['id'], $ada);
        CourseFactory::enroll($o['id'], $bob);
        $items = $this->content($o['id'], 4);
        CourseFactory::progress($items[0], $ada);
        CourseFactory::progress($items[1], $ada);
        CourseFactory::progress($items[2], $ada);
        CourseFactory::progress($items[0], $bob);

        // 4 completions of 2 students x 4 items.
        $this->assertSame(50, $this->service->getCourseOfferings($teacher)[0]['progress']);
    }

    public function testAnEmptyClassHasZeroProgress(): void
    {
        $teacher = $this->person('instructor');
        $o = $this->offering('A', 'ART 100', $teacher);
        $this->content($o['id'], 3);

        $this->assertSame(0, $this->service->getCourseOfferings($teacher)[0]['progress']);
    }

    public function testAnInstructorWithNothingAssignedGetsAnEmptyList(): void
    {
        $this->assertSame([], $this->service->getCourseOfferings($this->person('instructor')));
    }

    /*
    |--------------------------------------------------------------------------
    | One course
    |--------------------------------------------------------------------------
    */

    public function testAStudentCanOpenOnlyACourseTheyAreEnrolledIn(): void
    {
        $student = $this->person('student');
        $mine = $this->offering('A', 'ART 100');
        $theirs = $this->offering('A', 'BIO 200');
        CourseFactory::enroll($mine['id'], $student);

        $course = $this->service->getCourseOffering($student, $mine['id']);

        $this->assertSame($mine['id'], (int) $course['offering_id']);
        $this->assertSame('ART 100', $course['course_code']);
        $this->assertNull($this->service->getCourseOffering($student, $theirs['id']));
        $this->assertNull($this->service->getCourseOffering($student, 999999));
    }

    public function testADroppedOrFinishedCourseCannotBeOpened(): void
    {
        $student = $this->person('student');
        $dropped = $this->offering('A', 'ART 100');
        $done = $this->offering('A', 'BIO 200');
        CourseFactory::enroll($dropped['id'], $student, 'dropped');
        CourseFactory::enroll($done['id'], $student);
        db_connect()->table('course_offerings')->where('id', $done['id'])->update(['status' => 'completed']);

        $this->assertNull($this->service->getCourseOffering($student, $dropped['id']));
        $this->assertNull($this->service->getCourseOffering($student, $done['id']));
    }

    public function testAnInstructorCanOpenOnlyTheirOwnOfferings(): void
    {
        $teacher = $this->person('instructor');
        $mine = $this->offering('A', 'ART 100', $teacher);
        $other = $this->offering('A', 'BIO 200');

        $this->assertSame($mine['id'], (int) $this->service->getCourseOffering($teacher, $mine['id'])['offering_id']);
        $this->assertNull($this->service->getCourseOffering($teacher, $other['id']));
    }
}
