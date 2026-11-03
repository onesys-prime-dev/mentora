<?php

declare(strict_types=1);

use App\Services\Messaging\MessageRateLimiter;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Over the limit answers 429 with a friendly sentence and writes nothing; what
 * creates no row is never counted.
 *
 * @internal
 */
final class MessagesRateLimitTest extends RouteTestCase
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

    private function fillMessages(int $senderId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('messages')->insert([
                'conversation_id' => $this->conversation, 'sender_id' => $senderId, 'body_enc' => 'x',
                'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function fillThreads(int $creatorId, int $count): void
    {
        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('conversations')->insert([
                'institution_id' => $this->institution, 'type' => 'direct', 'created_by' => $creatorId, 'is_locked' => 0,
                'direct_key' => bin2hex(random_bytes(32)), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function testTheThirtyFirstMessageInAMinuteIsTooMany(): void
    {
        $this->fillMessages((int) $this->student->id, MessageRateLimiter::MESSAGES_PER_MINUTE);
        $this->signIn($this->student);

        $response = $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'one more secret-words', 'client_uuid' => $this->uuid()])->response();
        $body     = json_decode($response->getBody(), true);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('rate_limited', $body['error']);
        $this->assertNotSame('', $body['message']);
        $this->assertStringNotContainsString('secret-words', $response->getBody());
        $this->assertSame(MessageRateLimiter::MESSAGES_PER_MINUTE, db_connect()->table('messages')->countAllResults(), 'nothing was written');
    }

    public function testARetriedSendAtTheLimitReturnsTheStoredMessageNotA429(): void
    {
        $this->signIn($this->student);
        $uuid  = $this->uuid();
        $first = $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'hello', 'client_uuid' => $uuid])->response();
        [$firstStatus, $firstId] = [$first->getStatusCode(), json_decode($first->getBody(), true)['message']['id'] ?? null];
        $this->fillMessages((int) $this->student->id, MessageRateLimiter::MESSAGES_PER_MINUTE);

        // The test client reuses one response object, so each answer is read before the next request.
        $retry = $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'hello', 'client_uuid' => $uuid])->response();

        $this->assertSame(201, $firstStatus);
        $this->assertSame(201, $retry->getStatusCode());
        $this->assertSame($firstId, json_decode($retry->getBody(), true)['message']['id']);
    }

    public function testTheEleventhNewThreadIsTooManyButAnExistingOneIsStillOpened(): void
    {
        $other = PeopleFactory::person('instructor', 'Oth', 'Er', null, $this->institution);
        CourseFactory::assignInstructor((int) db_connect()->table('enrollments')->get()->getRow('offering_id'), $other);
        $this->fillThreads((int) $this->student->id, MessageRateLimiter::THREADS_PER_HOUR);
        $this->signIn($this->student);

        // The test client reuses one response object, so each answer is read before the next request.
        $new = $this->api('POST', 'conversations', ['recipient_id' => (int) $other->id])->response();
        [$newStatus, $newError] = [$new->getStatusCode(), json_decode($new->getBody(), true)['error'] ?? null];

        $existing = $this->api('POST', 'conversations', ['recipient_id' => (int) $this->instructor->id])->response();

        $this->assertSame(429, $newStatus);
        $this->assertSame('rate_limited', $newError);
        $this->assertSame(200, $existing->getStatusCode());
        $this->assertSame($this->conversation, (int) json_decode($existing->getBody(), true)['conversation']['id']);
    }
}
