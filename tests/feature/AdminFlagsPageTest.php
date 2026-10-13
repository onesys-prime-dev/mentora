<?php

declare(strict_types=1);

use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use Config\FeatureFlags as FlagConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The feature flag pages as a system administrator uses them, and as
 * everyone else is refused: the list with each flag's global setting, the
 * per-institution page with the answer and the reason, and the forms to
 * change a flag (a change for everyone also needs the password).
 *
 * @internal
 */
final class AdminFlagsPageTest extends RouteTestCase
{
    private User $admin;

    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        config(FlagConfig::class)->flags = [
            'test.off' => ['description' => 'Off by default', 'default' => false],
            'test.on'  => ['description' => 'On by default', 'default' => true],
        ];

        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('institutions')->insert(['name' => 'Acme University', 'code' => 'ACME', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $this->institution = (int) db_connect()->insertID();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Factories::reset('config');
        $_GET = [];
        service('superglobals')->setGetArray([]);

        parent::tearDown();
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    /** @param array<string, string> $data */
    private function submit(string $uri, array $data = []): TestResponse
    {
        return $this->step('POST', $uri, $data);
    }

    private function body(string $uri): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    private function location(TestResponse $response): string
    {
        return $response->response()->getHeaderLine('Location');
    }

    private function institutionUri(string $suffix = ''): string
    {
        return "admin/institutions/{$this->institution}/flags{$suffix}";
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return db_connect()->table('feature_flag_overrides')->orderBy('id')->get()->getResultArray();
    }

    private function auditCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | Who may use it
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrChangeFlags(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();
        $this->confirmedPasswordFor();

        foreach (['admin/flags', $this->institutionUri()] as $uri) {
            $this->assertSame(302, $this->step('GET', $uri)->response()->getStatusCode(), "{$role} must not open {$uri}");
        }

        $this->submit('admin/flags/test.off/global', ['state' => 'on', 'note' => 'Sneaky']);
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Sneaky']);

        $this->assertSame([], $this->rows());
    }

    /** Pretends the (other) signed-in user has just confirmed their password, so only the role check can refuse. */
    private function confirmedPasswordFor(): void
    {
        $_SESSION['reauth'] = ['user' => auth()->id(), 'at' => time()];
        $this->withSession();
    }

    public function testAdminHomeAndTheInstitutionsListLinkToTheFlags(): void
    {
        $this->assertStringContainsString('admin/flags', $this->body('admin/dashboard'));
        $this->assertStringContainsString($this->institutionUri(), $this->body('admin/institutions'));
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function testTheListShowsEveryFlagWithItsDescriptionAndAdoption(): void
    {
        $body = $this->body('admin/flags');

        $this->assertStringContainsString('test.off', $body);
        $this->assertStringContainsString('Off by default', $body);
        $this->assertStringContainsString('test.on', $body);
        $this->assertStringContainsString('<strong>0</strong> of 1 institutions have it on', $body);
        $this->assertStringContainsString('<strong>1</strong> of 1 institutions have it on', $body);
        $this->assertStringContainsString('Following its default (off)', $body);
    }

    public function testTheListReflectsAnOverrideInTheCounts(): void
    {
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot']);

        $body = $this->body('admin/flags');

        $this->assertStringContainsString('<strong>1</strong> of 1 institutions have it on', $body);
        $this->assertStringContainsString('1 with their own override', $body);
    }

    public function testNamesAndDescriptionsAreEscaped(): void
    {
        config(FlagConfig::class)->flags = ['test.xss' => ['description' => '<script>alert(1)</script>', 'default' => false]];

        $body = $this->body('admin/flags');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Changing a flag for everyone
    |--------------------------------------------------------------------------
    */

    public function testChangingAFlagForEveryoneNeedsThePassword(): void
    {
        $response = $this->submit('admin/flags/test.on/global', ['state' => 'off', 'note' => 'Problem']);

        $this->assertStringEndsWith('admin/reauth', $this->location($response));
        $this->assertSame([], $this->rows());
        $this->assertSame(0, $this->auditCount('flag.global_changed'));
    }

    public function testOnceConfirmedTheKillSwitchWorksAndShowsOnTheList(): void
    {
        $this->confirmedPassword();

        $response = $this->submit('admin/flags/test.on/global', ['state' => 'off', 'note' => 'Security problem', 'ends_on' => '']);

        $this->assertStringEndsWith('admin/flags', $this->location($response));
        $this->assertSame(0, (int) $this->rows()[0]['enabled']);
        $this->assertSame(1, $this->auditCount('flag.global_changed'));

        $body = $this->body('admin/flags');

        $this->assertStringContainsString('Flag updated for everyone', $body);
        $this->assertStringContainsString('Switched off for everyone', $body);
        $this->assertStringContainsString('Note: Security problem', $body);
        $this->assertStringContainsString('data-flag-state="off"', $body);
        $this->assertStringContainsString('<strong>0</strong> of 1 institutions have it on', $body);
    }

    public function testAGlobalChangeWithoutANoteIsRefusedWithTheReason(): void
    {
        $this->confirmedPassword();

        $this->submit('admin/flags/test.on/global', ['state' => 'off', 'note' => '']);

        $this->assertSame([], $this->rows());
        $this->assertStringContainsString('note is needed', $this->body('admin/flags'));
    }

    public function testAGlobalChangeCanBeUndoneByGoingBackToTheDefault(): void
    {
        $this->confirmedPassword();
        $this->submit('admin/flags/test.on/global', ['state' => 'off', 'note' => 'Problem']);

        $this->submit('admin/flags/test.on/global', ['state' => 'default']);

        $this->assertSame([], $this->rows());
        $this->assertStringContainsString('data-flag-state="default"', $this->body('admin/flags'));
    }

    public function testAnUnknownFlagIsRefusedWithAMessage(): void
    {
        $this->confirmedPassword();

        $this->submit('admin/flags/test.nope/global', ['state' => 'on', 'note' => 'Why']);

        $this->assertSame([], $this->rows());
        $this->assertStringContainsString('does not exist', $this->body('admin/flags'));
    }

    public function testAPastLastDayIsRefused(): void
    {
        $this->confirmedPassword();

        $this->submit('admin/flags/test.off/global', ['state' => 'on', 'note' => 'Launch', 'ends_on' => '2020-01-01']);

        $this->assertSame([], $this->rows());
        $this->assertStringContainsString('cannot be in the past', $this->body('admin/flags'));
    }

    /*
    |--------------------------------------------------------------------------
    | One institution
    |--------------------------------------------------------------------------
    */

    public function testTheInstitutionPageShowsEachFlagWithItsAnswerAndReason(): void
    {
        $body = $this->body($this->institutionUri());

        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringContainsString('data-flag="test.off" data-flag-enabled="0" data-flag-reason="default"', $body);
        $this->assertStringContainsString('data-flag="test.on" data-flag-enabled="1" data-flag-reason="default"', $body);
        $this->assertStringContainsString('Default (off)', $body);
        $this->assertStringContainsString('Default (on)', $body);
        $this->assertStringContainsString('does not include it', $body);
    }

    public function testAMissingInstitutionIsNotFound(): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', 'admin/institutions/999999/flags');
    }

    public function testAnOverrideDoesNotNeedThePasswordAndShowsItsReason(): void
    {
        $response = $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot with their IT team', 'ends_on' => '2099-03-31']);

        $this->assertStringEndsWith($this->institutionUri(), $this->location($response));

        $body = $this->body($this->institutionUri());

        $this->assertStringContainsString('Flag updated for this institution', $body);
        $this->assertStringContainsString('data-flag="test.off" data-flag-enabled="1" data-flag-reason="override_on"', $body);
        $this->assertStringContainsString('Turned on for this institution', $body);
        $this->assertStringContainsString('until 2099-03-31', $body);
        $this->assertStringContainsString('Pilot with their IT team', $body);
        $this->assertSame(1, $this->auditCount('flag.override_changed'));
    }

    public function testTurningAFlagOffForOneInstitutionShowsAsSuch(): void
    {
        $this->submit($this->institutionUri('/test.on'), ['state' => 'off', 'note' => 'Trouble with their SIS']);

        $this->assertStringContainsString('data-flag="test.on" data-flag-enabled="0" data-flag-reason="override_off"', $this->body($this->institutionUri()));
    }

    public function testTheKillSwitchShowsOnTheInstitutionPageAsWinning(): void
    {
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot']);
        $this->confirmedPassword();
        $this->submit('admin/flags/test.off/global', ['state' => 'off', 'note' => 'Security problem']);

        $body = $this->body($this->institutionUri());

        $this->assertStringContainsString('data-flag="test.off" data-flag-enabled="0" data-flag-reason="kill_switch"', $body);
        $this->assertStringContainsString('Switched off for everyone', $body);
    }

    public function testAPlanGrantShowsAsTheReason(): void
    {
        db_connect()->table('plans')->insert(['name' => 'Gold', 'features' => json_encode(['test.off']), 'status' => 'active']);
        db_connect()->table('subscriptions')->insert(['institution_id' => $this->institution, 'plan_id' => (int) db_connect()->insertID(), 'start_date' => '2025-01-01', 'end_date' => '2099-12-31']);

        $body = $this->body($this->institutionUri());

        $this->assertStringContainsString('data-flag="test.off" data-flag-enabled="1" data-flag-reason="plan"', $body);
        $this->assertStringContainsString('Included in its plan', $body);
    }

    public function testAnOverrideThatEndedIsShownButNotApplied(): void
    {
        db_connect()->table('feature_flag_overrides')->insert(['flag_key' => 'test.off', 'institution_id' => $this->institution, 'enabled' => 1, 'note' => 'Old pilot', 'ends_on' => '2020-01-01']);

        $body = $this->body($this->institutionUri());

        $this->assertStringContainsString('data-flag="test.off" data-flag-enabled="0"', $body);
        $this->assertStringContainsString('(ended, not applied)', $body);
    }

    public function testAnOverrideWithoutANoteIsRefusedAndWhatWasTypedIsKept(): void
    {
        $response = $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => '', 'ends_on' => '2099-05-05']);

        $this->assertStringEndsWith($this->institutionUri(), $this->location($response));
        $this->assertSame([], $this->rows());

        $body = $this->body($this->institutionUri());

        $this->assertStringContainsString('note is needed', $body);
        $this->assertSame(0, $this->auditCount('flag.override_changed'));
    }

    public function testAnOverrideCanBeRemoved(): void
    {
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot']);

        $this->submit($this->institutionUri('/test.off'), ['state' => 'default']);

        $this->assertSame([], $this->rows());
        $this->assertStringContainsString('data-flag="test.off" data-flag-enabled="0" data-flag-reason="default"', $this->body($this->institutionUri()));
    }

    public function testRepeatingTheSameSettingSaysNothingChanged(): void
    {
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot']);
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot']);

        $this->assertStringContainsString('No change', $this->body($this->institutionUri()));
        $this->assertSame(1, $this->auditCount('flag.override_changed'));
    }

    public function testAnotherInstitutionIsNotAffected(): void
    {
        db_connect()->table('institutions')->insert(['name' => 'Other University', 'code' => 'OTHER', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $other = (int) db_connect()->insertID();

        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot']);

        $this->assertStringContainsString('data-flag="test.off" data-flag-enabled="0" data-flag-reason="default"', $this->body("admin/institutions/{$other}/flags"));
    }

    public function testNotesAreEscaped(): void
    {
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => '<script>alert(1)</script>']);

        $body = $this->body($this->institutionUri());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | The whole journey
    |--------------------------------------------------------------------------
    */

    public function testOverrideThenKillThenRestoreAFlag(): void
    {
        $this->submit($this->institutionUri('/test.off'), ['state' => 'on', 'note' => 'Pilot']);
        $this->confirmedPassword();
        $this->submit('admin/flags/test.off/global', ['state' => 'off', 'note' => 'Problem found']);
        $this->submit('admin/flags/test.off/global', ['state' => 'default']);

        $this->assertStringContainsString('data-flag-reason="override_on"', $this->body($this->institutionUri()));

        $actions = array_column(db_connect()->table('audit_logs')->like('action', 'flag.', 'after')->orderBy('id')->get()->getResultArray(), 'action');

        $this->assertSame(['flag.override_changed', 'flag.global_changed', 'flag.global_changed'], $actions);
    }
}
