<?php

declare(strict_types=1);

use App\Commands\CreateSystemAdmin;
use App\Services\Admin\AdminAccountPolicy;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\Mock\MockInputOutput;
use Config\Email as EmailConfig;
use Tests\Support\DatabaseTestCase;

/**
 * The interactive and server-facing parts of `admin:create`: refusing a
 * placeholder email on a server, asking for the password twice, prompting for
 * missing details, and warning when mail is not set up. The command is run
 * directly (with the environment and the terminal swapped for fakes) because
 * the real ones are fixed for the whole test run.
 *
 * @internal
 */
final class CreateSystemAdminCommandTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private MockInputOutput $io;

    private string $originalProtocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->io = new MockInputOutput();
        CLI::setInputOutput($this->io);

        // The mail settings come from the machine's .env; pin them so the
        // "mail is not set up" warning does not depend on who runs the test.
        $this->originalProtocol = config(EmailConfig::class)->protocol;
        config(EmailConfig::class)->protocol = 'mail';
    }

    protected function tearDown(): void
    {
        config(EmailConfig::class)->protocol = $this->originalProtocol;
        CLI::resetInputOutput();

        parent::tearDown();
    }

    public function testAPlaceholderEmailIsRefusedOnAServerAndNothingIsCreated(): void
    {
        $code = $this->command('production')->run(['admin@example.com', 'root', 'a-long-password-1']);

        $this->assertSame(EXIT_USER_INPUT, $code);
        $this->assertStringContainsString('placeholder domain', $this->io->getOutput());
        $this->assertNull(model(UserModel::class)->where('username', 'root')->first());
    }

    public function testARealLookingEmailIsAcceptedOnAServerAndMailIsFlaggedIfNotSet(): void
    {
        $code = $this->command('production')->run(['jane.doe@gmail.com', 'jane', 'a-long-password-1']);

        $output = $this->io->getOutput();

        $this->assertSame(EXIT_SUCCESS, $code);
        $this->assertNotNull(model(UserModel::class)->findByCredentials(['email' => 'jane.doe@gmail.com']));
        $this->assertStringContainsString('mail:test jane.doe@gmail.com', $output);
        $this->assertStringContainsString('SMTP', $output, 'the default mail settings are not enough on a server, and the command says so');
    }

    public function testMissingDetailsArePromptedForAndThePasswordIsAskedTwice(): void
    {
        $this->io->setInputs(['asked@mentora.test', 'asked.admin', 'a-long-password-1', 'a-long-password-1']);

        $code = $this->command('testing')->run([]);

        $this->assertSame(EXIT_SUCCESS, $code);
        $this->assertTrue(model(UserModel::class)->findByCredentials(['email' => 'asked@mentora.test'])->inGroup('admin'));
    }

    public function testPasswordsThatDoNotMatchCreateNothing(): void
    {
        $this->io->setInputs(['a-long-password-1', 'a-different-one-2']);

        $code = $this->command('testing')->run(['mismatch@mentora.test', 'mismatch']);

        $this->assertSame(EXIT_USER_INPUT, $code);
        $this->assertStringContainsString('did not match', $this->io->getOutput());
        $this->assertNull(model(UserModel::class)->where('username', 'mismatch')->first());
    }

    public function testOnATerminalThePasswordIsReadHiddenNotPrompted(): void
    {
        $command = $this->command('testing', hidden: ['a-long-password-1', 'a-long-password-1']);

        $code = $command->run(['hidden@mentora.test', 'hidden.admin']);

        $this->assertSame(EXIT_SUCCESS, $code);
        $this->assertNotNull(model(UserModel::class)->findByCredentials(['email' => 'hidden@mentora.test']));
        $this->assertStringContainsString('Repeat the password', $this->io->getOutput());
        $this->assertStringNotContainsString('a-long-password-1', $this->io->getOutput());
    }

    public function testAnInvalidEmailIsReportedByTheServiceAndNothingIsCreated(): void
    {
        $code = $this->command('testing')->run(['not-an-email', 'whoever', 'a-long-password-1']);

        $this->assertSame(EXIT_USER_INPUT, $code);
        $this->assertStringContainsString('valid email', $this->io->getOutput());
    }

    /**
     * @param list<string>|null $hidden answers to give from the hidden-input path; null = the terminal cannot hide input
     */
    private function command(string $environment, ?array $hidden = null): CreateSystemAdmin
    {
        return new class (service('logger'), service('commands'), new AdminAccountPolicy($environment), $hidden) extends CreateSystemAdmin {
            /** @param list<string>|null $hidden */
            public function __construct($logger, $commands, private readonly AdminAccountPolicy $fixedPolicy, private ?array $hidden)
            {
                parent::__construct($logger, $commands);
            }

            protected function policy(): AdminAccountPolicy
            {
                return $this->fixedPolicy;
            }

            protected function canHideInput(): bool
            {
                return $this->hidden !== null;
            }

            protected function readSecret(): string
            {
                return (string) array_shift($this->hidden);
            }
        };
    }
}
