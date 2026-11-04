<?php

declare(strict_types=1);

use App\Services\Messaging\ChatStateService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The per-person chat actions through the filters: pin, mute, archive, mark
 * unread and delete for me, and that none of them reaches another person's chat.
 *
 * @internal
 */
final class MessagesChatStateRoutesTest extends RouteTestCase
{
    use MessagingScenario;

    private const ACTIONS = ['pin', 'unpin', 'mute', 'unmute', 'archive', 'unarchive', 'unread', 'delete'];

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

    private function signIn(User $user): void
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @param array<string, mixed> $data */
    private function api(string $verb, string $uri, array $data = []): TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);

        if ($verb === 'POST') {
            $this->withBodyFormat('json');
        }

        return $this->step($verb, 'messages/api/' . $uri, $data);
    }

    private function act(string $action, int $conversationId): TestResponse
    {
        return $this->api('POST', "conversations/{$conversationId}/{$action}");
    }

    /** A direct chat between two other people, the student not in it. */
    private function foreignChat(): int
    {
        $a        = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        $b        = PeopleFactory::person('instructor', 'Oth', 'Teach', null, $this->institution);
        $offering = DeanFactory::offering($this->program, $this->term, 'RT2');
        CourseFactory::enroll($offering, $a);
        CourseFactory::assignInstructor($offering, $b);

        return (int) (new ConversationService())->startDirect($a, (int) $b->id)['id'];
    }

    /** @return array<string, mixed> */
    private function row(int $conversationId, User $user): array
    {
        return db_connect()->table('conversation_participants')->where(['conversation_id' => $conversationId, 'user_id' => $user->id])->get()->getRowArray();
    }

    public function testEveryActionAnswersAForeignAndAMissingChatIdentically(): void
    {
        $theirs = $this->foreignChat();
        $before = db_connect()->table('conversation_participants')->where('conversation_id', $theirs)->get()->getResultArray();
        $this->signIn($this->student);

        foreach (self::ACTIONS as $action) {
            $missing = $this->act($action, 999999)->response();
            $missing = [$missing->getStatusCode(), $missing->getBody()];
            $foreign = $this->act($action, $theirs)->response();

            $this->assertSame(404, $missing[0], $action);
            $this->assertSame($missing, [$foreign->getStatusCode(), $foreign->getBody()], $action);
        }

        $this->assertSame($before, db_connect()->table('conversation_participants')->where('conversation_id', $theirs)->get()->getResultArray());
    }

    public function testEveryActionIsNotFoundWhileTheFlagIsOff(): void
    {
        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();
        \App\Services\Admin\PermissionMatrix::flush();
        $this->signIn($this->student);

        foreach (self::ACTIONS as $action) {
            $this->assertSame(404, $this->act($action, $this->conversation)->response()->getStatusCode(), $action);
        }
    }

    public function testAnActionIsRefusedInAReadOnlyInstitution(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->student);

        $this->assertSame(403, $this->act('pin', $this->conversation)->response()->getStatusCode());
        $this->assertNull($this->row($this->conversation, $this->student)['pinned_at']);
    }

    public function testAnActionWithoutACsrfTokenIsRefused(): void
    {
        $this->signIn($this->student);
        $this->withoutCsrfToken();

        $this->assertNotSame(200, $this->act('pin', $this->conversation)->response()->getStatusCode());
        $this->assertNull($this->row($this->conversation, $this->student)['pinned_at']);
    }

    public function testAnActionChangesOnlyTheCallersRow(): void
    {
        $this->signIn($this->student);

        foreach (['pin', 'mute', 'archive'] as $action) {
            $response = $this->act($action, $this->conversation)->response();

            $this->assertSame(200, $response->getStatusCode(), $action);
            $this->assertSame(['ok' => true], json_decode($response->getBody(), true), $action);
        }

        $mine   = $this->row($this->conversation, $this->student);
        $theirs = $this->row($this->conversation, $this->instructor);

        $this->assertNotNull($mine['pinned_at']);
        $this->assertSame(1, (int) $mine['is_muted']);
        $this->assertNotNull($mine['archived_at']);
        $this->assertNull($theirs['pinned_at']);
        $this->assertSame(0, (int) $theirs['is_muted']);
        $this->assertNull($theirs['archived_at']);
    }

    public function testACourseChatCannotBePinned(): void
    {
        $offering = (int) db_connect()->table('enrollments')->get()->getRow('offering_id');
        $chat     = (int) (new CourseChatSync())->syncOffering($offering)['conversation_id'];
        $this->signIn($this->student);

        $response = $this->act('pin', $chat)->response();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'not_allowed'], json_decode($response->getBody(), true));
    }

    public function testASixthPinIsRefusedWithTheLimit(): void
    {
        $service = new ConversationService();
        $state   = new ChatStateService();

        for ($i = 0; $i < ChatStateService::MAX_PINNED; $i++) {
            $other    = PeopleFactory::person('instructor', 'Pin', 'Ner' . $i, null, $this->institution);
            $offering = DeanFactory::offering($this->program, $this->term, 'RP' . $i);
            CourseFactory::enroll($offering, $this->student);
            CourseFactory::assignInstructor($offering, $other);
            $chat = (int) $service->startDirect($this->student, (int) $other->id)['id'];
            $this->assertTrue($state->pin($this->student, $chat)['ok']);
        }

        $this->signIn($this->student);
        $response = $this->act('pin', $this->conversation)->response();

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['error' => 'limit', 'max' => 5], json_decode($response->getBody(), true));
    }

    public function testTheArchivedListHoldsTheArchivedChatAndTheDefaultListDoesNot(): void
    {
        $this->signIn($this->student);
        $this->assertSame(200, $this->act('archive', $this->conversation)->response()->getStatusCode());

        $default  = json_decode($this->api('GET', 'conversations')->response()->getBody(), true);
        $archived = json_decode($this->api('GET', 'conversations?archived=1')->response()->getBody(), true);

        $this->assertSame([], $default['conversations']);
        $this->assertSame(1, $default['archived_count']);
        $this->assertSame([$this->conversation], array_column($archived['conversations'], 'id'));
        $this->assertTrue($archived['conversations'][0]['archived']);
        $this->assertSame(1, $archived['archived_count']);
    }
}
