<?php

declare(strict_types=1);

use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageRateLimiter;
use App\Services\Messaging\MessageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Report a message over HTTP, and the administrator's JSON view. The common
 * refusals (signed out, flag off, system administrator, impersonation, read-only,
 * CSRF) are covered for these routes by MessagesRoutesTest.
 *
 * @internal
 */
final class MessagesReportRoutesTest extends RouteTestCase
{
    use ParentScenario;

    private const SECRET = 'route-secret-3307';

    private int $chat;

    private int $message;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('RR');
        $this->enableMessages();
        $this->admin   = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->chat    = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        $this->message = (int) (new MessageService($this->parentCipher()))->send($this->instructor, $this->chat, self::SECRET, 'u-' . bin2hex(random_bytes(6)))['message']['id'];
    }

    protected function tearDown(): void
    {
        $this->releaseMessages();

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

    private function file(?User $as = null): TestResponse
    {
        $this->signIn($as ?? $this->child);

        return $this->api('POST', "conversations/{$this->chat}/messages/{$this->message}/report", ['reason' => 'spam', 'note' => 'unwanted']);
    }

    public function testAParticipantReportsAndTheAdministratorSeesIt(): void
    {
        $this->assertSame(200, $this->file()->response()->getStatusCode());

        $this->signIn($this->admin);
        $list = json_decode($this->api('GET', 'reports')->response()->getBody(), true)['reports'];

        $this->assertCount(1, $list);
        $this->assertStringNotContainsString(self::SECRET, json_encode($list));

        $show = $this->api('GET', 'reports/' . $list[0]['id'])->response();

        $this->assertSame(200, $show->getStatusCode());
        $this->assertSame([self::SECRET], array_column(json_decode($show->getBody(), true)['report']['snapshot'], 'body'));
    }

    public function testTheAdministratorRemovesTheMessageThroughTheReview(): void
    {
        $this->file();
        $report = (int) db_connect()->table('message_reports')->get()->getRowArray()['id'];

        $this->signIn($this->admin);
        $response = $this->api('POST', "reports/{$report}/review", ['status' => 'actioned', 'remove_message' => true])->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull(db_connect()->table('messages')->where('id', $this->message)->get()->getRowArray()['removed_at']);

        $this->assertSame(404, $this->api('POST', "reports/{$report}/review", ['status' => 'dismissed'])->response()->getStatusCode(), 'reviewed once');
    }

    public function testRefusedReportsAnswerLikeAnUnknownMessage(): void
    {
        $stranger = PeopleFactory::person('student', 'Stran', 'Ger', null, $this->institution);
        $this->signIn($stranger);
        $unknown = $this->api('POST', "conversations/{$this->chat}/messages/99999999/report", ['reason' => 'spam'])->response();

        $this->assertSame(404, $unknown->getStatusCode());

        foreach ([[$stranger, $this->message], [$this->instructor, $this->message]] as [$user, $messageId]) {
            $this->signIn($user);
            $response = $this->api('POST', "conversations/{$this->chat}/messages/{$messageId}/report", ['reason' => 'spam'])->response();

            $this->assertSame(404, $response->getStatusCode(), (string) $user->first_name);
            $this->assertSame($unknown->getBody(), $response->getBody());
        }

        $this->assertSame(0, db_connect()->table('message_reports')->countAllResults());
    }

    public function testEveryoneButTheInstitutionsAdministratorGetsTheSharedNotFound(): void
    {
        $this->file();
        $report = (int) db_connect()->table('message_reports')->get()->getRowArray()['id'];
        $far    = PeopleFactory::person('institutional_admin', 'Far', 'Admin', null, PeopleFactory::institution('RRFAR'));

        foreach ([$this->child, $this->instructor, $this->parent, $this->chair, $far] as $user) {
            $this->signIn($user);

            $this->assertSame(404, $this->api('GET', 'reports')->response()->getStatusCode(), (string) $user->first_name);
            $this->assertSame(404, $this->api('GET', "reports/{$report}")->response()->getStatusCode(), (string) $user->first_name);
            $this->assertSame(404, $this->api('POST', "reports/{$report}/review", ['status' => 'dismissed'])->response()->getStatusCode(), (string) $user->first_name);
        }

        $this->assertSame('open', db_connect()->table('message_reports')->get()->getRowArray()['status']);
    }

    public function testTheTwentyFirstReportOfTheDayIsTooMany(): void
    {
        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < MessageRateLimiter::REPORTS_PER_DAY; $i++) {
            db_connect()->table('message_reports')->insert([
                'institution_id' => $this->institution, 'message_id' => $this->message, 'reporter_id' => $this->child->id, 'reason' => 'spam',
                'snapshot_enc' => 'x', 'status' => 'open', 'created_at' => $now,
            ]);
        }

        $response = $this->file()->response();

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('rate_limited', json_decode($response->getBody(), true)['error']);
    }
}
