<?php

declare(strict_types=1);

use App\Services\Institutions\OnboardingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;

/**
 * Finishing onboarding: what completing it does (a subscription, an
 * invitation, an active institution), what it refuses, and what it records.
 * That a failure leaves nothing behind needs a real rollback, so it is tested
 * in OnboardingRollbackTest.
 *
 * @internal
 */
final class OnboardingServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private OnboardingService $service;

    private FakeEmail $mailer;

    private User $admin;

    private DateTimeImmutable $now;

    private int $institution;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->service = new OnboardingService();
        $this->mailer  = FakeEmail::install();
        $this->admin   = CourseFactory::user('admin');
        $this->now     = new DateTimeImmutable('2026-10-09 10:00:00');

        db_connect()->table('institutions')->insert(['name' => 'Acme University', 'code' => 'ACME', 'status' => 'pending']);
        $this->institution = (int) db_connect()->insertID();

        db_connect()->table('plans')->insert(['name' => 'Standard', 'status' => 'active']);
        $this->plan = (int) db_connect()->insertID();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** @return array<string, string> a valid subscription step */
    private function term(array $override = []): array
    {
        return array_merge(['plan_id' => (string) $this->plan, 'period_start' => '2026-10-01', 'period_end' => '2027-09-30', 'amount' => '', 'currency' => '', 'reference' => '', 'notes' => ''], $override);
    }

    /** @return array<string, string> */
    private function person(array $override = []): array
    {
        return array_merge(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com'], $override);
    }

    private function institutionStatus(): string
    {
        return db_connect()->table('institutions')->where('id', $this->institution)->get()->getRowArray()['status'];
    }

    private function rows(string $table): int
    {
        return db_connect()->table($table)->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | Completing it
    |--------------------------------------------------------------------------
    */

    public function testCompletingStartsTheSubscriptionInvitesTheAdministratorAndActivatesTheInstitution(): void
    {
        $result = $this->service->complete($this->institution, $this->term(), $this->person(), $this->admin, $this->now);

        $this->assertSame('active', $result['status']);
        $this->assertSame('active', $this->institutionStatus());
        $this->assertNull($result['email_error']);

        $subscription = db_connect()->table('subscriptions')->where('institution_id', $this->institution)->get()->getRowArray();
        $this->assertSame($this->plan, (int) $subscription['plan_id']);
        $this->assertSame('2027-09-30', $subscription['end_date']);
        $this->assertSame($result['subscription_id'], (int) $subscription['id']);
        $this->assertSame(1, $this->rows('subscription_renewals'));

        $invitation = db_connect()->table('institution_invitations')->get()->getRowArray();
        $this->assertSame('maria.santos@example.com', $invitation['email']);
        $this->assertSame($this->institution, (int) $invitation['institution_id']);
        $this->assertSame($result['invitation_id'], (int) $invitation['id']);
        $this->assertNull($invitation['accepted_at']);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('maria.santos@example.com', $this->mailer->sent[0]['to']);
        $this->assertMatchesRegularExpression('#/invitation/[A-Za-z0-9_-]{43}#', $this->mailer->sent[0]['message']);
    }

    public function testNoAccountExistsUntilTheLinkIsUsed(): void
    {
        $users = $this->rows('users');

        $this->service->complete($this->institution, $this->term(), $this->person(), $this->admin, $this->now);

        $this->assertSame($users, $this->rows('users'));
        $this->assertSame(0, $this->rows('institution_users'));
    }

    public function testEveryPartIsRecordedInTheAuditLog(): void
    {
        $this->service->complete($this->institution, $this->term(), $this->person(), $this->admin, $this->now);

        $actions = array_column(db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray(), 'action');

        $this->assertSame(['subscription.started', 'invitation.created', 'institution.onboarded'], $actions);

        $entry = db_connect()->table('audit_logs')->where('action', 'institution.onboarded')->get()->getRowArray();

        $this->assertSame($this->institution, (int) $entry['institution_id']);
        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
        $this->assertStringContainsString('pending', (string) $entry['old_values']);
        $this->assertStringContainsString('maria.santos@example.com', (string) $entry['new_values']);
    }

    public function testASubscriptionThatEndsTodayIsStillAllowedAndTheInstitutionIsActive(): void
    {
        $result = $this->service->complete($this->institution, $this->term(['period_start' => '2026-09-01', 'period_end' => '2026-10-09']), $this->person(), $this->admin, $this->now);

        $this->assertSame('active', $result['status'], 'the last day is still inside the subscription');
    }

    public function testAFailedEmailStillLeavesTheInstitutionOnboardedAndSaysSo(): void
    {
        $this->mailer->failToSend = true;

        $result = $this->service->complete($this->institution, $this->term(), $this->person(), $this->admin, $this->now);

        $this->assertNotNull($result['email_error']);
        $this->assertSame('active', $this->institutionStatus());
        $this->assertNull(db_connect()->table('institution_invitations')->get()->getRowArray()['sent_at'], 'so it can be re-sent');
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideRefusedSubscriptions(): iterable
    {
        yield 'an end date that has passed' => [['period_start' => '2025-01-01', 'period_end' => '2026-10-08'], 'already ended'];
        yield 'an end before the start' => [['period_start' => '2026-10-01', 'period_end' => '2026-09-01'], 'end date must be after'];
        yield 'no plan' => [['plan_id' => ''], 'Choose a plan'];
        yield 'a plan that does not exist' => [['plan_id' => '999999'], 'does not exist'];
        yield 'a period that is not a date' => [['period_end' => 'next year'], 'real date'];
    }

    /**
     * @param array<string, string> $override
     */
    #[PHPUnit\Framework\Attributes\DataProvider('provideRefusedSubscriptions')]
    public function testABadSubscriptionIsRefusedAndNothingIsSaved(array $override, string $mentions): void
    {
        try {
            $this->service->complete($this->institution, $this->term($override), $this->person(), $this->admin, $this->now);
            $this->fail('This subscription should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($mentions, $e->getMessage());
        }

        $this->assertSame('pending', $this->institutionStatus());
        $this->assertSame(0, $this->rows('subscriptions'));
        $this->assertSame(0, $this->rows('institution_invitations'));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testARetiredPlanCannotBeChosen(): void
    {
        db_connect()->table('plans')->where('id', $this->plan)->update(['status' => 'retired']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retired');

        $this->service->complete($this->institution, $this->term(), $this->person(), $this->admin, $this->now);
    }

    public function testBadAdministratorDetailsAreRefusedAndNothingIsSaved(): void
    {
        foreach ([['email' => 'nope'], ['first_name' => ''], ['last_name' => 'S']] as $override) {
            try {
                $this->service->complete($this->institution, $this->term(), $this->person($override), $this->admin, $this->now);
                $this->fail('These details should have been refused.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame('pending', $this->institutionStatus());
        $this->assertSame(0, $this->rows('subscriptions'));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAnInstitutionThatIsAlreadyOnboardedCannotBeOnboardedAgain(): void
    {
        $this->service->complete($this->institution, $this->term(), $this->person(), $this->admin, $this->now);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been onboarded');

        $this->service->complete($this->institution, $this->term(), $this->person(['email' => 'other@example.com']), $this->admin, $this->now);
    }

    public function testAnUnknownOrDeletedInstitutionCannotBeOnboarded(): void
    {
        try {
            $this->service->complete(999999, $this->term(), $this->person(), $this->admin, $this->now);
            $this->fail('An unknown institution should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        db_connect()->table('institutions')->where('id', $this->institution)->update(['deleted_at' => '2026-10-01 00:00:00']);

        $this->expectException(InvalidArgumentException::class);

        $this->service->complete($this->institution, $this->term(), $this->person(), $this->admin, $this->now);
    }

    public function testCheckingTheSubscriptionStepWritesNothing(): void
    {
        $term = $this->service->checkSubscription($this->institution, $this->term(['amount' => '1,500.5']), $this->now);

        $this->assertSame('1500.50', $term['amount']);
        $this->assertSame($this->plan, $term['plan_id']);
        $this->assertSame(0, $this->rows('subscriptions'));
        $this->assertSame(0, $this->rows('audit_logs'));
    }

    public function testTheSubscriptionStepIsOnlyForAPendingInstitution(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been onboarded');

        $this->service->checkSubscription($this->institution, $this->term(), $this->now);
    }
}
