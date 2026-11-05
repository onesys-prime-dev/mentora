<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
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
 * Forward: a copy of a message sent to up to five of the person's own chats.
 *
 * @internal
 */
final class MessageActionsForwardTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private int $offering;

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
        $this->offering = DeanFactory::offering($program, DeanFactory::term($institution), 'MS1');

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $institution);
        CourseFactory::enroll($this->offering, $this->student);
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';
        $this->cipher          = new MessageCipher($config);

        $this->conversation = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
        $this->message      = $this->post($this->conversation, $this->student, 'pass it on');
    }

    private function actions(): MessageActions
    {
        return new MessageActions(new MessageService($this->cipher), null, null, null, $this->cipher);
    }

    private function post(int $conversation, User $sender, string $text): int
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $conversation, 'sender_id' => $sender->id,
            'body_enc' => $this->cipher->encrypt($text, $conversation, (int) $sender->id),
            'client_uuid' => bin2hex(random_bytes(16)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /** A new direct chat between the student and a fresh instructor of the same course. */
    private function otherChat(string $last): int
    {
        $other = PeopleFactory::person('instructor', 'Other', $last, null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $other);

        return (int) (new ConversationService())->startDirect($this->student, (int) $other->id)['id'];
    }

    /** @return list<int> */
    private function chats(int $count): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->otherChat('Ins' . $i);
        }

        return $out;
    }

    public function testCopiesArriveInEachTargetMarkedForwardedWithTheTextIntact(): void
    {
        [$a, $b] = $this->chats(2);

        $result = $this->actions()->forward($this->student, $this->conversation, $this->message, [$a, $b]);

        $this->assertTrue($result['ok']);
        $this->assertSame([['conversation_id' => $a, 'ok' => true], ['conversation_id' => $b, 'ok' => true]], $result['results']);

        $service = new MessageService($this->cipher);

        foreach ([$a, $b] as $chat) {
            $messages = $service->page($this->student, $chat)['messages'];

            $this->assertCount(1, $messages);
            $this->assertSame('pass it on', $messages[0]['body']);
            $this->assertTrue($messages[0]['forwarded']);
            $this->assertTrue($messages[0]['mine']);
        }
    }

    public function testForwardingIntoAChatWithItsOwnSwitchOffStillWorks(): void
    {
        $target = $this->otherChat('Off');
        db_connect()->table('conversations')->where('id', $target)->update(['allow_forwarding' => 0]);

        $this->assertTrue($this->actions()->forward($this->student, $this->conversation, $this->message, [$target])['results'][0]['ok']);
    }

    public function testFromAChatWithForwardingOffItIsRefused(): void
    {
        $target = $this->otherChat('T');
        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_forwarding' => 0]);

        $this->assertSame(['ok' => false, 'reason' => 'switched_off'], $this->actions()->forward($this->student, $this->conversation, $this->message, [$target]));
        $this->assertSame(0, db_connect()->table('messages')->where('conversation_id', $target)->countAllResults());
    }

    public function testFromAParentThreadIsRefusedEvenForTheInstructor(): void
    {
        $target = $this->otherChat('T');
        db_connect()->table('conversations')->where('id', $this->conversation)->update(['subject_student_id' => $this->student->id]);

        $this->assertSame('parent_thread', $this->actions()->forward($this->instructor, $this->conversation, $this->message, [$this->conversation])['reason']);
        $this->assertSame('parent_thread', $this->actions()->forward($this->student, $this->conversation, $this->message, [$target])['reason']);
    }

    public function testSixTargetsAreTooMany(): void
    {
        $chats = $this->chats(6);

        $this->assertSame(['ok' => false, 'reason' => 'too_many'], $this->actions()->forward($this->student, $this->conversation, $this->message, $chats));
        $this->assertSame(0, db_connect()->table('messages')->whereIn('conversation_id', $chats)->countAllResults());
    }

    public function testDuplicateTargetsCountOnceAndGetOneCopy(): void
    {
        [$a] = $this->chats(1);

        $result = $this->actions()->forward($this->student, $this->conversation, $this->message, [$a, $a, $a, $a, $a, $a]);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['results']);
        $this->assertSame(1, db_connect()->table('messages')->where('conversation_id', $a)->countAllResults());
    }

    public function testATargetThePersonIsNotInIsNotFoundAndNothingIsCreated(): void
    {
        $outsider = PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution);
        $theirs   = (int) (new ConversationService())->startDirect($outsider, (int) $this->instructor->id)['id'];
        [$mine]   = $this->chats(1);

        $result = $this->actions()->forward($this->student, $this->conversation, $this->message, [$theirs, $mine]);

        $this->assertSame([['conversation_id' => $theirs, 'ok' => false, 'reason' => 'not_found'], ['conversation_id' => $mine, 'ok' => true]], $result['results']);
        $this->assertSame(0, db_connect()->table('messages')->where('conversation_id', $theirs)->countAllResults());
    }

    public function testALockedTargetRefusesOnlyThatTarget(): void
    {
        [$locked, $open] = $this->chats(2);
        db_connect()->table('conversations')->where('id', $locked)->update(['is_locked' => 1]);

        $result = $this->actions()->forward($this->student, $this->conversation, $this->message, [$locked, $open]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['conversation_id' => $locked, 'ok' => false, 'reason' => 'not_allowed'], $result['results'][0]);
        $this->assertTrue($result['results'][1]['ok']);
        $this->assertSame(0, db_connect()->table('messages')->where('conversation_id', $locked)->countAllResults());
        $this->assertSame(1, db_connect()->table('messages')->where('conversation_id', $open)->countAllResults());
    }

    public function testRemovedDeletedHiddenOrForeignMessagesAreNotFound(): void
    {
        [$a]      = $this->chats(1);
        $outsider = PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution);

        $this->assertSame('not_found', $this->actions()->forward($this->student, $this->conversation, 999999, [$a])['reason']);
        $this->assertSame('not_found', $this->actions()->forward($outsider, $this->conversation, $this->message, [$a])['reason']);

        db_connect()->table('message_hidden')->insert(['message_id' => $this->message, 'user_id' => $this->student->id]);
        $this->assertSame('not_found', $this->actions()->forward($this->student, $this->conversation, $this->message, [$a])['reason']);
        db_connect()->table('message_hidden')->truncate();

        $this->actions()->deleteForEveryone($this->student, $this->conversation, $this->message);
        $this->assertSame('not_found', $this->actions()->forward($this->student, $this->conversation, $this->message, [$a])['reason']);

        $removed = $this->post($this->conversation, $this->student, 'gone');
        db_connect()->table('messages')->where('id', $removed)->update(['removed_at' => date('Y-m-d H:i:s')]);
        $this->assertSame('not_found', $this->actions()->forward($this->student, $this->conversation, $removed, [$a])['reason']);
        $this->assertSame(0, db_connect()->table('messages')->where('conversation_id', $a)->countAllResults());
    }

    public function testForwardingIntoAParentThreadIsRefusedForThatTargetOnly(): void
    {
        [$parent, $open] = $this->chats(2);
        db_connect()->table('conversations')->where('id', $parent)->update(['subject_student_id' => $this->student->id]);

        $result = $this->actions()->forward($this->student, $this->conversation, $this->message, [$parent, $open]);

        $this->assertSame([['conversation_id' => $parent, 'ok' => false, 'reason' => 'parent_thread'], ['conversation_id' => $open, 'ok' => true]], $result['results']);
        $this->assertSame(0, db_connect()->table('messages')->where('conversation_id', $parent)->countAllResults());
        $this->assertSame(1, db_connect()->table('messages')->where('conversation_id', $open)->countAllResults());
    }

    public function testABlockedTargetIsRefusedForThatTargetOnly(): void
    {
        // A teacher's block never binds the student, so the blocker is a classmate.
        $other = PeopleFactory::person('student', 'Other', 'Blocker', null, $this->institution);
        CourseFactory::enroll($this->offering, $other);
        $blocked =(int) (new ConversationService())->startDirect($this->student, (int) $other->id)['id'];
        [$open]  = $this->chats(1);
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $other->id, 'blocked_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $result = $this->actions()->forward($this->student, $this->conversation, $this->message, [$blocked, $open]);

        $this->assertSame(['conversation_id' => $blocked, 'ok' => false, 'reason' => 'not_allowed'], $result['results'][0]);
        $this->assertTrue($result['results'][1]['ok']);
        $this->assertSame(0, db_connect()->table('messages')->where('conversation_id', $blocked)->countAllResults());
    }

    public function testCanForwardFollowsTheSwitchAndParentThread(): void
    {
        $service = new MessageService($this->cipher);

        $this->assertTrue($service->page($this->student, $this->conversation)['messages'][0]['can_forward']);

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_forwarding' => 0]);
        $this->assertFalse((new MessageService($this->cipher))->page($this->student, $this->conversation)['messages'][0]['can_forward']);

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_forwarding' => 1, 'subject_student_id' => $this->student->id]);
        $this->assertFalse((new MessageService($this->cipher))->page($this->student, $this->conversation)['messages'][0]['can_forward']);
    }
}
