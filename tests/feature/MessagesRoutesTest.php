<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
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
            ['POST', 'conversations/first', ['recipient_id' => 1, 'body' => 'hi', 'client_uuid' => 'abcdefgh1']],
            ['GET', "conversations/{$c}/messages", []],
            ['POST', "conversations/{$c}/messages", ['body' => 'hi', 'client_uuid' => 'abcdefgh1']],
            ['POST', "conversations/{$c}/read", ['message_id' => 1]],
            ['POST', "conversations/{$c}/messages/1/hide", []],
            ['POST', "conversations/{$c}/messages/1/remove", []],
            ['POST', "conversations/{$c}/lock", []],
            ['POST', "conversations/{$c}/unlock", []],
            ['GET', 'group-candidates?q=Ab', []],
            ['POST', 'groups', ['title' => 'Group', 'member_ids' => [1]]],
            ['GET', 'blocks', []],
            ['POST', 'blocks', ['user_id' => 1]],
            ['POST', 'blocks/1/remove', []],
            ['POST', "conversations/{$c}/messages/1/report", ['reason' => 'spam']],
            ['GET', 'reports', []],
            ['GET', 'reports/1', []],
            ['POST', 'reports/1/review', ['status' => 'dismissed']],
            ['GET', 'preferences', []],
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

    /** FR11: reading stays open, so ?to= still opens a thread that exists; the draft for a new one is refused like any refusal. */
    public function testAReadOnlyInstitutionReopensAnExistingThreadByPerson(): void
    {
        $other = PeopleFactory::person('instructor', 'Oth', 'Er', null, $this->institution);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->student);

        $open = $this->api('POST', 'conversations', ['recipient_id' => (int) $this->instructor->id]);
        $open->assertStatus(200);
        $this->assertSame($this->conversation, json_decode($open->getJSON(), true)['draft']['conversation_id']);

        $this->assertSame(404, $this->api('POST', 'conversations', ['recipient_id' => (int) $other->id])->response()->getStatusCode());
        $this->assertSame(403, $this->api('POST', 'conversations/first', ['recipient_id' => (int) $other->id, 'body' => 'hi', 'client_uuid' => $this->uuid()])->response()->getStatusCode());
        $this->assertSame(1, db_connect()->table('conversations')->countAllResults());
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
        $this->assertSame((int) $peer->id, $started['draft']['recipient_id']);
        $this->assertNull($started['draft']['conversation_id']);
        $this->assertNotSame('', $started['draft']['name']);
        $before = db_connect()->table('conversations')->where('type', 'direct')->countAllResults();
        $uuid = $this->uuid();

        $first = $this->api('POST', 'conversations/first', ['recipient_id' => $peer->id, 'body' => 'hello there', 'client_uuid' => $uuid]);
        $this->assertSame(201, $first->response()->getStatusCode());
        $firstBody = json_decode($first->response()->getBody(), true);
        $id        = (int) $firstBody['conversation']['id'];
        $messageId = (int) $firstBody['message']['id'];
        $this->assertSame($before + 1, db_connect()->table('conversations')->where('type', 'direct')->countAllResults());

        $again = $this->api('POST', 'conversations/first', ['recipient_id' => $peer->id, 'body' => 'hello there', 'client_uuid' => $uuid]);
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

    /** The read route is registered twice (Routes.php); pins which controller answers: one that needs no message_id. */
    public function testMarkReadRouteAnswers(): void
    {
        $this->signIn($this->student);

        $response = $this->api('POST', "conversations/{$this->conversation}/read")->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode($response->getBody(), true));
    }

    public function testBadSendsAnswerTheirOwnStatus(): void
    {
        $this->signIn($this->student);

        $this->assertSame(422, $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => '   ', 'client_uuid' => $this->uuid()])->response()->getStatusCode());

        config(\Config\Messages::class)->encryptionKey = '';
        $this->assertSame(503, $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'hi', 'client_uuid' => $this->uuid()])->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
    }

    public function testANonNumericReplyToIsRefused(): void
    {
        $this->signIn($this->student);

        foreach (['abc', '1.5', '1e3'] as $bad) {
            $res = $this->api('POST', "conversations/{$this->conversation}/messages", ['body' => 'hi', 'client_uuid' => $this->uuid(), 'reply_to' => $bad]);
            $this->assertSame(422, $res->response()->getStatusCode());
            $this->assertSame('invalid_reply', json_decode($res->response()->getBody(), true)['error']);
        }

        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
    }

    public function testRecipientsThePickerWouldNeverListAnswerLikeAnUnknownId(): void
    {
        $parent  = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);
        $admin   = CourseFactory::user('admin');
        $far     = PeopleFactory::person('instructor', 'Far', 'Away', null, PeopleFactory::institution('FAR3'));
        $this->signIn($this->student);

        // Bodies are read at once: the test client reuses one response object.
        $unknown = $this->api('POST', 'conversations', ['recipient_id' => 999999])->response()->getBody();

        $this->assertSame(['error' => 'not_found'], json_decode($unknown, true));

        foreach ([(int) $this->student->id, (int) $parent->id, (int) $admin->id, (int) $far->id, 0, -5] as $recipient) {
            $response = $this->api('POST', 'conversations', ['recipient_id' => $recipient])->response();

            $this->assertSame(404, $response->getStatusCode(), "recipient {$recipient}");
            $this->assertSame($unknown, $response->getBody(), "recipient {$recipient}");
        }

        $this->assertSame(404, $this->api('POST', 'conversations', [])->response()->getStatusCode());
    }

    public function testRefusedDraftIs404(): void
    {
        $this->signIn($this->student);

        $this->assertSame(404, $this->api('POST', 'conversations', ['recipient_id' => (int) $this->student->id])->response()->getStatusCode());
    }

    public function testSendFirstRefusedIs404(): void
    {
        $this->signIn($this->student);

        $response = $this->api('POST', 'conversations/first', ['recipient_id' => (int) $this->student->id, 'body' => 'hi', 'client_uuid' => $this->uuid()])->response();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
    }

    public function testIndexIncludesOwnRestrictionOnly(): void
    {
        db_connect()->table('message_restrictions')->insert([
            'institution_id' => $this->institution, 'user_id' => (int) $this->student->id, 'kind' => 'no_new', 'source' => 'pause',
            'starts_at' => date('Y-m-d H:i:s'), 'ends_at' => date('Y-m-d H:i:s', strtotime('+2 days')), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->signIn($this->student);
        $mine = json_decode($this->api('GET', 'conversations')->response()->getBody(), true);
        $this->assertSame(['kind', 'ends_at', 'source'], array_keys($mine['restriction']));
        $this->assertSame(['no_new', 'pause'], [$mine['restriction']['kind'], $mine['restriction']['source']]);
        $this->assertNotNull($mine['restriction']['ends_at']);

        $this->signIn($this->instructor);
        $theirs = json_decode($this->api('GET', 'conversations')->response()->getBody(), true);
        $this->assertArrayHasKey('restriction', $theirs);
        $this->assertNull($theirs['restriction']);
    }

    /** S2-i: the explanation is on the page before anything is typed, for that parent only. */
    public function testTheMessagesPageTellsAParentWithNoChildUpFront(): void
    {
        $lonely = PeopleFactory::person('parent', 'Lone', 'Parent', null, $this->institution);
        $note   = 'Your account is not linked to a child yet';

        $this->signIn($lonely);
        $body = (string) $this->step('GET', 'messages')->response()->getBody();
        $this->assertStringContainsString('data-no-child="1"', $body);
        $this->assertStringContainsString($note, $body);

        $this->signIn($this->student);
        $body = (string) $this->step('GET', 'messages')->response()->getBody();
        $this->assertStringContainsString('data-no-child="0"', $body);
        $this->assertStringNotContainsString($note, $body);
    }

    public function testParentWithNoChildGetsExplanation(): void
    {
        $lonely = PeopleFactory::person('parent', 'Lone', 'Parent', null, $this->institution);
        $linked = PeopleFactory::person('parent', 'Link', 'Parent', null, $this->institution);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $linked->id, 'student_user_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->signIn($lonely);
        $this->assertSame(['recipients' => [], 'next' => null, 'reason' => 'no_linked_child'], json_decode($this->api('GET', 'recipients?q=Ins')->response()->getBody(), true));

        $this->signIn($linked);
        $answer = json_decode($this->api('GET', 'recipients?q=Ins')->response()->getBody(), true);
        $this->assertArrayNotHasKey('reason', $answer);
        $this->assertNotSame([], $answer['recipients']);

        $this->signIn($this->student);
        $this->assertArrayNotHasKey('reason', json_decode($this->api('GET', 'recipients?q=zzzz')->response()->getBody(), true), 'only that parent is told');
    }

    /** The draft header says who a parent's thread is about before anything is sent, like the thread's own header. */
    public function testAParentsDraftAboutTheirChildNamesTheChild(): void
    {
        $parent = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->signIn($parent);
        $draft = json_decode($this->api('POST', 'conversations', ['recipient_id' => (int) $this->instructor->id, 'subject_student_id' => (int) $this->student->id])->response()->getBody(), true)['draft'];

        $this->assertSame((int) $this->student->id, $draft['subject_student_id']);
        $this->assertNotSame('', (string) ($draft['subject_name'] ?? ''));

        $this->signIn($this->student);
        $plain = json_decode($this->api('POST', 'conversations', ['recipient_id' => (int) $this->instructor->id])->response()->getBody(), true)['draft'];
        $this->assertNull($plain['subject_name']);
    }
}
