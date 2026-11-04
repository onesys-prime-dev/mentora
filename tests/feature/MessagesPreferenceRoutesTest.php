<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\MessagingScenario;
use Tests\Support\RouteTestCase;

/**
 * The person's own Messages preferences over HTTP. The common refusals (signed out, flag off,
 * system administrator, impersonation, read-only, CSRF) are covered by MessagesRoutesTest.
 *
 * @internal
 */
final class MessagesPreferenceRoutesTest extends RouteTestCase
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

    public function testTheDefaultsAndWhetherQuietHoursAreOffered(): void
    {
        $this->signIn($this->instructor);
        $body = json_decode($this->api('GET', 'preferences')->response()->getBody(), true);

        $this->assertSame(['quiet_start' => null, 'quiet_end' => null, 'email_alerts' => true], $body['preferences']);
        $this->assertTrue($body['quiet_hours_allowed']);

        $this->signIn($this->student);
        $this->assertFalse(json_decode($this->api('GET', 'preferences')->response()->getBody(), true)['quiet_hours_allowed']);
    }

    public function testAnInstructorSavesQuietHoursAndTheEmailSwitch(): void
    {
        $this->signIn($this->instructor);

        $response = $this->api('POST', 'preferences', ['quiet_start' => '22:00', 'quiet_end' => '07:00', 'email_alerts' => false])->response();
        $body     = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['quiet_start' => '22:00', 'quiet_end' => '07:00', 'email_alerts' => false], $body['preferences']);
    }

    public function testAStudentCanSwitchEmailsButNotSetQuietHours(): void
    {
        $this->signIn($this->student);

        $this->assertSame(200, $this->api('POST', 'preferences', ['quiet_start' => null, 'quiet_end' => null, 'email_alerts' => false])->response()->getStatusCode());

        $refused = $this->api('POST', 'preferences', ['quiet_start' => '22:00', 'quiet_end' => '07:00', 'email_alerts' => true])->response();

        $this->assertSame(422, $refused->getStatusCode());
        $this->assertSame('invalid', json_decode($refused->getBody(), true)['error']);
    }

    public function testBadValuesAreRefusedAndChangeNothing(): void
    {
        $this->signIn($this->instructor);
        $this->api('POST', 'preferences', ['quiet_start' => '22:00', 'quiet_end' => '07:00', 'email_alerts' => true]);

        foreach ([['quiet_start' => '25:00', 'quiet_end' => '07:00', 'email_alerts' => true], ['quiet_start' => '22:00', 'quiet_end' => null, 'email_alerts' => true], ['quiet_start' => 2200, 'quiet_end' => '07:00', 'email_alerts' => true], ['quiet_start' => '22:00', 'quiet_end' => '07:00', 'email_alerts' => 'yes']] as $input) {
            $this->assertSame(422, $this->api('POST', 'preferences', $input)->response()->getStatusCode(), json_encode($input));
        }

        $this->assertSame('22:00', json_decode($this->api('GET', 'preferences')->response()->getBody(), true)['preferences']['quiet_start']);
    }
}
