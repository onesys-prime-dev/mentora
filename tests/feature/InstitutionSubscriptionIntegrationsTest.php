<?php

declare(strict_types=1);

use App\Services\Sis\SisConnectors;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The subscription page (read only) and the integrations page (what the SIS
 * did and the runs the administrator may start): access, no connection
 * settings, folders or credentials in anything returned, the password in
 * front of a real import and of sending grades, and scoping.
 *
 * @internal
 */
final class InstitutionSubscriptionIntegrationsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    private User $root;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->folder      = SisFixture::useFolder();
        $this->institution = SisFixture::institution('SIPA');
        $this->other       = SisFixture::institution('SIPB');
        $this->root        = PeopleFactory::person('admin', 'Root', 'Admin', 'root@sip.example');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@sip.example', $this->institution);

        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);

        parent::tearDown();
    }

    private function signIn(User $user, bool $confirmed = true): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);

        if ($confirmed) {
            $_SESSION['reauth'] = ['user' => $user->id, 'at' => time()];
        } else {
            unset($_SESSION['reauth']);
        }

        $this->withSession();
    }

    private function subscribe(string $plan = 'Standard', ?int $seats = 100, string $end = '+6 months'): int
    {
        $planId = PeopleFactory::plan($plan, $seats);

        db_connect()->table('subscriptions')->insert([
            'institution_id' => $this->institution, 'plan_id' => $planId,
            'start_date' => date('Y-m-d', strtotime('-1 month')), 'end_date' => date('Y-m-d', strtotime($end)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseThem(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@sip.example", $role === 'admin' ? null : $this->institution));

        foreach (['subscription', 'integrations'] as $page) {
            $this->assertSame(302, $this->step('GET', "institution/{$page}")->response()->getStatusCode(), $page);
        }

        $this->assertSame(302, $this->step('POST', 'institution/integrations/preview')->response()->getStatusCode());
    }

    /*
    |--------------------------------------------------------------------------
    | Subscription
    |--------------------------------------------------------------------------
    */

    public function testTheSubscriptionShowsThePlanPeriodStateAndUse(): void
    {
        $this->subscribe('Campus', 100);

        $body = $this->step('GET', 'institution/subscription')->response()->getBody();

        $this->assertStringContainsString('Campus', $body);
        $this->assertStringContainsString('data-subscription', $body);
        $this->assertStringContainsString('Running', $body);
        $this->assertStringContainsString('data-usage', $body);
        $this->assertStringContainsString('Student seats', $body);
        $this->assertStringContainsString('Storage', $body);
    }

    public function testTheSubscriptionOffersNoActionAndNamesNoStaff(): void
    {
        $id = $this->subscribe();
        db_connect()->table('subscription_renewals')->insert([
            'subscription_id' => $id, 'plan_id' => (int) db_connect()->table('subscriptions')->where('id', $id)->get()->getRowArray()['plan_id'],
            'kind' => 'start', 'period_start' => date('Y-m-d', strtotime('-1 month')), 'period_end' => date('Y-m-d', strtotime('+6 months')),
            'amount' => '1200.00', 'currency' => 'PHP', 'reference' => 'INV-77', 'recorded_by_label' => 'staff.person@mentora.example', 'recorded_at' => date('Y-m-d H:i:s'),
        ]);

        $body = $this->step('GET', 'institution/subscription')->response()->getBody();

        $this->assertStringContainsString('INV-77', $body);
        $this->assertStringContainsString('1,200.00', $body);
        $this->assertStringNotContainsString('staff.person@mentora.example', $body, 'who recorded it is the vendor\'s staff');
        $this->assertStringNotContainsString('<form', preg_replace('/<form[^>]*logout[^>]*>/', '', $body) ?? $body, 'nothing to submit on this page');
        $this->assertStringNotContainsString('Record a renewal', $body);
    }

    public function testTheTimelineStateIsSaid(): void
    {
        $this->subscribe('Standard', 100, '+5 days');

        $this->assertStringContainsString('Expiring soon', $this->step('GET', 'institution/subscription')->response()->getBody());
    }

    public function testAnInstitutionWithNoSubscriptionSaysSo(): void
    {
        $this->assertStringContainsString('No subscription yet', $this->step('GET', 'institution/subscription')->response()->getBody());
    }

    public function testAnotherInstitutionsSubscriptionIsNeverShown(): void
    {
        $planId = PeopleFactory::plan('Secret Plan', 5);
        db_connect()->table('subscriptions')->insert(['institution_id' => $this->other, 'plan_id' => $planId, 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+1 year')), 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertStringNotContainsString('Secret Plan', $this->step('GET', 'institution/subscription')->response()->getBody());
    }

    public function testAReadOnlyInstitutionCanStillReadIt(): void
    {
        $this->subscribe();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(200, $this->step('GET', 'institution/subscription')->response()->getStatusCode());
    }

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    */

    public function testWithoutTheFeatureItSaysNotAvailableAndOffersNoRun(): void
    {
        $body = $this->step('GET', 'institution/integrations')->response()->getBody();

        $this->assertStringContainsString('Not available', $body);
        $this->assertStringNotContainsString('data-sis-run', $body);
        $this->assertStringContainsString('Nothing has run yet', $body);
    }

    public function testAConnectedInstitutionShowsStateRunsAndNoConnectionSettings(): void
    {
        SisFixture::connect($this->institution, $this->root);
        SisFixture::dropAll($this->institution);

        $body = $this->step('GET', 'institution/integrations')->response()->getBody();

        $this->assertStringContainsString('Connected', $body);
        $this->assertStringContainsString('data-sis-preview', $body);
        $this->assertStringContainsString('data-grades-preview', $body);

        $folders = SisConnectors::folders($this->institution);

        foreach ([$folders['inbound'], $folders['outbound'], SisConnectors::baseFolder(), 'Credentials are saved', 'Remove the saved credentials', 'Inbound folder', 'Outbound folder', 'signing'] as $secret) {
            $this->assertStringNotContainsString($secret, html_entity_decode($body), $secret);
        }
    }

    public function testAPreviewRunsAndTheLatestResultIsShownWithItsRefusedRows(): void
    {
        SisFixture::connect($this->institution, $this->root);
        SisFixture::dropAll($this->institution);

        $this->step('POST', 'institution/integrations/preview');

        $body = $this->step('GET', 'institution/integrations')->response()->getBody();

        $this->assertStringContainsString('Preview done', $body);
        $this->assertStringContainsString('data-latest-import', $body);
        $this->assertStringContainsString('Latest preview', $body);
        $this->assertStringContainsString('data-issues', $body, 'the bad email row is listed');
    }

    public function testARealImportNeedsThePasswordAndAPreviewFirst(): void
    {
        SisFixture::connect($this->institution, $this->root);
        SisFixture::dropAll($this->institution);

        $this->signIn($this->admin, false);
        $refused = $this->step('POST', 'institution/integrations/run');

        $this->assertStringEndsWith('/reauth', $refused->response()->getHeaderLine('Location'));
        $this->assertSame(0, db_connect()->table('sis_runs')->where(['institution_id' => $this->institution, 'dry_run' => 0])->countAllResults());

        $this->signIn($this->admin, true);
        $this->step('POST', 'institution/integrations/run');

        $this->assertStringContainsString('preview', strtolower((string) session('error')), 'a preview comes first');
    }

    public function testAfterAPreviewTheImportRunsAndIsLogged(): void
    {
        SisFixture::connect($this->institution, $this->root);
        SisFixture::dropAll($this->institution);

        $this->step('POST', 'institution/integrations/preview');
        $this->step('POST', 'institution/integrations/run');

        $this->assertSame(1, db_connect()->table('sis_runs')->where(['institution_id' => $this->institution, 'dry_run' => 0, 'status' => 'succeeded'])->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.import_run')->countAllResults());
    }

    public function testSendingGradesNeedsThePassword(): void
    {
        SisFixture::connect($this->institution, $this->root);

        $this->signIn($this->admin, false);
        $refused = $this->step('POST', 'institution/integrations/grades/send');

        $this->assertStringEndsWith('/reauth', $refused->response()->getHeaderLine('Location'));

        $this->signIn($this->admin, true);
        $this->step('POST', 'institution/integrations/grades/preview');

        $this->assertSame(1, db_connect()->table('sis_runs')->where(['institution_id' => $this->institution, 'direction' => 'outbound', 'dry_run' => 1])->countAllResults());
    }

    public function testTheHistoryHidesConnectionTestsAndNeverNamesAFolder(): void
    {
        SisFixture::connect($this->institution, $this->root);
        $folders = SisConnectors::folders($this->institution);

        $now = date('Y-m-d H:i:s');
        $db  = db_connect();

        // The system administrator's connection test names the folders; a failed import might too.
        $db->table('sis_runs')->insert(['institution_id' => $this->institution, 'direction' => 'inbound', 'trigger' => 'test', 'status' => 'failed', 'dry_run' => 0, 'message' => 'The inbound folder does not exist yet: ' . $folders['inbound'], 'started_at' => $now]);
        $db->table('sis_runs')->insert(['institution_id' => $this->institution, 'direction' => 'inbound', 'trigger' => 'scheduled', 'status' => 'failed', 'dry_run' => 0, 'message' => 'Could not read ' . $folders['inbound'] . '/students.csv', 'started_at' => $now]);

        $body = html_entity_decode($this->step('GET', 'institution/integrations')->response()->getBody());

        $this->assertStringNotContainsString($folders['inbound'], $body);
        $this->assertStringNotContainsString('does not exist yet', $body, 'the test run is not listed');
        $this->assertStringContainsString('the shared folder', $body, 'the failed import is listed with the folder taken out');
    }

    public function testAnotherInstitutionsRunsAreNeverShown(): void
    {
        SisFixture::connect($this->institution, $this->root);
        db_connect()->table('sis_runs')->insert(['institution_id' => $this->other, 'direction' => 'inbound', 'trigger' => 'manual', 'status' => 'succeeded', 'dry_run' => 0, 'message' => 'Only the other school knows this', 'created_count' => 99, 'started_at' => date('Y-m-d H:i:s')]);

        $this->assertStringNotContainsString('Only the other school knows this', $this->step('GET', 'institution/integrations')->response()->getBody());
    }

    public function testAReadOnlyInstitutionCannotRunAnything(): void
    {
        SisFixture::connect($this->institution, $this->root);
        SisFixture::dropAll($this->institution);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $page = $this->step('GET', 'institution/integrations')->response()->getBody();
        $this->assertMatchesRegularExpression('/data-sis-run[^>]*disabled|disabled[^>]*data-sis-run/', $page);

        $this->step('POST', 'institution/integrations/preview');

        $this->assertSame(0, db_connect()->table('sis_runs')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testTheTilesLeadHere(): void
    {
        $dashboard = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        foreach (['subscription', 'integrations'] as $tile) {
            $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/' . $tile . '"[^>]*data-app-tile="' . $tile . '"/s', $dashboard), $tile);
        }
    }
}
