<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The main risk of Messages is reaching a conversation or message by guessing
 * its id. Whoever it belongs to, whichever institution it is in, however it
 * ended, the answer must be the one a nonexistent id gets.
 *
 * @internal
 */
final class MessagesIdorTest extends RouteTestCase
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

    /** @return array<string, array{int, string}> status and body of every endpoint, for one conversation and message id */
    private function sweep(int $conversationId, int $messageId): array
    {
        $out = [];

        foreach ([
            'page'  => ['GET', "conversations/{$conversationId}/messages", []],
            'send'  => ['POST', "conversations/{$conversationId}/messages", ['body' => 'probe', 'client_uuid' => $this->uuid()]],
            'read'  => ['POST', "conversations/{$conversationId}/read", ['message_id' => $messageId]],
            'hide'  => ['POST', "conversations/{$conversationId}/messages/{$messageId}/hide", []],
        ] as $name => [$verb, $uri, $data]) {
            $response    = $this->api($verb, $uri, $data)->response();
            $out[$name] = [$response->getStatusCode(), $response->getBody()];
        }

        return $out;
    }

    public function testAForeignOrDeadConversationAnswersLikeANonexistentOne(): void
    {
        $db = db_connect();

        // B's thread: two other people in the same institution.
        $otherStudent    = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        $otherInstructor = PeopleFactory::person('instructor', 'Oth', 'Teach', null, $this->institution);
        $offering        = DeanFactory::offering($this->program, $this->term, 'RT2');
        CourseFactory::enroll($offering, $otherStudent);
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $otherInstructor);
        $theirs = (int) (new ConversationService())->startDirect($otherStudent, (int) $otherInstructor->id)['id'];
        $theirMessage = (int) (new MessageService())->send($otherStudent, $theirs, 'private words', $this->uuid())['message']['id'];

        // Another institution's thread.
        $farInstitution = PeopleFactory::institution('FARX');
        $farTerm        = DeanFactory::term($farInstitution);
        $farDept        = DeanFactory::department('FX', $farInstitution);
        $farProgram     = DeanFactory::program(DeanFactory::subDepartment($farDept['department'], 'FX'), 'FX');
        $farStudent     = PeopleFactory::person('student', 'Far', 'S', null, $farInstitution);
        $farInstructor  = PeopleFactory::person('instructor', 'Far', 'I', null, $farInstitution);
        $farOffering    = DeanFactory::offering($farProgram, $farTerm, 'FX1');
        CourseFactory::enroll($farOffering, $farStudent);
        CourseFactory::assignInstructor($farOffering, $farInstructor);
        $far = (int) (new ConversationService())->startDirect($farStudent, (int) $farInstructor->id)['id'];
        $farMessage = (int) (new MessageService())->send($farStudent, $far, 'far words', $this->uuid())['message']['id'];

        // A thread the caller was in and left.
        $left = (int) (new ConversationService())->startDirect($this->student, (int) $otherInstructor->id)['id'] ?? 0;
        $db->table('conversation_participants')->where(['conversation_id' => $left, 'user_id' => $this->student->id])->update(['left_at' => date('Y-m-d H:i:s')]);

        $this->signIn($this->student);
        $nonexistent = $this->sweep(999999, 999999);

        foreach (['someone else\'s' => [$theirs, $theirMessage], 'another institution\'s' => [$far, $farMessage], 'one left' => [$left, $theirMessage]] as $label => [$conversationId, $messageId]) {
            $this->assertSame($nonexistent, $this->sweep($conversationId, $messageId), "{$label} conversation");
        }

        $this->assertSame(0, $db->table('messages')->where('conversation_id', $left)->countAllResults());
        $this->assertSame(1, $db->table('messages')->where('conversation_id', $theirs)->countAllResults(), 'the probe sends wrote nothing');
    }

    public function testAMessageFromAnotherConversationCannotBeReadOrHiddenThroughOwnConversation(): void
    {
        $other = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        CourseFactory::enroll((int) db_connect()->table('enrollments')->get()->getRow('offering_id'), $other);
        $theirs  = (int) (new ConversationService())->startDirect($other, (int) $this->instructor->id)['id'];
        $message = (int) (new MessageService())->send($other, $theirs, 'not for you', $this->uuid())['message']['id'];

        $this->signIn($this->student);
        $foreign     = $this->sweep($this->conversation, $message);
        $nonexistent = $this->sweep($this->conversation, 999999);

        $this->assertSame($nonexistent['read'], $foreign['read']);
        $this->assertSame($nonexistent['hide'], $foreign['hide']);
        $this->assertSame(404, $foreign['hide'][0]);
        $this->assertSame(0, db_connect()->table('message_hidden')->countAllResults());
    }

    public function testNoResponseCarriesCiphertextAndAnHtmlMessageIsPlainData(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        (new MessageService())->send($this->student, $this->conversation, $payload, $this->uuid());
        $stored = db_connect()->table('messages')->get()->getRow('body_enc');

        $this->signIn($this->instructor);
        // Read each body at once: the test client reuses one response object.
        $response = $this->api('GET', "conversations/{$this->conversation}/messages")->response();
        $page     = $response->getBody();
        $type     = $response->getHeaderLine('Content-Type');
        $list     = $this->api('GET', 'conversations')->response()->getBody();

        $this->assertStringContainsString('application/json', $type);
        $this->assertSame($payload, json_decode($page, true)['messages'][0]['body']);
        $this->assertStringNotContainsString($stored, $page . $list);
        // From the Messages screen on, a list row carries a preview of the last message, as plain data for a participant.
        $this->assertSame($payload, json_decode($list, true)['conversations'][0]['preview']);
    }
}
