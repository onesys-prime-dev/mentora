<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Institutions following their subscription's timeline: the daily sync (down
 * only, never up), what a renewal does to the institution, and the command.
 *
 * The date is fixed at 12 October 2026. With the default timeline (30 days'
 * warning, 14 grace, 30 read-only) a subscription ending on:
 *   2026-12-01 or later         is running or expiring
 *   2026-09-28 to 2026-10-11    is in grace
 *   2026-08-29 to 2026-09-27    is read-only
 *   2026-08-28 or earlier       has expired
 *
 * @internal
 */
final class SubscriptionLifecycleTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private SubscriptionLifecycle $lifecycle;

    private SubscriptionService $subscriptions;

    private User $admin;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->lifecycle     = new SubscriptionLifecycle();
        $this->subscriptions = new SubscriptionService();
        $this->admin         = CourseFactory::user('admin');

        db_connect()->table('plans')->insert(['name' => 'Standard', 'status' => 'active']);
        $this->plan = (int) db_connect()->insertID();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-12 08:00:00');
    }

    /** An institution in the given status, with a subscription ending on $endDate (or none). */
    private function institution(string $code, string $status, ?string $endDate, ?string $deletedAt = null): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => $status, 'deleted_at' => $deletedAt]);
        $id = (int) db_connect()->insertID();

        if ($endDate !== null) {
            db_connect()->table('subscriptions')->insert(['institution_id' => $id, 'plan_id' => $this->plan, 'start_date' => '2025-01-01', 'end_date' => $endDate]);
        }

        return $id;
    }

    private function statusOfInstitution(int $id): string
    {
        return db_connect()->table('institutions')->where('id', $id)->get()->getRowArray()['status'];
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    /** @return array<string, mixed> a renewal form */
    private function renewal(string $start, string $end): array
    {
        return ['plan_id' => (string) $this->plan, 'period_start' => $start, 'period_end' => $end, 'amount' => '', 'currency' => '', 'reference' => '', 'notes' => ''];
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
    | The timeline
    |--------------------------------------------------------------------------
    */

    public function testTheDefaultTimelineIsUsed(): void
    {
        $id = $this->institution('A', 'active', '2026-10-01');

        $status = $this->lifecycle->statusOf('2026-10-01', $id, $this->now());

        $this->assertSame('grace', $status->state);
        $this->assertSame('2026-10-15', $status->graceEnds, '14 days of grace');
        $this->assertSame('2026-11-14', $status->readOnlyEnds, 'then 30 days read-only');
    }

    public function testAnInstitutionCanHaveItsOwnTimeline(): void
    {
        $custom = $this->institution('CUSTOM', 'active', '2026-10-01');
        $plain  = $this->institution('PLAIN', 'active', '2026-10-01');

        (new PlatformSettings())->set('expiryGraceDays', 60, $custom);
        Services::resetSingle('settings');

        $this->assertSame('2026-11-30', $this->lifecycle->statusOf('2026-10-01', $custom, $this->now())->graceEnds);
        $this->assertSame('2026-10-15', $this->lifecycle->statusOf('2026-10-01', $plain, $this->now())->graceEnds, 'another institution keeps the default');
    }

    /*
    |--------------------------------------------------------------------------
    | The daily sync
    |--------------------------------------------------------------------------
    */

    public function testNothingChangesWhileRunningExpiringOrInGrace(): void
    {
        $running  = $this->institution('RUN', 'active', '2027-06-30');
        $expiring = $this->institution('EXP', 'active', '2026-10-25');
        $grace    = $this->institution('GRC', 'active', '2026-10-11');
        $lastGrace = $this->institution('LGR', 'active', '2026-09-28');

        $result = $this->lifecycle->sync($this->now());

        $this->assertSame([], $result['changed']);
        foreach ([$running, $expiring, $grace, $lastGrace] as $id) {
            $this->assertSame('active', $this->statusOfInstitution($id));
        }
    }

    public function testAnActiveInstitutionWhoseGraceHasEndedBecomesReadOnly(): void
    {
        $id = $this->institution('RO', 'active', '2026-09-27');

        $result = $this->lifecycle->sync($this->now());

        $this->assertSame('read_only', $this->statusOfInstitution($id));
        $this->assertSame([['id' => $id, 'from' => 'active', 'to' => 'read_only', 'state' => 'read_only']], $result['changed']);
    }

    public function testTheLastDayOfReadOnlyStillReadOnlyAndTheNextIsPast(): void
    {
        $lastDay  = $this->institution('LAST', 'read_only', '2026-08-29');
        $nextDay  = $this->institution('NEXT', 'read_only', '2026-08-28');

        $this->lifecycle->sync($this->now());

        $this->assertSame('read_only', $this->statusOfInstitution($lastDay));
        $this->assertSame('past', $this->statusOfInstitution($nextDay));
    }

    public function testAnExpiredSubscriptionMakesAnActiveInstitutionPastInOneStep(): void
    {
        $id = $this->institution('OLD', 'active', '2025-12-31');

        $this->lifecycle->sync($this->now());

        $this->assertSame('past', $this->statusOfInstitution($id));
    }

    public function testASecondRunChangesNothingMore(): void
    {
        $this->institution('OLD', 'active', '2025-12-31');
        $this->lifecycle->sync($this->now());

        $this->assertSame([], $this->lifecycle->sync($this->now())['changed']);
        $this->assertCount(1, $this->audit('institution.status_changed'));
    }

    public function testItNeverLiftsAnInstitution(): void
    {
        $suspended = $this->institution('SUSP', 'past', '2027-06-30');
        $readOnly  = $this->institution('RO', 'read_only', '2027-06-30');

        $result = $this->lifecycle->sync($this->now());

        $this->assertSame([], $result['changed']);
        $this->assertSame('past', $this->statusOfInstitution($suspended), 'a manual suspension is not undone');
        $this->assertSame('read_only', $this->statusOfInstitution($readOnly));
    }

    public function testPendingInstitutionsAreLeftAlone(): void
    {
        $id = $this->institution('PEND', 'pending', '2020-01-01');

        $this->assertSame([], $this->lifecycle->sync($this->now())['changed']);
        $this->assertSame('pending', $this->statusOfInstitution($id));
    }

    public function testInstitutionsWithNoSubscriptionAreLeftAlone(): void
    {
        $id = $this->institution('NOSUB', 'active', null);

        $this->assertSame([], $this->lifecycle->sync($this->now())['changed']);
        $this->assertSame('active', $this->statusOfInstitution($id));
    }

    public function testDeletedInstitutionsAreLeftAlone(): void
    {
        $id = $this->institution('GONE', 'active', '2020-01-01', '2026-10-01 10:00:00');

        $this->assertSame([], $this->lifecycle->sync($this->now())['changed']);
        $this->assertSame('active', $this->statusOfInstitution($id));
    }

    public function testAnInstitutionsOwnTimelineDecidesWhenItMoves(): void
    {
        $long  = $this->institution('LONG', 'active', '2026-09-27');
        $plain = $this->institution('PLAIN', 'active', '2026-09-27');

        (new PlatformSettings())->set('expiryGraceDays', 60, $long);
        Services::resetSingle('settings');

        $this->lifecycle->sync($this->now());

        $this->assertSame('active', $this->statusOfInstitution($long), 'still in its longer grace');
        $this->assertSame('read_only', $this->statusOfInstitution($plain));
    }

    public function testADryRunListsTheChangesAndMakesNone(): void
    {
        $id = $this->institution('RO', 'active', '2026-09-27');

        $result = $this->lifecycle->sync($this->now(), true);

        $this->assertCount(1, $result['changed']);
        $this->assertSame('active', $this->statusOfInstitution($id));
        $this->assertSame([], $this->audit('institution.status_changed'));
    }

    public function testEachChangeIsAuditedAsTheSystem(): void
    {
        $id = $this->institution('OLD', 'active', '2025-12-31');

        $this->lifecycle->sync($this->now());

        $entry = $this->audit('institution.status_changed')[0];

        $this->assertNull($entry['actor_user_id']);
        $this->assertSame('system (subscription check)', $entry['actor_label']);
        $this->assertSame((string) $id, $entry['target_id']);
        $this->assertSame((string) $id, (string) $entry['institution_id']);
        $this->assertSame(['status' => 'active'], json_decode($entry['old_values'], true));

        $new = json_decode($entry['new_values'], true);

        $this->assertSame('past', $new['status']);
        $this->assertSame('subscription expired', $new['reason']);
        $this->assertSame('2025-12-31', $new['subscription_ended']);
    }

    public function testOneFailureDoesNotStopTheOthersAndIsTakenBack(): void
    {
        $first  = $this->institution('F1', 'active', '2025-12-31');
        $second = $this->institution('F2', 'active', '2025-12-30');

        $calls = 0;
        Services::injectMock('auditLogger', new class ($calls) extends AuditLogger {
            public function __construct(private int &$calls)
            {
            }

            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('first one fails');
                }

                return parent::log($action, $actor, $details);
            }
        });

        $result = $this->lifecycle->sync($this->now());

        $this->assertSame([$first => 'first one fails'], $result['failed']);
        $this->assertSame('active', $this->statusOfInstitution($first), 'taken back, to be tried again tomorrow');
        $this->assertSame('past', $this->statusOfInstitution($second));
        $this->assertCount(1, $result['changed']);
    }

    /*
    |--------------------------------------------------------------------------
    | A renewal brings an institution back
    |--------------------------------------------------------------------------
    */

    /** @return int an institution with a subscription that has long expired, and so is past */
    private function lapsed(string $status = 'past'): int
    {
        return $this->institution('LAPSED', $status, '2025-12-31');
    }

    public function testRenewingAPastInstitutionReactivatesIt(): void
    {
        $id = $this->lapsed();

        $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('+1 year'))), $this->admin);

        $this->assertSame('active', $this->statusOfInstitution($id));
    }

    public function testRenewingAReadOnlyInstitutionReactivatesIt(): void
    {
        $id = $this->lapsed('read_only');

        $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('+6 months'))), $this->admin);

        $this->assertSame('active', $this->statusOfInstitution($id));
    }

    public function testARenewalThatStillEndsInThePastOnlyLiftsItAsFarAsTheDateAllows(): void
    {
        // Ends 20 days ago: inside the 14 + 30 day window, so read-only, not active.
        $id = $this->lapsed('past');

        $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('-20 days'))), $this->admin);

        $this->assertSame('read_only', $this->statusOfInstitution($id));
    }

    public function testRenewingAnActiveInstitutionLeavesItActive(): void
    {
        $id = $this->institution('LIVE', 'active', date('Y-m-d', strtotime('+10 days')));

        $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('+1 year'))), $this->admin);

        $this->assertSame('active', $this->statusOfInstitution($id));
        $this->assertSame([], $this->audit('institution.status_changed'), 'no separate status event; it is part of the renewal entry');
    }

    public function testRenewingAPendingInstitutionLeavesItPending(): void
    {
        $id = $this->institution('NEW', 'pending', '2026-03-31');

        $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('+1 year'))), $this->admin);

        $this->assertSame('pending', $this->statusOfInstitution($id), 'onboarding activates it, not a renewal');
    }

    public function testTheRenewalEntryRecordsTheStatusChange(): void
    {
        $id = $this->lapsed();

        $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('+1 year'))), $this->admin);

        $entry = $this->audit('subscription.renewed')[0];

        $this->assertSame('past', json_decode($entry['old_values'], true)['institution_status']);
        $this->assertSame('active', json_decode($entry['new_values'], true)['institution_status']);
    }

    public function testARenewalThatCannotBeAuditedLeavesTheInstitutionAsItWas(): void
    {
        $id = $this->lapsed();
        $this->breakAuditing();

        try {
            $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('+1 year'))), $this->admin);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('past', $this->statusOfInstitution($id));
        $this->assertSame('2025-12-31', db_connect()->table('subscriptions')->where('institution_id', $id)->get()->getRowArray()['end_date']);
    }

    public function testAfterARenewalTheNextSyncLeavesItAlone(): void
    {
        $id = $this->lapsed();
        $this->subscriptions->renew($id, $this->renewal('2026-01-01', date('Y-m-d', strtotime('+1 year'))), $this->admin);

        $this->assertSame([], $this->lifecycle->sync(new DateTimeImmutable())['changed']);
        $this->assertSame('active', $this->statusOfInstitution($id));
    }

    /*
    |--------------------------------------------------------------------------
    | The spark command
    |--------------------------------------------------------------------------
    */

    public function testTheCommandMovesWhatIsDue(): void
    {
        $id = $this->institution('OLD', 'active', '2020-01-01');

        command('subscriptions:sync');

        $this->assertStringContainsString('Changed 1 institution(s)', $this->getStreamFilterBuffer());
        $this->assertStringContainsString('active -> past', $this->getStreamFilterBuffer());
        $this->assertSame('past', $this->statusOfInstitution($id));
    }

    public function testTheCommandSaysWhenThereIsNothingToDo(): void
    {
        $this->institution('LIVE', 'active', '2099-01-01');

        command('subscriptions:sync');

        $this->assertStringContainsString('Changed 0 institution(s)', $this->getStreamFilterBuffer());
    }

    public function testTheCommandDryRunChangesNothing(): void
    {
        $id = $this->institution('OLD', 'active', '2020-01-01');

        command('subscriptions:sync --dry-run');

        $this->assertStringContainsString('Would change 1 institution(s)', $this->getStreamFilterBuffer());
        $this->assertSame('active', $this->statusOfInstitution($id));
    }
}
