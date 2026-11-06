<?php

declare(strict_types=1);

use App\Services\Messaging\ChatStateService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Pin, mute, archive, mark unread and delete for me: the caller's own row only.
 *
 * @internal
 */
final class ChatStateServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ChatStateService $service;

    private int $institution;

    private int $program;

    private int $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service     = new ChatStateService();
        $d                 = DeanFactory::department('C1');
        $this->institution = $d['institution'];
        $sub               = DeanFactory::subDepartment($d['department'], 'CA');
        $this->program     = DeanFactory::program($sub, 'CA');
        $this->term        = DeanFactory::term($this->institution);
    }

    private function person(string $role): User
    {
        return PeopleFactory::person($role, ucfirst($role), 'Person', null, $this->institution, true);
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

    /** A direct chat between the pair. */
    private function direct(User $student, User $instructor): int
    {
        return (int) (new ConversationService())->startDirect($student, (int) $instructor->id)['id'];
    }

    /** @return array<string, mixed> */
    private function row(int $chat, User $user): array
    {
        return db_connect()->table('conversation_participants')->where('conversation_id', $chat)->where('user_id', $user->id)->get()->getRowArray();
    }

    public function testPinSetsPinnedAtForTheCallerOnly(): void
    {
        [$student, $instructor] = $this->pair();
        $chat                   = $this->direct($student, $instructor);

        $this->assertSame(['ok' => true], $this->service->pin($student, $chat));
        $this->assertNotNull($this->row($chat, $student)['pinned_at']);
        $this->assertNull($this->row($chat, $instructor)['pinned_at']);
        $this->assertSame(['ok' => true], $this->service->pin($student, $chat));
    }

    public function testAChatThatIsNotTheCallersIsNotFound(): void
    {
        [$student, $instructor] = $this->pair();
        $chat                   = $this->direct($student, $instructor);
        $outsider               = $this->person('student');
        $expected               = ['ok' => false, 'reason' => 'not_found'];

        foreach ([$chat, 999999] as $id) {
            $this->assertSame($expected, $this->service->pin($outsider, $id));
            $this->assertSame($expected, $this->service->unpin($outsider, $id));
            $this->assertSame($expected, $this->service->setMuted($outsider, $id, true));
            $this->assertSame($expected, $this->service->setArchived($outsider, $id, true));
            $this->assertSame($expected, $this->service->markUnread($outsider, $id));
            $this->assertSame($expected, $this->service->deleteForMe($outsider, $id));
        }

        $this->assertNull($this->row($chat, $student)['pinned_at']);
    }

    public function testACourseChatCannotBePinnedOrUnpinnedOrDeleted(): void
    {
        [$student, , $offering] = $this->pair();
        $chat                   = (new CourseChatSync())->syncOffering($offering)['conversation_id'];
        $expected               = ['ok' => false, 'reason' => 'not_allowed'];

        $this->assertSame($expected, $this->service->pin($student, $chat));
        $this->assertSame($expected, $this->service->unpin($student, $chat));
        $this->assertSame($expected, $this->service->deleteForMe($student, $chat));
        $this->assertNull($this->row($chat, $student)['pinned_at']);
    }

    public function testASixthPinIsRefusedWithLimit(): void
    {
        $student = $this->person('student');
        $chats   = $this->fiveChatsPlusOne($student);

        foreach (array_slice($chats, 0, ChatStateService::MAX_PINNED) as $chat) {
            $this->assertSame(['ok' => true], $this->service->pin($student, $chat));
        }

        $this->assertSame(['ok' => false, 'reason' => 'limit'], $this->service->pin($student, $chats[5]));
        $this->assertNull($this->row($chats[5], $student)['pinned_at']);
    }

    public function testUnpinningFreesASlot(): void
    {
        $student = $this->person('student');
        $chats   = $this->fiveChatsPlusOne($student);

        foreach (array_slice($chats, 0, ChatStateService::MAX_PINNED) as $chat) {
            $this->service->pin($student, $chat);
        }

        $this->assertSame(['ok' => true], $this->service->unpin($student, $chats[0]));
        $this->assertNull($this->row($chats[0], $student)['pinned_at']);
        $this->assertSame(['ok' => true], $this->service->pin($student, $chats[5]));
    }

    public function testMuteAndUnmute(): void
    {
        [$student, $instructor] = $this->pair();
        $chat                   = $this->direct($student, $instructor);

        $this->assertSame(['ok' => true], $this->service->setMuted($student, $chat, true));
        $this->assertSame(1, (int) $this->row($chat, $student)['is_muted']);
        $this->assertSame(0, (int) $this->row($chat, $instructor)['is_muted']);

        $this->service->setMuted($student, $chat, false);
        $this->assertSame(0, (int) $this->row($chat, $student)['is_muted']);
    }

    public function testArchiveAndUnarchive(): void
    {
        [$student, $instructor] = $this->pair();
        $chat                   = $this->direct($student, $instructor);

        $this->assertSame(['ok' => true], $this->service->setArchived($student, $chat, true));
        $this->assertNotNull($this->row($chat, $student)['archived_at']);
        $this->assertNull($this->row($chat, $instructor)['archived_at']);

        $this->service->setArchived($student, $chat, false);
        $this->assertNull($this->row($chat, $student)['archived_at']);
    }

    public function testMarkUnreadSetsTheFlag(): void
    {
        [$student, $instructor] = $this->pair();
        $chat                   = $this->direct($student, $instructor);

        $this->assertSame(['ok' => true], $this->service->markUnread($student, $chat));
        $this->assertSame(1, (int) $this->row($chat, $student)['marked_unread']);
        $this->assertSame(0, (int) $this->row($chat, $instructor)['marked_unread']);
    }

    public function testDeleteForMeRecordsTheLastMessageAndClearsPinAndArchive(): void
    {
        [$student, $instructor] = $this->pair();
        $chat                   = $this->direct($student, $instructor);
        $db                     = db_connect();
        $db->table('messages')->insert(['conversation_id' => $chat, 'sender_id' => $instructor->id, 'body_enc' => 'x', 'client_uuid' => bin2hex(random_bytes(18)), 'created_at' => date('Y-m-d H:i:s')]);
        $last = (int) $db->insertID();
        $db->table('conversations')->where('id', $chat)->update(['last_message_id' => $last]);
        $this->service->pin($student, $chat);
        $this->service->setArchived($student, $chat, true);
        $this->service->pin($instructor, $chat);

        $this->assertSame(['ok' => true], $this->service->deleteForMe($student, $chat));

        $mine = $this->row($chat, $student);
        $this->assertSame($last, (int) $mine['cleared_before_message_id']);
        $this->assertNull($mine['pinned_at']);
        $this->assertNull($mine['archived_at']);

        $theirs = $this->row($chat, $instructor);
        $this->assertNotNull($theirs['pinned_at']);
        $this->assertSame(0, (int) $theirs['cleared_before_message_id']);
    }

    /** Six direct chats for $student, each with a different instructor. @return list<int> */
    private function fiveChatsPlusOne(User $student): array
    {
        $offering = DeanFactory::offering($this->program, $this->term, 'K' . random_int(100, 999));
        CourseFactory::enroll($offering, $student);
        $chats = [];

        for ($i = 0; $i < 6; $i++) {
            $instructor = $this->person('instructor');
            CourseFactory::assignInstructor($offering, $instructor);
            $chats[] = $this->direct($student, $instructor);
        }

        return $chats;
    }
}
