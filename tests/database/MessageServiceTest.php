<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Sending, paging, reading and hiding messages in a direct conversation.
 *
 * @internal
 */
final class MessageServiceTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private int $program;

    private int $term;

    private User $student;

    private User $instructor;

    private int $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $d                 = DeanFactory::department('S1');
        $this->institution = $d['institution'];
        $this->program     = DeanFactory::program(DeanFactory::subDepartment($d['department'], 'SA'), 'SA');
        $this->term        = DeanFactory::term($this->institution);

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);
        $offering         = DeanFactory::offering($this->program, $this->term, 'MS1');
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $this->conversation = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
    }

    private function cipher(string $key = self::KEY): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = $key;
        $config->previousKeys  = '';

        return new MessageCipher($config);
    }

    private function service(?MessageCipher $cipher = null): MessageService
    {
        return new MessageService($cipher ?? $this->cipher());
    }

    private function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function send(User $user, string $body = 'hello'): array
    {
        return $this->service()->send($user, $this->conversation, $body, $this->uuid());
    }

    public function testASentMessageIsStoredEncryptedAndReadBack(): void
    {
        $result = $this->send($this->student, 'a secret phrase');

        $this->assertTrue($result['ok']);
        $row = db_connect()->table('messages')->get()->getRowArray();
        $this->assertStringNotContainsString('secret', $row['body_enc']);

        $page = $this->service()->page($this->instructor, $this->conversation);
        $this->assertSame('a secret phrase', $page['messages'][0]['body']);
        $this->assertFalse($page['messages'][0]['mine']);
        $this->assertTrue($this->service()->page($this->student, $this->conversation)['messages'][0]['mine']);
        $this->assertSame((int) $row['id'], (int) db_connect()->table('conversations')->get()->getRow('last_message_id'));
    }

    public function testAMessageNamesItsSenderAndTheirRole(): void
    {
        $this->send($this->student, 'hi');

        $message = $this->service()->page($this->instructor, $this->conversation)['messages'][0];

        $this->assertSame('Stu Dent', $message['sender_name']);
        $this->assertSame('student', $message['sender_role']);
    }

    public function testARetriedSendWithTheSameUuidIsOneMessage(): void
    {
        $uuid  = $this->uuid();
        $first = $this->service()->send($this->student, $this->conversation, 'once', $uuid);
        $again = $this->service()->send($this->student, $this->conversation, 'once', $uuid);

        $this->assertTrue($again['ok']);
        $this->assertSame($first['message']['id'], $again['message']['id']);
        $this->assertSame(1, db_connect()->table('messages')->countAllResults());
    }

    public function testBadBodiesAndUuidsAreInvalid(): void
    {
        foreach (['', '   ', str_repeat('a', 4001)] as $body) {
            $this->assertSame('invalid_body', $this->service()->send($this->student, $this->conversation, $body, $this->uuid())['reason']);
        }

        $this->assertSame('invalid_body', $this->service()->send($this->student, $this->conversation, 'hi', 'not a uuid!')['reason']);
        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
    }

    public function testAStrangerOrANonexistentConversationIsNotFound(): void
    {
        $stranger = PeopleFactory::person('student', 'Str', 'Anger', null, $this->institution);

        $this->assertSame('not_found', $this->service()->send($stranger, $this->conversation, 'hi', $this->uuid())['reason']);
        $this->assertSame('not_found', $this->service()->send($this->student, 999999, 'hi', $this->uuid())['reason']);
        $this->assertNull($this->service()->page($stranger, $this->conversation));
        $this->assertFalse($this->service()->markRead($stranger, $this->conversation, 1));
        $this->assertFalse($this->service()->hideForMe($stranger, $this->conversation, 1));
    }

    public function testAParticipantWhoLeftIsNotFound(): void
    {
        db_connect()->table('conversation_participants')->where(['conversation_id' => $this->conversation, 'user_id' => $this->student->id])->update(['left_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('not_found', $this->send($this->student)['reason']);
    }

    public function testABlockedSenderAndADeactivatedAccountAreNotAllowed(): void
    {
        $peer = PeopleFactory::person('student', 'Pe', 'Er', null, $this->institution);
        $off  = (int) db_connect()->table('enrollments')->get()->getRow('offering_id');
        CourseFactory::enroll($off, $peer);
        $direct = (int) (new ConversationService())->startDirect($this->student, (int) $peer->id)['id'];
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $peer->id, 'blocked_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('not_allowed', $this->service()->send($this->student, $direct, 'hi', $this->uuid())['reason']);
        $this->assertTrue($this->service()->send($peer, $direct, 'hi', $this->uuid())['ok']);

        db_connect()->table('users')->where('id', $this->instructor->id)->update(['active' => 0]);
        $this->assertSame('not_allowed', $this->send($this->instructor)['reason']);
    }

    public function testAClosedOfferingsCourseChatIsNotAllowed(): void
    {
        $offering = DeanFactory::offering($this->program, $this->term, 'MS2');
        CourseFactory::enroll($offering, $this->student);
        $db = db_connect();
        $db->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'course', 'offering_id' => $offering, 'created_at' => date('Y-m-d H:i:s')]);
        $course = (int) $db->insertID();
        $db->table('conversation_participants')->insert(['conversation_id' => $course, 'user_id' => $this->student->id, 'joined_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->service()->send($this->student, $course, 'hi', $this->uuid())['ok']);

        $db->table('course_offerings')->where('id', $offering)->update(['status' => 'completed']);
        $this->assertSame('not_allowed', $this->service()->send($this->student, $course, 'late', $this->uuid())['reason']);
    }

    public function testWithoutAKeySendingIsUnavailableAndStoresNothing(): void
    {
        $result = $this->service($this->cipher(''))->send($this->student, $this->conversation, 'hi', $this->uuid());

        $this->assertSame('unavailable', $result['reason']);
        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
    }

    public function testARemovedMessageIsATombstone(): void
    {
        $id = $this->send($this->student, 'bad words')['message']['id'];
        db_connect()->table('messages')->where('id', $id)->update(['removed_at' => date('Y-m-d H:i:s')]);

        $row = $this->service()->page($this->instructor, $this->conversation)['messages'][0];

        $this->assertTrue($row['removed']);
        $this->assertNull($row['body']);
    }

    public function testAnUnreadableMessageSaysSo(): void
    {
        $this->send($this->student, 'fine');

        $page = $this->service($this->cipher('hex2bin:6262626262626262626262626262626262626262626262626262626262626262'))->page($this->instructor, $this->conversation);

        $this->assertSame('Message unavailable', $page['messages'][0]['body']);
    }

    public function testHidingAMessageHidesItOnlyForMe(): void
    {
        $id = $this->send($this->student, 'mine to hide')['message']['id'];

        $this->assertTrue($this->service()->hideForMe($this->instructor, $this->conversation, $id));
        $this->assertTrue($this->service()->hideForMe($this->instructor, $this->conversation, $id));

        $this->assertSame([], $this->service()->page($this->instructor, $this->conversation)['messages']);
        $this->assertCount(1, $this->service()->page($this->student, $this->conversation)['messages']);
        $this->assertFalse($this->service()->hideForMe($this->instructor, $this->conversation, 999999));
    }

    public function testALateJoinerDoesNotSeeEarlierMessages(): void
    {
        $this->send($this->student, 'before');
        $second = $this->send($this->student, 'after')['message']['id'];
        db_connect()->table('conversation_participants')->where(['conversation_id' => $this->conversation, 'user_id' => $this->instructor->id])->update(['first_visible_message_id' => $second]);

        $bodies = array_column($this->service()->page($this->instructor, $this->conversation)['messages'], 'body');

        $this->assertSame(['after'], $bodies);
    }

    public function testPagingByCursor(): void
    {
        $ids = [];

        for ($i = 1; $i <= 31; $i++) {
            $ids[] = $this->send($this->student, "m{$i}")['message']['id'];
        }

        $latest = $this->service()->page($this->student, $this->conversation);
        $this->assertCount(30, $latest['messages']);
        $this->assertTrue($latest['has_more']);
        $this->assertSame($ids[1], $latest['messages'][0]['id']);
        $this->assertSame($ids[30], $latest['messages'][29]['id']);

        $older = $this->service()->page($this->student, $this->conversation, $ids[1]);
        $this->assertSame([$ids[0]], array_column($older['messages'], 'id'));
        $this->assertFalse($older['has_more']);

        $this->assertSame([], $this->service()->page($this->student, $this->conversation, null, $ids[30])['messages']);
        $this->assertSame([$ids[30]], array_column($this->service()->page($this->student, $this->conversation, null, $ids[29])['messages'], 'id'));
        $this->assertCount(2, $this->service()->page($this->student, $this->conversation, null, null, 2)['messages']);
        $this->assertCount(31, $this->service()->page($this->student, $this->conversation, null, null, 500)['messages']);
    }

    public function testMarkReadOnlyMovesForwardAndWithinTheConversation(): void
    {
        $one = $this->send($this->student, 'one')['message']['id'];
        $two = $this->send($this->student, 'two')['message']['id'];
        $lastRead = fn (): ?int => ($v = db_connect()->table('conversation_participants')->where(['conversation_id' => $this->conversation, 'user_id' => $this->instructor->id])->get()->getRow('last_read_message_id')) === null ? null : (int) $v;

        $this->assertTrue($this->service()->markRead($this->instructor, $this->conversation, $two));
        $this->assertTrue($this->service()->markRead($this->instructor, $this->conversation, $one));
        $this->assertSame($two, $lastRead());
        $this->assertFalse($this->service()->markRead($this->instructor, $this->conversation, 999999));
    }

    public function testTheNavigationBadgeCountsUnreadMessages(): void
    {
        helper(['navigation']);
        $this->send($this->student, 'ping');
        auth()->login($this->instructor);

        try {
            $this->assertSame(1, messages_unread_count());
        } finally {
            auth()->logout();
        }

        $this->assertSame(0, messages_unread_count());
    }
}
