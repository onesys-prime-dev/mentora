<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\RouteTestCase;

/**
 * The Messages page and the recipient search, through the filters.
 *
 * @internal
 */
final class MessagesPageTest extends RouteTestCase
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

    public function testSignedOutVisitorsAreSentToLogin(): void
    {
        $response = $this->step('GET', 'messages')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
    }

    public function testThePageIsNotFoundForTheAdministratorAndWhileTheFlagIsOff(): void
    {
        $this->signIn(CourseFactory::user('admin'));
        $this->assertSame(404, $this->step('GET', 'messages')->response()->getStatusCode());

        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();
        PermissionMatrix::flush();
        $this->signIn($this->student);

        $this->assertSame(404, $this->step('GET', 'messages')->response()->getStatusCode());
        $this->assertSame(404, $this->step('GET', 'messages/api/recipients?q=ab')->response()->getStatusCode());
    }

    public function testAnInstructorGetsThePageShell(): void
    {
        $this->signIn($this->instructor);

        $response = $this->step('GET', 'messages')->response();
        $html     = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('id="messages-app"', $html);
        $this->assertStringContainsString('data-page="messages"', $html);
        $this->assertMatchesRegularExpression('#data-api="[^"]*messages/api"#', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    public function testADeepLinkPutsTheIdInDataOpenAndTheShellSaysNothingAboutWhetherItIsReal(): void
    {
        $this->signIn($this->student);
        $html = $this->step('GET', 'messages/' . $this->conversation)->response()->getBody();
        $this->assertStringContainsString('data-open="' . $this->conversation . '"', $html);

        // The shell is the same for any id; the API answers 404 for one that is not the person's.
        $other = $this->step('GET', 'messages/999999')->response();
        $this->assertSame(200, $other->getStatusCode());
        $this->assertStringContainsString('data-open="999999"', $other->getBody());
    }

    public function testThePageCarriesNoMessageText(): void
    {
        $this->signIn($this->instructor);
        $this->withHeaders(['Accept' => 'application/json'])->withBodyFormat('json');
        $this->step('POST', "messages/api/conversations/{$this->conversation}/messages", ['body' => 'zebra-secret-phrase', 'client_uuid' => $this->uuid()]);

        $html = $this->step('GET', 'messages')->response()->getBody();

        $this->assertStringNotContainsString('zebra-secret-phrase', $html);
    }

    public function testTheRecipientSearchAnswersJsonAndNothingForOneCharacter(): void
    {
        $this->signIn($this->student);
        $this->withHeaders(['Accept' => 'application/json']);

        $short = $this->step('GET', 'messages/api/recipients?q=a')->response();
        $this->assertSame(200, $short->getStatusCode());
        $this->assertSame(['recipients' => []], json_decode($short->getBody(), true));

        $found = json_decode($this->step('GET', 'messages/api/recipients?q=Tructor')->response()->getBody(), true);
        $this->assertCount(1, $found['recipients']);
        $this->assertSame((int) $this->instructor->id, $found['recipients'][0]['id']);
    }

    public function testTheListRowsCarryNamesAndNoCiphertext(): void
    {
        $this->signIn($this->instructor);
        $this->withHeaders(['Accept' => 'application/json']);

        $body = $this->step('GET', 'messages/api/conversations')->response()->getBody();
        $rows = json_decode($body, true)['conversations'];

        $this->assertSame('Stu Dent', $rows[0]['name']);
        $this->assertArrayHasKey('can_post', $rows[0]);
        $this->assertStringNotContainsString('body_enc', $body);
    }
}
