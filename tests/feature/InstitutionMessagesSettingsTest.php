<?php

declare(strict_types=1);

use App\Services\Messaging\StudentMessaging;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The "Messages" tab of the institution's settings: the administrator switches
 * students messaging each other off and on again; nobody else can.
 *
 * @internal
 */
final class InstitutionMessagesSettingsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('MSGA');
        $this->other       = SisFixture::institution('MSGB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@msg.example', $this->institution);

        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function save(string $value): \CodeIgniter\Test\TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);

        return $this->step('POST', 'institution/settings/messages', ['enabled' => $value]);
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@msg.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/settings/messages')->response()->getStatusCode(), $role);
        $this->assertSame(302, $this->save('0')->response()->getStatusCode(), $role);
        $this->assertTrue((new StudentMessaging())->enabled($this->institution), $role . ' changed nothing');
    }

    public function testTheTabShowsOnByDefaultAndIsInTheList(): void
    {
        $body = $this->step('GET', 'institution/settings/messages')->response()->getBody();

        $this->assertStringContainsString('Students messaging each other', $body);
        $this->assertStringContainsString('institution/settings/messages', $body);
        $this->assertMatchesRegularExpression('/name="enabled"[^>]*value="1"[^>]*checked/s', $body);
    }

    public function testSwitchingItOffShowsOffAndOnAgainRestoresIt(): void
    {
        $this->assertSame(200, $this->save('0')->response()->getStatusCode());
        $this->assertFalse((new StudentMessaging())->enabled($this->institution));
        $this->assertMatchesRegularExpression('/name="enabled"[^>]*value="0"[^>]*checked/s', $this->step('GET', 'institution/settings/messages')->response()->getBody());

        $this->assertSame(200, $this->save('1')->response()->getStatusCode());
        $this->assertTrue((new StudentMessaging())->enabled($this->institution));
    }

    public function testABadValueIsRefusedAndChangesNothing(): void
    {
        $response = $this->save('maybe')->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue((new StudentMessaging())->enabled($this->institution));
    }

    public function testItDoesNotTouchAnotherInstitution(): void
    {
        $this->save('0');

        $this->assertTrue((new StudentMessaging())->enabled($this->other));
    }
}
