<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Block and unblock over HTTP. Signed-out, flag-off, administrator, impersonation,
 * read-only and CSRF cases are covered for these routes by MessagesRoutesTest.
 *
 * @internal
 */
final class MessagesBlockRoutesTest extends RouteTestCase
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

    public function testBlockListAndUnblockEndToEnd(): void
    {
        $classmate = PeopleFactory::person('student', 'Cla', 'Ssmate', null, $this->institution);
        $this->signIn($this->student);

        $this->assertSame(200, $this->api('POST', 'blocks', ['user_id' => (int) $classmate->id])->response()->getStatusCode());

        $list = json_decode($this->api('GET', 'blocks')->response()->getBody(), true)['blocked'];
        $this->assertSame([(int) $classmate->id], array_column($list, 'id'));

        $this->assertSame(200, $this->api('POST', 'blocks/' . $classmate->id . '/remove')->response()->getStatusCode());
        $this->assertSame([], json_decode($this->api('GET', 'blocks')->response()->getBody(), true)['blocked']);
    }

    public function testWhoCannotBeBlockedAnswersLikeAnUnknownPerson(): void
    {
        $far = PeopleFactory::person('student', 'Far', 'Away', null, PeopleFactory::institution('BKFAR'));
        $this->signIn($this->student);

        $unknown = $this->api('POST', 'blocks', ['user_id' => 99999999])->response();

        foreach ([(int) $this->instructor->id, (int) $far->id, (int) $this->student->id] as $id) {
            $response = $this->api('POST', 'blocks', ['user_id' => $id])->response();

            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame($unknown->getBody(), $response->getBody());
        }

        $this->assertSame(0, db_connect()->table('user_blocks')->countAllResults());
    }
}
