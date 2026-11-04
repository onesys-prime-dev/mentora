<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\RouteTestCase;

/**
 * The message action routes (react, reactors) through the filters.
 *
 * @internal
 */
final class MessagesActionRoutesTest extends RouteTestCase
{
    use MessagingScenario;

    private int $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->message = (int) db_connect()->insertID();
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

    private function react(?string $emoji, ?int $message = null): TestResponse
    {
        return $this->api('POST', "conversations/{$this->conversation}/messages/" . ($message ?? $this->message) . '/react', ['emoji' => $emoji]);
    }

    public function testSignedOutVisitorsAreSentToLogin(): void
    {
        foreach ([['POST', 'react'], ['GET', 'reactors']] as [$verb, $tail]) {
            $response = $this->api($verb, "conversations/{$this->conversation}/messages/{$this->message}/{$tail}", [])->response();

            $this->assertSame(302, $response->getStatusCode(), $tail);
        }
    }

    public function testReactingReturnsTheCountsAndReactorsListsNames(): void
    {
        $this->signIn($this->student);
        $response = $this->react('👍')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['reactions' => [['emoji' => '👍', 'count' => 1, 'mine' => true]]], json_decode($response->getBody(), true));

        $list = $this->api('GET', "conversations/{$this->conversation}/messages/{$this->message}/reactors")->response();
        $this->assertSame(['reactors' => ['👍' => ['Stu Dent']]], json_decode($list->getBody(), true));
    }

    public function testAnEmojiOutsideThePaletteIsRefused(): void
    {
        $this->signIn($this->student);

        $this->assertSame(422, $this->react('🦄')->response()->getStatusCode());
    }

    public function testASwitchedOffChatIsRefusedAndAForeignMessageIs404(): void
    {
        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_reactions' => 0]);
        $this->signIn($this->student);
        $this->assertSame(403, $this->react('👍')->response()->getStatusCode());

        $this->assertSame(404, $this->react('👍', 999999)->response()->getStatusCode());

        $this->signIn(\Tests\Support\PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution));
        $this->assertSame(404, $this->react('👍')->response()->getStatusCode());
    }

    public function testTheSixtyFirstReactionInAMinuteIs429(): void
    {
        $db = db_connect();

        for ($i = 0; $i < 60; $i++) {
            $db->table('messages')->insert([
                'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
                'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->table('message_reactions')->insert(['message_id' => $db->insertID(), 'user_id' => $this->student->id, 'emoji' => '👍', 'created_at' => date('Y-m-d H:i:s')]);
        }

        $this->signIn($this->student);

        $this->assertSame(429, $this->react('🔥')->response()->getStatusCode());
    }

    public function testDeleteForEveryoneMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id): string => "conversations/{$this->conversation}/messages/{$id}/delete-everyone";

        $this->signIn($this->student);
        $this->assertSame(403, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', $uri(999999))->response()->getStatusCode());

        $this->signIn($this->instructor);
        $this->assertSame(200, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', $uri($this->message))->response()->getStatusCode());

        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s', time() - 20 * 60),
        ]);
        $old = $this->api('POST', $uri((int) db_connect()->insertID()))->response();
        $this->assertSame(403, $old->getStatusCode());
        $this->assertSame(['error' => 'too_late'], json_decode($old->getBody(), true));
    }

    public function testBumpMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id): string => "conversations/{$this->conversation}/messages/{$id}/bump";

        // The seeded body is not valid ciphertext, so a bump of it is unavailable.
        $this->signIn($this->student);
        $this->assertSame(403, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', $uri(999999))->response()->getStatusCode());

        $this->signIn($this->instructor);
        $this->assertSame(503, $this->api('POST', $uri($this->message))->response()->getStatusCode());

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_bump' => 0]);
        $this->assertSame(403, $this->api('POST', $uri($this->message))->response()->getStatusCode());
    }

    public function testPinMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id, string $verb = 'pin'): string => "conversations/{$this->conversation}/messages/{$id}/{$verb}";

        $this->signIn($this->student);
        $this->assertSame(404, $this->api('POST', $uri(999999))->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', $uri($this->message, 'unpin'))->response()->getStatusCode());

        $ids = [$this->message];

        for ($i = 0; $i < 3; $i++) {
            db_connect()->table('messages')->insert([
                'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
                'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $ids[] = (int) db_connect()->insertID();
        }

        foreach (array_slice($ids, 0, 3) as $id) {
            $this->assertSame(200, $this->api('POST', $uri($id))->response()->getStatusCode());
        }

        $limited = $this->api('POST', $uri($ids[3]))->response();
        $this->assertSame(409, $limited->getStatusCode());
        $this->assertSame(['error' => 'limit', 'max' => 3], json_decode($limited->getBody(), true));
    }

    public function testAStudentPinningInACourseChatIsForbidden(): void
    {
        $offering = (int) db_connect()->table('course_offerings')->get()->getRowArray()['id'];
        $course   = (int) (new \App\Services\Messaging\CourseChatSync())->syncOffering($offering)['conversation_id'];

        db_connect()->table('messages')->insert([
            'conversation_id' => $course, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) db_connect()->insertID();

        $this->signIn($this->student);
        $response = $this->api('POST', "conversations/{$course}/messages/{$id}/pin")->response();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'not_allowed'], json_decode($response->getBody(), true));
    }

    public function testForwardMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id): string => "conversations/{$this->conversation}/messages/{$id}/forward";

        $this->signIn($this->instructor);
        $this->assertSame(422, $this->api('POST', $uri($this->message), ['targets' => 'x'])->response()->getStatusCode());
        $this->assertSame(422, $this->api('POST', $uri($this->message), ['targets' => ['a']])->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', $uri(999999), ['targets' => [1]])->response()->getStatusCode());
        $this->assertSame(422, $this->api('POST', $uri($this->message), ['targets' => [1, 2, 3, 4, 5, 6]])->response()->getStatusCode());

        // The seeded body is not valid ciphertext, so the copy is unavailable.
        $this->assertSame(503, $this->api('POST', $uri($this->message), ['targets' => [$this->conversation]])->response()->getStatusCode());

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_forwarding' => 0]);
        $this->assertSame(403, $this->api('POST', $uri($this->message), ['targets' => [$this->conversation]])->response()->getStatusCode());

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_forwarding' => 1, 'subject_student_id' => $this->student->id]);
        $denied = $this->api('POST', $uri($this->message), ['targets' => [$this->conversation]])->response();
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(['error' => 'parent_thread'], json_decode($denied->getBody(), true));
    }

    public function testTheSystemAdministratorReachesNeitherRoute(): void
    {
        $this->signIn(CourseFactory::user('admin'));

        $this->assertSame(404, $this->react('👍')->response()->getStatusCode());
        $this->assertSame(404, $this->api('GET', "conversations/{$this->conversation}/messages/{$this->message}/reactors")->response()->getStatusCode());
    }
}
