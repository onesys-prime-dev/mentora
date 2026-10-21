<?php

declare(strict_types=1);

use App\Services\Parents\ParentCalendarEvents;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * What a parent's calendar holds for a child: the deadlines and the
 * announcements of the child's courses, and nothing private (no reminders).
 * Reading it never touches anyone's role.
 *
 * @internal
 */
final class ParentCalendarEventsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $child;

    private User $teacher;

    private int $offering;

    private int $module;

    protected function setUp(): void
    {
        parent::setUp();

        $institution    = PeopleFactory::institution('PCE');
        $this->child    = PeopleFactory::person('student', 'Ann', 'Lee', null, $institution);
        $this->teacher  = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $institution);
        $this->offering = CourseFactory::offering('A');
        $this->module   = CourseFactory::module($this->offering);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->child);
    }

    private function assessment(string $title, string $status, string $due): int
    {
        $id = CourseFactory::assessment($this->offering, $this->teacher, $this->module);

        db_connect()->table('assessments')->where('id', $id)->update(['title' => $title, 'status' => $status, 'due_date' => $due]);

        return $id;
    }

    private function events(): array
    {
        return (new ParentCalendarEvents())->forRange($this->child, '2026-11-01 00:00:00', '2026-11-30 23:59:59')['events'];
    }

    public function testPublishedDeadlinesOfTheChildsCoursesAreThere(): void
    {
        $this->assessment('Quiz Three', 'Published', '2026-11-10 17:00:00');
        $this->assessment('A draft', 'Draft', '2026-11-11 17:00:00');
        $this->assessment('Next month', 'Published', '2026-12-10 17:00:00');

        $events = $this->events();

        $this->assertSame(['Quiz Three'], array_column($events, 'title'));
        $this->assertSame('assessment', $events[0]['kind']);
        $this->assertSame('2026-11-10', $events[0]['date']);
    }

    public function testTheChildsPrivateRemindersNeverAppear(): void
    {
        db_connect()->table('reminders')->insert([
            'user_id' => $this->child->id, 'title' => 'Buy a gift', 'remind_at' => '2026-11-10 09:00:00', 'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->events());
    }

    public function testAnnouncementsAreThereButWithoutALinkIntoTheStudentsPages(): void
    {
        db_connect()->table('announcements')->insert([
            'offering_id' => $this->offering, 'author_id' => $this->teacher->id, 'subject' => 'Room change', 'message' => 'We move to room 4.',
            'created_at' => '2026-11-05 08:00:00', 'updated_at' => '2026-11-05 08:00:00',
        ]);

        $events = $this->events();

        $this->assertSame(['Room change'], array_column($events, 'title'));
        $this->assertSame('announcement', $events[0]['kind']);
        $this->assertArrayNotHasKey('url', $events[0], 'the course pages for a parent come later');
    }

    public function testAnotherCoursesEventsAreNotIncluded(): void
    {
        $other = CourseFactory::offering('B');
        $id    = CourseFactory::assessment($other, $this->teacher, CourseFactory::module($other));
        db_connect()->table('assessments')->where('id', $id)->update(['title' => 'Not mine', 'status' => 'Published', 'due_date' => '2026-11-10 17:00:00']);

        $this->assertSame([], $this->events());
    }

    public function testLookingNeverChangesTheViewersActiveRole(): void
    {
        $_SESSION['active_role'] = 'parent';
        db_connect()->table('announcements')->insert([
            'offering_id' => $this->offering, 'author_id' => $this->teacher->id, 'subject' => 'Hello', 'message' => 'Hi',
            'created_at' => '2026-11-05 08:00:00', 'updated_at' => '2026-11-05 08:00:00',
        ]);

        $this->events();

        $this->assertSame('parent', $_SESSION['active_role']);
        $this->assertNull(db_connect()->table('user_profiles')->where('user_id', $this->child->id)->get()->getRowArray()['active_role'] ?? null);
    }

    public function testAStudentWhoIsAlsoStaffHasNoEvents(): void
    {
        $this->child->addGroup('chairperson');
        $this->assessment('A draft', 'Draft', '2026-11-11 17:00:00');

        $this->assertSame([], $this->events());
    }
}
