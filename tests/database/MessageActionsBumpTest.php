<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageActions;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Bump: the author's own message posted again at the bottom, once per original per hour.
 *
 * @internal
 */
final class MessageActionsBumpTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private User $student;

    private User $instructor;

    private int $conversation;

    private int $message;

    private MessageCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();

        $d           = DeanFactory::department('S1');
        $institution = $this->institution = $d['institution'];
        $program     = DeanFactory::program(DeanFactory::subDepartment($d['department'], 'SA'), 'SA');
        $offering    = DeanFactory::offering($program, DeanFactory::term($institution), 'MS1');

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $institution);
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';
        $this->cipher          = new MessageCipher($config);

        $this->conversation = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
        $this->message      = $this->post($this->student, 'bump me');
    }

    private function actions(): MessageActions
    {
        return new MessageActions(new MessageService($this->cipher), null, null, null, $this->cipher);
    }

    private function post(User $sender, string $text): int
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $sender->id,
            'body_enc' => $this->cipher->encrypt($text, $this->conversation, (int) $sender->id),
            'client_uuid' => bin2hex(random_bytes(16)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    private function ageBumps(): void
    {
        db_connect()->table('messages')->where('bumped_from_message_id IS NOT NULL', null, false)->update(['created_at' => date('Y-m-d H:i:s', time() - 61 * 60)]);
    }

    public function testTheBumpIsANewMessageAtTheBottomWithTheSameText(): void
    {
        $result = $this->actions()->bump($this->student, $this->conversation, $this->message);

        $this->assertTrue($result['ok']);
        $this->assertGreaterThan($this->message, $result['message']['id']);
        $this->assertSame('bump me', $result['message']['body']);
        $this->assertTrue($result['message']['bumped']);
        $this->assertSame($result['message']['id'], (int) db_connect()->table('conversations')->where('id', $this->conversation)->get()->getRowArray()['last_message_id']);
        $this->assertSame($this->message, (int) db_connect()->table('messages')->where('id', $result['message']['id'])->get()->getRowArray()['bumped_from_message_id']);
    }

    public function testSomeoneElsesMessageIsNotAllowed(): void
    {
        $this->assertSame(['ok' => false, 'reason' => 'not_allowed'], $this->actions()->bump($this->instructor, $this->conversation, $this->message));
    }

    public function testASecondBumpWithinAnHourIsLimitedAndAllowedAfter(): void
    {
        $this->assertTrue($this->actions()->bump($this->student, $this->conversation, $this->message)['ok']);
        $this->assertSame(['ok' => false, 'reason' => 'limit'], $this->actions()->bump($this->student, $this->conversation, $this->message));

        $this->ageBumps();

        $this->assertTrue($this->actions()->bump($this->student, $this->conversation, $this->message)['ok']);
    }

    public function testABumpOfABumpPointsAtTheOriginal(): void
    {
        $first = $this->actions()->bump($this->student, $this->conversation, $this->message);
        $this->ageBumps();

        $second = $this->actions()->bump($this->student, $this->conversation, $first['message']['id']);

        $this->assertTrue($second['ok']);
        $this->assertSame($this->message, (int) db_connect()->table('messages')->where('id', $second['message']['id'])->get()->getRowArray()['bumped_from_message_id']);
    }

    public function testBlockedOrLockedIsRefused(): void
    {
        // A teacher's block never binds the student, so the blocker here is a classmate.
        $peer = PeopleFactory::person('student', 'Pe', 'Er', null, $this->institution);
        CourseFactory::enroll((int) db_connect()->table('enrollments')->get()->getRow('offering_id'), $peer);
        $direct = (int) (new ConversationService())->startDirect($this->student, (int) $peer->id)['id'];
        db_connect()->table('messages')->insert([
            'conversation_id' => $direct, 'sender_id' => $this->student->id,
            'body_enc' => $this->cipher->encrypt('bump me', $direct, (int) $this->student->id),
            'client_uuid' => bin2hex(random_bytes(16)), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $own = (int) db_connect()->insertID();
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $peer->id, 'blocked_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertSame('not_allowed', $this->actions()->bump($this->student, $direct, $own)['reason']);
        // emptyTable, not truncate: TRUNCATE commits, and the test transaction could no longer roll the rest back.
        db_connect()->table('user_blocks')->emptyTable();

        // Only a course chat locks (the instructor still posts there); a direct chat has no lock.
        $offeringId = (int) db_connect()->table('enrollments')->get()->getRow('offering_id');
        $chat       = (int) (new CourseChatSync())->syncOffering($offeringId)['conversation_id'];
        db_connect()->table('messages')->insert([
            'conversation_id' => $chat, 'sender_id' => $this->student->id,
            'body_enc' => $this->cipher->encrypt('bump me', $chat, (int) $this->student->id),
            'client_uuid' => bin2hex(random_bytes(16)), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $inChat = (int) db_connect()->insertID();
        db_connect()->table('conversations')->where('id', $chat)->update(['is_locked' => 1]);
        $this->assertSame('not_allowed', $this->actions()->bump($this->student, $chat, $inChat)['reason']);
        $this->assertSame(1, db_connect()->table('messages')->where('conversation_id', $chat)->countAllResults());
    }

    public function testWithTheSwitchOffItIsRefused(): void
    {
        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_bump' => 0]);

        $this->assertSame(['ok' => false, 'reason' => 'switched_off'], $this->actions()->bump($this->student, $this->conversation, $this->message));
    }

    public function testADeletedMessageCannotBeBumpedAndUnknownOnesAreNotFound(): void
    {
        $outsider = PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution);

        $this->assertSame('not_found', $this->actions()->bump($this->student, $this->conversation, 999999)['reason']);
        $this->assertSame('not_found', $this->actions()->bump($outsider, $this->conversation, $this->message)['reason']);

        $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message);
        $this->assertSame('not_allowed', $this->actions()->bump($this->student, $this->conversation, $this->message)['reason']);
    }

    public function testTheOtherParticipantIsNotifiedAndTheCanBumpFlagFollowsTheRules(): void
    {
        $this->actions()->bump($this->student, $this->conversation, $this->message);

        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $this->instructor->id)->like('dedupe_key', "msg:{$this->conversation}:", 'after')->countAllResults());

        $service = new MessageService($this->cipher);
        $mine    = $service->page($this->student, $this->conversation)['messages'];
        $theirs  = $service->page($this->instructor, $this->conversation)['messages'];

        $this->assertTrue($mine[0]['can_bump']);
        $this->assertFalse($theirs[0]['can_bump']);
    }
}
