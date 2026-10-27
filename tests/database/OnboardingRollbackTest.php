<?php

declare(strict_types=1);

use App\Services\Institutions\InvitationService;
use App\Services\Institutions\OnboardingService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;

/**
 * "All or nothing": when a later step of onboarding, or of accepting an
 * invitation, fails, the earlier steps are undone.
 *
 * Unlike the other database tests this class does not run inside a rolled-back
 * test transaction. Its own transaction is the outermost one, so a rollback
 * really rolls back (inside another transaction it would be a no-op), and it
 * wipes the tables it touches before and after each test.
 *
 * @internal
 */
final class OnboardingRollbackTest extends DatabaseTestCase
{
    protected bool $isolateTests = false;

    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    /** Touched by these tests; emptied with foreign keys off. */
    private const TABLES = [
        'institution_invitations', 'institution_users', 'user_profiles', 'auth_groups_users', 'auth_identities', 'users',
        'subscription_renewals', 'subscriptions', 'plans', 'institutions', 'audit_logs',
    ];

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        FakeEmail::install();
        $this->now = new DateTimeImmutable('2026-10-09 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->wipe();

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function wipe(): void
    {
        $db = db_connect();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $db->query('TRUNCATE TABLE `' . $table . '`');
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function rows(string $table): int
    {
        return db_connect()->table($table)->countAllResults();
    }

    public function testAFailureAfterTheSubscriptionWasStartedUndoesTheSubscription(): void
    {
        $admin = CourseFactory::user('admin');

        db_connect()->table('institutions')->insert(['name' => 'Acme University', 'code' => 'ACME', 'status' => 'pending']);
        $institution = (int) db_connect()->insertID();
        db_connect()->table('plans')->insert(['name' => 'Standard', 'status' => 'active']);
        $plan = (int) db_connect()->insertID();

        // The administrator's address already belongs to an account. That is only found
        // while the invitation is written, after the subscription has already been started.
        $existing = CourseFactory::user('student');
        db_connect()->table('auth_identities')->insert(['user_id' => $existing->id, 'type' => 'email_password', 'secret' => 'taken@example.com', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);
        // Only the audit log, and with foreign keys off: other tables (the dean's review marks) refer to it.
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('TRUNCATE TABLE `audit_logs`');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $term = ['plan_id' => (string) $plan, 'period_start' => '2026-10-01', 'period_end' => '2027-09-30'];

        try {
            (new OnboardingService())->complete($institution, $term, ['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'taken@example.com'], $admin, $this->now);
            $this->fail('The address of an existing account should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertSame('pending', db_connect()->table('institutions')->where('id', $institution)->get()->getRowArray()['status'], 'still not usable');
        $this->assertSame(0, $this->rows('subscriptions'), 'the subscription that was started is gone');
        $this->assertSame(0, $this->rows('subscription_renewals'));
        $this->assertSame(0, $this->rows('institution_invitations'));
        $this->assertSame(0, $this->rows('audit_logs'), 'and so are the entries written along the way');
    }

    public function testAFailureWhileMakingTheAccountLeavesTheInvitationUnused(): void
    {
        $admin = CourseFactory::user('admin');

        db_connect()->table('institutions')->insert(['name' => 'Acme University', 'code' => 'ACME', 'status' => 'active']);
        $institution = (int) db_connect()->insertID();

        $mailer = FakeEmail::install();
        (new InvitationService())->invite($institution, ['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com'], $admin, $this->now);
        preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $mailer->sent[0]['message'], $match);

        // A first name the profile will not accept (it needs two letters), which is only
        // found after the account itself has been written.
        db_connect()->table('institution_invitations')->update(['first_name' => 'M']);

        try {
            (new InvitationService())->accept($match[1], self::STRONG, $this->now);
            $this->fail('The profile should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('profile', $e->getMessage());
        }

        $this->assertNull(model(UserModel::class)->findByCredentials(['email' => 'maria.santos@example.com']), 'no half-made account');
        $this->assertSame(0, $this->rows('institution_users'));

        $invitation = db_connect()->table('institution_invitations')->get()->getRowArray();
        $this->assertNull($invitation['accepted_at'], 'the link has not been used up');
        $this->assertNull($invitation['accepted_user_id']);
    }
}
