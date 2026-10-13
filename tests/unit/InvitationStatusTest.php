<?php

declare(strict_types=1);

use App\Services\Institutions\InvitationStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Where an invitation stands, at the boundaries. The current time is passed in.
 *
 * @internal
 */
final class InvitationStatusTest extends CIUnitTestCase
{
    private const EXPIRES = '2026-10-16 12:00:00';

    /**
     * @return array{accepted_at: ?string, revoked_at: ?string, expires_at: string}
     */
    private function invitation(?string $accepted = null, ?string $revoked = null): array
    {
        return ['accepted_at' => $accepted, 'revoked_at' => $revoked, 'expires_at' => self::EXPIRES];
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment);
    }

    public function testItIsPendingBeforeItExpires(): void
    {
        $this->assertSame(InvitationStatus::PENDING, InvitationStatus::of($this->invitation(), $this->at('2026-10-09 12:00:00')));
        $this->assertSame(InvitationStatus::PENDING, InvitationStatus::of($this->invitation(), $this->at('2026-10-16 11:59:59')), 'one second before');
    }

    public function testItIsExpiredAtTheExpiryMomentAndAfter(): void
    {
        $this->assertSame(InvitationStatus::EXPIRED, InvitationStatus::of($this->invitation(), $this->at(self::EXPIRES)), 'the moment itself');
        $this->assertSame(InvitationStatus::EXPIRED, InvitationStatus::of($this->invitation(), $this->at('2026-10-16 12:00:01')));
        $this->assertSame(InvitationStatus::EXPIRED, InvitationStatus::of($this->invitation(), $this->at('2027-01-01 00:00:00')));
    }

    public function testAnAcceptedInvitationStaysAcceptedForever(): void
    {
        $accepted = $this->invitation('2026-10-10 08:00:00');

        $this->assertSame(InvitationStatus::ACCEPTED, InvitationStatus::of($accepted, $this->at('2026-10-11 00:00:00')));
        $this->assertSame(InvitationStatus::ACCEPTED, InvitationStatus::of($accepted, $this->at('2030-01-01 00:00:00')), 'being old does not undo it');
    }

    public function testARevokedInvitationIsRevokedEvenBeforeItWouldHaveExpired(): void
    {
        $revoked = $this->invitation(null, '2026-10-10 08:00:00');

        $this->assertSame(InvitationStatus::REVOKED, InvitationStatus::of($revoked, $this->at('2026-10-11 00:00:00')));
        $this->assertSame(InvitationStatus::REVOKED, InvitationStatus::of($revoked, $this->at('2030-01-01 00:00:00')));
    }

    public function testAcceptedWinsOverRevoked(): void
    {
        $this->assertSame(InvitationStatus::ACCEPTED, InvitationStatus::of($this->invitation('2026-10-10 08:00:00', '2026-10-11 08:00:00'), $this->at('2026-10-12 00:00:00')));
    }
}
