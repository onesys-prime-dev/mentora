<?php

declare(strict_types=1);

use App\Services\Profiles\EmailChangeService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * A verified email change (D17, Review Focus 6): the sign-in email changes only when the link sent to the new
 * address is opened within 48 hours, once, by nobody but its owner, and while the address is still free.
 *
 * @internal
 */
final class EmailChangeServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private EmailChangeService $service;

    private FakeEmail $mailer;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->service = new EmailChangeService();
        $this->mailer  = FakeEmail::install();
        $this->ann     = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.com', PeopleFactory::institution('EML'));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    /** Starts a change and returns the token from the link in the email to the new address. */
    private function start(string $to = 'ann.new@example.com', ?int $requestId = null): string
    {
        $this->service->start((int) $this->ann->id, $to, $requestId);

        $mail = array_values(array_filter($this->mailer->sent, static fn (array $m): bool => $m['to'] === $to));
        $this->assertCount(1, $mail, 'one email to the new address');
        $this->assertSame(1, preg_match('#email-change/([0-9a-f]{64})#', $mail[0]['message'], $m), 'it carries the link');

        return $m[1];
    }

    private function email(): string
    {
        return (string) db_connect()->table('auth_identities')->where(['user_id' => $this->ann->id, 'type' => 'email_password'])->get()->getRow('secret');
    }

    public function testStartTellsBothAddressesAndStoresOnlyTheHash(): void
    {
        $token = $this->start();

        $old = array_values(array_filter($this->mailer->sent, static fn (array $m): bool => $m['to'] === 'ann@example.com'));
        $this->assertCount(1, $old);
        $this->assertStringContainsString("If this wasn't you", $old[0]['message']);

        $row = db_connect()->table('pending_email_changes')->where('user_id', $this->ann->id)->get()->getRowArray();
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertSame(48 * 3600, strtotime($row['expires_at']) - strtotime($row['created_at']));
    }

    public function testConfirmingWithin48HoursChangesTheEmailAndTellsTheOldAddress(): void
    {
        $token              = $this->start();
        $this->mailer->sent = [];

        $this->assertTrue($this->service->confirm($token, null));

        $this->assertSame('ann.new@example.com', $this->email());
        $this->assertSame(['ann@example.com'], array_column($this->mailer->sent, 'to'));
        $this->assertStringContainsString('changed', $this->mailer->sent[0]['subject']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where(['action' => 'profile.email_changed', 'target_id' => $this->ann->id])->countAllResults());
    }

    public function testTheOwnerMaySignInToConfirm(): void
    {
        $this->assertTrue($this->service->confirm($this->start(), $this->ann));
    }

    public function testAfter48HoursTheLinkIsRefusedAndTheRequestExpires(): void
    {
        $db = db_connect();
        $db->table('profile_correction_requests')->insert(['user_id' => $this->ann->id, 'field' => 'email', 'new_value' => 'ann.new@example.com', 'status' => 'approved', 'routed_to' => 'institution']);
        $request = (int) $db->insertID();
        $token   = $this->start('ann.new@example.com', $request);
        $db->table('pending_email_changes')->where('user_id', $this->ann->id)->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);

        $this->assertFalse($this->service->confirm($token, null));

        $this->assertSame('ann@example.com', $this->email());
        $this->assertSame('expired', $db->table('profile_correction_requests')->where('id', $request)->get()->getRow('status'));
    }

    public function testASecondConfirmIsRefused(): void
    {
        $token = $this->start();
        $this->assertTrue($this->service->confirm($token, null));
        db_connect()->table('auth_identities')->where(['user_id' => $this->ann->id, 'type' => 'email_password'])->update(['secret' => 'ann.again@example.com']);

        $this->assertFalse($this->service->confirm($token, null));
        $this->assertSame('ann.again@example.com', $this->email());
    }

    public function testASignedInDifferentUserIsRefused(): void
    {
        $token = $this->start();
        $ben   = PeopleFactory::person('student', 'Ben', 'Cruz');

        $this->assertFalse($this->service->confirm($token, $ben));
        $this->assertSame('ann@example.com', $this->email());
        $this->assertTrue($this->service->confirm($token, null), 'still usable by its owner');
    }

    public function testAWrongTokenIsRefused(): void
    {
        $token = $this->start();

        $this->assertFalse($this->service->confirm(str_repeat('0', 64), null));
        $this->assertFalse($this->service->confirm('nonsense', null));
        $this->assertFalse($this->service->confirm(strtoupper($token), null));
        $this->assertSame('ann@example.com', $this->email());
    }

    public function testANewerStartInvalidatesTheOlderToken(): void
    {
        $older = $this->start('ann.one@example.com');
        $newer = $this->start('ann.two@example.com');

        $this->assertFalse($this->service->confirm($older, null));
        $this->assertSame('ann@example.com', $this->email());
        $this->assertTrue($this->service->confirm($newer, null));
        $this->assertSame('ann.two@example.com', $this->email());
    }

    public function testAnAddressTakenInTheMeantimeIsRefused(): void
    {
        $token = $this->start();
        PeopleFactory::person('student', 'Ben', 'Cruz', 'ann.new@example.com');

        $this->assertFalse($this->service->confirm($token, null));
        $this->assertSame('ann@example.com', $this->email());
    }
}
