<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageNotifier;
use App\Services\Messaging\MessagePreferences;
use App\Services\Messaging\MessageService;
use App\Services\Notifications\NotificationService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\ParentScenario;

/**
 * Notifications for new messages: no text, one per thread, held in quiet hours,
 * emailed at most hourly.
 *
 * @internal
 */
final class MessageNotifierTest extends DatabaseTestCase
{
    use ParentScenario;

    private const SECRET = 'notify-secret-8842';

    protected bool $isolateTests = true;

    private int $thread;

    private FakeEmail $mail;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        $this->mail = FakeEmail::install();
        $this->buildParentScenario('NT');
        $this->thread = (int) (new ConversationService())->startDirect($this->parent, (int) $this->instructor->id)['id'];
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** Writes a message straight into the table, so the send hook does not tell anyone. */
    private function plant(int $conversation, User $sender, string $text): int
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $conversation, 'sender_id' => $sender->id, 'body_enc' => $this->parentCipher()->encrypt($text, $conversation, (int) $sender->id),
            'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    private function notifier(): MessageNotifier
    {
        return new MessageNotifier();
    }

    /** @return list<array<string, mixed>> */
    private function notices(User $user): array
    {
        return db_connect()->table('notifications')->where(['user_id' => $user->id, 'type' => 'message'])->orderBy('id')->get()->getResultArray();
    }

    public function testADirectMessageNotifiesTheOtherPersonWithoutAnyText(): void
    {
        $this->plant($this->thread, $this->parent, self::SECRET);

        $this->assertSame(1, $this->notifier()->notifyConversation($this->thread));

        $notice = $this->notices($this->instructor)[0];

        $this->assertSame('New message', $notice['title']);
        $this->assertSame('Pat Parent sent you a message.', $notice['body']);
        $this->assertSame('messages/' . $this->thread, $notice['link']);
        $this->assertSame($this->institution, (int) $notice['institution_id']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($notice));
        $this->assertSame([], $this->notices($this->parent), 'nothing for the sender');
    }

    public function testACourseChatNamesTheCourse(): void
    {
        $chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        $this->plant($chat, $this->instructor, 'Quiz on Friday');

        $this->notifier()->notifyConversation($chat);

        $this->assertStringStartsWith('Ins Tructor sent you a message in NTC1', $this->notices($this->child)[0]['body']);
    }

    public function testManyMessagesAreOneNoticeAndAnUnreadNoticeBlocksAnother(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->plant($this->thread, $this->parent, "line {$i}");
        }

        $this->assertSame(1, $this->notifier()->notifyConversation($this->thread));

        $this->plant($this->thread, $this->parent, 'one more');
        $this->assertSame(0, $this->notifier()->notifyConversation($this->thread), 'the first notice is still unread');
        $this->assertCount(1, $this->notices($this->instructor));

        (new NotificationService())->markRead((int) $this->instructor->id);
        $this->plant($this->thread, $this->parent, 'after reading');

