<?php

declare(strict_types=1);

use App\Services\Profiles\SecurityNotice;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeEmail;

/**
 * The email that tells the owner about a security change made from Settings (D16).
 *
 * @internal
 */
final class SecurityNoticeTest extends CIUnitTestCase
{
    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = FakeEmail::install();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');

        parent::tearDown();
    }

    private function user(string $email): User
    {
        $user     = new User();
        $user->id = 7;
        $user->email = $email;

        return $user;
    }

    public function testItSendsTheSubjectAndABodyThatStartsWithHello(): void
    {
        SecurityNotice::send($this->user('ivo@example.com'), 'Your Mentora password was changed', 'It was changed.' . "\n\n" . SecurityNotice::ifNotYou());

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('ivo@example.com', $this->mailer->sent[0]['to']);
        $this->assertSame('Your Mentora password was changed', $this->mailer->sent[0]['subject']);
        $this->assertStringStartsWith("Hello,\n\nIt was changed.", $this->mailer->sent[0]['message']);
        $this->assertStringContainsString('ask an administrator to send you a reset link', $this->mailer->sent[0]['message']);
    }

    public function testAnAccountWithoutAnEmailIsSkipped(): void
    {
        SecurityNotice::send($this->user(''), 'Subject', 'Body');

        $this->assertSame([], $this->mailer->sent);
    }

    public function testAFailedOrThrowingMailerNeverReachesTheCaller(): void
    {
        $this->mailer->failToSend = true;
        SecurityNotice::send($this->user('ivo@example.com'), 'Subject', 'Body');

        $this->mailer->failToSend  = false;
        $this->mailer->throwOnSend = new RuntimeException('no connection');
        SecurityNotice::send($this->user('ivo@example.com'), 'Subject', 'Body');

        $this->assertSame([], $this->mailer->sent);
    }
}
