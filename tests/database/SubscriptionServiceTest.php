<?php

declare(strict_types=1);

use App\Models\Subscriptions\SubscriptionRenewalModel;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\SubscriptionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * An institution's subscription and its renewal history: the tables and their
 * constraints, starting, renewing and correcting, the rule that history rows
 * are never changed, the audit entries, and the undo when an entry cannot be
 * written.
 *
 * @internal
 */
final class SubscriptionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private SubscriptionService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->service = new SubscriptionService();
        $this->admin   = CourseFactory::user('admin');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function institution(string $code = 'ACME', string $status = 'pending'): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => $status]);

        return (int) db_connect()->insertID();
    }

    private function plan(string $name = 'Standard', string $status = 'active'): int
    {
        db_connect()->table('plans')->insert(['name' => $name, 'status' => $status]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed> a valid form post */
    private function term(int $planId, string $start = '2026-01-01', string $end = '2026-12-31', array $extra = []): array
    {
        return array_merge(['plan_id' => (string) $planId, 'period_start' => $start, 'period_end' => $end, 'amount' => '', 'currency' => '', 'reference' => '', 'notes' => ''], $extra);
    }

    /** @return array<string, mixed> */
    private function subscription(int $institutionId): array
    {
        return db_connect()->table('subscriptions')->where('institution_id', $institutionId)->get()->getRowArray();
    }

    /** @return list<array<string, mixed>> oldest first */
    private function rows(int $institutionId): array
    {
        return db_connect()->table('subscription_renewals')
            ->where('subscription_id', $this->subscription($institutionId)['id'])
            ->orderBy('id')
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    private function breakAuditing(): void
    {
        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('the audit log is unavailable');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The tables
    |--------------------------------------------------------------------------
    */

    public function testTheTablesExist(): void
    {
        $this->assertTrue(db_connect()->tableExists('subscriptions'));
        $this->assertTrue(db_connect()->tableExists('subscription_renewals'));
    }

    public function testAnInstitutionHasAtMostOneSubscription(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan), $this->admin);

        try {
            $stored = db_connect()->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => $plan, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        } catch (CodeIgniter\Database\Exceptions\DatabaseException) {
            $stored = false;
        }

        $this->assertFalse($stored);
        $this->assertSame(1, db_connect()->table('subscriptions')->where('institution_id', $institution)->countAllResults());
    }

    public function testAPlanInUseCannotBeDeleted(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan), $this->admin);

        try {
            $deleted = db_connect()->table('plans')->where('id', $plan)->delete();
        } catch (CodeIgniter\Database\Exceptions\DatabaseException) {
            $deleted = false;
        }

        $this->assertFalse($deleted);
        $this->assertSame(1, db_connect()->table('plans')->where('id', $plan)->countAllResults());
    }

    public function testRemovingAnInstitutionRemovesItsSubscriptionAndHistory(): void
    {
        $institution = $this->institution();
        $other       = $this->institution('OTHER');
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan), $this->admin);
        $this->service->start($other, $this->term($plan), $this->admin);

        db_connect()->table('institutions')->where('id', $institution)->delete();

        $this->assertSame(0, db_connect()->table('subscriptions')->where('institution_id', $institution)->countAllResults());
        $this->assertSame(1, db_connect()->table('subscriptions')->where('institution_id', $other)->countAllResults(), 'another institution is untouched');
        $this->assertSame(1, db_connect()->table('subscription_renewals')->countAllResults(), 'only the other institution\'s history is left');
    }

    /*
    |--------------------------------------------------------------------------
    | The history is append-only
    |--------------------------------------------------------------------------
    */

    private function aHistoryRowId(): int
    {
        $institution = $this->institution();
        $this->service->start($institution, $this->term($this->plan()), $this->admin);

        return (int) $this->rows($institution)[0]['id'];
    }

    public function testAHistoryRowCannotBeUpdated(): void
    {
        $id = $this->aHistoryRowId();

        $this->expectException(LogicException::class);

        (new SubscriptionRenewalModel())->update($id, ['amount' => '1.00']);
    }

    public function testAHistoryRowCannotBeSavedOver(): void
    {
        $id = $this->aHistoryRowId();

        $this->expectException(LogicException::class);

        (new SubscriptionRenewalModel())->save(['id' => $id, 'amount' => '1.00']);
    }

    public function testHistoryRowsCannotBeUpdatedInBatches(): void
    {
        $id = $this->aHistoryRowId();

        $this->expectException(LogicException::class);

        (new SubscriptionRenewalModel())->updateBatch([['id' => $id, 'amount' => '1.00']], 'id');
    }

    public function testAHistoryRowCannotBeDeleted(): void
    {
        $id = $this->aHistoryRowId();

        $this->expectException(LogicException::class);

        (new SubscriptionRenewalModel())->delete($id);
    }

    public function testHistoryRowsCannotBePurgedOrReplaced(): void
    {
        $id = $this->aHistoryRowId();

        foreach ([
            static fn () => (new SubscriptionRenewalModel())->purgeDeleted(),
            static fn () => (new SubscriptionRenewalModel())->replace(['id' => $id, 'amount' => '1.00']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a refusal');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Renewal records can never be', $e->getMessage());
            }
        }

        $this->assertSame(1, db_connect()->table('subscription_renewals')->where('id', $id)->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | Start
    |--------------------------------------------------------------------------
    */

    public function testStartingMakesTheSubscriptionAndItsFirstHistoryRow(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan('Premium');

        $id = $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31', ['amount' => '120000', 'reference' => 'INV-1', 'notes' => 'Paid in full']), $this->admin);

        $subscription = $this->subscription($institution);

        $this->assertSame($id, (int) $subscription['id']);
        $this->assertSame($plan, (int) $subscription['plan_id']);
        $this->assertSame('2026-01-01', $subscription['start_date']);
        $this->assertSame('2026-12-31', $subscription['end_date']);

        $rows = $this->rows($institution);

        $this->assertCount(1, $rows);
        $this->assertSame('start', $rows[0]['kind']);
        $this->assertNull($rows[0]['corrects_renewal_id']);
        $this->assertSame('120000.00', $rows[0]['amount']);
        $this->assertSame('PHP', $rows[0]['currency']);
        $this->assertSame('INV-1', $rows[0]['reference']);
        $this->assertSame('Paid in full', $rows[0]['notes']);
        $this->assertSame((string) $this->admin->id, (string) $rows[0]['recorded_by_user_id']);
        $this->assertEqualsWithDelta(time(), strtotime($rows[0]['recorded_at']), 5);
    }

    public function testStartingIsAudited(): void
    {
        $institution = $this->institution();
        $id          = $this->service->start($institution, $this->term($this->plan('Premium')), $this->admin);

        $entry = $this->audit('subscription.started')[0];

        $this->assertSame((string) $this->admin->id, (string) $entry['actor_user_id']);
        $this->assertSame('subscription', $entry['target_type']);
        $this->assertSame((string) $id, $entry['target_id']);
        $this->assertSame((string) $institution, (string) $entry['institution_id']);
        $this->assertSame('Premium', json_decode($entry['new_values'], true)['plan']);
    }

    public function testStartingLeavesTheInstitutionsStatusAlone(): void
    {
        $institution = $this->institution('ACME', 'pending');

        $this->service->start($institution, $this->term($this->plan()), $this->admin);

        $this->assertSame('pending', db_connect()->table('institutions')->where('id', $institution)->get()->getRowArray()['status']);
    }

    public function testASecondStartIsRefusedAndNothingChanges(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan), $this->admin);

        try {
            $this->service->start($institution, $this->term($plan, '2027-01-01', '2027-12-31'), $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already has a subscription', $e->getMessage());
        }

        $this->assertCount(1, $this->rows($institution));
        $this->assertSame('2026-12-31', $this->subscription($institution)['end_date']);
    }

    public function testARetiredPlanCannotStartASubscription(): void
    {
        $institution = $this->institution();
        $retired     = $this->plan('Old', 'retired');

        try {
            $this->service->start($institution, $this->term($retired), $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('retired', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('subscriptions')->countAllResults());
    }

    public function testMissingOrDeletedInstitutionsAndPlansAreRefused(): void
    {
        $plan = $this->plan();
        $gone = $this->institution('GONE');
        db_connect()->table('institutions')->where('id', $gone)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        foreach ([[999999, $plan, 'institution'], [$gone, $plan, 'institution'], [$this->institution('REAL'), 999999, 'plan']] as [$institution, $planId, $what]) {
            try {
                $this->service->start($institution, $this->term($planId), $this->admin);
                $this->fail("expected a refusal about the {$what}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($what, $e->getMessage());
            }
        }

        $this->assertSame(0, db_connect()->table('subscriptions')->countAllResults());
    }

    public function testBadDetailsStartNothing(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();

        foreach ([['period_end' => '2025-01-01'], ['amount' => 'lots'], ['period_start' => 'soon'], ['plan_id' => '']] as $bad) {
            try {
                $this->service->start($institution, array_merge($this->term($plan), $bad), $this->admin);
                $this->fail('expected a refusal for ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, db_connect()->table('subscriptions')->countAllResults());
        $this->assertSame([], $this->audit('subscription.started'));
    }

    /*
    |--------------------------------------------------------------------------
    | Renew
    |--------------------------------------------------------------------------
    */

    public function testARenewalExtendsTheEndDateAndAddsAHistoryRow(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31'), $this->admin);

        $this->service->renew($institution, $this->term($plan, '2027-01-01', '2027-12-31', ['amount' => '130000', 'reference' => 'INV-2']), $this->admin);

        $subscription = $this->subscription($institution);

        $this->assertSame('2026-01-01', $subscription['start_date'], 'the subscription still began in 2026');
        $this->assertSame('2027-12-31', $subscription['end_date'], 'and now runs further');

        $rows = $this->rows($institution);

        $this->assertCount(2, $rows);
        $this->assertSame(['start', 'renewal'], array_column($rows, 'kind'));
        $this->assertSame('2027-01-01', $rows[1]['period_start']);
        $this->assertSame('2027-12-31', $rows[1]['period_end']);
        $this->assertSame('130000.00', $rows[1]['amount']);
        $this->assertSame('2026-12-31', $rows[0]['period_end'], 'the first row is exactly as it was');
    }

    public function testSeveralRenewalsBuildUpAHistoryNewestFirst(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31'), $this->admin);
        $this->service->renew($institution, $this->term($plan, '2027-01-01', '2027-12-31'), $this->admin);
        $this->service->renew($institution, $this->term($plan, '2028-01-01', '2028-12-31'), $this->admin);

        $history = $this->service->history((int) $this->subscription($institution)['id']);

        $this->assertSame(['2028-12-31', '2027-12-31', '2026-12-31'], array_column($history, 'period_end'));
        $this->assertSame('2028-12-31', $this->subscription($institution)['end_date']);
    }

    public function testARenewalMayMoveTheInstitutionToAnotherActivePlan(): void
    {
        $institution = $this->institution();
        $basic       = $this->plan('Basic');
        $premium     = $this->plan('Premium');
        $this->service->start($institution, $this->term($basic), $this->admin);

        $this->service->renew($institution, $this->term($premium, '2027-01-01', '2027-12-31'), $this->admin);

        $this->assertSame($premium, (int) $this->subscription($institution)['plan_id']);
        $this->assertSame($premium, (int) $this->rows($institution)[1]['plan_id']);
        $this->assertSame($basic, (int) $this->rows($institution)[0]['plan_id'], 'the history remembers the old plan');
    }

    public function testARenewalCannotMoveToARetiredPlanButMayStayOnOne(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan('Legacy');
        $this->service->start($institution, $this->term($plan), $this->admin);

        db_connect()->table('plans')->where('id', $plan)->update(['status' => 'retired']);

        $this->service->renew($institution, $this->term($plan, '2027-01-01', '2027-12-31'), $this->admin);
        $this->assertSame('2027-12-31', $this->subscription($institution)['end_date'], 'staying on a retired plan is fine');

        $other = $this->plan('Also retired', 'retired');

        try {
            $this->service->renew($institution, $this->term($other, '2028-01-01', '2028-12-31'), $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('retired', $e->getMessage());
        }

        $this->assertSame($plan, (int) $this->subscription($institution)['plan_id']);
    }

    public function testARenewalMustEndAfterTheCurrentEndDate(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31'), $this->admin);

        foreach ([['2026-07-01', '2026-12-31'], ['2026-03-01', '2026-06-30']] as [$from, $end]) {
            try {
                $this->service->renew($institution, $this->term($plan, $from, $end), $this->admin);
                $this->fail("{$end} must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('must end after the current end date', $e->getMessage());
            }
        }

        $this->assertCount(1, $this->rows($institution));
        $this->assertSame('2026-12-31', $this->subscription($institution)['end_date']);
    }

    public function testARenewalCannotStartBeforeTheSubscriptionDid(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31'), $this->admin);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot start before the subscription did');

        $this->service->renew($institution, $this->term($plan, '2025-06-01', '2027-12-31'), $this->admin);
    }

    public function testARenewalAfterALapseMayLeaveAGap(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31'), $this->admin);

        $this->service->renew($institution, $this->term($plan, '2027-06-01', '2028-05-31'), $this->admin);

        $this->assertSame('2028-05-31', $this->subscription($institution)['end_date']);
    }

    public function testAnInstitutionWithNoSubscriptionCannotBeRenewed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no subscription yet');

        $this->service->renew($this->institution(), $this->term($this->plan()), $this->admin);
    }

    public function testRenewingIsAuditedWithBeforeAndAfter(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan('Basic');
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31'), $this->admin);

        $this->service->renew($institution, $this->term($plan, '2027-01-01', '2027-12-31', ['amount' => '5']), $this->admin);

        $entry = $this->audit('subscription.renewed')[0];

        $this->assertSame((string) $institution, (string) $entry['institution_id']);
        $this->assertSame('2026-12-31', json_decode($entry['old_values'], true)['end_date']);
        $this->assertSame('2027-12-31', json_decode($entry['new_values'], true)['end_date']);
        $this->assertSame('Basic', json_decode($entry['new_values'], true)['plan']);
    }

    /*
    |--------------------------------------------------------------------------
    | Correct
    |--------------------------------------------------------------------------
    */

    public function testACorrectionAddsARowAndLeavesTheOriginalAndTheSubscriptionAlone(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31', ['amount' => '12000', 'reference' => 'INV-1']), $this->admin);
        $original = $this->rows($institution)[0];

        $this->service->correct($institution, (int) $original['id'], ['amount' => '120000', 'reference' => 'INV-1', 'currency' => 'PHP', 'notes' => 'Amount had a missing zero'], $this->admin);

        $rows = $this->rows($institution);

        $this->assertCount(2, $rows);
        $this->assertSame($original, $rows[0], 'the original row is exactly as it was');
        $this->assertSame('correction', $rows[1]['kind']);
        $this->assertSame((string) $original['id'], (string) $rows[1]['corrects_renewal_id']);
        $this->assertSame('120000.00', $rows[1]['amount']);
        $this->assertSame('Amount had a missing zero', $rows[1]['notes']);
        $this->assertSame($original['period_start'], $rows[1]['period_start'], 'the period is carried over');
        $this->assertSame($original['period_end'], $rows[1]['period_end']);
        $this->assertSame('2026-12-31', $this->subscription($institution)['end_date'], 'dates do not move');
    }

    public function testACorrectionThatChangesNothingIsRefused(): void
    {
        $institution = $this->institution();
        $this->service->start($institution, $this->term($this->plan(), '2026-01-01', '2026-12-31', ['amount' => '500', 'reference' => 'OR-9']), $this->admin);
        $row = $this->rows($institution)[0];

        try {
            $this->service->correct($institution, (int) $row['id'], ['amount' => '500.00', 'reference' => 'OR-9', 'currency' => 'php', 'notes' => ''], $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Nothing to correct', $e->getMessage());
        }

        $this->assertCount(1, $this->rows($institution));
    }

    public function testCorrectingAMissingRecordIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->correct($this->institution(), 999999, ['amount' => '1'], $this->admin);
    }

    public function testAnotherInstitutionsRecordCannotBeCorrectedThroughThisOne(): void
    {
        $mine  = $this->institution('MINE');
        $other = $this->institution('OTHER');
        $plan  = $this->plan();
        $this->service->start($mine, $this->term($plan, '2026-01-01', '2026-12-31', ['amount' => '100']), $this->admin);
        $this->service->start($other, $this->term($plan, '2026-01-01', '2026-12-31', ['amount' => '200']), $this->admin);
        $theirs = $this->rows($other)[0];

        try {
            $this->service->correct($mine, (int) $theirs['id'], ['amount' => '1'], $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->assertCount(1, $this->rows($other), 'nothing was added to their history');
        $this->assertCount(1, $this->rows($mine));
    }

    public function testACorrectionWithBadDetailsAddsNothing(): void
    {
        $institution = $this->institution();
        $this->service->start($institution, $this->term($this->plan()), $this->admin);
        $row = $this->rows($institution)[0];

        try {
            $this->service->correct($institution, (int) $row['id'], ['amount' => 'lots'], $this->admin);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertCount(1, $this->rows($institution));
    }

    public function testACorrectionIsAuditedAgainstTheRecordItCorrects(): void
    {
        $institution = $this->institution();
        $this->service->start($institution, $this->term($this->plan(), '2026-01-01', '2026-12-31', ['amount' => '100']), $this->admin);
        $row = $this->rows($institution)[0];

        $this->service->correct($institution, (int) $row['id'], ['amount' => '1000'], $this->admin);

        $entry = $this->audit('subscription.corrected')[0];

        $this->assertSame('subscription_renewal', $entry['target_type']);
        $this->assertSame((string) $row['id'], $entry['target_id']);
        $this->assertSame((string) $institution, (string) $entry['institution_id']);
        $this->assertSame('100.00', json_decode($entry['old_values'], true)['amount']);
        $this->assertSame('1000.00', json_decode($entry['new_values'], true)['amount']);
    }

    /*
    |--------------------------------------------------------------------------
    | Is it running?
    |--------------------------------------------------------------------------
    */

    public function testASubscriptionIsRunningUntilItsEndDateInclusive(): void
    {
        $institution = $this->institution();
        $this->service->start($institution, $this->term($this->plan(), '2026-01-01', '2026-12-31'), $this->admin);

        $this->assertTrue($this->service->isRunning($institution, new DateTimeImmutable('2026-06-15')));
        $this->assertTrue($this->service->isRunning($institution, new DateTimeImmutable('2026-12-31')), 'the last day still counts');
        $this->assertFalse($this->service->isRunning($institution, new DateTimeImmutable('2027-01-01')));
    }

    public function testNoSubscriptionMeansNotRunning(): void
    {
        $this->assertFalse($this->service->isRunning($this->institution(), new DateTimeImmutable('2026-06-15')));
    }

    public function testRenewingKeepsItRunningLonger(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->service->start($institution, $this->term($plan, '2026-01-01', '2026-12-31'), $this->admin);
        $this->service->renew($institution, $this->term($plan, '2027-01-01', '2027-12-31'), $this->admin);

        $this->assertTrue($this->service->isRunning($institution, new DateTimeImmutable('2027-06-01')));
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing changes without a trace
    |--------------------------------------------------------------------------
    */

    public function testAStartThatCannotBeAuditedLeavesNothingBehind(): void
    {
        $institution = $this->institution();
        $plan        = $this->plan();
        $this->breakAuditing();

        try {
            $this->service->start($institution, $this->term($plan), $this->admin);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('the audit log is unavailable', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('subscriptions')->countAllResults());
        $this->assertSame(0, db_connect()->table('subscription_renewals')->countAllResults(), 'and no history row either');
    }

    public function testARenewalThatCannotBeAuditedIsTakenBackCompletely(): void
    {
        $institution = $this->institution();
        $basic       = $this->plan('Basic');
        $premium     = $this->plan('Premium');
        $this->service->start($institution, $this->term($basic, '2026-01-01', '2026-12-31'), $this->admin);
        $before = $this->subscription($institution);
        $this->breakAuditing();

        try {
            $this->service->renew($institution, $this->term($premium, '2027-01-01', '2027-12-31'), $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $after = $this->subscription($institution);

        $this->assertSame($before['end_date'], $after['end_date']);
        $this->assertSame($before['plan_id'], $after['plan_id']);
        $this->assertCount(1, $this->rows($institution), 'the renewal row was taken back');
    }

    public function testACorrectionThatCannotBeAuditedIsTakenBack(): void
    {
        $institution = $this->institution();
        $this->service->start($institution, $this->term($this->plan(), '2026-01-01', '2026-12-31', ['amount' => '100']), $this->admin);
        $row = $this->rows($institution)[0];
        $this->breakAuditing();

        try {
            $this->service->correct($institution, (int) $row['id'], ['amount' => '999'], $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(1, $this->rows($institution));
    }
}
