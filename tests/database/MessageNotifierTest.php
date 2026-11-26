<?php

declare(strict_types=1);

use App\Services\Messaging\ChatStateService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageNotifier;
use App\Services\Messaging\MessagePreferences;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingContextResolver;
use App\Services\Notifications\NotificationService;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\FakeEmail;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * Notifications for new messages: no text, one per thread, held in quiet hours,
 * emailed at most hourly.
 *
 * @internal
 */
final class MessageNotifierTest extends DatabaseTestCase
{
    use ParentScenario;

    private const MARKER = 'notify-marker-8842';

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
        $this->plant($this->thread, $this->parent, self::MARKER);

        $this->assertSame(1, $this->notifier()->notifyConversation($this->thread));

        $notice = $this->notices($this->instructor)[0];

        $this->assertSame('New message', $notice['title']);
        $this->assertSame('Pat Parent sent you a message.', $notice['body']);
        $this->assertSame('messages/' . $this->thread, $notice['link']);
        $this->assertSame($this->institution, (int) $notice['institution_id']);
        $this->assertStringNotContainsString(self::MARKER, json_encode($notice));
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

    public function testAMutedParticipantIsNotNotified(): void
    {
        $state = new ChatStateService();

        $this->assertTrue($state->setMuted($this->instructor, $this->thread, true)['ok']);
        $this->plant($this->thread, $this->parent, 'while muted');

        $this->assertSame(0, $this->notifier()->notifyConversation($this->thread));
        $this->assertSame([], $this->notices($this->instructor));
        $this->assertCount(0, $this->mail->sent);

        $this->assertTrue($state->setMuted($this->instructor, $this->thread, false)['ok']);
        $this->plant($this->thread, $this->parent, 'after unmuting');

        $this->assertSame(1, $this->notifier()->notifyConversation($this->thread));
        $this->assertCount(1, $this->notices($this->instructor));
        $this->assertCount(1, $this->mail->sent);
    }

    public function testQuietHoursHoldTheNoticeAndTheMorningReleasesExactlyOne(): void
    {
        $this->assertTrue((new MessagePreferences())->save($this->instructor, '22:00', '07:00', 'everyone'));
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
        $this->plant($this->thread, $this->parent, self::MARKER);

        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(1, $this->mail->sent);
        $this->assertStringContainsString('Pat Parent sent you a message.', $this->mail->sent[0]['message']);
        $this->assertStringContainsString('messages/' . $this->thread, $this->mail->sent[0]['message']);
        $this->assertStringNotContainsString(self::MARKER, $this->mail->sent[0]['subject'] . $this->mail->sent[0]['message']);
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
        (new MessagePreferences())->save($this->instructor, null, null, 'off');
        $this->plant($this->thread, $this->parent, 'hello');
        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(1, $this->notices($this->instructor));
        $this->assertCount(0, $this->mail->sent);

        (new MessagePreferences())->save($this->instructor, null, null, 'everyone');
        $this->mail->failToSend = true;
        (new NotificationService())->markRead((int) $this->instructor->id);
        $this->plant($this->thread, $this->parent, 'again');
        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(2, $this->notices($this->instructor), 'the in-app notice survives a mail failure');
    }

