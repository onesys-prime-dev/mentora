<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationService;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * A person's own notifications: the list, the unread notice, marking read,
 * that nobody sees or changes anyone else's, and the daily command that
 * creates them.
 *
 * @internal
 */
final class NotificationPagesTest extends RouteTestCase
{
    use StreamFilterTrait;

    private int $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        FakeEmail::install();

        $this->acme = PeopleFactory::institution('ACME', 'active', 'Acme University');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function signIn(string $role, string $email): int
    {
        $user = PeopleFactory::person($role, 'Some', 'One', $email, $role === 'admin' ? null : $this->acme);

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();

        return (int) $user->id;
    }

    private function tell(int $userId, string $title, bool $urgent = false, string $key = ''): void
    {
        (new NotificationService())->send($userId, 'test', $title, 'Body of ' . $title, $urgent, $key !== '' ? $key : $title, $this->acme, null, new DateTimeImmutable('2026-10-10 08:00:00'));
    }

    public function testAVisitorIsSentToSignIn(): void
    {
        auth()->logout();
        $this->withSession([]);

        $this->assertStringContainsString('login', $this->step('GET', 'notifications')->response()->getHeaderLine('Location'));
    }

    public function testAPersonSeesOnlyTheirOwn(): void
    {
        $mine = $this->signIn('instructor', 'mine@example.com');
        $this->tell($mine, 'For me');
        $this->tell((int) PeopleFactory::person('instructor', 'Else', 'One', 'else@example.com', $this->acme)->id, 'For somebody else');

        $body = $this->step('GET', 'notifications')->response()->getBody();

        $this->assertStringContainsString('For me', $body);
        $this->assertStringNotContainsString('For somebody else', $body);
    }

    public function testTheBellPanelFeedGivesSevenAtATimeOfOnlyMyNotifications(): void
    {
        $mine = $this->signIn('instructor', 'feed@example.com');
        $other = (int) PeopleFactory::person('instructor', 'Else', 'One', 'else2@example.com', $this->acme)->id;

        for ($i = 1; $i <= 9; $i++) {
            $this->tell($mine, 'Mine ' . $i);
        }

        $this->tell($other, 'Somebody elses');

        $first = json_decode($this->step('GET', 'notifications/feed')->response()->getBody(), true);

        $this->assertSame(7, $first['count']);
        $this->assertTrue($first['has_more']);
        $this->assertStringContainsString('Mine 9', $first['html']);
        $this->assertStringNotContainsString('Mine 2', $first['html']);
        $this->assertStringNotContainsString('Somebody elses', $first['html']);

        $second = json_decode($this->step('GET', 'notifications/feed?before=' . $first['next_before'])->response()->getBody(), true);

        $this->assertSame(2, $second['count']);
        $this->assertFalse($second['has_more']);
        $this->assertStringContainsString('Mine 1', $second['html']);
        $this->assertStringNotContainsString('Mine 9', $second['html']);
    }

    public function testTheFeedIsForSignedInPeopleOnly(): void
    {
        auth()->logout();
        $this->withSession([]);

        $this->assertStringContainsString('login', $this->step('GET', 'notifications/feed')->response()->getHeaderLine('Location'));
    }

    public function testEveryRoleCanOpenTheList(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent', 'admin'] as $role) {
            $this->signIn($role, "{$role}@example.com");

            $this->assertSame(200, $this->step('GET', 'notifications')->response()->getStatusCode(), $role);
        }
    }

    public function testTheListShowsUrgencyAndSaysWhenThereIsNothing(): void
    {
        $id = $this->signIn('instructor', 'mine@example.com');

        $this->assertStringContainsString('Nothing yet', $this->step('GET', 'notifications')->response()->getBody());

        $this->tell($id, 'Big deal', true);

        $this->assertStringContainsString('Urgent', $this->step('GET', 'notifications')->response()->getBody());
    }

    public function testUnreadOnesAreMarkedAndCanBeMarkedRead(): void
    {
        $id = $this->signIn('instructor', 'mine@example.com');
        $this->tell($id, 'One');
        $this->tell($id, 'Two');

        $body = $this->step('GET', 'notifications')->response()->getBody();
        $this->assertSame(2, substr_count($body, 'data-notification="unread"'));

        $first = (int) db_connect()->table('notifications')->where('user_id', $id)->orderBy('id')->get()->getRowArray()['id'];
        $this->step('POST', 'notifications/read', ['id' => (string) $first]);

        $this->assertSame(1, (new NotificationService())->unreadCount($id));

        $this->step('POST', 'notifications/read');

        $this->assertSame(0, (new NotificationService())->unreadCount($id));
    }

    public function testSomeoneElsesCannotBeMarkedRead(): void
    {
        $other = (int) PeopleFactory::person('instructor', 'Else', 'One', 'else@example.com', $this->acme)->id;
        $this->tell($other, 'Theirs');
        $theirs = (int) db_connect()->table('notifications')->where('user_id', $other)->get()->getRowArray()['id'];

        $this->signIn('instructor', 'mine@example.com');
        $this->step('POST', 'notifications/read', ['id' => (string) $theirs]);

        $this->assertSame(1, (new NotificationService())->unreadCount($other));
    }

    public function testTheUnreadCountShowsOnAnAdministratorsPagesAndTheNoticeOnOthers(): void
    {
        $admin = $this->signIn('admin', 'ada@example.com');
        $this->tell($admin, 'Admin news');

        $this->assertStringContainsString('data-unread-count', $this->step('GET', 'admin/users')->response()->getBody());

        $student = $this->signIn('student', 'stu@example.com');
        $this->tell($student, 'Student news');

        $this->assertStringContainsString('data-notifications-strip', $this->step('GET', 'student/dashboard')->response()->getBody());
    }

    public function testNoNoticeWhenThereIsNothingUnread(): void
    {
        $this->signIn('student', 'stu@example.com');

        $this->assertStringNotContainsString('data-notifications-strip', $this->step('GET', 'student/dashboard')->response()->getBody());
    }

    public function testTheDailyCommandCreatesThemAndAskedAgainCreatesNone(): void
    {
        $admin = (int) PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com')->id;
        PeopleFactory::subscribe($this->acme, PeopleFactory::plan('Plan'));
        db_connect()->table('subscriptions')->update(['end_date' => date('Y-m-d', strtotime('-20 days'))]);

        command('subscriptions:sync');
        $first = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Created', $first);
        $this->assertGreaterThan(0, (new NotificationService())->unreadCount($admin));

        $count = db_connect()->table('notifications')->countAllResults();

        command('subscriptions:sync');

        $this->assertSame($count, db_connect()->table('notifications')->countAllResults());
    }

    public function testADryRunTellsNobody(): void
    {
        PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com');
        PeopleFactory::subscribe($this->acme, PeopleFactory::plan('Plan'));
        db_connect()->table('subscriptions')->update(['end_date' => date('Y-m-d', strtotime('-20 days'))]);

        command('subscriptions:sync --dry-run');

        $this->assertSame(0, db_connect()->table('notifications')->countAllResults());
    }
}
