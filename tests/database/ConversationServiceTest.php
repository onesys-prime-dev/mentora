<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\DirectKey;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Starting, finding, listing and counting conversations for one person.
 *
 * @internal
 */
final class ConversationServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ConversationService $service;

    private int $institution;

    private int $program;

    private int $term;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service     = new ConversationService();
        $d                 = DeanFactory::department('C1');
        $this->institution = $d['institution'];
        $this->sub         = DeanFactory::subDepartment($d['department'], 'CA');
        $this->program     = DeanFactory::program($this->sub, 'CA');
        $this->term        = DeanFactory::term($this->institution);
    }

    private function person(string $role, bool $active = true, ?int $institution = null): User
    {
        return PeopleFactory::person($role, ucfirst($role), 'Person', null, $institution ?? $this->institution, $active);
    }

    /** A student and an instructor sharing an active course. @return array{User, User, int} */
    private function pair(): array
    {
        $student    = $this->person('student');
        $instructor = $this->person('instructor');
        $offering   = DeanFactory::offering($this->program, $this->term, 'K' . random_int(100, 999));
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $instructor);

        return [$student, $instructor, $offering];
    }

    private function message(int $conversationId, int $senderId, ?string $removedAt = null): int
    {
        $db = db_connect();
        $db->table('messages')->insert([
            'conversation_id' => $conversationId, 'sender_id' => $senderId, 'body_enc' => 'x', 'client_uuid' => bin2hex(random_bytes(18)),
            'removed_at' => $removedAt, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    public function testAnAllowedStartCreatesOneConversationWithTwoParticipants(): void
    {
        [$student, $instructor] = $this->pair();

        $conversation = $this->service->startDirect($student, (int) $instructor->id);

        $this->assertNotNull($conversation);
        $this->assertSame('direct', $conversation['type']);
        $this->assertSame($this->institution, (int) $conversation['institution_id']);
        $this->assertSame(2, db_connect()->table('conversation_participants')->where('conversation_id', $conversation['id'])->countAllResults());
    }

    public function testStartingTwiceFromEitherSideReturnsTheSameConversation(): void
    {
        [$student, $instructor] = $this->pair();

        $first  = $this->service->startDirect($student, (int) $instructor->id);
        $second = $this->service->startDirect($student, (int) $instructor->id);
        $third  = $this->service->startDirect($instructor, (int) $student->id);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['id'], $third['id']);
        $this->assertSame(1, db_connect()->table('conversations')->countAllResults());
        $this->assertSame(2, db_connect()->table('conversation_participants')->countAllResults());
    }

    public function testAnExistingConversationWithTheSameKeyIsReturned(): void
    {
        [$student, $instructor] = $this->pair();
        $db = db_connect();
        $db->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'direct', 'direct_key' => DirectKey::for($this->institution, (int) $student->id, (int) $instructor->id, null), 'created_at' => date('Y-m-d H:i:s')]);
        $existing = (int) $db->insertID();

        $this->assertSame($existing, (int) $this->service->startDirect($student, (int) $instructor->id)['id']);
    }

    public function testRefusedStartsAnswerNullWithoutRevealingWhy(): void
    {
        [$student, $instructor] = $this->pair();
        $chair   = DeanFactory::chair($this->sub);
        $admin   = CourseFactory::user('admin');
        $far     = $this->person('instructor', true, PeopleFactory::institution('FAR'));
        $off     = $this->person('instructor', false);
        $blocker = $this->person('student');
        CourseFactory::enroll((int) db_connect()->table('enrollments')->select('offering_id')->where('student_user_id', $student->id)->get()->getRow('offering_id'), $blocker);
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $blocker->id, 'blocked_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        foreach ([999999, (int) $student->id, (int) $chair->id, (int) $admin->id, (int) $far->id, (int) $off->id, (int) $blocker->id] as $recipientId) {
            $this->assertNull($this->service->startDirect($student, $recipientId), "recipient {$recipientId}");
        }

        $this->assertSame(0, db_connect()->table('conversations')->countAllResults());
    }

    public function testAParentWithTwoChildrenGetsTwoThreadsWithTheSameInstructor(): void
    {
        $parent     = $this->person('parent');
        $instructor = $this->person('instructor');
        $offering   = DeanFactory::offering($this->program, $this->term, 'KP1');
        CourseFactory::assignInstructor($offering, $instructor);
        $children = [];

        foreach ([1, 2] as $n) {
            $child = $this->person('student');
            CourseFactory::enroll($offering, $child);
            db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
            $children[] = (int) $child->id;
        }

        $one = $this->service->startDirect($parent, (int) $instructor->id, $children[0]);
        $two = $this->service->startDirect($parent, (int) $instructor->id, $children[1]);

        $this->assertNotSame($one['id'], $two['id']);
        $this->assertSame($children[0], (int) $one['subject_student_id']);
        $this->assertNull($this->service->startDirect($parent, (int) $instructor->id, (int) $this->person('student')->id));
    }

    /** @return array{User, User, list<int>} a parent, an instructor, and the children (all taught by that instructor) */
    private function familyWithChildren(int $count): array
    {
        $parent     = $this->person('parent');
        $instructor = $this->person('instructor');
        $offering   = DeanFactory::offering($this->program, $this->term, 'KF' . random_int(100, 999));
        CourseFactory::assignInstructor($offering, $instructor);
        $children = [];

        for ($i = 0; $i < $count; $i++) {
            $child = $this->person('student');
            CourseFactory::enroll($offering, $child);
            db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
            $children[] = (int) $child->id;
        }

        return [$parent, $instructor, $children];
    }

    public function testAParentThreadIsOneThreadWhicheverSideStartsItAndNamesTheChild(): void
    {
        [$parent, $instructor, $children] = $this->familyWithChildren(1);

        $fromInstructor = $this->service->startDirect($instructor, (int) $parent->id);
        $fromParent     = $this->service->startDirect($parent, (int) $instructor->id);
        $named          = $this->service->startDirect($parent, (int) $instructor->id, $children[0]);

        $this->assertNotNull($fromInstructor);
        $this->assertSame($children[0], (int) $fromInstructor['subject_student_id']);
        $this->assertSame($fromInstructor['id'], $fromParent['id']);
        $this->assertSame($fromInstructor['id'], $named['id']);
        $this->assertSame(1, db_connect()->table('conversations')->countAllResults());
    }

    public function testAParentThreadWithSeveralSharedChildrenNeedsTheChildNamed(): void
    {
        [$parent, $instructor, $children] = $this->familyWithChildren(2);

        $this->assertNull($this->service->startDirect($instructor, (int) $parent->id));
        $this->assertNull($this->service->startDirect($parent, (int) $instructor->id));
        $this->assertNotNull($this->service->startDirect($parent, (int) $instructor->id, $children[1]));
    }

    public function testFindReturnsOnlyForAnActiveParticipantOfTheirOwnInstitution(): void
    {
        [$student, $instructor] = $this->pair();
        $stranger = $this->person('student');
        $id       = (int) $this->service->startDirect($student, (int) $instructor->id)['id'];

        $this->assertSame($id, (int) $this->service->find($student, $id)['id']);
        $this->assertNull($this->service->find($stranger, $id));
        $this->assertNull($this->service->find($student, 999999));

        db_connect()->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $student->id])->update(['left_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($this->service->find($student, $id));

        $outsider = $this->person('student', true, PeopleFactory::institution('OUT2'));
        db_connect()->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $outsider->id, 'joined_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($this->service->find($outsider, $id));
    }

    public function testListForFiltersByTypeAndUnreadAndPinsCourseChatsFirst(): void
    {
        [$student, $instructor, $offering] = $this->pair();
        $direct = (int) $this->service->startDirect($student, (int) $instructor->id)['id'];
        $db     = db_connect();
        $db->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'course', 'offering_id' => $offering, 'created_at' => date('Y-m-d H:i:s')]);
        $course = (int) $db->insertID();
        $db->table('conversation_participants')->insert(['conversation_id' => $course, 'user_id' => $student->id, 'joined_at' => date('Y-m-d H:i:s')]);
        $this->message($direct, (int) $instructor->id);
        $db->table('conversations')->where('id', $direct)->update(['last_message_at' => date('Y-m-d H:i:s')]);

        $all = $this->service->listFor($student);
        $this->assertSame([$course, $direct], array_map(static fn (array $r): int => (int) $r['id'], $all));
        $this->assertSame([$direct], array_map(static fn (array $r): int => (int) $r['id'], $this->service->listFor($student, 'direct')));
        $this->assertSame([$course], array_map(static fn (array $r): int => (int) $r['id'], $this->service->listFor($student, 'course')));
        $this->assertSame([$direct], array_map(static fn (array $r): int => (int) $r['id'], $this->service->listFor($student, 'all', true)));
        $this->assertSame((int) $instructor->id, (int) $all[1]['other_user_id']);
        $this->assertSame(1, (int) $all[1]['unread']);
        $this->assertArrayNotHasKey('body_enc', $all[1]);
    }

    public function testUnreadCountIgnoresOwnRemovedHiddenReadAndEarlierMessages(): void
    {
        [$student, $instructor] = $this->pair();
        $id = (int) $this->service->startDirect($student, (int) $instructor->id)['id'];
        $db = db_connect();

        $this->message($id, (int) $student->id);
        $this->message($id, (int) $instructor->id, date('Y-m-d H:i:s'));
        $early  = $this->message($id, (int) $instructor->id);
        $read   = $this->message($id, (int) $instructor->id);
        $hidden = $this->message($id, (int) $instructor->id);
        $counts = $this->message($id, (int) $instructor->id);
        $db->table('message_hidden')->insert(['message_id' => $hidden, 'user_id' => $student->id]);
        $db->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $student->id])->update(['first_visible_message_id' => $read, 'last_read_message_id' => $read]);

        $this->assertGreaterThan($early, $read);
        $this->assertSame(1, $this->service->unreadCount($student));
        $this->assertSame($counts, (int) $db->table('messages')->selectMax('id')->get()->getRow('id'));
    }
}
