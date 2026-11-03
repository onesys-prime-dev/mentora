<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Every Messages route through the filters: who is turned away, and the
 * start, send, page, read and hide journey for the people who are not.
 *
 * @internal
 */
final class MessagesRoutesTest extends RouteTestCase
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
        // A new person starts from a clean session, as in a different browser.
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

    /** @return list<array{string, string, array<string, mixed>}> */
    private function everyRoute(): array
    {
        $c = $this->conversation;

        return [
            ['GET', 'conversations', []],
            ['POST', 'conversations', ['recipient_id' => 1]],
            ['GET', "conversations/{$c}/messages", []],
            ['POST', "conversations/{$c}/messages", ['body' => 'hi', 'client_uuid' => 'abcdefgh1']],
            ['POST', "conversations/{$c}/read", ['message_id' => 1]],
            ['POST', "conversations/{$c}/messages/1/hide", []],
            ['POST', "conversations/{$c}/messages/1/remove", []],
            ['POST', "conversations/{$c}/lock", []],
            ['POST', "conversations/{$c}/unlock", []],
            ['GET', 'unread-count', []],
        ];
    }

    public function testSignedOutVisitorsAreSentToLogin(): void
    {
        foreach ($this->everyRoute() as [$verb, $uri, $data]) {
            $response = $this->api($verb, $uri, $data)->response();

            $this->assertSame(302, $response->getStatusCode(), "{$verb} {$uri}");
            $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
        }
    }

    public function testEveryRouteIsNotFoundWhileTheFlagIsOff(): void
    {
        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();
        \App\Services\Admin\PermissionMatrix::flush();
        $this->signIn($this->student);

        foreach ($this->everyRoute() as [$verb, $uri, $data]) {
            $this->assertSame(404, $this->api($verb, $uri, $data)->response()->getStatusCode(), "{$verb} {$uri}");
        }
    }

    public function testTheSystemAdministratorReachesNoRoute(): void
    {
        $this->signIn(CourseFactory::user('admin'));

        foreach ($this->everyRoute() as [$verb, $uri, $data]) {
            $this->assertSame(404, $this->api($verb, $uri, $data)->response()->getStatusCode(), "{$verb} {$uri}");
        }
    }

    public function testAnImpersonationSessionReachesNoRouteEvenToRead(): void
    {
        $admin = CourseFactory::user('admin');
        $this->signIn($admin);
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $this->withSession();
        $_SESSION['reauth'] = ['user' => $admin->id, 'at' => time()];
        $this->withSession();
        Services::resetSingle('auth');
        $this->step('POST', "admin/users/{$this->student->id}/impersonate", ['reason' => 'Support ticket 1234']);
        Services::resetSingle('auth');

        $response = $this->step('GET', 'messages/api/conversations')->response();

        $this->assertSame((int) $this->student->id, (int) ($_SESSION['user']['id'] ?? 0), 'the view of the student must be on');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAWriteIsRefusedInAReadOnlyInstitution(): void
    {
        $db = db_connect();
        $db->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->student);

        $response = $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'hi', 'client_uuid' => $this->uuid()])->response();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $db->table('messages')->countAllResults());
        $this->assertSame(200, $this->api('GET', "conversations/{$this->conversation}/messages")->response()->getStatusCode());
    }

    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $this->signIn($this->student);
        $this->withoutCsrfToken();

        $response = $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'hi', 'client_uuid' => $this->uuid()])->response();

        $this->assertNotContains($response->getStatusCode(), [200, 201]);
        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
    }

    public function testTheInstructorLocksACourseChatAndAStudentCannot(): void
    {
        $offering = (int) db_connect()->table('enrollments')->get()->getRow('offering_id');
        $chat     = (new \App\Services\Messaging\CourseChatSync())->syncOffering($offering)['conversation_id'];

        $this->signIn($this->student);
        $this->assertSame(404, $this->api('POST', "conversations/{$chat}/lock")->response()->getStatusCode());

        $this->signIn($this->instructor);
        $response = $this->api('POST', "conversations/{$chat}/lock")->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['conversation' => ['id' => $chat, 'is_locked' => true]], json_decode($response->getBody(), true));

        $unlocked = $this->api('POST', "conversations/{$chat}/unlock")->response();
        $this->assertFalse(json_decode($unlocked->getBody(), true)['conversation']['is_locked']);
    }

    public function testStartSendPageReadAndHideEndToEnd(): void
    {
        $peer = PeopleFactory::person('student', 'Pe', 'Er', null, $this->institution);
        CourseFactory::enroll((int) db_connect()->table('enrollments')->get()->getRow('offering_id'), $peer);

        $this->signIn($this->student);
        $started = json_decode($this->api('POST', 'conversations', ['recipient_id' => $peer->id])->response()->getBody(), true);
        $id      = (int) $started['conversation']['id'];
        $uuid    = $this->uuid();

        $sent = $this->api('POST', "conversations/{$id}/messages", ['body' => 'hello there', 'client_uuid' => $uuid]);
        $this->assertSame(201, $sent->response()->getStatusCode());
        $messageId = (int) json_decode($sent->response()->getBody(), true)['message']['id'];

        $again = $this->api('POST', "conversations/{$id}/messages", ['body' => 'hello there', 'client_uuid' => $uuid]);
        $this->assertSame($messageId, (int) json_decode($again->response()->getBody(), true)['message']['id']);

        $this->signIn($peer);
        $page = json_decode($this->api('GET', "conversations/{$id}/messages")->response()->getBody(), true);
        $this->assertSame('hello there', $page['messages'][0]['body']);
        $this->assertSame(1, json_decode($this->api('GET', 'unread-count')->response()->getBody(), true)['unread']);

        $this->assertSame(200, $this->api('POST', "conversations/{$id}/read", ['message_id' => $messageId])->response()->getStatusCode());
        $this->assertSame(0, json_decode($this->api('GET', 'unread-count')->response()->getBody(), true)['unread']);

        $list = json_decode($this->api('GET', 'conversations?filter=direct')->response()->getBody(), true);
        $this->assertSame($id, (int) $list['conversations'][0]['id']);

        $this->assertSame(200, $this->api('POST', "conversations/{$id}/messages/{$messageId}/hide")->response()->getStatusCode());
        $this->assertSame([], json_decode($this->api('GET', "conversations/{$id}/messages")->response()->getBody(), true)['messages']);
    }

    public function testBadSendsAnswerTheirOwnStatus(): void
    {
        $this->signIn($this->student);

        $this->assertSame(422, $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => '   ', 'client_uuid' => $this->uuid()])->response()->getStatusCode());

        config(\Config\Messages::class)->encryptionKey = '';
        $this->assertSame(503, $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'hi', 'client_uuid' => $this->uuid()])->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
    }

    public function testRecipientsThePickerWouldNeverListAnswerLikeAnUnknownId(): void
    {
        $chair   = DeanFactory::chair((int) db_connect()->table('sub_departments')->get()->getRow('id'));
        $admin   = CourseFactory::user('admin');
        $far     = PeopleFactory::person('instructor', 'Far', 'Away', null, PeopleFactory::institution('FAR3'));
        $this->signIn($this->student);

        // Bodies are read at once: the test client reuses one response object.
        $unknown = $this->api('POST', 'conversations', ['recipient_id' => 999999])->response()->getBody();

        $this->assertSame(['error' => 'not_found'], json_decode($unknown, true));

        foreach ([(int) $this->student->id, (int) $chair->id, (int) $admin->id, (int) $far->id, 0, -5] as $recipient) {
            $response = $this->api('POST', 'conversations', ['recipient_id' => $recipient])->response();

            $this->assertSame(404, $response->getStatusCode(), "recipient {$recipient}");
            $this->assertSame($unknown, $response->getBody(), "recipient {$recipient}");
        }

        $this->assertSame(404, $this->api('POST', 'conversations', [])->response()->getStatusCode());
    }
}
