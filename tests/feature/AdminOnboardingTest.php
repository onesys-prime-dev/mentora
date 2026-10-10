<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\RouteTestCase;

/**
 * Onboarding an institution as a system administrator: add it, choose its
 * plan and subscription, invite its first administrator. Covers the three
 * steps, what is saved and when, what is refused, and that stopping halfway
 * leaves an institution that nobody can use.
 *
 * @internal
 */
final class AdminOnboardingTest extends RouteTestCase
{
    private User $admin;

    private FakeEmail $mailer;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->mailer = FakeEmail::install();
        $this->admin  = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('plans')->insert(['name' => 'Standard', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $this->plan = (int) db_connect()->insertID();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function seeded(string $code = 'ACME', string $status = 'pending', ?string $name = null): int
    {
        db_connect()->table('institutions')->insert(['name' => $name ?? "Institution {$code}", 'code' => $code, 'status' => $status, 'created_at' => date('Y-m-d H:i:s')]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, string> a valid subscription step */
    private function term(array $override = []): array
    {
        return array_merge([
            'plan_id' => (string) $this->plan, 'period_start' => date('Y-m-d', strtotime('-1 month')), 'period_end' => date('Y-m-d', strtotime('+11 months')),
            'amount' => '', 'currency' => 'PHP', 'reference' => '', 'notes' => '',
        ], $override);
    }

    /** @return array<string, string> */
    private function person(array $override = []): array
    {
        return array_merge(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com'], $override);
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

    private function institutionStatus(int $id): string
    {
        return db_connect()->table('institutions')->where('id', $id)->get()->getRowArray()['status'];
    }

    private function rows(string $table): int
    {
        return db_connect()->table($table)->countAllResults();
    }

    /** Takes the institution through the subscription step so that the administrator step is open. */
    private function throughTheSubscriptionStep(int $id): void
    {
        $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term());
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
    public function testNobodyElseCanOnboardAnInstitution(string $role): void
    {
        $id = $this->seeded();

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        foreach (['', '/subscription', '/administrator'] as $suffix) {
            $this->assertSame(302, $this->step('GET', "admin/institutions/{$id}/onboarding{$suffix}")->response()->getStatusCode(), "{$role} must not open onboarding{$suffix}");
        }

        $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term());
        $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person());

        $this->assertSame('pending', $this->institutionStatus($id));
        $this->assertSame(0, $this->rows('subscriptions'));
        $this->assertSame(0, $this->rows('institution_invitations'));
        $this->assertSame([], $this->mailer->sent);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1 leads on, step 2 is the subscription
    |--------------------------------------------------------------------------
    */

    public function testTheAddFormShowsTheStepsAndLeadsIntoTheNextOne(): void
    {
        $body = $this->body('admin/institutions/new');

        $this->assertStringContainsString('data-onboarding-steps', $body);
        $this->assertMatchesRegularExpression('/data-step="1" data-state="current"/', $body);

        $response = $this->submit('admin/institutions', ['name' => 'Acme University', 'code' => 'acme']);
        $id       = (int) db_connect()->table('institutions')->where('code', 'ACME')->get()->getRowArray()['id'];

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/subscription", $this->location($response));
    }

    public function testTheSubscriptionStepOffersThePlansThatCanBeChosen(): void
    {
        db_connect()->table('plans')->insert(['name' => 'Old Plan', 'status' => 'retired', 'created_at' => date('Y-m-d H:i:s')]);
        $id = $this->seeded('ACME', 'pending', 'Acme University');

        $body = $this->body("admin/institutions/{$id}/onboarding/subscription");

        $this->assertStringContainsString('Onboard Acme University', $body);
        $this->assertMatchesRegularExpression('/data-step="2" data-state="current"/', $body);
        $this->assertMatchesRegularExpression('/data-step="1" data-state="done"/', $body);
        $this->assertStringContainsString('>Standard<', $body);
        $this->assertStringNotContainsString('Old Plan', $body);
    }

    public function testTheSubscriptionStepSaysSoWhenThereIsNoPlanToChoose(): void
    {
        db_connect()->table('plans')->update(['status' => 'retired']);
        $id = $this->seeded();

        $this->assertStringContainsString('no plan to choose from', $this->body("admin/institutions/{$id}/onboarding/subscription"));
    }

    /** @return iterable<string, array{string}> */
    public static function provideOnboardingPages(): iterable
    {
        yield 'the entry point' => [''];
        yield 'the subscription step' => ['/subscription'];
        yield 'the administrator step' => ['/administrator'];
    }

    #[DataProvider('provideOnboardingPages')]
    public function testAnUnknownInstitutionIsNotFound(string $suffix): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', "admin/institutions/999999/onboarding{$suffix}");
    }

    public function testAnInstitutionThatIsAlreadyOnboardedIsSentToItsProfile(): void
    {
        $id = $this->seeded('DONE', 'active', 'Finished College');

        foreach (['', '/subscription', '/administrator'] as $suffix) {
            $response = $this->step('GET', "admin/institutions/{$id}/onboarding{$suffix}");

            $this->assertStringEndsWith("admin/institutions/{$id}", $this->location($response), $suffix);
        }

        $this->assertStringContainsString('Finished College has already been onboarded', $this->body("admin/institutions/{$id}"));

        $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term());
        $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person());

        $this->assertSame(0, $this->rows('subscriptions'));
        $this->assertSame(0, $this->rows('institution_invitations'));
    }

    public function testBadSubscriptionAnswersGoBackWithTheReasonAndTheInput(): void
    {
        $id = $this->seeded();

        $response = $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term(['period_end' => '2020-01-01', 'period_start' => '2019-01-01', 'reference' => 'INV-77']));

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/subscription", $this->location($response));

        $body = $this->body("admin/institutions/{$id}/onboarding/subscription");

        $this->assertStringContainsString('already ended', $body);
        $this->assertStringContainsString('value="INV-77"', $body, 'what was typed is kept');
        $this->assertSame(0, $this->rows('subscriptions'));

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/subscription", $this->location($this->step('GET', "admin/institutions/{$id}/onboarding/administrator")), 'nothing was kept for the next step');
    }

    public function testARetiredPlanIsRefusedOnTheSubscriptionStep(): void
    {
        db_connect()->table('plans')->where('id', $this->plan)->update(['status' => 'retired']);
        $id = $this->seeded();

        $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term());

        $this->assertStringContainsString('retired', $this->body("admin/institutions/{$id}/onboarding/subscription"));
    }

