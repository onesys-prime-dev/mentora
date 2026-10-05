<?php

declare(strict_types=1);

use App\Services\Users\ReminderService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Personal reminders: what may be saved, and that only the owner can ever
 * mark one done or delete it.
 *
 * @internal
 */
final class ReminderServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ReminderService $service;

    private int $offering;

    private User $student;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReminderService();
        $this->offering = CourseFactory::offering();
        $this->student = CourseFactory::user('student');
        $this->other = CourseFactory::user('student');

        CourseFactory::enroll($this->offering, $this->student);
    }

    private function row(int $id): ?array
    {
        return db_connect()->table('reminders')->where('id', $id)->get()->getRowArray();
    }

    private function stored(): int
    {
        return db_connect()->table('reminders')->countAllResults();
    }

    private function create(array $data = [], ?User $as = null): array
    {
        return $this->service->create($as ?? $this->student, $data + ['title' => 'Read chapter 3', 'remind_at' => '2026-10-20 09:00:00']);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function testAReminderIsSavedForItsOwner(): void
    {
        $result = $this->create(['title' => '  Read chapter 3  ', 'note' => '  pages 40-60  ', 'offering_id' => $this->offering]);

        $this->assertTrue($result['success']);

        $row = $this->row((int) $result['reminder']['id']);
        $this->assertSame('Read chapter 3', $row['title']);
        $this->assertSame('pages 40-60', $row['note']);
        $this->assertSame('2026-10-20 09:00:00', $row['remind_at']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($this->offering, (int) $row['offering_id']);
        $this->assertSame((int) $this->student->id, (int) $row['user_id']);
    }

    public function testTheNoteAndCourseAreOptional(): void
    {
        $result = $this->create(['note' => '   ', 'offering_id' => '']);

        $row = $this->row((int) $result['reminder']['id']);
        $this->assertNull($row['note']);
        $this->assertNull($row['offering_id']);
    }

    public function testATitleAndADateAreRequired(): void
    {
        foreach ([['title' => '   '], ['title' => ''], ['remind_at' => null], ['remind_at' => 'not a date'], ['remind_at' => '']] as $bad) {
            $result = $this->create($bad);
            $this->assertFalse($result['success'], json_encode($bad));
            $this->assertNotSame('', $result['message']);
        }

        $this->assertSame(0, $this->stored());
    }

    public function testATitleHasAtMost150Characters(): void
    {
        $this->assertTrue($this->create(['title' => str_repeat('a', 150)])['success']);
        $this->assertFalse($this->create(['title' => str_repeat('a', 151)])['success']);
    }

    public function testTheOwnerAndStatusCannotBeChosenByTheCaller(): void
    {
        $result = $this->create(['user_id' => $this->other->id, 'status' => 'done']);

        $row = $this->row((int) $result['reminder']['id']);
        $this->assertSame((int) $this->student->id, (int) $row['user_id']);
        $this->assertSame('pending', $row['status']);
    }

    public function testAReminderCanOnlyBeTiedToACourseThePersonIsIn(): void
    {
        $elsewhere = CourseFactory::offering('B');

        foreach ([$elsewhere, 999999] as $offering) {
            $result = $this->create(['offering_id' => $offering]);
            $this->assertFalse($result['success'], "offering {$offering}");
        }

        $this->assertSame(0, $this->stored());

        $teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($elsewhere, $teacher);
        $this->assertTrue($this->create(['offering_id' => $elsewhere], $teacher)['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Done / delete
    |--------------------------------------------------------------------------
    */

    public function testTheOwnerMarksAReminderDone(): void
    {
        $id = (int) $this->create()['reminder']['id'];

        $this->assertTrue($this->service->markDone($this->student, $id)['success']);
        $this->assertSame('done', $this->row($id)['status']);
    }

    public function testTheOwnerDeletesAReminder(): void
    {
        $id = (int) $this->create()['reminder']['id'];

        $this->assertTrue($this->service->delete($this->student, $id)['success']);
        $this->assertNull($this->row($id));
    }

    public function testNobodyElseCanMarkDoneOrDeleteSomeonesReminder(): void
    {
        $id = (int) $this->create()['reminder']['id'];

        $this->assertFalse($this->service->markDone($this->other, $id)['success']);
        $this->assertFalse($this->service->delete($this->other, $id)['success']);

        $row = $this->row($id);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
    }

    public function testUnknownRemindersAreReportedNotFound(): void
    {
        $this->assertSame('Reminder not found.', $this->service->markDone($this->student, 999999)['message']);
        $this->assertSame('Reminder not found.', $this->service->delete($this->student, 999999)['message']);
    }
}
