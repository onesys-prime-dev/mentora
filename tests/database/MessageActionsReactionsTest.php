<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageActions;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\RateLimitedException;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Reacting to a message: the palette, replace and remove, counts, the switch, the limits and the author's notice.
 *
 * @internal
 */
final class MessageActionsReactionsTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private int $offering;

    private User $student;

    private User $instructor;

    private int $conversation;

    private int $message;

    protected function setUp(): void
    {
        parent::setUp();

        $d                 = DeanFactory::college('S1');
        $this->institution = $d['institution'];
        $program           = DeanFactory::program(DeanFactory::department($d['college'], 'SA'), 'SA');
        $term              = DeanFactory::term($this->institution);

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);
        $this->offering   = DeanFactory::offering($program, $term, 'MS1');
        CourseFactory::enroll($this->offering, $this->student);
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $this->conversation = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
        $this->message      = $this->post($this->conversation, $this->instructor);
    }

    private function actions(): MessageActions
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';
        $cipher                = new MessageCipher($config);

        return new MessageActions(new MessageService($cipher), null, null, null, $cipher);
    }

    private function post(int $conversation, User $sender): int
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $conversation, 'sender_id' => $sender->id, 'body_enc' => 'x',
            'client_uuid' => bin2hex(random_bytes(16)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    private function emojiOf(User $user, ?int $message = null): ?string
    {
        $row = db_connect()->table('message_reactions')->where(['message_id' => $message ?? $this->message, 'user_id' => $user->id])->get()->getRowArray();

        return $row === null ? null : $row['emoji'];
    }

    public function testThePaletteHasTwentyFourWithTheQuickRowFirst(): void
    {
        $this->assertCount(24, MessageActions::PALETTE);
        $this->assertSame(MessageActions::QUICK, array_slice(MessageActions::PALETTE, 0, 5));
    }

    public function testAnEmojiOutsideThePaletteIsInvalid(): void
    {
        $result = $this->actions()->react($this->student, $this->conversation, $this->message, '🦄');

        $this->assertSame(['ok' => false, 'reason' => 'invalid'], $result);
        $this->assertSame(0, db_connect()->table('message_reactions')->countAllResults());
    }

    public function testReactingCountsAndMarksMine(): void
    {
        $this->actions()->react($this->instructor, $this->conversation, $this->message, '👍');
        $result = $this->actions()->react($this->student, $this->conversation, $this->message, '👍');

        $this->assertTrue($result['ok']);
        $this->assertSame([['emoji' => '👍', 'count' => 2, 'mine' => true]], $result['reactions']);
        $this->assertNotNull(db_connect()->table('messages')->where('id', $this->message)->get()->getRow('changed_at'));
    }

    public function testADifferentEmojiReplacesAndMovesCreatedAt(): void
    {
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        $db = db_connect();
        $db->table('message_reactions')->update(['created_at' => '2020-01-01 00:00:00']);

        $result = $this->actions()->react($this->student, $this->conversation, $this->message, '🔥');

        $this->assertSame([['emoji' => '🔥', 'count' => 1, 'mine' => true]], $result['reactions']);
        $this->assertSame(1, $db->table('message_reactions')->countAllResults());
        $this->assertNotSame('2020-01-01 00:00:00', $db->table('message_reactions')->get()->getRow('created_at'));
    }

    public function testNullAndTheSameEmojiBothRemove(): void
    {
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        $this->assertSame([], $this->actions()->react($this->student, $this->conversation, $this->message, null)['reactions']);

        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        $this->assertSame([], $this->actions()->react($this->student, $this->conversation, $this->message, '👍')['reactions']);
        $this->assertNull($this->emojiOf($this->student));
    }

    public function testDifferentNonBmpEmojiFromTwoPeopleStaySeparate(): void
    {
        $this->actions()->react($this->instructor, $this->conversation, $this->message, '👍');
        $result = $this->actions()->react($this->student, $this->conversation, $this->message, '🔥');

        $this->assertSame([['emoji' => '👍', 'count' => 1, 'mine' => false], ['emoji' => '🔥', 'count' => 1, 'mine' => true]], $result['reactions']);
    }

    public function testPressingTheSameEmojiTwiceNeverDoubleCounts(): void
    {
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        $this->assertSame(0, db_connect()->table('message_reactions')->countAllResults());

        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        $this->assertSame(1, db_connect()->table('message_reactions')->countAllResults());
    }

    public function testReactorsListsNamesPerEmoji(): void
    {
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');

        $this->assertSame(['👍' => ['Stu Dent']], $this->actions()->reactors($this->instructor, $this->conversation, $this->message)['reactors']);
    }

    public function testAVisibleReactorShowsTheirPreferredNameAndAvatar(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->student->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');

        $who = $this->actions()->reactors($this->instructor, $this->conversation, $this->message);

        $this->assertSame(['👍' => ['Jun Dela Cruz']], $who['reactors']);
        $this->assertSame((int) $this->student->id, $who['avatars']['👍'][0]['id']);
        $this->assertTrue($who['avatars']['👍'][0]['interactive']);
    }

    public function testAHiddenReactorKeepsTheLegalNameAndAnInertAvatar(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->student->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        // The student drops out of every institution: the instructor may no longer see their profile.
        db_connect()->table('institution_users')->where('user_id', $this->student->id)->delete();

        $who = $this->actions()->reactors($this->instructor, $this->conversation, $this->message);

        $this->assertSame(['👍' => ['Juan Dela Cruz']], $who['reactors']);
        $this->assertFalse($who['avatars']['👍'][0]['interactive']);
        $this->assertNull($who['avatars']['👍'][0]['photoUrl']);
        $this->assertStringNotContainsString('Jun ', (string) json_encode($who));
    }

    public function testReactorsAreLookedUpOnce(): void
    {
        $classmate = PeopleFactory::person('student', 'Cla', 'Ssmate', null, $this->institution);
        CourseFactory::enroll($this->offering, $classmate);
        $chat = (int) (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        $post = $this->post($chat, $this->instructor);
        $this->actions()->react($this->student, $chat, $post, '👍');
        $this->actions()->react($classmate, $chat, $post, '😂');

        $n = 0;
        $l = static function (Query $q) use (&$n): void {
            $n += str_contains($q->getQuery(), 'preferred_first_name') ? 1 : 0;
        };
        Events::on('DBQuery', $l);
        $who = $this->actions()->reactors($this->instructor, $chat, $post);
        Events::removeListener('DBQuery', $l);

        $this->assertCount(2, $who['reactors']);
        $this->assertSame(1, $n);
    }

    public function testRefusedWhenTheSwitchIsOff(): void
    {
        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_reactions' => 0]);

        $this->assertSame('switched_off', $this->actions()->react($this->student, $this->conversation, $this->message, '👍')['reason']);
    }

    public function testRefusedWhenBlockedOrTheOfferingIsClosed(): void
    {
        // A teacher's block never binds the student, so the blocker here is a classmate.
        $peer = PeopleFactory::person('student', 'Pe', 'Er', null, $this->institution);
        CourseFactory::enroll($this->offering, $peer);
        $direct = (int) (new ConversationService())->startDirect($this->student, (int) $peer->id)['id'];
        $theirs = $this->post($direct, $peer);
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $peer->id, 'blocked_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertSame('not_allowed', $this->actions()->react($this->student, $direct, $theirs, '👍')['reason']);
        // emptyTable, not truncate: TRUNCATE commits, and the test transaction could no longer roll the rest back.
        db_connect()->table('user_blocks')->emptyTable();

        $chat = (int) (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        $msg  = $this->post($chat, $this->instructor);
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'completed']);

        $this->assertSame('not_allowed', $this->actions()->react($this->student, $chat, $msg, '👍')['reason']);
    }

    public function testAllowedInALockedCourseChat(): void
    {
        $chat = (int) (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        $msg  = $this->post($chat, $this->instructor);
        db_connect()->table('conversations')->where('id', $chat)->update(['is_locked' => 1]);

        $this->assertTrue($this->actions()->react($this->student, $chat, $msg, '👍')['ok']);
    }

    public function testAForeignOrMismatchedMessageIsNotFound(): void
    {
        $outsider = PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution);
        $peer     = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        CourseFactory::enroll($this->offering, $peer);
        $other    = (int) (new ConversationService())->startDirect($this->instructor, (int) $peer->id)['id'];
        $foreign  = $this->post($other, $this->instructor);

        $this->assertSame('not_found', $this->actions()->react($outsider, $this->conversation, $this->message, '👍')['reason']);
        $this->assertSame('not_found', $this->actions()->react($this->student, $this->conversation, $foreign, '👍')['reason']);
        $this->assertSame('not_found', $this->actions()->react($this->student, $this->conversation, 999999, '👍')['reason']);
        $this->assertNull($this->actions()->reactors($outsider, $this->conversation, $this->message));
    }

    public function testTheSixtyFirstReactionInAMinuteIsRateLimited(): void
    {
        $db = db_connect();

        for ($i = 0; $i < 60; $i++) {
            $db->table('message_reactions')->insert(['message_id' => $this->post($this->conversation, $this->instructor), 'user_id' => $this->student->id, 'emoji' => '👍', 'created_at' => date('Y-m-d H:i:s')]);
        }

        $this->expectException(RateLimitedException::class);
        $this->actions()->react($this->student, $this->conversation, $this->message, '🔥');
    }

    public function testTheAuthorIsToldOncePerMessageWithoutTheText(): void
    {
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');
        $this->actions()->react($this->student, $this->conversation, $this->message, '🔥');

        $rows = db_connect()->table('notifications')->where(['user_id' => $this->instructor->id, 'dedupe_key' => 'react:' . $this->message])->get()->getResultArray();

        $this->assertCount(1, $rows);
        $this->assertSame('Stu Dent reacted to your message.', $rows[0]['body']);
    }

    public function testNoNoticeForSelfReactionsOrAMutedChat(): void
    {
        $this->actions()->react($this->instructor, $this->conversation, $this->message, '👍');
        db_connect()->table('conversation_participants')->where(['conversation_id' => $this->conversation, 'user_id' => $this->instructor->id])->update(['is_muted' => 1]);
        $this->actions()->react($this->student, $this->conversation, $this->message, '👍');

        $this->assertSame(0, db_connect()->table('notifications')->like('dedupe_key', 'react:', 'after')->countAllResults());
    }
}
