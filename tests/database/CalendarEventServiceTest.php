<?php

declare(strict_types=1);

use App\Services\Calendar\CalendarEventService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Everything on a person's Calendar: assessment due dates, announcements and
 * their own reminders, across every course they're in, limited to the range
 * asked for and to what that person may see.
 *
 * @internal
 */
final class CalendarEventServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private CalendarEventService $service;

    private int $offering;

    private int $module;

    private User $instructor;

    private User $student;

    private User $outsider;

    private const FROM = '2026-10-01 00:00:00';

    private const TO = '2026-10-31 23:59:59';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CalendarEventService();
        $this->offering = CourseFactory::offering();
        $this->module = CourseFactory::module($this->offering);

        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        $this->outsider = CourseFactory::user('student');

        // Real people always have a profile and a sign-in; the instructor's course list reads both.
        db_connect()->table('user_profiles')->insert(['user_id' => $this->instructor->id, 'first_name' => 'Tess', 'last_name' => 'Teacher']);
        db_connect()->table('auth_identities')->insert(['user_id' => $this->instructor->id, 'type' => 'email_password', 'secret' => 'tess@example.test', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function assessment(string $title, string $due, string $status = 'Published', ?int $module = null, ?int $item = null, ?int $offering = null): int
    {
        $module ??= $offering === null ? $this->module : CourseFactory::module($offering);
        $id = CourseFactory::assessment($offering ?? $this->offering, $this->instructor, $module, $item);
        db_connect()->table('assessments')->where('id', $id)->update(['title' => $title, 'due_date' => $due, 'status' => $status]);

        return $id;
    }

    private function announce(string $subject, string $at, ?int $offering = null, string $message = ''): int
    {
        $db = db_connect();
        $db->table('announcements')->insert([
            'offering_id' => $offering ?? $this->offering, 'author_id' => $this->instructor->id,
            'subject' => $subject, 'message' => $message ?: '{}', 'created_at' => $at,
        ]);

        return (int) $db->insertID();
    }

    private function remind(User $user, string $title, string $at, string $status = 'pending', ?int $offering = null, ?string $note = null): int
    {
        $db = db_connect();
        $db->table('reminders')->insert([
            'user_id' => $user->id, 'offering_id' => $offering, 'title' => $title, 'note' => $note,
            'remind_at' => $at, 'status' => $status, 'created_at' => $at,
        ]);

        return (int) $db->insertID();
    }

    /** @return array{events: list<array>, courses: list<array>} */
    private function range(User $user, string $from = self::FROM, string $to = self::TO): array
    {
        return $this->service->forRange($user, $from, $to);
    }

    /** @return list<string> */
    private function titles(User $user, string $from = self::FROM, string $to = self::TO): array
    {
        return array_column($this->range($user, $from, $to)['events'], 'title');
    }

    /*
    |--------------------------------------------------------------------------
    | Assessments
    |--------------------------------------------------------------------------
    */

    public function testADueDateIsPlottedWithItsCourseTypeAndTime(): void
    {
        $id = $this->assessment('Midterm Quiz', '2026-10-12 14:30:00');

        $event = $this->range($this->student)['events'][0];

        $this->assertSame('assessment', $event['kind']);
        $this->assertSame($id, $event['id']);
        $this->assertSame('2026-10-12', $event['date']);
        $this->assertSame('2:30 PM', $event['time']);
        $this->assertSame('Midterm Quiz', $event['title']);
        $this->assertSame($this->offering, $event['offering_id']);
        $this->assertSame('warning', $event['tone']);
        $this->assertSame('Quiz', $event['detail']);
        $this->assertMatchesRegularExpression('/^IT\d+ - A$/', $event['course']);
    }

    public function testStudentsOnlySeePublishedAssessmentsButInstructorsAlsoSeeDrafts(): void
    {
        $this->assessment('Live', '2026-10-05 09:00:00', 'Published');
        $this->assessment('Unreleased', '2026-10-06 09:00:00', 'Draft');
        $this->assessment('Old', '2026-10-07 09:00:00', 'Archived');

        $this->assertSame(['Live'], $this->titles($this->student));
        $this->assertSame(['Live', 'Unreleased'], $this->titles($this->instructor));

        $draft = $this->range($this->instructor)['events'][1];
        $this->assertSame('muted', $draft['tone']);
        $this->assertSame('Quiz · Draft', $draft['detail']);
    }

    public function testAStudentDoesNotSeeDueDatesOfContentThatIsHiddenFromThem(): void
    {
        $draftModule = CourseFactory::module($this->offering, 'draft', 2);
        $this->assessment('In draft module', '2026-10-05 09:00:00', 'Published', $draftModule);

        $unit = CourseFactory::unit($this->module);
        [$hiddenItem] = CourseFactory::items($unit, [['status' => 'draft']]);
        $this->assessment('Behind draft item', '2026-10-06 09:00:00', 'Published', $this->module, $hiddenItem);

        [$inDraftUnit] = CourseFactory::items(CourseFactory::unit($this->module, 'draft', 3), [['status' => 'published']]);
        $this->assessment('Behind draft unit', '2026-10-08 09:00:00', 'Published', $this->module, $inDraftUnit);

        [$shownItem] = CourseFactory::items(CourseFactory::unit($this->module, 'published', 2), [['status' => 'published']]);
        $this->assessment('Behind shown item', '2026-10-07 09:00:00', 'Published', $this->module, $shownItem);

        $this->assertSame(['Behind shown item'], $this->titles($this->student));
        $this->assertSame(['In draft module', 'Behind draft item', 'Behind shown item', 'Behind draft unit'], $this->titles($this->instructor));
    }

    public function testOnlyDueDatesInsideTheRangeAreReturnedEndsIncluded(): void
    {
        $this->assessment('Before', '2026-09-30 23:59:59');
        $this->assessment('First moment', '2026-10-01 00:00:00');
        $this->assessment('Last moment', '2026-10-31 23:59:59');
        $this->assessment('After', '2026-11-01 00:00:00');

        $this->assertSame(['First moment', 'Last moment'], $this->titles($this->student));
    }

    public function testOnlyTheCoursesThePersonIsInShowUp(): void
    {
        $other = CourseFactory::offering('B');
        $this->assessment('Elsewhere', '2026-10-05 09:00:00', 'Published', null, null, $other);
        $this->assessment('Mine', '2026-10-06 09:00:00');

        $this->assertSame(['Mine'], $this->titles($this->student));
        $this->assertSame(['Mine'], $this->titles($this->instructor));
        $this->assertSame([], $this->titles($this->outsider));
    }

    public function testADroppedStudentNoLongerSeesTheCourse(): void
    {
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');
        $this->assessment('Mine', '2026-10-06 09:00:00');
        $this->announce('News', '2026-10-06 09:00:00');

        $result = $this->range($dropped);

        $this->assertSame([], $result['events']);
        $this->assertSame([], $result['courses']);
    }

    public function testAnAssessmentWithoutAModuleIsLeftOff(): void
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, null);
        db_connect()->table('assessments')->where('id', $id)->update(['due_date' => '2026-10-05 09:00:00', 'status' => 'Published']);

        $this->assertSame([], $this->titles($this->student));
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    public function testAnAnnouncementLinksToItsCourseTabAndPreviewsTheMessage(): void
    {
        helper('announcement');
        $id = $this->announce('Room change', '2026-10-09 08:15:00', null, announcement_message_from_text('See you in room 4'));

        $title = db_connect()->table('course_offerings')
            ->select('courses.course_title')->join('courses', 'courses.id = course_offerings.course_id')
            ->where('course_offerings.id', $this->offering)->get()->getRowArray()['course_title'];

        $student = $this->range($this->student)['events'][0];
        $teacher = $this->range($this->instructor)['events'][0];

        $this->assertSame('announcement', $student['kind']);
        $this->assertSame($id, $student['id']);
        $this->assertSame('2026-10-09', $student['date']);
        $this->assertSame('8:15 AM', $student['time']);
        $this->assertSame('info', $student['tone']);
        $this->assertSame('See you in room 4', $student['detail']);
        $this->assertSame(site_url("student/courses/{$this->offering}/" . url_title($title, '-', true) . "/announcements?announcement={$id}"), $student['url']);
        $this->assertStringStartsWith(site_url("instructor/courses/{$this->offering}/"), $teacher['url']);
    }

    public function testALongPreviewIsCutAtEightyCharacters(): void
    {
        helper('announcement');
        $this->announce('Long', '2026-10-09 08:15:00', null, announcement_message_from_text(str_repeat('word ', 40)));

        $detail = $this->range($this->student)['events'][0]['detail'];

        $this->assertSame(80, mb_strlen($detail));
        $this->assertStringEndsWith('…', $detail);
    }

    public function testAnnouncementsOfOtherCoursesAndOutsideTheRangeAreLeftOut(): void
    {
        $other = CourseFactory::offering('B');
        $this->announce('Elsewhere', '2026-10-09 08:00:00', $other);
        $this->announce('Too early', '2026-09-30 08:00:00');
        $this->announce('Just right', '2026-10-09 08:00:00');

        $this->assertSame(['Just right'], $this->titles($this->student));
        $this->assertSame([], $this->titles($this->outsider));
    }

    /*
    |--------------------------------------------------------------------------
    | Reminders
    |--------------------------------------------------------------------------
    */

    public function testRemindersArePrivateToTheirOwner(): void
    {
        $this->remind($this->student, 'Study for quiz', '2026-10-08 18:00:00', 'pending', null, 'chapters 1-3');
        $this->remind($this->instructor, 'Grade papers', '2026-10-08 19:00:00');

        $mine = $this->range($this->student)['events'];

        $this->assertSame(['Study for quiz'], array_column($mine, 'title'));
        $this->assertSame('reminder', $mine[0]['kind']);
        $this->assertSame('neutral', $mine[0]['tone']);
        $this->assertSame('chapters 1-3', $mine[0]['detail']);
        $this->assertSame('6:00 PM', $mine[0]['time']);
        $this->assertSame(['Grade papers'], $this->titles($this->instructor));
        $this->assertSame([], $this->titles($this->outsider));
    }

    public function testADoneReminderIsMutedAndAReminderCanBeTiedToACourse(): void
    {
        $this->remind($this->student, 'Done thing', '2026-10-08 18:00:00', 'done', $this->offering);

        $event = $this->range($this->student)['events'][0];

        $this->assertSame('muted', $event['tone']);
        $this->assertSame($this->offering, $event['offering_id']);
        $this->assertSame('', $event['detail']);
    }

    public function testARemindersRangeIsInclusive(): void
    {
        $this->remind($this->student, 'Early', '2026-09-30 23:59:59');
        $this->remind($this->student, 'In', '2026-10-31 23:59:59');

        $this->assertSame(['In'], $this->titles($this->student));
    }

    /*
    |--------------------------------------------------------------------------
    | Together
    |--------------------------------------------------------------------------
    */

    public function testAllKindsAreMergedInDateOrderAndTheCoursesComeWithThem(): void
    {
        $this->assessment('Due', '2026-10-20 09:00:00');
        $this->announce('News', '2026-10-05 09:00:00');
        $this->remind($this->student, 'Remember', '2026-10-12 09:00:00');

        $result = $this->range($this->student);

        $this->assertSame(['News', 'Remember', 'Due'], array_column($result['events'], 'title'));
        $this->assertSame(['announcement', 'reminder', 'assessment'], array_column($result['events'], 'kind'));
        $this->assertSame([$this->offering], array_map('intval', array_column($result['courses'], 'offering_id')));
    }

    public function testAPersonInNoCourseStillGetsTheirOwnReminders(): void
    {
        $this->remind($this->outsider, 'Mine alone', '2026-10-08 18:00:00');

        $result = $this->range($this->outsider);

        $this->assertSame(['Mine alone'], array_column($result['events'], 'title'));
        $this->assertSame([], $result['courses']);
    }
}
