<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The institutional administrator's home: what needs attention, the latest
 * activity and the quick actions, each drawn from this institution alone and
 * from what the signed-in administrator may do.
 *
 * @internal
 */
final class InstitutionHomeAttentionTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->institution = SisFixture::institution('HOMA');
        $this->other       = SisFixture::institution('HOMB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@home.example', $this->institution);

        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->where('id >', 0)->delete();
    }

    private function home(): string
    {
        return $this->step('GET', 'institution/dashboard')->response()->getBody();
    }

    public function testItListsWhatNeedsAttentionWithWhereToGo(): void
    {
        SisFixture::course($this->institution, 'C1', 'IT101');

        db_connect()->table('institution_invitations')->insert([
            'institution_id' => $this->institution, 'email' => 'old@home.example', 'role' => 'student', 'first_name' => 'Old', 'last_name' => 'Invite',
            'token_hash' => bin2hex(random_bytes(16)), 'expires_at' => '2020-01-01 00:00:00', 'created_at' => '2019-12-01 00:00:00',
        ]);

        $body = $this->home();

        $this->assertStringContainsString('data-attention="no-instructor"', $body);
        $this->assertStringContainsString('1 offering has no instructor', $body);
        $this->assertStringContainsString('instructor=none', $body);
        $this->assertStringContainsString('data-attention="invites-expired"', $body);
    }

    public function testAQuietInstitutionSaysSoInsteadOfShowingAnEmptyList(): void
    {
        // A term that is neither ending soon nor started empty: nothing to do.
        db_connect()->table('academic_terms')->where('institution_id', $this->institution)->update(['start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => date('Y-m-d', strtotime('+120 days'))]);

        $body = $this->home();

        $this->assertStringContainsString('data-attention-empty', $body);
        $this->assertStringContainsString('Nothing needs your attention right now.', $body);
    }

    public function testAnotherInstitutionsProblemsAreNotShown(): void
    {
        SisFixture::course($this->other, 'C1', 'IT101');

        $this->assertStringNotContainsString('data-attention="no-instructor"', $this->home());
    }

    public function testRecentActivityIsOwnEntriesNewestFirstAndLinksToTheLog(): void
    {
        foreach ([['term.created', $this->institution, '-2 minutes'], ['offering.created', $this->institution, '-1 minute'], ['secret.elsewhere', $this->other, '-30 seconds']] as [$action, $institution, $ago]) {
            db_connect()->table('audit_logs')->insert([
                'actor_user_id' => (int) $this->admin->id, 'actor_label' => 'ada@home.example', 'acting_role' => 'institutional_admin', 'action' => $action,
                'institution_id' => $institution, 'ip_address' => '203.0.113.9', 'created_at' => date('Y-m-d H:i:s', strtotime($ago)),
            ]);
        }

        $body = $this->home();

        $this->assertStringContainsString('Offering created', $body);
        $this->assertStringContainsString('Term created', $body);
        $this->assertStringNotContainsString('Secret elsewhere', $body);
        $this->assertStringNotContainsString('203.0.113.9', $body);
        $this->assertLessThan(strpos($body, 'Term created'), strpos($body, 'Offering created'), 'newest first');
        $this->assertStringContainsString('institution/audit', $body);
    }

    public function testTheQuickActionsFollowWhatMayBeDone(): void
    {
        $body = $this->home();

        $this->assertStringContainsString('Invite a person', $body);
        $this->assertStringContainsString('Add an offering', $body);
        $this->assertStringContainsString('Upload a CSV', $body);
        $this->assertStringContainsString('See reports', $body);

        // A read-only institution can still look and export, but is not offered what writes.
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->home();

        $this->assertStringNotContainsString('Invite a person', $body);
        $this->assertStringNotContainsString('Add an offering', $body);
        $this->assertStringContainsString('See reports', $body);
    }

    public function testAReadOnlyInstitutionIsNotToldTwiceThatItIsReadOnly(): void
    {
        PeopleFactory::subscribe($this->institution, PeopleFactory::plan('Std'));
        db_connect()->table('subscriptions')->where('institution_id', $this->institution)->update(['end_date' => date('Y-m-d', strtotime('-30 days'))]);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->home();

        $this->assertStringNotContainsString('data-attention="subscription"', $body);
        $this->assertStringContainsString('The subscription has ended.', $body);
    }

    public function testASubscriptionEndingSoonIsListed(): void
    {
        PeopleFactory::subscribe($this->institution, PeopleFactory::plan('Std'));
        db_connect()->table('subscriptions')->where('institution_id', $this->institution)->update(['end_date' => date('Y-m-d', strtotime('+10 days'))]);

        $this->assertStringContainsString('data-attention="subscription"', $this->home());
    }
}
