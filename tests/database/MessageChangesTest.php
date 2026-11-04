<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageActions;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The changes feed (reactions, pins and erased messages since a time) and the per-viewer flags on every message.
 *
 * @internal
 */
final class MessageChangesTest extends DatabaseTestCase
{
    private const KEY      = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';
    private const LONG_AGO = '2000-01-01 00:00:00';

    protected bool $isolateTests = true;

    private User $student;

    private User $instructor;

    private int $chat;

    private MessageCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();

        $d           = DeanFactory::department('CH1');
        $institution = $d['institution'];
        $program     = DeanFactory::program(DeanFactory::subDepartment($d['department'], 'CA'), 'CA');
        $offering    = DeanFactory::offering($program, DeanFactory::term($institution), 'MC1');

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $institution);
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';
        $this->cipher          = new MessageCipher($config);
        $this->chat            = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
    }

    private function messages(): MessageService
    {
        return new MessageService($this->cipher);
    }

    private function actions(): MessageActions
    {
        return new MessageActions($this->messages(), null, null, null, $this->cipher);
    }

    private function say(User $sender, string $text = 'hello'): int
    {
        return (int) $this->messages()->send($sender, $this->chat, $text, bin2hex(random_bytes(8)))['message']['id'];
    }

    /** @return list<int> */
    private function changedIds(User $viewer, string $since): array
    {
        return array_column($this->messages()->changedSince($viewer, $this->chat, $since)['messages'], 'id');
    }

    public function testAReactionByTheOtherPersonShowsUpAfterSinceAndNotBefore(): void
    {
        $id = $this->say($this->student);
        $this->assertSame([], $this->changedIds($this->student, self::LONG_AGO), 'a plain message is not a change');

        $this->actions()->react($this->instructor, $this->chat, $id, '👍');

        $feed = $this->messages()->changedSince($this->student, $this->chat, self::LONG_AGO);
        $this->assertSame([$id], array_column($feed['messages'], 'id'));
        $this->assertSame([['emoji' => '👍', 'count' => 1, 'mine' => false]], $feed['messages'][0]['reactions']);
        $this->assertSame([], $this->changedIds($this->student, date('Y-m-d H:i:s', time() + 3600)));
    }

    public function testAPinAndADeleteForEveryoneShowUpForTheOtherPerson(): void
    {
        $pinned = $this->say($this->instructor, 'pin me');
        $erased = $this->say($this->instructor, 'erase me');
        $this->say($this->instructor, 'untouched');

        $this->assertTrue($this->actions()->pinMessage($this->instructor, $this->chat, $pinned, true)['ok']);
        $this->assertTrue($this->actions()->deleteForEveryone($this->instructor, $this->chat, $erased)['ok']);

        $messages = $this->messages()->changedSince($this->student, $this->chat, self::LONG_AGO)['messages'];

        $this->assertSame([$pinned, $erased], array_column($messages, 'id'));
        $this->assertTrue($messages[0]['pinned']);
        $this->assertTrue($messages[1]['deleted_everyone']);
        $this->assertNull($messages[1]['body']);
        $this->assertSame([], $this->changedIds($this->student, date('Y-m-d H:i:s', time() + 3600)));
    }

    public function testHiddenAndClearedMessagesAreNotInTheFeed(): void
    {
        $hidden  = $this->say($this->instructor, 'hidden');
        $cleared = $this->say($this->instructor, 'cleared');
        $shown   = $this->say($this->instructor, 'shown');

        foreach ([$hidden, $cleared, $shown] as $id) {
            $this->actions()->react($this->student, $this->chat, $id, '❤️');
        }

        $this->messages()->hideForMe($this->student, $this->chat, $hidden);
        db_connect()->table('conversation_participants')->where(['conversation_id' => $this->chat, 'user_id' => $this->student->id])->update(['cleared_before_message_id' => $cleared]);

        $this->assertSame([$shown], $this->changedIds($this->student, self::LONG_AGO));
        $this->assertSame([$hidden, $cleared, $shown], $this->changedIds($this->instructor, self::LONG_AGO));
    }

    public function testServerTimeIsTheDatabaseClockAndABadSinceMeansNow(): void
    {
        $id = $this->say($this->instructor);
        $this->actions()->react($this->student, $this->chat, $id, '👍');

        $feed = $this->messages()->changedSince($this->instructor, $this->chat, 'yesterday-ish');

        $this->assertSame([], $feed['messages']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $feed['server_time']);
        $this->assertSame([], $this->messages()->changedSince($this->instructor, $this->chat, '')['messages']);
    }

    public function testAChatThatIsNotThePersonsIsNull(): void
    {
        $outsider = PeopleFactory::person('student', 'Out', 'Sider', null, DeanFactory::department('CH2')['institution']);

        $this->assertNull($this->messages()->changedSince($outsider, $this->chat, self::LONG_AGO));
        $this->assertNull($this->messages()->changedSince($this->student, 999999, self::LONG_AGO));
    }

    public function testEveryMessageCarriesTheViewersFlagsInPageAndChanges(): void
    {
        $id = $this->say($this->student);
        $this->actions()->react($this->instructor, $this->chat, $id, '🔥');
        $flags = ['reactions', 'pinned', 'can_react', 'can_reply', 'can_forward', 'can_pin', 'can_bump', 'can_delete_everyone'];

        foreach ([$this->messages()->page($this->student, $this->chat)['messages'][0], $this->messages()->changedSince($this->student, $this->chat, self::LONG_AGO)['messages'][0]] as $message) {
            foreach ($flags as $flag) {
                $this->assertArrayHasKey($flag, $message, $flag);
            }

            $this->assertSame([['emoji' => '🔥', 'count' => 1, 'mine' => false]], $message['reactions']);
            $this->assertFalse($message['pinned']);
            $this->assertTrue($message['can_react']);
            $this->assertTrue($message['can_reply']);
            $this->assertTrue($message['can_forward']);
            $this->assertTrue($message['can_pin']);
            $this->assertTrue($message['can_bump']);
            $this->assertTrue($message['can_delete_everyone']);
        }

        $theirs = $this->messages()->page($this->instructor, $this->chat)['messages'][0];
        $this->assertFalse($theirs['can_bump']);
        $this->assertFalse($theirs['can_delete_everyone']);
    }

    public function testTheFlagsFollowTheSwitches(): void
    {
        $id = $this->say($this->student);
        db_connect()->table('conversations')->where('id', $this->chat)->update(['allow_reactions' => 0, 'allow_replies' => 0, 'allow_forwarding' => 0, 'allow_bump' => 0, 'allow_delete_everyone' => 0]);

        $message = $this->messages()->page($this->student, $this->chat)['messages'][0];

        $this->assertSame($id, $message['id']);

        foreach (['can_react', 'can_reply', 'can_forward', 'can_bump', 'can_delete_everyone'] as $flag) {
            $this->assertFalse($message[$flag], $flag);
        }

        $this->assertTrue($message['can_pin'], 'pinning has no switch');
    }

    public function testAnErasedMessageOffersNoActions(): void
    {
        $id = $this->say($this->student);
        $this->actions()->deleteForEveryone($this->student, $this->chat, $id);

        $message = $this->messages()->page($this->instructor, $this->chat)['messages'][0];

        foreach (['can_react', 'can_reply', 'can_forward', 'can_pin', 'can_bump', 'can_delete_everyone'] as $flag) {
            $this->assertFalse($message[$flag], $flag);
        }
    }

    public function testAPageOfThirtyWithReactionsCostsNoMoreThanOneExtraQuery(): void
    {
        $ids = [];

        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->say($i % 2 === 0 ? $this->student : $this->instructor, 'm' . $i);
        }

        $count = function (): int {
            $n        = 0;
            $listener = static function () use (&$n): void {
                $n++;
            };

            Events::on('DBQuery', $listener);
            $this->messages()->page($this->student, $this->chat);
            Events::removeListener('DBQuery', $listener);

            return $n;
        };

        $without = $count();

        foreach ($ids as $id) {
            $this->actions()->react($this->instructor, $this->chat, $id, '👍');
            $this->actions()->react($this->student, $this->chat, $id, '🔥');
        }

        $this->assertLessThanOrEqual($without + 1, $count());
    }
}