    /** A student the instructor has never dealt with, and their (first-contact) direct thread to the instructor. */
    private function strangerThread(): array
    {
        $stranger = PeopleFactory::person('student', 'Sam', 'Stranger', null, $this->institution);
        $db       = db_connect();
        $now      = date('Y-m-d H:i:s');

        // Written straight to the tables: the policy refuses a student's cold message to staff, but a first contact exists all the same.
        $db->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => 'direct', 'created_by' => $stranger->id, 'is_locked' => 0, 'first_contact' => 1,
            'direct_key' => 'test-stranger-' . $stranger->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $thread = (int) $db->insertID();

        foreach ([(int) $stranger->id, (int) $this->instructor->id] as $userId) {
            $db->table('conversation_participants')->insert(['conversation_id' => $thread, 'user_id' => $userId, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        return [$stranger, $thread];
    }

    public function testEveryoneScopeDefault(): void
    {
        [$stranger, $thread] = $this->strangerThread();
        $this->assertSame('everyone', (new MessagePreferences())->get((int) $this->instructor->id)['email_scope']);

        $this->plant($thread, $stranger, 'hi');
        $this->notifier()->notifyConversation($thread);

        $this->assertCount(1, $this->mail->sent);
    }

    public function testOffScopeSendsNoEmail(): void
    {
        [$stranger, $thread] = $this->strangerThread();
        $this->assertTrue((new MessagePreferences())->save($this->instructor, null, null, 'off'));

        $this->plant($thread, $stranger, 'hi');
        $this->notifier()->notifyConversation($thread);

        $this->assertCount(0, $this->mail->sent);
    }

    public function testTalkedScopeSkipsEmailFromStranger(): void
    {
        [$stranger, $thread] = $this->strangerThread();
        $this->assertTrue((new MessagePreferences())->save($this->instructor, null, null, 'talked'));

        $this->plant($thread, $stranger, 'hi');
        $this->notifier()->notifyConversation($thread);

        $this->assertCount(0, $this->mail->sent);
        $this->assertCount(1, $this->notices($this->instructor), 'the in-app notice still comes');
    }

    public function testTalkedScopeEmailsAfterReply(): void
    {
        [$stranger, $thread] = $this->strangerThread();
        $this->assertTrue((new MessagePreferences())->save($this->instructor, null, null, 'talked'));
        $this->plant($thread, $stranger, 'hi');
        $this->notifier()->notifyConversation($thread);
        $this->assertCount(0, $this->mail->sent);

        (new MessageService($this->parentCipher()))->send($this->instructor, $thread, 'hello back', 'uuid-reply-1');
        (new NotificationService())->markRead((int) $this->instructor->id);
        $this->mail->sent = [];
        $this->plant($thread, $stranger, 'thanks');
        $this->notifier()->notifyConversation($thread, new DateTimeImmutable('+2 hours'));

        $this->assertCount(1, $this->mail->sent);
    }

    public function testTalkedScopeEmailsSomeoneTheyShareACourseWith(): void
    {
        (new MessagePreferences())->save($this->instructor, null, null, 'talked');
        $this->plant($this->thread, $this->parent, 'hi');
        $this->notifier()->notifyConversation($this->thread);

        $this->assertCount(1, $this->mail->sent, 'the parent is linked to the instructor through the child');
    }

    public function testTalkedScopeDoesNotApplyToCourseChats(): void
    {
        $chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        (new MessagePreferences())->save($this->child, null, null, 'talked');
        $this->plant($chat, $this->instructor, 'hi');

        $calls    = new ArrayObject();
        $resolver = new class ($calls) extends MessagingContextResolver {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function talkedBefore(int $a, int $b): bool
            {
                $this->calls[] = [$a, $b];

                return parent::talkedBefore($a, $b);
            }
        };
        (new MessageNotifier(null, null, $resolver))->notifyConversation($chat);

        $this->assertCount(0, $calls, 'course chats are unchanged (D18): no talked-before lookup');
    }

    public function testInAppNoticeAlwaysSent(): void
    {
        [$stranger, $thread] = $this->strangerThread();

        foreach (['off', 'talked', 'everyone'] as $scope) {
            (new MessagePreferences())->save($this->instructor, null, null, $scope);
            db_connect()->table('notifications')->where('user_id', $this->instructor->id)->delete();
            db_connect()->table('conversation_participants')->where(['conversation_id' => $thread, 'user_id' => $this->instructor->id])->update(['last_notified_message_id' => null]);
            $this->plant($thread, $stranger, 'hi ' . $scope);

            $this->assertSame(1, $this->notifier()->notifyConversation($thread), $scope);
            $this->assertCount(1, $this->notices($this->instructor), $scope);
        }
    }

    public function testASendTellsASmallChatAtOnce(): void
    {
        (new MessageService($this->parentCipher()))->send($this->parent, $this->thread, self::MARKER, 'uuid-parent-1');

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

    public function testTheBellAndTheEmailNameAVisibleSenderByTheirPreferredName(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->parent->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $this->plant($this->thread, $this->parent, 'hello');

        $this->notifier()->notifyConversation($this->thread);

        $this->assertSame('Jun Dela Cruz sent you a message.', $this->notices($this->instructor)[0]['body']);
        $this->assertStringContainsString('Jun Dela Cruz sent you a message.', $this->mail->sent[0]['message']);
    }

    public function testAHiddenSenderIsNamedByTheLegalNameOnly(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->parent->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $this->plant($this->thread, $this->parent, 'hello');
        // The parent drops out of every institution: the instructor may no longer see their profile.
        db_connect()->table('institution_users')->where('user_id', $this->parent->id)->delete();

        $this->notifier()->notifyConversation($this->thread);

        $this->assertSame('Juan Dela Cruz sent you a message.', $this->notices($this->instructor)[0]['body']);
        $this->assertStringNotContainsString('Jun Dela', $this->mail->sent[0]['message']);
    }

    public function testAReactionNoticeNamesAVisibleReactorByTheirPreferredName(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->parent->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $message = $this->plant($this->thread, $this->instructor, 'hello');

        $this->notifier()->notifyReaction($message, (int) $this->parent->id);

        $this->assertSame('Jun Dela Cruz reacted to your message.', $this->notices($this->instructor)[0]['body']);
    }

    public function testNamingTheSenderCostsTheSameForTwoOrTenMembers(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->instructor->id)->update(['preferred_first_name' => 'Jun']);
        $cost = function (int $students): int {
            $offering = DeanFactory::offering($this->program, $this->term, 'NQ' . $students);
            CourseFactory::assignInstructor($offering, $this->instructor);
            for ($i = 0; $i < $students; $i++) {
                CourseFactory::enroll($offering, PeopleFactory::person('student', 'Mem' . $i, 'Ber', null, $this->institution));
            }
            $chat = (int) (new CourseChatSync())->syncOffering($offering)['conversation_id'];
            $this->plant($chat, $this->instructor, 'hello');

            // Name and visibility lookups only: the per-member bookkeeping is not what this guards.
            $n = 0;
            $l = static function (Query $q) use (&$n): void {
                $sql = $q->getQuery();
                $n += preg_match('/user_profiles|auth_groups_users|parent_student_links|`users`.*`deleted_at`/', $sql) === 1 && ! str_contains($sql, 'INSERT') ? 1 : 0;
            };
            Events::on('DBQuery', $l);
            $this->assertSame($students, $this->notifier()->notifyConversation($chat));
            Events::removeListener('DBQuery', $l);

            return $n;
        };

        $two = $cost(2);
        $ten = $cost(10);

        $this->assertSame($two, $ten, 'one lookup per send, not per recipient');
        $this->assertStringStartsWith('Jun Tructor sent you a message', db_connect()->table('notifications')->orderBy('id', 'DESC')->get()->getRowArray()['body']);
    }
}
