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

    public function testTheSystemAdministratorReachesNeitherRoute(): void
    {
        $this->signIn(CourseFactory::user('admin'));

        $this->assertSame(404, $this->react('👍')->response()->getStatusCode());
        $this->assertSame(404, $this->api('GET', "conversations/{$this->conversation}/messages/{$this->message}/reactors")->response()->getStatusCode());
    }
}
