<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The staff group routes through the filters: a chairperson starts a group of
 * their instructors; anyone else, and any member they may not add, gets the
 * shared 404 and nothing is created.
 *
 * @internal
 */
final class MessagesGroupRoutesTest extends RouteTestCase
{
    use ParentScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('GRT');
        $this->enableMessages();
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

    private function groupCount(): int
    {
        return db_connect()->table('conversations')->where('type', 'group')->countAllResults();
    }

    public function testAChairpersonStartsAGroupOverHttp(): void
    {
        $this->signIn($this->chair);

        $response = $this->api('POST', 'groups', ['title' => 'Exam planning', 'member_ids' => [(int) $this->instructor->id]])->response();
        $body     = json_decode($response->getBody(), true)['conversation'];

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('group', $body['type']);
        $this->assertSame('Exam planning', $body['name']);
        $this->assertTrue($body['can_moderate']);
        $this->assertSame(1, $this->groupCount());

        $this->signIn($this->instructor);
        $list = json_decode($this->api('GET', 'conversations?filter=group')->response()->getBody(), true)['conversations'];

        $this->assertSame(['Exam planning'], array_column($list, 'name'));
        $this->assertFalse($list[0]['can_moderate']);
    }

    public function testAMemberTheyMayNotAddAnswersLikeAnUnknownRecipientAndCreatesNothing(): void
    {
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->signIn($this->chair);

        $refused = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [(int) $this->instructor->id, (int) $student->id]])->response();
        $unknown = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [99999999]])->response();

        $this->assertSame(404, $refused->getStatusCode());
        $this->assertSame($unknown->getStatusCode(), $refused->getStatusCode());
        $this->assertSame($unknown->getBody(), $refused->getBody());
        $this->assertSame(0, $this->groupCount());
    }

    public function testMalformedInputIsTheSameNotFound(): void
    {
        $this->signIn($this->chair);

        foreach ([['title' => 'G'], ['title' => 'G', 'member_ids' => 'x'], ['title' => 'G', 'member_ids' => [['a']]], ['title' => ['x'], 'member_ids' => [1]], ['member_ids' => [1]]] as $input) {
            $this->assertSame(404, $this->api('POST', 'groups', $input)->response()->getStatusCode());
        }

        $this->assertSame(0, $this->groupCount());
    }

    public function testNobodyWhoMayNotStartGroupsReachesEitherRoute(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        foreach ([$this->child, $this->instructor, $this->parent, $admin] as $user) {
            $this->signIn($user);

            // The test client reuses one response object, so read each answer before the next request.
            $created    = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [(int) $this->instructor->id]])->response()->getStatusCode();
            $candidates = json_decode($this->api('GET', 'group-candidates?q=Tructor')->response()->getBody(), true)['candidates'] ?? [];

            $this->assertSame(404, $created, 'user ' . $user->id);
            $this->assertSame([], $candidates, 'user ' . $user->id);
        }

        $this->assertSame(0, $this->groupCount());
    }

    public function testCandidatesAreScopedAndCarryNoContactDetails(): void
    {
        $this->signIn($this->chair);

        $found = json_decode($this->api('GET', 'group-candidates?q=Tructor')->response()->getBody(), true)['candidates'];

        $this->assertSame([(int) $this->instructor->id], array_column($found, 'id'));
        $this->assertSame(['id', 'name', 'role'], array_keys($found[0]));
    }

    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $this->signIn($this->chair);
        $this->withoutCsrfToken();

        $response = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [(int) $this->instructor->id]])->response();

        $this->assertNotContains($response->getStatusCode(), [200, 201]);
        $this->assertSame(0, $this->groupCount());
    }

    public function testThePageOffersTheNewGroupButtonOnlyToChairpersonsAndDeans(): void
    {
        $this->withHeaders(['Accept' => 'text/html']);

        $this->signIn($this->chair);
        $this->assertStringContainsString('data-can-group="1"', $this->step('GET', 'messages')->response()->getBody());

        $this->signIn($this->instructor);
        $this->assertStringContainsString('data-can-group="0"', $this->step('GET', 'messages')->response()->getBody());
    }
}
