<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationRecipients;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationTriggers;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Notifications (SA15): who is told, what is urgent (and so also emailed),
 * what each trigger creates from the state of subscriptions and limits, and
 * that running them again tells nobody twice.
 *
 * @internal
 */
final class NotificationsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FakeEmail $mailer;

    private DateTimeImmutable $now;

    private int $acme;

    private int $admin;

    private int $boss;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->mailer = FakeEmail::install();
        $this->now    = new DateTimeImmutable('2026-10-10 08:00:00');
        $this->acme   = PeopleFactory::institution('ACME', 'active', 'Acme University');
        $this->admin  = (int) PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com')->id;

        // A recent good backup, so these tests are about subscriptions and limits only.
        db_connect()->table('backups')->insert(['status' => 'succeeded', 'trigger' => 'scheduled', 'created_at' => '2026-10-10 06:00:00', 'finished_at' => '2026-10-10 06:00:00']);
        $this->boss   = (int) PeopleFactory::person('institutional_admin', 'Bo', 'Boss', 'boss@acme.example', $this->acme)->id;
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** Gives Acme a subscription ending $days from the fixed "today" (negative = ended). */
    private function subscribeEndingIn(int $days, ?int $seats = null, ?int $institution = null): void
    {
        PeopleFactory::subscribe($institution ?? $this->acme, PeopleFactory::plan('Plan' . random_int(1000, 9999), $seats));

        db_connect()->table('subscriptions')->where('institution_id', $institution ?? $this->acme)
            ->update(['end_date' => $this->now->modify("{$days} days")->format('Y-m-d')]);
    }

    /** @return list<array<string, mixed>> */
    private function notes(int $userId): array
    {
        return db_connect()->table('notifications')->where('user_id', $userId)->orderBy('id')->get()->getResultArray();
    }

    /** @return list<string> */
    private function types(int $userId): array
    {
        return array_column($this->notes($userId), 'type');
    }

    /*
    |--------------------------------------------------------------------------
    | The service
    |--------------------------------------------------------------------------
    */

    public function testTellingStoresItAndTellingAgainDoesNot(): void
    {
        $service = new NotificationService();

        $this->assertTrue($service->send($this->boss, 'x', 'Title', 'Body', false, 'key-1', $this->acme, null, $this->now));
        $this->assertFalse($service->send($this->boss, 'x', 'Title', 'Body', false, 'key-1', $this->acme, null, $this->now));
        $this->assertTrue($service->send($this->boss, 'x', 'Title', 'Body', false, 'key-2', $this->acme, null, $this->now));
        $this->assertTrue($service->send($this->admin, 'x', 'Title', 'Body', false, 'key-1', $this->acme, null, $this->now), 'another person can be told the same');

        $this->assertCount(2, $this->notes($this->boss));
    }

    public function testSaysWhetherAPersonWasToldWithoutTellingThem(): void
    {
        $service = new NotificationService();

        $this->assertFalse($service->has($this->boss, 'key-1'));
        $this->assertCount(0, $this->notes($this->boss), 'asking tells nobody');

        $service->send($this->boss, 'x', 'Title', 'Body', false, 'key-1', $this->acme, null, $this->now);

        $this->assertTrue($service->has($this->boss, 'key-1'));
        $this->assertFalse($service->has($this->admin, 'key-1'), 'another person was not told');
        $this->assertFalse($service->has($this->boss, 'key-2'));
    }

    public function testAnUrgentOneIsAlsoEmailedAndAnOrdinaryOneIsNot(): void
    {
        $service = new NotificationService();

        $service->send($this->boss, 'x', 'Quiet', 'Just so you know', false, 'quiet', $this->acme, null, $this->now);
        $this->assertSame([], $this->mailer->sent);

        $service->send($this->boss, 'x', 'Loud', 'Act now', true, 'loud', $this->acme, 'notifications', $this->now);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('boss@acme.example', $this->mailer->sent[0]['to']);
        $this->assertSame('Mentora: Loud', $this->mailer->sent[0]['subject']);
        $this->assertStringContainsString('Act now', $this->mailer->sent[0]['message']);
        $this->assertSame('2026-10-10 08:00:00', $this->notes($this->boss)[1]['emailed_at']);
        $this->assertNull($this->notes($this->boss)[0]['emailed_at']);
    }

    public function testAnUrgentOneIsNotEmailedTwice(): void
    {
        $service = new NotificationService();

        $service->send($this->boss, 'x', 'Loud', 'Act now', true, 'loud', null, null, $this->now);
        $service->send($this->boss, 'x', 'Loud', 'Act now', true, 'loud', null, null, $this->now);

        $this->assertCount(1, $this->mailer->sent);
    }

    public function testAFailedEmailStillLeavesTheInAppNotification(): void
    {
        $this->mailer->failToSend = true;

        (new NotificationService())->send($this->boss, 'x', 'Loud', 'Act now', true, 'loud', null, null, $this->now);

        $note = $this->notes($this->boss)[0];

        $this->assertSame('Loud', $note['title']);
        $this->assertNull($note['emailed_at']);
    }

    public function testReadingAndCounting(): void
    {
        $service = new NotificationService();
        $service->send($this->boss, 'x', 'One', 'b', false, 'k1', null, null, $this->now);
        $service->send($this->boss, 'x', 'Two', 'b', false, 'k2', null, null, $this->now);
        $service->send($this->admin, 'x', 'Not yours', 'b', false, 'k1', null, null, $this->now);

        $this->assertSame(2, $service->unreadCount($this->boss));

        $first = (int) $this->notes($this->boss)[0]['id'];
        $this->assertSame(1, $service->markRead($this->boss, $first, $this->now));
        $this->assertSame(1, $service->unreadCount($this->boss));
        $this->assertSame(0, $service->markRead($this->boss, $first, $this->now), 'already read');

        $otherPersons = (int) $this->notes($this->admin)[0]['id'];
        $this->assertSame(0, $service->markRead($this->boss, $otherPersons, $this->now), 'not theirs to read');
        $this->assertSame(1, $service->unreadCount($this->admin));

        $this->assertSame(1, $service->markRead($this->boss, null, $this->now));
        $this->assertSame(0, $service->unreadCount($this->boss));
        $this->assertSame(['Two', 'One'], array_column($service->listFor($this->boss), 'title'), 'newest first');
    }

    public function testThePanelGetsSevenAtATimeNewestFirstAndSaysWhenThereAreMore(): void
    {
        $service = new NotificationService();

        for ($i = 1; $i <= 10; $i++) {
            $service->send($this->boss, 'x', 'Note ' . $i, 'b', false, 'k' . $i, null, null, $this->now);
        }

        $service->send($this->admin, 'x', 'Not yours', 'b', false, 'k1', null, null, $this->now);

        $first = $service->page($this->boss);

        $this->assertSame(['Note 10', 'Note 9', 'Note 8', 'Note 7', 'Note 6', 'Note 5', 'Note 4'], array_column($first['rows'], 'title'));
        $this->assertTrue($first['has_more']);

        $second = $service->page($this->boss, (int) end($first['rows'])['id']);

        $this->assertSame(['Note 3', 'Note 2', 'Note 1'], array_column($second['rows'], 'title'));
        $this->assertFalse($second['has_more']);
    }

    public function testAnExactlyFullLastBatchHasNoMore(): void
    {
        $service = new NotificationService();

        for ($i = 1; $i <= 7; $i++) {
            $service->send($this->boss, 'x', 'Note ' . $i, 'b', false, 'k' . $i, null, null, $this->now);
        }

        $page = $service->page($this->boss);

        $this->assertCount(7, $page['rows']);
        $this->assertFalse($page['has_more']);
    }

    /*
    |--------------------------------------------------------------------------
    | Recipients
    |--------------------------------------------------------------------------
    */

    public function testAdministratorsAreTheActiveSystemAdministrators(): void
    {
        $off = PeopleFactory::person('admin', 'Off', 'Admin', null, null, false);

        $ids = (new NotificationRecipients())->administrators();

        $this->assertContains($this->admin, $ids);
        $this->assertNotContains((int) $off->id, $ids);
        $this->assertNotContains($this->boss, $ids);
    }

    public function testAnInstitutionsAdministratorsAreOnlyItsOwnActiveOnes(): void
    {
        $other = PeopleFactory::institution('OTHER');
        PeopleFactory::person('institutional_admin', 'Other', 'Boss', null, $other);
        PeopleFactory::person('instructor', 'Not', 'Boss', null, $this->acme);
        PeopleFactory::person('institutional_admin', 'Off', 'Boss', null, $this->acme, false);

        $this->assertSame([$this->boss], (new NotificationRecipients())->institutionAdministrators($this->acme));
    }

    /*
    |--------------------------------------------------------------------------
    | Triggers
    |--------------------------------------------------------------------------
    */

    public function testAFarOffSubscriptionTellsNobody(): void
    {
        $this->subscribeEndingIn(200);

        $this->assertSame(0, (new NotificationTriggers())->run($this->now));
    }

    public function testExpiringSoonTellsTheAdministratorsAndTheInstitutionsOwnInApp(): void
    {
        $this->subscribeEndingIn(10);

        $this->assertSame(2, (new NotificationTriggers())->run($this->now));

        $this->assertSame(['subscription.expiring'], $this->types($this->admin));
        $this->assertSame(['subscription.expiring'], $this->types($this->boss));
        $this->assertSame([], $this->mailer->sent, 'a warning is not urgent');
    }

    public function testOnlyAdministratorsGetALinkIntoTheAdminArea(): void
    {
        $this->subscribeEndingIn(10);
        (new NotificationTriggers())->run($this->now);

        $this->assertSame("admin/institutions/{$this->acme}/subscription", $this->notes($this->admin)[0]['link']);
        $this->assertNull($this->notes($this->boss)[0]['link']);
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function provideTimeline(): iterable
    {
        yield 'grace started' => [-3, 'subscription.grace', false];
        yield 'read-only started' => [-20, 'subscription.read_only', true];
        yield 'expired' => [-100, 'subscription.expired', true];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideTimeline')]
    public function testEachStageTellsWhatItShouldAndOnlyUrgentOnesAreEmailed(int $endsIn, string $type, bool $urgent): void
    {
        $this->subscribeEndingIn($endsIn);

        (new NotificationTriggers())->run($this->now);

        $this->assertSame([$type], $this->types($this->boss));
        $this->assertSame($urgent, $this->mailer->sent !== []);
        $this->assertSame($urgent ? 2 : 0, count($this->mailer->sent), 'one email each to the administrator and the institution\'s administrator');
    }

    public function testRunningAgainTellsNobodyTwice(): void
    {
        $this->subscribeEndingIn(-20);
        $triggers = new NotificationTriggers();

        $first = $triggers->run($this->now);
        $again = $triggers->run($this->now->modify('+3 hours'));

        $this->assertSame(2, $first);
        $this->assertSame(0, $again);
        $this->assertCount(1, $this->notes($this->boss));
        $this->assertCount(2, $this->mailer->sent, 'and no second round of email');
    }

    public function testEachNewStageIsToldOnceAsTheDaysGoBy(): void
    {
        $this->subscribeEndingIn(-3);
        $triggers = new NotificationTriggers();

        $triggers->run($this->now);
        $triggers->run($this->now->modify('+14 days'));
        $triggers->run($this->now->modify('+16 days'));

        $this->assertSame(['subscription.grace', 'subscription.read_only'], $this->types($this->boss));
    }

    public function testARenewalIsToldAgainWhenThatEndDateRunsOutToo(): void
    {
        $this->subscribeEndingIn(-20);
        $triggers = new NotificationTriggers();
        $triggers->run($this->now);

        db_connect()->table('subscriptions')->update(['end_date' => $this->now->modify('+8 days')->format('Y-m-d')]);
        $triggers->run($this->now);

        $this->assertSame(['subscription.read_only', 'subscription.expiring'], $this->types($this->boss));
    }

    public function testAPendingOrDeletedInstitutionIsNotWatched(): void
    {
        $pending = PeopleFactory::institution('NEWCO', 'pending');
        $this->subscribeEndingIn(-100, null, $pending);

        $deleted = PeopleFactory::institution('GONE');
        $this->subscribeEndingIn(-100, null, $deleted);
        db_connect()->table('institutions')->where('id', $deleted)->update(['deleted_at' => '2026-10-01 00:00:00']);

        $this->assertSame(0, (new NotificationTriggers())->run($this->now));
    }

    public function testASeatLimitAtNinetyPercentWarnsAndAtOneHundredIsUrgent(): void
    {
        $this->subscribeEndingIn(200, 10);

        foreach (range(1, 9) as $n) {
            PeopleFactory::person('student', 'Stu', "N{$n}", null, $this->acme);
        }

        $triggers = new NotificationTriggers();
        $triggers->run($this->now);

        $this->assertSame(['limit.near'], $this->types($this->boss));
        $this->assertSame([], $this->mailer->sent);

        PeopleFactory::person('student', 'Stu', 'N10', null, $this->acme);
        $triggers->run($this->now);

        $this->assertSame(['limit.near', 'limit.reached'], $this->types($this->boss));
        $this->assertCount(2, $this->mailer->sent, 'the limit being reached is urgent');
        $this->assertStringContainsString('student seats', $this->notes($this->boss)[1]['title']);

        $this->assertSame(0, $triggers->run($this->now), 'and nothing more on another run');
    }

    public function testAnotherInstitutionsLimitsAreNotToldToThisOne(): void
    {
        $other = PeopleFactory::institution('OTHER', 'active', 'Other College');
        $this->subscribeEndingIn(-20, null, $other);

        (new NotificationTriggers())->run($this->now);

        $this->assertSame([], $this->types($this->boss));
        $this->assertSame(['subscription.read_only'], $this->types($this->admin));
    }
}
