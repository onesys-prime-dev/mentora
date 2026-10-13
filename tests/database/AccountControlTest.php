<?php

declare(strict_types=1);

use App\Services\Users\AccountControl;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * Switching an account off and ending sessions: what is written, and what the
 * access check then answers.
 *
 * @internal
 */
final class AccountControlTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AccountControl $control;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->control = new AccountControl();
        $this->now     = new DateTimeImmutable('2026-10-09 12:00:00');
    }

    /** @return array<string, mixed>|null */
    private function row(int $userId): ?array
    {
        return db_connect()->table('user_account_controls')->where('user_id', $userId)->get()->getRowArray();
    }

    private function active(int $userId): int
    {
        return (int) db_connect()->table('users')->where('id', $userId)->get()->getRowArray()['active'];
    }

    public function testAnAccountWithNoRowIsInGoodStanding(): void
    {
        $user = PeopleFactory::person('student');

        $this->assertNull($this->control->blockReason((int) $user->id, null));
        $this->assertNull($this->control->blockReason((int) $user->id, time()));
        $this->assertFalse($this->control->isDeactivated((int) $user->id));
        $this->assertNull($this->row((int) $user->id), 'and nothing is written for it');
    }

    public function testDeactivatingSwitchesTheAccountOffAndEndsItsSessions(): void
    {
        $user = PeopleFactory::person('student');

        $this->control->deactivate((int) $user->id, $this->now);

        $row = $this->row((int) $user->id);

        $this->assertSame('2026-10-09 12:00:00', $row['deactivated_at']);
        $this->assertSame('2026-10-09 12:00:00', $row['sessions_valid_from']);
        $this->assertSame(0, $this->active((int) $user->id), 'the seat count reads this');
        $this->assertTrue($this->control->isDeactivated((int) $user->id));
        $this->assertSame(AccountControl::REASON_DEACTIVATED, $this->control->blockReason((int) $user->id, strtotime('2026-10-09 13:00:00')), 'even a session that began afterwards');
    }

    public function testReactivatingLetsThemBackInButOldSessionsStayEnded(): void
    {
        $user = PeopleFactory::person('student');
        $this->control->deactivate((int) $user->id, $this->now);

        $this->control->reactivate((int) $user->id, $this->now->modify('+1 hour'));

        $row = $this->row((int) $user->id);

        $this->assertNull($row['deactivated_at']);
        $this->assertSame(1, $this->active((int) $user->id));
        $this->assertFalse($this->control->isDeactivated((int) $user->id));

        $this->assertSame(AccountControl::REASON_SIGNED_OUT, $this->control->blockReason((int) $user->id, strtotime('2026-10-09 11:00:00')), 'a session from before stays ended');
        $this->assertNull($this->control->blockReason((int) $user->id, strtotime('2026-10-09 14:00:00')), 'a new sign-in works');
    }

    public function testSigningOutEverywhereEndsOlderSessionsOnly(): void
    {
        $user = PeopleFactory::person('instructor');

        $this->control->signOutEverywhere((int) $user->id, $this->now);

        $this->assertSame(AccountControl::REASON_SIGNED_OUT, $this->control->blockReason((int) $user->id, strtotime('2026-10-09 11:59:59')));
        $this->assertSame(AccountControl::REASON_SIGNED_OUT, $this->control->blockReason((int) $user->id, null), 'a session with no recorded start');
        $this->assertNull($this->control->blockReason((int) $user->id, strtotime('2026-10-09 12:00:01')));
        $this->assertFalse($this->control->isDeactivated((int) $user->id), 'the account itself is still on');
        $this->assertSame(1, $this->active((int) $user->id));
    }

    public function testAnotherPersonIsNotAffected(): void
    {
        $one = PeopleFactory::person('student');
        $two = PeopleFactory::person('student');

        $this->control->deactivate((int) $one->id, $this->now);

        $this->assertNull($this->control->blockReason((int) $two->id, strtotime('2026-01-01')));
        $this->assertNull($this->row((int) $two->id));
        $this->assertSame(1, $this->active((int) $two->id));
    }

    public function testThereIsOnlyEverOneRowPerPerson(): void
    {
        $user = PeopleFactory::person('student');

        $this->control->signOutEverywhere((int) $user->id, $this->now);
        $this->control->deactivate((int) $user->id, $this->now->modify('+1 minute'));
        $this->control->reactivate((int) $user->id, $this->now->modify('+2 minutes'));
        $this->control->signOutEverywhere((int) $user->id, $this->now->modify('+3 minutes'));

        $this->assertSame(1, db_connect()->table('user_account_controls')->where('user_id', $user->id)->countAllResults());
        $this->assertSame('2026-10-09 12:03:00', $this->row((int) $user->id)['sessions_valid_from']);
    }

    public function testADeactivatedStudentFreesASeat(): void
    {
        $institution = PeopleFactory::institution('ACME');
        PeopleFactory::subscribe($institution, PeopleFactory::plan('Small', 2));

        $one = PeopleFactory::person('student', 'Ana', 'One', null, $institution);
        PeopleFactory::person('student', 'Ben', 'Two', null, $institution);

        $limits = new App\Services\Subscriptions\PlanLimits();

        $this->assertNotNull($limits->seatBlock($institution), 'both seats are taken');

        $this->control->deactivate((int) $one->id, $this->now);

        $this->assertNull($limits->seatBlock($institution), 'one is free again');
    }
}
