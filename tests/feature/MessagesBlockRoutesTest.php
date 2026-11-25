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

        foreach ([(int) $far->id, (int) $this->student->id] as $id) {
            $response = $this->api('POST', 'blocks', ['user_id' => $id])->response();

            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame($unknown->getBody(), $response->getBody());
        }

        $this->assertSame(0, db_connect()->table('user_blocks')->countAllResults());
    }

    public function testBlockingOwnInstructorReturnsReason(): void
    {
        $this->signIn($this->student);

        $response = $this->api('POST', 'blocks', ['user_id' => (int) $this->instructor->id])->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['reason' => 'protected_link', 'message' => "You can't block your instructor while you share a course."],
            json_decode($response->getBody(), true),
        );
    }

    public function testInstructorBlockingTheirStudentReturnsReason(): void
    {
        $this->signIn($this->instructor);

        $response = $this->api('POST', 'blocks', ['user_id' => (int) $this->student->id])->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame("You can't block a student while you teach them.", json_decode($response->getBody(), true)['message']);
    }

    public function testBlockingInstitutionalAdminIsGeneric404(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ins', 'Admin', null, $this->institution);
        $this->signIn($this->student);

        $unknown  = $this->api('POST', 'blocks', ['user_id' => 99999999])->response();
        $response = $this->api('POST', 'blocks', ['user_id' => (int) $admin->id])->response();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($unknown->getBody(), $response->getBody());
    }

    public function testBlocksListIncludesPausedFlag(): void
    {
        $classmate = PeopleFactory::person('student', 'Cla', 'Ssmate', null, $this->institution);
        $this->signIn($this->student);
        $this->api('POST', 'blocks', ['user_id' => (int) $classmate->id]);
        // A pre-existing row against the instructor (made before the protected link rule) is listed as paused.
        db_connect()->table('user_blocks')->insert([
            'institution_id' => $this->institution, 'blocker_id' => (int) $this->student->id,
            'blocked_id' => (int) $this->instructor->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $list   = json_decode($this->api('GET', 'blocks')->response()->getBody(), true)['blocked'];
        $paused = array_column($list, 'paused', 'id');

        $this->assertFalse($paused[(int) $classmate->id]);
        $this->assertTrue($paused[(int) $this->instructor->id]);
    }
}
