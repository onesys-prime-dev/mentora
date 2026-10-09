<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Email as EmailConfig;
use Tests\Support\FakeEmail;

/**
 * `php spark mail:test`: sends one message through the app's mailer and says
 * plainly whether it worked. The mailer is faked, so nothing is really sent.
 * The one thing that must hold on every path: the SMTP password is never printed.
 *
 * @internal
 */
final class MailTestCommandTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private const PASSWORD = 'hunter2-app-password';

    private EmailConfig $config;

    /** @var array<string, mixed> */
    private array $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config   = config(EmailConfig::class);
        $this->original = get_object_vars($this->config);

        $this->config->protocol = 'smtp';
        $this->config->SMTPHost = 'smtp.provider.test';
        $this->config->SMTPPort = 587;
        $this->config->SMTPUser = 'sender@provider.test';
        $this->config->SMTPPass = self::PASSWORD;
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $property => $value) {
            $this->config->{$property} = $value;
        }

        parent::tearDown();
    }

    public function testSendsOneMessageToTheAddressGiven(): void
    {
        $mail = FakeEmail::install();

        $output = $this->runCommand('mail:test you@provider.test');

        $this->assertCount(1, $mail->sent);
        $this->assertSame('you@provider.test', $mail->sent[0]['to']);
        $this->assertStringContainsString('Sent a test email to you@provider.test', $output);
    }

    public function testShowsTheSettingsInUseButOnlySaysWhetherThePasswordIsSet(): void
    {
        FakeEmail::install();

        $output = $this->runCommand('mail:test you@provider.test');

        $this->assertStringContainsString('smtp.provider.test:587', $output);
        $this->assertStringContainsString('sender@provider.test', $output);
        $this->assertStringContainsString('(set)', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
    }

    public function testSaysSoWhenThePasswordIsNotSet(): void
    {
        FakeEmail::install();
        $this->config->SMTPPass = '';

        $this->assertStringContainsString('(not set)', $this->runCommand('mail:test you@provider.test'));
    }

    public function testReportsAFailureAndStillNeverShowsThePassword(): void
    {
        FakeEmail::install()->failToSend = true;

        $output = $this->runCommand('mail:test you@provider.test');

        $this->assertStringContainsString('NOT sent', $output);
        $this->assertStringNotContainsString('Sent a test email', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
    }

    public function testAMailerThatThrowsIsReportedWithThePasswordRemoved(): void
    {
        FakeEmail::install()->throwOnSend = new RuntimeException('login failed for ' . self::PASSWORD);

        $output = $this->runCommand('mail:test you@provider.test');

        $this->assertStringContainsString('Could not send', $output);
        $this->assertStringContainsString('login failed for ********', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
    }

    public function testRefusesAnInvalidAddressWithoutSending(): void
    {
        $mail = FakeEmail::install();

        $output = $this->runCommand('mail:test not-an-email');

        $this->assertStringContainsString('not a valid email address', $output);
        $this->assertSame([], $mail->sent);
    }

    public function testWarnsThatPhpMailRarelyDelivers(): void
    {
        FakeEmail::install();
        $this->config->protocol = 'mail';

        $output = $this->runCommand('mail:test you@provider.test');

        $this->assertStringContainsString('rarely delivers', $output);
        $this->assertStringNotContainsString('smtp.provider.test', $output, 'SMTP details are only shown for the smtp protocol');
    }

    private function runCommand(string $command): string
    {
        command($command);

        return $this->getStreamFilterBuffer();
    }
}