    public function testAGoodSubscriptionStepIsKeptForTheLastStepButNothingIsSavedYet(): void
    {
        $id = $this->seeded();

        $response = $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term());

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/administrator", $this->location($response));
        $this->assertSame('pending', $this->institutionStatus($id));
        $this->assertSame(0, $this->rows('subscriptions'));
        $this->assertSame(0, $this->rows('subscription_renewals'));
    }

    public function testGoingBackToTheSubscriptionStepShowsWhatWasChosen(): void
    {
        $id = $this->seeded();
        $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term(['reference' => 'INV-42']));

        $body = $this->body("admin/institutions/{$id}/onboarding/subscription");

        $this->assertStringContainsString('value="INV-42"', $body);
        $this->assertMatchesRegularExpression('/<option value="' . $this->plan . '" selected>/', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3 is the administrator
    |--------------------------------------------------------------------------
    */

    public function testTheAdministratorStepNeedsTheSubscriptionStepFirst(): void
    {
        $id = $this->seeded();

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/subscription", $this->location($this->step('GET', "admin/institutions/{$id}/onboarding/administrator")));

        $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person());

        $this->assertSame('pending', $this->institutionStatus($id));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testTheAdministratorStepSummarisesTheSubscription(): void
    {
        $id = $this->seeded('ACME', 'pending', 'Acme University');
        $this->submit("admin/institutions/{$id}/onboarding/subscription", $this->term(['period_start' => '2026-10-01', 'period_end' => '2027-09-30']));

        $body = $this->body("admin/institutions/{$id}/onboarding/administrator");

        $this->assertMatchesRegularExpression('/data-step="3" data-state="current"/', $body);
        $this->assertStringContainsString('data-onboarding-summary', $body);
        $this->assertStringContainsString('<strong>Standard</strong>', $body);
        $this->assertStringContainsString('2026-10-01', $body);
        $this->assertStringContainsString('2027-09-30', $body);
        $this->assertStringContainsString('expires after 7 days', $body);
    }

    public function testOneInstitutionsAnswersAreNotUsedForAnother(): void
    {
        $a = $this->seeded('AAA');
        $b = $this->seeded('BBB');
        $this->throughTheSubscriptionStep($a);

        $this->assertStringEndsWith("admin/institutions/{$b}/onboarding/subscription", $this->location($this->step('GET', "admin/institutions/{$b}/onboarding/administrator")));
    }

    public function testPickingUpWhereTheyLeftOff(): void
    {
        $id = $this->seeded();

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/subscription", $this->location($this->step('GET', "admin/institutions/{$id}/onboarding")));

        $this->throughTheSubscriptionStep($id);

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/administrator", $this->location($this->step('GET', "admin/institutions/{$id}/onboarding")));
    }

    /*
    |--------------------------------------------------------------------------
    | Finishing
    |--------------------------------------------------------------------------
    */

    public function testTheWholeJourneyOnboardsTheInstitutionAndInvitesTheAdministrator(): void
    {
        $this->submit('admin/institutions', ['name' => 'Acme University', 'code' => 'acme']);
        $id = (int) db_connect()->table('institutions')->where('code', 'ACME')->get()->getRowArray()['id'];

        $this->throughTheSubscriptionStep($id);
        $response = $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person());

        $this->assertStringEndsWith("admin/institutions/{$id}", $this->location($response));
        $this->assertSame('active', $this->institutionStatus($id));
        $this->assertSame(1, $this->rows('subscriptions'));
        $this->assertSame(1, $this->rows('institution_invitations'));

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('maria.santos@example.com', $this->mailer->sent[0]['to']);

        $profile = $this->body("admin/institutions/{$id}");

        $this->assertStringContainsString('Acme University is onboarded', $profile);
        $this->assertStringContainsString('maria.santos@example.com', $profile);
        $this->assertStringContainsString('data-invitation-status="pending"', $profile);
        $this->assertStringNotContainsString('data-onboarding-pending', $profile);

        $actions = array_column(db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray(), 'action');
        $this->assertSame(['institution.created', 'subscription.started', 'invitation.created', 'institution.onboarded'], $actions);
    }

    public function testFinishingTwiceDoesNotInviteTwice(): void
    {
        $id = $this->seeded();
        $this->throughTheSubscriptionStep($id);
        $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person());

        $again = $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person(['email' => 'second@example.com']));

        $this->assertStringEndsWith("admin/institutions/{$id}", $this->location($again));
        $this->assertSame(1, $this->rows('subscriptions'));
        $this->assertSame(1, $this->rows('institution_invitations'));
        $this->assertCount(1, $this->mailer->sent);
    }

    public function testBadAdministratorDetailsGoBackWithTheReasonAndSaveNothing(): void
    {
        $id = $this->seeded();
        $this->throughTheSubscriptionStep($id);

        $response = $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person(['email' => 'not-an-email', 'first_name' => 'Maria José']));

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding/administrator", $this->location($response));

        $body = $this->body("admin/institutions/{$id}/onboarding/administrator");

        $this->assertStringContainsString('valid email address', $body);
        $this->assertStringContainsString('value="Maria José"', $body, 'what was typed is kept');
        $this->assertSame('pending', $this->institutionStatus($id));
        $this->assertSame(0, $this->rows('subscriptions'));
        $this->assertSame(0, $this->rows('institution_invitations'));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAnAddressThatAlreadyHasAnAccountIsRefusedAndNothingIsSaved(): void
    {
        $existing = CourseFactory::user('student');
        db_connect()->table('auth_identities')->insert(['user_id' => $existing->id, 'type' => 'email_password', 'secret' => 'taken@example.com', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        $id = $this->seeded();
        $this->throughTheSubscriptionStep($id);

        $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person(['email' => 'taken@example.com']));

        $this->assertStringContainsString('already exists', $this->body("admin/institutions/{$id}/onboarding/administrator"));
        $this->assertSame('pending', $this->institutionStatus($id));
        $this->assertSame([], $this->mailer->sent);

        // That the subscription started a moment earlier is undone too is checked in
        // OnboardingRollbackTest: this test runs inside a transaction of its own, where
        // a rollback cannot be seen.
    }

    public function testAFailedEmailStillOnboardsAndTheAdministratorIsToldWhatToDo(): void
    {
        $this->mailer->failToSend = true;

        $id = $this->seeded();
        $this->throughTheSubscriptionStep($id);
        $this->submit("admin/institutions/{$id}/onboarding/administrator", $this->person());

        $this->assertSame('active', $this->institutionStatus($id));

        $profile = $this->body("admin/institutions/{$id}");

        $this->assertStringContainsString('email could not be sent', $profile);
        $this->assertStringContainsString('Resend invitation', $this->body("admin/institutions/{$id}/users"));
    }

    /*
    |--------------------------------------------------------------------------
    | Stopping halfway
    |--------------------------------------------------------------------------
    */

    public function testStoppingHalfwayLeavesAnInstitutionNobodyCanUse(): void
    {
        $this->submit('admin/institutions', ['name' => 'Half University', 'code' => 'half']);
        $id = (int) db_connect()->table('institutions')->where('code', 'HALF')->get()->getRowArray()['id'];
        $this->throughTheSubscriptionStep($id);

        // ...and then they close the tab.

        $this->assertSame('pending', $this->institutionStatus($id));
        $this->assertSame(0, $this->rows('subscriptions'), 'no subscription');
        $this->assertSame(0, $this->rows('subscription_renewals'));
        $this->assertSame(0, $this->rows('institution_invitations'), 'no invitation, so no link that works');
        $this->assertSame(0, db_connect()->table('institution_users')->where('institution_id', $id)->countAllResults(), 'no one belongs to it');
        $this->assertSame([], $this->mailer->sent);

        $list = $this->body('admin/institutions');

        $this->assertStringContainsString('Half University', $list);
        $this->assertStringContainsString("admin/institutions/{$id}/onboarding", $list, 'and it can be finished later');
    }

    /*
    |--------------------------------------------------------------------------
    | The subscription page and onboarding
    |--------------------------------------------------------------------------
    */

    public function testThePlainSubscriptionPageSendsAPendingInstitutionToOnboarding(): void
    {
        $id = $this->seeded();

        $page = $this->body("admin/institutions/{$id}/subscription");

        $this->assertStringContainsString('data-onboarding-pending', $page);
        $this->assertStringNotContainsString('Start subscription', $page);

        $response = $this->submit("admin/institutions/{$id}/subscription", $this->term());

        $this->assertStringEndsWith("admin/institutions/{$id}/onboarding", $this->location($response));
        $this->assertSame(0, $this->rows('subscriptions'), 'a pending institution is not started there');
    }
}
