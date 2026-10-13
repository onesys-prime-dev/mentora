<?php

declare(strict_types=1);

use App\Services\Admin\AdminAccountPolicy;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Email as EmailConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Where a system administrator account may be created, and whether the server
 * could mail them their sign-in code. Pure logic: the environment and the mail
 * settings are passed in.
 *
 * @internal
 */
final class AdminAccountPolicyTest extends CIUnitTestCase
{
    /** @return iterable<string, array{string}> */
    public static function provideLooseEnvironments(): iterable
    {
        yield 'development' => ['development'];
        yield 'testing' => ['testing'];
    }

    /** @return iterable<string, array{string}> */
    public static function providePlaceholderAddresses(): iterable
    {
        yield 'example.com' => ['admin@example.com'];
        yield 'example.org, any case' => ['Admin@Example.ORG'];
        yield '.test domain' => ['admin@mentora.test'];
        yield '.local domain' => ['admin@office.local'];
        yield '.invalid domain' => ['admin@nowhere.invalid'];
        yield 'localhost' => ['admin@localhost'];
        yield 'padded' => ['  admin@example.net '];
    }

    #[DataProvider('provideLooseEnvironments')]
    public function testAnyAddressIsFineWhileDeveloping(string $environment): void
    {
        $policy = new AdminAccountPolicy($environment);

        $this->assertTrue($policy->isLoose());
        $this->assertNull($policy->emailProblem('admin@mentora.test'));
    }

    #[DataProvider('providePlaceholderAddresses')]
    public function testAPlaceholderAddressIsRefusedOnAServer(string $email): void
    {
        $problem = (new AdminAccountPolicy('production'))->emailProblem($email);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('real address', $problem);
    }

    public function testStagingIsTreatedLikeProduction(): void
    {
        $this->assertNotNull((new AdminAccountPolicy('staging'))->emailProblem('admin@example.com'));
    }

    public function testARealLookingAddressIsAcceptedOnAServer(): void
    {
        $policy = new AdminAccountPolicy('production');

        $this->assertNull($policy->emailProblem('jane.doe@gmail.com'));
        $this->assertNull($policy->emailProblem('ops@mentora.example.com'), 'a subdomain of a real host is not reserved by this rule');
    }

    public function testSomethingThatIsNotAnAddressIsLeftToTheServiceToReport(): void
    {
        $this->assertNull((new AdminAccountPolicy('production'))->emailProblem('not-an-email'));
    }

    public function testTheDemoSeederIsRefusedOnlyInProduction(): void
    {
        $this->assertNotNull((new AdminAccountPolicy('production'))->seederProblem());
        $this->assertNull((new AdminAccountPolicy('development'))->seederProblem());
        $this->assertNull((new AdminAccountPolicy('testing'))->seederProblem());
    }

    public function testMailIsNotRequiredWhileDeveloping(): void
    {
        $this->assertNull((new AdminAccountPolicy('development'))->mailProblem($this->config('mail')));
    }

    public function testPhpMailIsNotEnoughOnAServer(): void
    {
        $problem = (new AdminAccountPolicy('production'))->mailProblem($this->config('mail'));

        $this->assertNotNull($problem);
        $this->assertStringContainsString('SMTP', $problem);
    }

    public function testSmtpWithMissingCredentialsIsNotEnoughOnAServer(): void
    {
        $policy = new AdminAccountPolicy('production');

        $this->assertNotNull($policy->mailProblem($this->config('smtp', host: '')));
        $this->assertNotNull($policy->mailProblem($this->config('smtp', user: '')));
        $this->assertNotNull($policy->mailProblem($this->config('smtp', pass: '')));
    }

    public function testCompleteSmtpSettingsPass(): void
    {
        $this->assertNull((new AdminAccountPolicy('production'))->mailProblem($this->config('smtp')));
    }

    private function config(string $protocol, string $host = 'smtp.example.test', string $user = 'u@example.test', string $pass = 'secret'): EmailConfig
    {
        $config           = new EmailConfig();
        $config->protocol = $protocol;
        $config->SMTPHost = $host;
        $config->SMTPUser = $user;
        $config->SMTPPass = $pass;

        return $config;
    }
}
