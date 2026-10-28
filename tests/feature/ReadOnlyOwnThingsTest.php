<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * In a read-only or past institution, records stay frozen but a person's own
 * things stay theirs (audit decisions 8.2, 8.3), and the dates alone are
 * enough to make it read-only (6.1).
 *
 * @internal
 */
final class ReadOnlyOwnThingsTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
    }

    private function personIn(string $role, string $status): User
    {
        static $n = 0;
        $n++;

        $this->loggedInAs($user = PeopleFactory::person($role, institutionId: PeopleFactory::institution("ROWN{$n}", $status)));
        $this->withSession();

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function api(string $verb, string $uri, array $body = []): TestResponse
    {
        return $this->withBody(json_encode($body))
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->step($verb, $uri);
    }

    /** @return iterable<string, array{string}> */
    public static function provideFrozenStatuses(): iterable
    {
        yield 'read-only' => ['read_only'];
        yield 'past'      => ['past'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideFrozenStatuses')]
    public function testAStudentCanStillKeepTheirOwnReminders(string $status): void
    {
        $student = $this->personIn('student', $status);

        $created = json_decode((string) $this->api('POST', 'student/api/calendar/reminders', ['title' => 'Revise', 'remind_at' => date('Y-m-d 10:00:00')])->getJSON(), true);
        $this->assertTrue($created['success'] ?? false, (string) json_encode($created));

        $id = (int) db_connect()->table('reminders')->where('user_id', $student->id)->get()->getRowArray()['id'];

        $this->assertTrue(json_decode((string) $this->api('PATCH', "student/api/calendar/reminders/{$id}/done")->getJSON(), true)['success'] ?? false);
        $this->assertTrue(json_decode((string) $this->api('DELETE', "student/api/calendar/reminders/{$id}")->getJSON(), true)['success'] ?? false);
        $this->assertSame(0, db_connect()->table('reminders')->where('user_id', $student->id)->countAllResults());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideFrozenStatuses')]
    public function testAParentCanStillChooseTheirEmails(string $status): void
    {
        $this->personIn('parent', $status);

        $result = $this->step('POST', 'parent/profile/notifications', ['email' => []]);

        $this->assertStringNotContainsString('read-only', (string) session('error'));
        $this->assertSame('Your email choices were saved.', session('message'));
        $result->assertRedirect();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideFrozenStatuses')]
    public function testACourseChangeIsStillRefused(string $status): void
    {
        $this->personIn('student', $status);

        $this->assertStringContainsString('institution_read_only', (string) $this->api('POST', 'student/api/courses/1/announcements/1/react')->getBody());
    }

    public function testARenewalRecordedBeforeTheJobRunsAllowsWritingAtOnce(): void
    {
        $student     = $this->personIn('student', 'read_only');
        $institution = (int) db_connect()->table('institution_users')->where('user_id', $student->id)->get()->getRowArray()['institution_id'];

        $db = db_connect();
        $db->table('plans')->insert(['name' => 'Renewed plan', 'status' => 'active']);
        $plan = (int) $db->insertID();
        $db->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => $plan, 'start_date' => '2024-01-01', 'end_date' => date('Y-m-d', strtotime('-30 days'))]);

        $this->assertStringContainsString('institution_read_only', (string) $this->api('POST', 'student/api/courses/1/announcements/1/react')->getBody(), 'read-only before');

        (new \App\Services\Subscriptions\SubscriptionService())->renew($institution, ['plan_id' => (string) $plan, 'period_start' => date('Y-m-d'), 'period_end' => date('Y-m-d', strtotime('+1 year')), 'amount' => '', 'currency' => '', 'reference' => '', 'notes' => ''], PeopleFactory::person('admin', 'Root', 'Admin', 'root@rown.example'));

        // Past the read-only filter: what answers now is the route itself (there is no announcement 1).
        try {
            $body = (string) $this->api('POST', 'student/api/courses/1/announcements/1/react')->getBody();
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $body = '';
        }

        $this->assertStringNotContainsString('institution_read_only', $body, 'writable at once, no job run');
    }

    public function testTheAdministratorsHomeAgreesWithTheDates(): void
    {
        $admin       = $this->personIn('institutional_admin', 'active');
        $institution = (int) db_connect()->table('institution_users')->where('user_id', $admin->id)->get()->getRowArray()['institution_id'];

        $db = db_connect();
        $db->table('plans')->insert(['name' => 'Lapsed plan', 'status' => 'active']);
        $db->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => (int) $db->insertID(), 'start_date' => '2024-01-01', 'end_date' => date('Y-m-d', strtotime('-30 days'))]);

        $html = (string) $this->step('GET', 'institution')->getBody();

        $this->assertStringContainsString('The subscription has ended.', $html);
        $this->assertStringNotContainsString('opens when the subscription ends', $html);
    }

    public function testTheDatesAloneMakeItReadOnly(): void
    {
        $student     = $this->personIn('student', 'active');
        $institution = (int) db_connect()->table('institution_users')->where('user_id', $student->id)->get()->getRowArray()['institution_id'];

        $db = db_connect();
        $db->table('plans')->insert(['name' => 'Lapsed plan', 'status' => 'active']);
        $db->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => (int) $db->insertID(), 'start_date' => '2024-01-01', 'end_date' => date('Y-m-d', strtotime('-30 days'))]);

        $this->assertStringContainsString('institution_read_only', (string) $this->api('POST', 'student/api/courses/1/announcements/1/react')->getBody());
        $this->assertStringContainsString('data-read-only-banner', (string) $this->step('GET', 'student/dashboard')->getBody());
    }
}
