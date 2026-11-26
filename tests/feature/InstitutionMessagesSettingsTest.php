<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
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

    /** @param array<string, string>|string $input a scope, or the whole form */
    private function save(array|string $input): \CodeIgniter\Test\TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);

        return $this->step('POST', 'institution/settings/messages', is_array($input) ? $input : ['scope' => $input]);
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
        $this->assertSame(302, $this->save('off')->response()->getStatusCode(), $role);
        $this->assertTrue((new StudentMessaging())->enabled($this->institution), $role . ' changed nothing');
    }

    public function testTheTabShowsOnByDefaultAndIsInTheList(): void
    {
        $body = $this->step('GET', 'institution/settings/messages')->response()->getBody();

        $this->assertStringContainsString('Students messaging each other', $body);
        $this->assertStringContainsString('institution/settings/messages', $body);
        $this->assertStringContainsString('<legend', $body);
        $this->assertMatchesRegularExpression('/name="scope"[^>]*value="institution"[^>]*checked/s', $body);
        $this->assertStringContainsString('Classmates only', $body);
        $this->assertStringContainsString('Default quiet hours', $body);
    }

    public function testSwitchingItOffShowsOffAndOnAgainRestoresIt(): void
    {
        $this->assertSame(200, $this->save('off')->response()->getStatusCode());
        $this->assertFalse((new StudentMessaging())->enabled($this->institution));
        $this->assertMatchesRegularExpression('/name="scope"[^>]*value="off"[^>]*checked/s', $this->step('GET', 'institution/settings/messages')->response()->getBody());

        $this->assertSame(200, $this->save('institution')->response()->getStatusCode());
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
        $this->save('off');

        $this->assertTrue((new StudentMessaging())->enabled($this->other));
    }

    public function testSavesScopeAndAudits(): void
    {
        $this->assertSame(200, $this->save('course')->response()->getStatusCode());

        $this->assertSame('course', (new StudentMessaging())->scope($this->institution));
        $this->assertTrue((new StudentMessaging())->enabled($this->institution));
        $this->assertSame('institution', (new StudentMessaging())->scope($this->other));

        $row = db_connect()->table('audit_logs')->where(['action' => 'settings.changed', 'target_id' => 'studentMessagingScope'])->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame($this->institution, (int) $row['institution_id']);
        $this->assertStringContainsString('course', (string) $row['new_values']);
    }

    public function testSavesDefaultQuietHours(): void
    {
        $this->assertSame(200, $this->save(['scope' => 'institution', 'quietStart' => '21:00', 'quietEnd' => '07:00'])->response()->getStatusCode());

        $platform = new PlatformSettings();
        $this->assertSame('21:00', $platform->get('defaultQuietStart', $this->institution));
        $this->assertSame('07:00', $platform->get('defaultQuietEnd', $this->institution));
        $this->assertNull($platform->get('defaultQuietStart', $this->other));

        // None: both empty clears them.
        $this->assertSame(200, $this->save(['scope' => 'institution', 'quietStart' => '', 'quietEnd' => ''])->response()->getStatusCode());
        $this->assertNull($platform->get('defaultQuietStart', $this->institution));
        $this->assertNull($platform->get('defaultQuietEnd', $this->institution));
    }

    public function testEqualDefaultTimesAreRefused(): void
    {
        $this->assertSame(422, $this->save(['scope' => 'off', 'quietStart' => '21:00', 'quietEnd' => '21:00'])->response()->getStatusCode());
        $this->assertNull((new PlatformSettings())->get('defaultQuietStart', $this->institution));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideBadTimes(): iterable
    {
        yield 'hour 24'  => ['24:00', '07:00'];
        yield 'not time' => ['soon', '07:00'];
        yield 'one only' => ['21:00', ''];
        yield 'no colon' => ['2100', '0700'];
    }

    #[DataProvider('provideBadTimes')]
    public function testRejectsBadTimes(string $start, string $end): void
    {
        $this->save('course');

        $this->assertSame(422, $this->save(['scope' => 'off', 'quietStart' => $start, 'quietEnd' => $end])->response()->getStatusCode());
        $this->assertSame('course', (new StudentMessaging())->scope($this->institution), 'nothing saved');
        $this->assertNull((new PlatformSettings())->get('defaultQuietStart', $this->institution));
    }
}
