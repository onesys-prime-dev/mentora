<?php

declare(strict_types=1);

use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessagingContextResolver;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The course chat of an offering matches its students and instructors.
 *
 * @internal
 */
final class CourseChatSyncTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $offering;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $d                 = DeanFactory::department('C1');
        $this->institution = $d['institution'];
        $program           = DeanFactory::program(DeanFactory::subDepartment($d['department'], 'CA'), 'CA');
        $term              = DeanFactory::term($this->institution);
        $this->offering    = DeanFactory::offering($program, $term, 'CC1');
        $this->student     = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->instructor  = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);

        CourseFactory::enroll($this->offering, $this->student);
        CourseFactory::assignInstructor($this->offering, $this->instructor);
    }

    /** @return list<int> active participant ids, sorted */
    private function members(int $conversationId): array
    {
        $ids = array_map('intval', array_column(db_connect()->table('conversation_participants')
            ->select('user_id')->where('conversation_id', $conversationId)->where('left_at', null)->get()->getResultArray(), 'user_id'));
        sort($ids);

        return $ids;
    }

    private function row(int $conversationId, User $user): array
    {
        return db_connect()->table('conversation_participants')->where(['conversation_id' => $conversationId, 'user_id' => (int) $user->id])->get()->getRowArray();
    }

    private function message(int $conversationId, int $senderId): int
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $conversationId, 'sender_id' => $senderId, 'body_enc' => 'x', 'client_uuid' => bin2hex(random_bytes(18)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    private function setStatus(User $student, string $status): void
    {
        db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => (int) $student->id])->update(['status' => $status]);
    }

    public function testCreatesTheChatWithInstructorAndEnrolledStudents(): void
    {
        $result = (new CourseChatSync())->syncOffering($this->offering);
        $chat   = db_connect()->table('conversations')->where('offering_id', $this->offering)->get()->getRowArray();

        $this->assertTrue($result['created']);
        $this->assertSame((int) $chat['id'], $result['conversation_id']);
        $this->assertSame('course', $chat['type']);
        $this->assertSame($this->institution, (int) $chat['institution_id']);
        $this->assertSame(0, (int) $chat['is_locked']);
        $this->assertNull($chat['created_by']);

        $expected = [(int) $this->student->id, (int) $this->instructor->id];
        sort($expected);
        $this->assertSame($expected, $this->members((int) $chat['id']));
        $this->assertSame(0, (int) $this->row((int) $chat['id'], $this->student)['first_visible_message_id']);
    }

    public function testIsIdempotent(): void
    {
        $sync = new CourseChatSync();
        $sync->syncOffering($this->offering);
        $db     = db_connect();
        $before = [$db->table('conversations')->countAllResults(), $db->table('conversation_participants')->countAllResults()];

        $again = $sync->syncOffering($this->offering);

        $this->assertSame([false, 0, 0], [$again['created'], $again['joined'], $again['left']]);
        $this->assertSame($before, [$db->table('conversations')->countAllResults(), $db->table('conversation_participants')->countAllResults()]);
    }

    public function testDroppedAndWithdrawnStudentsAreNotMembers(): void
    {
        $other = PeopleFactory::person('student', 'Wi', 'Thdrawn', null, $this->institution);
        CourseFactory::enroll($this->offering, $other, 'withdrawn');
        $dropped = PeopleFactory::person('student', 'Dro', 'Pped', null, $this->institution);
        CourseFactory::enroll($this->offering, $dropped, 'dropped');

        $id = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];

        $this->assertNotContains((int) $other->id, $this->members($id));
        $this->assertNotContains((int) $dropped->id, $this->members($id));
    }

    public function testCompletedStudentsStayMembers(): void
    {
        $this->setStatus($this->student, 'completed');

        $id = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];

        $this->assertContains((int) $this->student->id, $this->members($id));
    }

    public function testStudentWhoDropsLeavesAtOnce(): void
    {
        $sync = new CourseChatSync();
        $id   = $sync->syncOffering($this->offering)['conversation_id'];
        $this->setStatus($this->student, 'dropped');

        $result = $sync->syncOffering($this->offering);

        $this->assertSame(1, $result['left']);
        $this->assertNotNull($this->row($id, $this->student)['left_at']);
        $this->assertFalse((new MessagingContextResolver())->isActiveParticipant((int) $this->student->id, $id));
    }

    public function testRejoinSeesOnlyWhatFollows(): void
    {
        $sync = new CourseChatSync();
        $id   = $sync->syncOffering($this->offering)['conversation_id'];
        $this->message($id, (int) $this->instructor->id);
        $last = $this->message($id, (int) $this->instructor->id);
        $this->setStatus($this->student, 'dropped');
        $sync->syncOffering($this->offering);
        $rowId = $this->row($id, $this->student)['id'];
        $this->setStatus($this->student, 'enrolled');

        $sync->syncOffering($this->offering);
        $back = $this->row($id, $this->student);

        $this->assertSame($rowId, $back['id']);
        $this->assertNull($back['left_at']);
        $this->assertSame($last + 1, (int) $back['first_visible_message_id']);
    }

    public function testCompletedToEnrolledKeepsHistory(): void
    {
        $sync = new CourseChatSync();
        $id   = $sync->syncOffering($this->offering)['conversation_id'];
        $this->message($id, (int) $this->instructor->id);
        $this->setStatus($this->student, 'completed');
        $sync->syncOffering($this->offering);
        $before = $this->row($id, $this->student);
        $this->setStatus($this->student, 'enrolled');

        $result = $sync->syncOffering($this->offering);

        $this->assertSame(0, $result['joined'] + $result['left']);
        $this->assertSame($before, $this->row($id, $this->student));
        $this->assertSame(0, (int) $before['first_visible_message_id']);
    }

    public function testNewStudentSeesNothingEarlier(): void
    {
        $sync = new CourseChatSync();
        $id   = $sync->syncOffering($this->offering)['conversation_id'];
        $this->message($id, (int) $this->instructor->id);
        $this->message($id, (int) $this->instructor->id);
        $last = $this->message($id, (int) $this->instructor->id);
        $late = PeopleFactory::person('student', 'La', 'Te', null, $this->institution);
        CourseFactory::enroll($this->offering, $late);

        $sync->syncOffering($this->offering);

        $this->assertSame($last + 1, (int) $this->row($id, $late)['first_visible_message_id']);
    }

    public function testInstructorSwapMovesMembership(): void
    {
        $sync = new CourseChatSync();
        $id   = $sync->syncOffering($this->offering)['conversation_id'];
        $b    = PeopleFactory::person('instructor', 'Be', 'Tter', null, $this->institution);
        db_connect()->table('instructor_assignments')->where('offering_id', $this->offering)->delete();
        CourseFactory::assignInstructor($this->offering, $b);

        $sync->syncOffering($this->offering);

        $this->assertNotNull($this->row($id, $this->instructor)['left_at']);
        $this->assertContains((int) $b->id, $this->members($id));
    }

    public function testPlanWritesNothing(): void
    {
        $db   = db_connect();
        $plan = (new CourseChatSync())->plan($this->offering);

        $expected = [(int) $this->student->id, (int) $this->instructor->id];
        sort($expected);
        sort($plan['join']);

        $this->assertTrue($plan['create']);
        $this->assertSame($expected, $plan['join']);
        $this->assertSame([], $plan['leave']);
        $this->assertSame(0, $db->table('conversations')->countAllResults());
        $this->assertSame(0, $db->table('conversation_participants')->countAllResults());
    }

    public function testLeavesOtherInstitutionsAlone(): void
    {
        $other   = DeanFactory::department('C2')['institution'];
        $foreign = PeopleFactory::person('student', 'For', 'Eign', null, $other);
        CourseFactory::enroll($this->offering, $foreign);

        $id = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];

        $this->assertNotContains((int) $foreign->id, $this->members($id));
    }

    public function testAFailedSyncDoesNotLeaveItsTransactionOpen(): void
    {
        $failing = new class () extends CourseChatSync {
            public function plan(int $offeringId): array
            {
                throw new RuntimeException('boom');
            }
        };
        $db    = db_connect();
        $depth = $db->transDepth;

        try {
            $failing->syncOffering($this->offering);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($depth, $db->transDepth);
    }

    public function testDuplicateKeyFromARacingSyncIsAbsorbed(): void
    {
        $now = date('Y-m-d H:i:s');
        db_connect()->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => 'course', 'offering_id' => $this->offering, 'is_locked' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $existing = (int) db_connect()->insertID();

        $result = (new CourseChatSync())->syncOffering($this->offering);

        $this->assertFalse($result['created']);
        $this->assertSame($existing, $result['conversation_id']);
        $this->assertSame(1, db_connect()->table('conversations')->where('offering_id', $this->offering)->countAllResults());
        $this->assertCount(2, $this->members($existing));
    }
}
