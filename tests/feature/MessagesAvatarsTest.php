<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The Messages JSON carries an `avatar` per person (the other person of a direct chat, each sender) built from
 * one AvatarData::forViewer lookup per response: interactive only for someone the viewer may see, and for
 * anyone else the legal name, no photo and no preferred name (FR2, FR3).
 *
 * @internal
 */
final class MessagesAvatarsTest extends RouteTestCase
{
    use MessagingScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        $this->releaseScenario();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function getJson(User $as, string $uri): array
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($as);
        $this->withSession();
        $this->withHeaders(['Accept' => 'application/json']);

        $response = $this->step('GET', 'messages/api/' . $uri)->response();
        $this->assertSame(200, $response->getStatusCode(), $uri);

        return json_decode((string) $response->getBody(), true);
    }

    private function say(int $conversation, int $senderId): void
    {
        db_connect()->table('messages')->insert([
            'conversation_id' => $conversation, 'sender_id' => $senderId, 'body_enc' => 'x',
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** The instructor drops out of every institution: the student may no longer open their card. */
    private function hideInstructor(): void
    {
        db_connect()->table('institution_users')->where('user_id', $this->instructor->id)->delete();
    }

    /** @return array<string, mixed> */
    private function directRow(array $list): array
    {
        foreach ($list['conversations'] as $row) {
            if ((int) $row['id'] === $this->conversation) {
                return $row;
            }
        }

        $this->fail('The direct chat is not in the list.');
    }

    public function testTheListGivesTheOtherPersonsInteractiveAvatar(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->instructor->id)->update(['preferred_first_name' => 'Zed']);

        $avatar = $this->directRow($this->getJson($this->student, 'conversations'))['avatar'];

        $this->assertSame((int) $this->instructor->id, $avatar['id']);
        $this->assertTrue($avatar['interactive']);
        $this->assertSame(['id', 'name', 'initials', 'colour', 'photoUrl', 'photoUrlLg', 'interactive'], array_keys($avatar));
    }

    public function testAHiddenPersonsAvatarIsInertWithTheLegalNameAndNoPhoto(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->instructor->id)->update(['preferred_first_name' => 'Zedekiah']);
        $this->say($this->conversation, (int) $this->instructor->id);
        $this->hideInstructor();

        $list   = $this->getJson($this->student, 'conversations');
        $avatar = $this->directRow($list)['avatar'];

        $this->assertFalse($avatar['interactive']);
        $this->assertSame('Ins Tructor', $avatar['name']);
        $this->assertSame('Ins Tructor', $this->directRow($list)['name'], 'the row title keeps the legal name');
        $this->assertSame('IT', $avatar['initials']);
        $this->assertNull($avatar['photoUrl']);
        $this->assertNull($avatar['photoUrlLg']);

        $page    = $this->getJson($this->student, "conversations/{$this->conversation}/messages");
        $message = $page['messages'][0];

        $this->assertSame('Ins Tructor', $message['sender_name'], 'the name field is unchanged');
        $this->assertFalse($message['avatar']['interactive']);
        $this->assertSame('Ins Tructor', $message['avatar']['name']);
        $this->assertNull($message['avatar']['photoUrl']);

        foreach ([$list, $page] as $json) {
            $this->assertStringNotContainsString('Zedekiah', json_encode($json), 'no preferred name of a hidden person anywhere');
        }
    }

    public function testAVisiblePersonsPreferredNameTitlesTheChatAndTheirMessages(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->instructor->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $this->say($this->conversation, (int) $this->instructor->id);

        $this->assertSame('Jun Dela Cruz', $this->directRow($this->getJson($this->student, 'conversations'))['name']);
        $this->assertSame('Jun Dela Cruz', $this->getJson($this->student, "conversations/{$this->conversation}/messages")['messages'][0]['sender_name']);
    }

    /** The instructor's pinned message and the student's reply quoting it. */
    private function pinAndQuote(): void
    {
        $this->say($this->conversation, (int) $this->instructor->id);
        $original = (int) db_connect()->insertID();
        db_connect()->table('messages')->where('id', $original)->update(['pinned_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $this->student->id, 'body_enc' => 'x', 'reply_to_message_id' => $original,
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testQuoteAndPinNamesMatchTheSenderName(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->instructor->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $this->pinAndQuote();

        $page = $this->getJson($this->student, "conversations/{$this->conversation}/messages");

        $this->assertSame('Jun Dela Cruz', $page['pinned'][0]['sender_name']);
        $this->assertSame('Jun Dela Cruz', $page['messages'][1]['reply']['sender_name']);
    }

    public function testQuoteAndPinKeepTheLegalNameOfAHiddenPerson(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->instructor->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);
        $this->pinAndQuote();
        $this->hideInstructor();

        $page = $this->getJson($this->student, "conversations/{$this->conversation}/messages");

        $this->assertSame('Juan Dela Cruz', $page['pinned'][0]['sender_name']);
        $this->assertSame('Juan Dela Cruz', $page['messages'][1]['reply']['sender_name']);
        $this->assertStringNotContainsString('Jun Dela Cruz', (string) json_encode($page));
    }

    public function testMyOwnQuotedAndPinnedMessagesShowMyPreferredName(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->student->id)->update(['first_name' => 'Maria', 'last_name' => 'Santos', 'preferred_first_name' => 'Mia']);
        $this->say($this->conversation, (int) $this->student->id);
        $original = (int) db_connect()->insertID();
        db_connect()->table('messages')->where('id', $original)->update(['pinned_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x', 'reply_to_message_id' => $original,
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $page = $this->getJson($this->student, "conversations/{$this->conversation}/messages");

        $this->assertSame('Mia Santos', $page['pinned'][0]['sender_name']);
        $this->assertSame('Mia Santos', $page['messages'][1]['reply']['sender_name']);
        $this->assertNull($page['messages'][0]['avatar'], 'my own messages still carry no avatar');
    }

    public function testEachSendersAvatarIsOnTheirMessagesAndMineHaveNone(): void
    {
        $this->say($this->conversation, (int) $this->instructor->id);
        $this->say($this->conversation, (int) $this->student->id);

        $messages = $this->getJson($this->student, "conversations/{$this->conversation}/messages")['messages'];

        $this->assertSame((int) $this->instructor->id, $messages[0]['avatar']['id']);
        $this->assertTrue($messages[0]['avatar']['interactive']);
        $this->assertNull($messages[1]['avatar']);
    }

    public function testAvatarsAreOneLookupWhateverTheNumberOfPeople(): void
    {
        $offering = (int) db_connect()->table('course_offerings')->get()->getRowArray()['id'];
        $course   = (int) (new CourseChatSync())->syncOffering($offering)['conversation_id'];
        $lookups  = function (callable $call): int {
            $n = 0;
            $l = static function (Query $q) use (&$n): void {
                $n += str_contains($q->getQuery(), 'preferred_first_name') ? 1 : 0;
            };
            Events::on('DBQuery', $l);
            $call();
            Events::removeListener('DBQuery', $l);

            return $n;
        };

        $this->say($course, (int) $this->instructor->id);
        $fewList = $lookups(fn () => $this->getJson($this->student, 'conversations'));
        $fewPage = $lookups(fn () => $this->getJson($this->student, "conversations/{$course}/messages"));

        for ($i = 1; $i <= 4; $i++) {
            $peer = PeopleFactory::person('student', 'Peer' . $i, 'Mate', null, $this->institution);
            CourseFactory::enroll($offering, $peer);
            (new CourseChatSync())->syncOffering($offering);
            $this->say($course, (int) $peer->id);
            $other = PeopleFactory::person('instructor', 'Other' . $i, 'Teacher', null, $this->institution);
            CourseFactory::assignInstructor($offering, $other);
            (new ConversationService())->startDirect($this->student, (int) $other->id);
        }

        $this->assertSame($fewList, $lookups(fn () => $this->getJson($this->student, 'conversations')), 'the list looks avatars up once');
        $this->assertSame($fewPage, $lookups(fn () => $this->getJson($this->student, "conversations/{$course}/messages")), 'a page looks avatars up once');
        $this->assertSame(1, $fewPage, 'the page does look them up');
        $this->assertGreaterThanOrEqual(1, $fewList);
    }
}
