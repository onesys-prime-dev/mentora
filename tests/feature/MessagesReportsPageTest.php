<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ReportService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The administrator's reports page: the list, one report with its escaped
 * snapshot, the decision buttons, and a 404 for everyone else.
 *
 * @internal
 */
final class MessagesReportsPageTest extends RouteTestCase
{
    use ParentScenario;

    private const SECRET = 'page-secret-6612';

    private int $chat;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('RG');
        $this->enableMessages();
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->chat  = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
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

    private function report(string $text, ?int $conversation = null): int
    {
        $chat    = $conversation ?? $this->chat;
        $service = new MessageService($this->parentCipher());
        $id      = (int) $service->send($this->instructor, $chat, $text, 'u-' . bin2hex(random_bytes(6)))['message']['id'];

        $this->assertTrue((new ReportService($this->parentCipher()))->report($this->child, $chat, $id, 'harassment', 'Unkind.'));

        return (int) db_connect()->table('message_reports')->selectMax('id', 'id')->get()->getRow('id');
    }

    /** @return array{int, string} */
    private function open(string $verb, string $uri, array $data = []): array
    {
        $this->withHeaders(['Accept' => 'text/html']);

        try {
            $response = $this->step($verb, $uri, $data)->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    public function testTheListShowsReportsWithoutAnyText(): void
    {
        $this->report(self::SECRET);
        $this->signIn($this->admin);

        [$status, $body] = $this->open('GET', 'messages/reports');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Harassment', $body);
        $this->assertStringContainsString('Kid Child', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
    }

    public function testOneReportShowsTheSnapshotEscaped(): void
    {
        $id = $this->report('<img src=x onerror=alert(1)> ' . self::SECRET);
        $this->signIn($this->admin);

        [$status, $body] = $this->open('GET', "messages/reports/{$id}");

        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;img', $body);
        $this->assertStringContainsString('value="remove"', $body);
    }

    public function testEachButtonDecidesTheReportAndTheRemoveButtonBlanksTheMessage(): void
    {
        $dismiss = $this->report('one');
        $action  = $this->report('two');
        $remove  = $this->report('three');
        $this->signIn($this->admin);

        foreach ([[$dismiss, 'dismiss', 'dismissed'], [$action, 'action', 'actioned'], [$remove, 'remove', 'actioned']] as [$id, $decision, $status]) {
            $this->assertSame(302, $this->open('POST', "messages/reports/{$id}/review", ['decision' => $decision])[0], $decision);
            $this->assertSame($status, db_connect()->table('message_reports')->where('id', $id)->get()->getRowArray()['status'], $decision);
        }

        $messageIds = array_map('intval', array_column(db_connect()->table('message_reports')->select('message_id')->orderBy('id')->get()->getResultArray(), 'message_id'));
        $removedAt  = array_column(db_connect()->table('messages')->select('id, removed_at')->whereIn('id', $messageIds)->orderBy('id')->get()->getResultArray(), 'removed_at');

        $this->assertSame([null, null], array_slice($removedAt, 0, 2));
        $this->assertNotNull($removedAt[2]);
    }

    public function testADirectReportOffersNoRemoveButton(): void
    {
        $direct = (int) (new ConversationService())->startDirect($this->child, (int) $this->instructor->id)['id'];
        $id     = $this->report('private', $direct);
        $this->signIn($this->admin);

        [$status, $body] = $this->open('GET', "messages/reports/{$id}");

        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('value="remove"', $body);
        $this->assertStringContainsString('Direct messages are never removed', $body);
    }

    public function testEveryoneElseGetsNotFoundOnAllThreeRoutes(): void
    {
        $id  = $this->report('hello');
        $far = PeopleFactory::person('institutional_admin', 'Far', 'Admin', null, DeanFactory::department('RG2')['institution']);

        foreach ([$this->child, $this->instructor, $this->parent, $this->chair, $far, CourseFactory::user('admin')] as $user) {
            $this->signIn($user);

            $this->assertSame(404, $this->open('GET', 'messages/reports')[0], (string) $user->first_name);
            $this->assertSame(404, $this->open('GET', "messages/reports/{$id}")[0], (string) $user->first_name);
            $this->assertSame(404, $this->open('POST', "messages/reports/{$id}/review", ['decision' => 'dismiss'])[0], (string) $user->first_name);
        }

        $this->assertSame('open', db_connect()->table('message_reports')->get()->getRowArray()['status']);
    }

    public function testNothingExistsWhileMessagesIsOffAndAPostNeedsItsToken(): void
    {
        $id = $this->report('hello');
        $this->signIn($this->admin);
        $this->withoutCsrfToken();

        $this->assertNotSame(302, $this->open('POST', "messages/reports/{$id}/review", ['decision' => 'dismiss'])[0]);
        $this->assertSame('open', db_connect()->table('message_reports')->get()->getRowArray()['status']);

        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();
        \App\Services\Admin\PermissionMatrix::flush();

        $this->assertSame(404, $this->open('GET', 'messages/reports')[0]);
    }

    public function testTheMessagesPageLinksAdministratorsToReports(): void
    {
        $this->signIn($this->admin);
        $this->assertStringContainsString('messages/reports', $this->open('GET', 'messages')[1]);

        $this->signIn($this->instructor);
        $this->assertStringNotContainsString('messages/reports', $this->open('GET', 'messages')[1]);
    }
}
