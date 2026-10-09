<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * An institution's subscription page as a system administrator uses it, and
 * as everyone else is refused: start a subscription, record renewals, correct
 * an earlier record, and read the history.
 *
 * @internal
 */
final class AdminSubscriptionPageTest extends RouteTestCase
{
    private User $admin;

    private int $institution;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('institutions')->insert(['name' => 'Acme University', 'code' => 'ACME', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
        $this->institution = (int) db_connect()->insertID();

        db_connect()->table('plans')->insert(['name' => 'Standard', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $this->plan = (int) db_connect()->insertID();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        service('superglobals')->setGetArray([]);

        parent::tearDown();
    }

    private function uri(string $suffix = ''): string
    {
        return "admin/institutions/{$this->institution}/subscription{$suffix}";
    }

    /** @return array<string, string> a valid term form */
    private function term(array $override = []): array
    {
        return array_merge([
            'plan_id' => (string) $this->plan, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'amount' => '', 'currency' => 'PHP', 'reference' => '', 'notes' => '',
        ], $override);
    }

    /** @param array<string, string> $data */
    private function submit(string $uri, array $data = []): TestResponse
    {
        return $this->step('POST', $uri, $data);
    }

    private function body(?string $uri = null): string
    {
        return $this->step('GET', $uri ?? $this->uri())->response()->getBody();
    }

    private function location(TestResponse $response): string
    {
        return $response->response()->getHeaderLine('Location');
    }

    /** @return array<string, mixed>|null */
    private function subscription(): ?array
    {
        return db_connect()->table('subscriptions')->where('institution_id', $this->institution)->get()->getRowArray();
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return db_connect()->table('subscription_renewals')->orderBy('id')->get()->getResultArray();
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
    public function testNobodyElseCanSeeOrChangeASubscription(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', $this->uri())->response()->getStatusCode());

        $this->submit($this->uri(), $this->term());
        $this->submit($this->uri('/renew'), $this->term(['period_end' => '2027-12-31']));

        $this->assertNull($this->subscription());
        $this->assertSame([], $this->rows());
    }

    public function testAMissingInstitutionIsNotFound(): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', 'admin/institutions/999999/subscription');
    }

    /*
    |--------------------------------------------------------------------------
    | Start
    |--------------------------------------------------------------------------
    */

    public function testAnInstitutionWithNoSubscriptionOffersToStartOne(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringContainsString('Start a subscription', $body);
        $this->assertStringContainsString('Standard', $body, 'the plan is offered');
        $this->assertStringNotContainsString('Record a renewal', $body);
    }

    public function testStartingCreatesTheSubscriptionAndShowsIt(): void
    {
        $response = $this->submit($this->uri(), $this->term(['amount' => '120000', 'reference' => 'INV-1']));

        $this->assertStringEndsWith($this->uri(), $this->location($response));
        $this->assertSame('2026-12-31', $this->subscription()['end_date']);
        $this->assertSame(1, $this->auditCount('subscription.started'));

        $body = $this->body();

        $this->assertStringContainsString('Subscription started', $body);
        $this->assertStringContainsString('2026-01-01', $body);
        $this->assertStringContainsString('2026-12-31', $body);
        $this->assertStringContainsString('PHP 120,000.00', $body);
        $this->assertStringContainsString('INV-1', $body);
        $this->assertStringContainsString('Record a renewal', $body);
        $this->assertStringNotContainsString('Start a subscription', $body);
    }

    public function testStartingWithBadDetailsShowsTheMessageAndKeepsTheInput(): void
    {
        $this->submit($this->uri(), $this->term(['period_end' => '2025-01-01', 'reference' => 'KEEP-ME']));

        $this->assertNull($this->subscription());

        $body = $this->body();

        $this->assertStringContainsString('after the start date', $body);
        $this->assertStringContainsString('KEEP-ME', $body, 'what was typed is not lost');
    }

    public function testASecondStartIsRefused(): void
    {
        $this->submit($this->uri(), $this->term());
        $this->submit($this->uri(), $this->term(['period_start' => '2027-01-01', 'period_end' => '2027-12-31']));

        $this->assertSame('2026-12-31', $this->subscription()['end_date']);
        $this->assertCount(1, $this->rows());
    }

    /*
    |--------------------------------------------------------------------------
    | Renew
    |--------------------------------------------------------------------------
    */

    public function testRenewingExtendsTheEndDateAndGrowsTheHistory(): void
    {
        $this->submit($this->uri(), $this->term());

        $this->submit($this->uri('/renew'), $this->term(['period_start' => '2027-01-01', 'period_end' => '2027-12-31', 'amount' => '130000']));

        $this->assertSame('2027-12-31', $this->subscription()['end_date']);
        $this->assertCount(2, $this->rows());
        $this->assertSame(1, $this->auditCount('subscription.renewed'));

        $body = $this->body();

        $this->assertStringContainsString('Renewal recorded', $body);
        $this->assertStringContainsString('PHP 130,000.00', $body);
        $this->assertLessThan(strpos($body, '2026-01-01 &rarr; 2026-12-31'), strpos($body, '2027-01-01 &rarr; 2027-12-31'), 'newest first');
    }

    public function testRenewingWithAnEndDateThatDoesNotExtendIsRefusedWithTheReason(): void
    {
        $this->submit($this->uri(), $this->term());

        $this->submit($this->uri('/renew'), $this->term(['period_start' => '2026-03-01', 'period_end' => '2026-06-30']));

        $this->assertSame('2026-12-31', $this->subscription()['end_date']);
        $this->assertCount(1, $this->rows());
        $this->assertStringContainsString('must end after the current end date', $this->body());
    }

    public function testRenewingWithoutASubscriptionIsRefused(): void
    {
        $this->submit($this->uri('/renew'), $this->term());

        $this->assertNull($this->subscription());
        $this->assertStringContainsString('no subscription yet', $this->body());
    }

    public function testARetiredPlanStaysSelectableForTheInstitutionUsingIt(): void
    {
        $this->submit($this->uri(), $this->term());
        db_connect()->table('plans')->where('id', $this->plan)->update(['status' => 'retired']);

        $body = $this->body();

        $this->assertStringContainsString('Standard (retired)', $body);
        $this->assertStringContainsString('Plan retired', $body);
    }

    public function testARetiredPlanIsNotOfferedForANewSubscription(): void
    {
        db_connect()->table('plans')->where('id', $this->plan)->update(['status' => 'retired']);

        $this->assertStringNotContainsString('>Standard<', $this->body());

        $this->submit($this->uri(), $this->term());

        $this->assertNull($this->subscription());
    }

    public function testTheStatusShowsWhetherItIsStillRunning(): void
    {
        $this->submit($this->uri(), $this->term(['period_start' => '2025-01-01', 'period_end' => '2025-12-31']));

        $this->assertStringContainsString('>Expired<', $this->body());

        $this->submit($this->uri('/renew'), $this->term(['period_start' => '2026-01-01', 'period_end' => date('Y-m-d', strtotime('+1 year'))]));

        $this->assertStringContainsString('Running', $this->body());
    }

    /** @return iterable<string, array{string, string, string}> end date relative to today, badge, sentence */
    public static function provideTimelineStates(): iterable
    {
        yield 'far from the end' => ['+200 days', '>Running<', 'days until the end date'];
        yield 'close to the end' => ['+10 days', '>Expiring soon<', 'Ends in 10 days'];
        yield 'ends today' => ['+0 days', '>Expiring soon<', 'Ends today'];
        yield 'just ended' => ['-3 days', '>Grace period<', 'Full access continues until'];
        yield 'after grace' => ['-30 days', '>Read-only<', 'Read-only until'];
        yield 'long after' => ['-200 days', '>Expired<', 'a renewal brings it back'];
    }

    #[DataProvider('provideTimelineStates')]
    public function testThePageShowsWhereTheSubscriptionIsOnItsTimeline(string $end, string $badge, string $sentence): void
    {
        $this->submit($this->uri(), $this->term(['period_start' => '2020-01-01', 'period_end' => date('Y-m-d', strtotime($end))]));

        $body = $this->body();

        $this->assertStringContainsString($badge, $body);
        $this->assertStringContainsString($sentence, $body);
    }

    public function testRenewingAPastInstitutionShowsItActiveAgainInTheList(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);
        $this->submit($this->uri(), $this->term(['period_start' => '2020-01-01', 'period_end' => '2020-12-31']));

        $this->submit($this->uri('/renew'), $this->term(['period_start' => '2021-01-01', 'period_end' => date('Y-m-d', strtotime('+1 year'))]));

        $this->assertSame('active', db_connect()->table('institutions')->where('id', $this->institution)->get()->getRowArray()['status']);
        $this->assertStringContainsString('>Running<', $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | Usage
    |--------------------------------------------------------------------------
    */

    private function planLimits(?int $seats, ?int $storageMb, ?int $ai): void
    {
        db_connect()->table('plans')->where('id', $this->plan)->update(['seat_limit' => $seats, 'storage_limit_mb' => $storageMb, 'ai_generation_limit' => $ai]);
    }

    private function students(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $student = CourseFactory::user('student');
            db_connect()->table('institution_users')->insert(['institution_id' => $this->institution, 'user_id' => $student->id]);
        }
    }

    public function testThereIsNoUsagePanelBeforeASubscriptionStarts(): void
    {
        $this->assertStringNotContainsString('data-usage', $this->body());
    }

    public function testTheUsagePanelShowsEachLimitAndHowFullItIs(): void
    {
        $this->planLimits(10, 100, 200);
        $this->students(3);
        $this->submit($this->uri(), $this->term());

        $body = $this->body();

        $this->assertStringContainsString('data-usage', $body);
        $this->assertStringContainsString('3 of 10 (30%)', $body);
        $this->assertStringContainsString('0 MB of 100 MB (0%)', $body);
        $this->assertStringContainsString('0 of 200 (0%)', $body);
        $this->assertSame(3, substr_count($body, 'data-usage-line="ok"'));
    }

    public function testNoLimitShowsUnlimitedWithoutABar(): void
    {
        $this->planLimits(null, null, null);
        $this->submit($this->uri(), $this->term());

        $body = $this->body();

        $this->assertSame(3, substr_count($body, 'data-usage-line="unlimited"'));
        $this->assertStringContainsString('of unlimited', $body);
        $this->assertStringNotContainsString('role="progressbar"', $body);
    }

    public function testFromNinetyPercentItIsAWarningAndAtOneHundredItIsFull(): void
    {
        $this->planLimits(10, null, null);
        $this->students(9);
        $this->submit($this->uri(), $this->term());

        $body = $this->body();

        $this->assertStringContainsString('data-usage-line="warning"', $body);
        $this->assertStringContainsString('Nearly full', $body);
        $this->assertStringContainsString('9 of 10 (90%)', $body);

        $this->students(1);

        $body = $this->body();

        $this->assertStringContainsString('data-usage-line="full"', $body);
        $this->assertStringContainsString('>Full<', $body);
        $this->assertStringContainsString('10 of 10 (100%)', $body);
    }

    public function testTheBarNeverGoesBeyondFullWidth(): void
    {
        $this->planLimits(2, null, null);
        $this->students(5); // already over, e.g. the plan was lowered later
        $this->submit($this->uri(), $this->term());

        $body = $this->body();

        $this->assertStringContainsString('5 of 2 (250%)', $body);
        $this->assertStringContainsString('style="width: 100%"', $body);
        $this->assertStringContainsString('aria-valuenow="100"', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Corrections
    |--------------------------------------------------------------------------
    */

    public function testACorrectionAddsARowAndShowsWhatItCorrects(): void
    {
        $this->submit($this->uri(), $this->term(['amount' => '12000']));
        $first = $this->rows()[0];

        $this->submit($this->uri("/renewals/{$first['id']}/correct"), ['amount' => '120000', 'currency' => 'PHP', 'reference' => '', 'notes' => 'Missing zero']);

        $rows = $this->rows();

        $this->assertCount(2, $rows);
        $this->assertSame($first, $rows[0], 'the original record is exactly as it was');
        $this->assertSame('correction', $rows[1]['kind']);
        $this->assertSame('2026-12-31', $this->subscription()['end_date'], 'dates do not move');
        $this->assertSame(1, $this->auditCount('subscription.corrected'));

        $body = $this->body();

        $this->assertStringContainsString('Correction recorded', $body);
        $this->assertStringContainsString("of record #{$first['id']}", $body);
        $this->assertStringContainsString('PHP 12,000.00', $body, 'the old figure is still on the page');
        $this->assertStringContainsString('PHP 120,000.00', $body);
    }

    public function testACorrectionThatChangesNothingIsRefused(): void
    {
        $this->submit($this->uri(), $this->term(['amount' => '500']));
        $first = $this->rows()[0];

        $this->submit($this->uri("/renewals/{$first['id']}/correct"), ['amount' => '500', 'currency' => 'PHP', 'reference' => '', 'notes' => '']);

        $this->assertCount(1, $this->rows());
        $this->assertStringContainsString('Nothing to correct', $this->body());
    }

    public function testAnotherInstitutionsRecordCannotBeCorrectedThroughThisPage(): void
    {
        db_connect()->table('institutions')->insert(['name' => 'Other University', 'code' => 'OTHER', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
        $other = (int) db_connect()->insertID();

        $this->submit("admin/institutions/{$other}/subscription", $this->term(['amount' => '200']));
        $theirs = db_connect()->table('subscription_renewals')->get()->getRowArray();

        $this->submit($this->uri("/renewals/{$theirs['id']}/correct"), ['amount' => '1', 'currency' => 'PHP']);

        $this->assertCount(1, $this->rows(), 'nothing was added');
    }

    /*
    |--------------------------------------------------------------------------
    | The page itself
    |--------------------------------------------------------------------------
    */

    public function testNotesAndReferencesAreEscaped(): void
    {
        $this->submit($this->uri(), $this->term(['reference' => '<script>alert(1)</script>', 'notes' => '<img src=x onerror=alert(2)>']));

        $body = $this->body();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testTheInstitutionsListLinksToTheSubscriptionAndShowsThePlan(): void
    {
        $this->submit($this->uri(), $this->term());

        $body = $this->body('admin/institutions');

        $this->assertStringContainsString("admin/institutions/{$this->institution}/subscription", $body);
        $this->assertStringContainsString('Standard', $body);
    }

    public function testTheListCanBeFilteredByPlan(): void
    {
        db_connect()->table('institutions')->insert(['name' => 'Plain University', 'code' => 'PLAIN', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
        $this->submit($this->uri(), $this->term());

        $body = $this->body('admin/institutions?plan=' . $this->plan);

        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringNotContainsString('Plain University', $body);
    }

    public function testAnInstitutionWithARunningSubscriptionCannotBeDeletedFromTheList(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);
        $this->submit($this->uri(), $this->term(['period_start' => '2026-01-01', 'period_end' => date('Y-m-d', strtotime('+60 days'))]));

        $this->submit("admin/institutions/{$this->institution}/delete", ['confirm_name' => 'Acme University']);

        $this->assertNull(db_connect()->table('institutions')->where('id', $this->institution)->get()->getRowArray()['deleted_at']);
        $this->assertStringContainsString('subscription that runs until', $this->body('admin/institutions'));
    }

    public function testStartRenewAndCorrectInOneJourney(): void
    {
        $this->submit($this->uri(), $this->term(['amount' => '100']));
        $this->submit($this->uri('/renew'), $this->term(['period_start' => '2027-01-01', 'period_end' => '2027-12-31', 'amount' => '110']));
        $this->submit($this->uri('/renewals/' . $this->rows()[1]['id'] . '/correct'), ['amount' => '115', 'currency' => 'PHP']);

        $this->assertSame(['start', 'renewal', 'correction'], array_column($this->rows(), 'kind'));
        $this->assertSame('2027-12-31', $this->subscription()['end_date']);

        $actions = array_column(db_connect()->table('audit_logs')->like('action', 'subscription.', 'after')->orderBy('id')->get()->getResultArray(), 'action');

        $this->assertSame(['subscription.started', 'subscription.renewed', 'subscription.corrected'], $actions);
    }
}