        $this->assertSame(1, $this->notifier()->notifyConversation($this->thread));
        $this->assertCount(2, $this->notices($this->instructor));
    }

    public function testReadingTheThreadClearsItsNoticeSoTheNextMessageNotifiesAgain(): void
    {
        $first = $this->plant($this->thread, $this->parent, 'hello');
        $this->notifier()->notifyConversation($this->thread);
        $this->assertNull($this->notices($this->instructor)[0]['read_at']);

        // They open Messages directly, not through the notification, and read the thread.
        $this->assertTrue((new MessageService($this->parentCipher()))->markRead($this->instructor, $this->thread, $first));
        $this->assertNotNull($this->notices($this->instructor)[0]['read_at']);

        $this->plant($this->thread, $this->parent, 'a new one');

        $this->assertSame(1, $this->notifier()->notifyConversation($this->thread));
        $this->assertCount(2, $this->notices($this->instructor));
    }

    public function testARetryTellsNobodyAgain(): void
    {
        $this->plant($this->thread, $this->parent, 'hello');
        $this->notifier()->notifyConversation($this->thread);

        $this->assertSame(0, $this->notifier()->notifyConversation($this->thread));
        $this->assertCount(1, $this->notices($this->instructor));
    }

    public function testMutedReadAndDeactivatedMembersAreNotTold(): void
    {
        $last = $this->plant($this->thread, $this->parent, 'hello');

        db_connect()->table('conversation_participants')->where(['conversation_id' => $this->thread, 'user_id' => $this->instructor->id])->update(['is_muted' => 1]);
        $this->assertSame(0, $this->notifier()->notifyConversation($this->thread), 'muted');

        db_connect()->table('conversation_participants')->where(['conversation_id' => $this->thread, 'user_id' => $this->instructor->id])->update(['is_muted' => 0, 'last_read_message_id' => $last]);
        $this->assertSame(0, $this->notifier()->notifyConversation($this->thread), 'already read');

        db_connect()->table('conversation_participants')->where(['conversation_id' => $this->thread, 'user_id' => $this->instructor->id])->update(['last_read_message_id' => null]);
        db_connect()->table('users')->where('id', $this->instructor->id)->update(['active' => 0]);
        $this->assertSame(0, $this->notifier()->notifyConversation($this->thread), 'deactivated');
    }

    public function testQuietHoursHoldTheNoticeAndTheMorningReleasesExactlyOne(): void
    {
        $this->assertTrue((new MessagePreferences())->save($this->instructor, '22:00', '07:00', true));
        $this->plant($this->thread, $this->parent, 'late one');
        $this->plant($this->thread, $this->parent, 'later one');

        $this->assertSame(0, $this->notifier()->notifyConversation($this->thread, new DateTimeImmutable('2026-11-03 22:30:00')));
        $this->assertSame([], $this->notices($this->instructor));
        $this->assertCount(0, $this->mail->sent);
        $this->assertNull(db_connect()->table('conversation_participants')->where(['conversation_id' => $this->thread, 'user_id' => $this->instructor->id])->get()->getRowArray()['last_notified_message_id'], 'held, not dropped');

        $this->assertSame(1, $this->notifier()->notifyConversation($this->thread, new DateTimeImmutable('2026-11-04 07:05:00')));
        $this->assertCount(1, $this->notices($this->instructor));
    }

    public function testTheEmailCarriesTheSameWordsAndLinkAndNoText(): void
    {
        $this->plant($this->thread, $this->parent, self::SECRET);

        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(1, $this->mail->sent);
        $this->assertStringContainsString('Pat Parent sent you a message.', $this->mail->sent[0]['message']);
        $this->assertStringContainsString('messages/' . $this->thread, $this->mail->sent[0]['message']);
        $this->assertStringNotContainsString(self::SECRET, $this->mail->sent[0]['subject'] . $this->mail->sent[0]['message']);
        $this->assertNotNull($this->notices($this->instructor)[0]['emailed_at']);
    }

    public function testAtMostOneEmailAnHourPerThread(): void
    {
        $this->plant($this->thread, $this->parent, 'first');
        $this->notifier()->notifyConversation($this->thread);
        (new NotificationService())->markRead((int) $this->instructor->id);
        $this->plant($this->thread, $this->parent, 'second');

        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(2, $this->notices($this->instructor));
        $this->assertCount(1, $this->mail->sent, 'the second notice is in-app only');

        $this->plant($this->thread, $this->parent, 'third');
        (new NotificationService())->markRead((int) $this->instructor->id);
        $this->notifier()->notifyConversation($this->thread, new DateTimeImmutable('+2 hours'));

        $this->assertCount(2, $this->mail->sent);
    }

    public function testNoEmailWhenTheSwitchIsOffAndAFailedEmailKeepsTheNotice(): void
    {
        (new MessagePreferences())->save($this->instructor, null, null, false);
        $this->plant($this->thread, $this->parent, 'hello');
        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(1, $this->notices($this->instructor));
        $this->assertCount(0, $this->mail->sent);

        (new MessagePreferences())->save($this->instructor, null, null, true);
        $this->mail->failToSend = true;
        (new NotificationService())->markRead((int) $this->instructor->id);
        $this->plant($this->thread, $this->parent, 'again');
        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(2, $this->notices($this->instructor), 'the in-app notice survives a mail failure');
    }

    public function testASendTellsASmallChatAtOnce(): void
    {
        (new MessageService($this->parentCipher()))->send($this->parent, $this->thread, self::SECRET, 'uuid-parent-1');

        $this->assertCount(1, $this->notices($this->instructor));
    }

    public function testASendInABigChatLeavesItToTheSweep(): void
    {
        $chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];

        for ($i = 0; $i < MessageNotifier::IMMEDIATE_MAX_MEMBERS; $i++) {
            $person = \Tests\Support\PeopleFactory::person('student', "Bulk{$i}", 'Student', null, $this->institution);
            \Tests\Support\CourseFactory::enroll($this->offering, $person);
        }

        (new CourseChatSync())->syncOffering($this->offering);
        (new MessageService($this->parentCipher()))->send($this->instructor, $chat, 'Hello class', 'uuid-teacher-1');

        $this->assertSame([], $this->notices($this->child), 'not told inside the send');
        $this->assertContains($chat, $this->notifier()->pendingConversationIds());
    }
}
