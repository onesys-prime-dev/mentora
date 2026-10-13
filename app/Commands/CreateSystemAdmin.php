<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Admin\AdminAccountPolicy;
use App\Services\Admin\SystemAdminService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * The only way (besides the seeder) to create a system administrator.
 *
 * Outside development and testing the email must be a real inbox: the
 * administrator signs in with a code sent there (see AdminAccountPolicy).
 */
class CreateSystemAdmin extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'admin:create';
    protected $description = 'Creates a system administrator account.';
    protected $usage       = 'admin:create <email> <username> [password]';
    protected $arguments   = [
        'email'    => 'Sign-in email address. On a server it must be a real inbox: the 2FA code is sent there.',
        'username' => 'Unique username',
        'password' => 'Optional. Prompted for (hidden where the terminal allows) when left out, which keeps it out of shell history.',
    ];

    public function run(array $params)
    {
        $policy = $this->policy();

        $email    = $params[0] ?? CLI::prompt('Email (a real inbox, the sign-in code is sent there)');
        $username = $params[1] ?? CLI::prompt('Username');

        if (($problem = $policy->emailProblem($email)) !== null) {
            CLI::error($problem);

            return EXIT_USER_INPUT;
        }

        $password = $params[2] ?? $this->askPassword();

        if ($password === null) {
            CLI::error('The two passwords did not match. Nothing was created.');

            return EXIT_USER_INPUT;
        }

        try {
            $user = (new SystemAdminService())->create($email, $username, $password);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_USER_INPUT;
        }

        CLI::write("Created system administrator {$user->username} ({$user->email}).", 'green');
        CLI::newLine();
        CLI::write('Next:');
        CLI::write('  1. Make sure mail works:  php spark mail:test ' . $user->email);

        if (($mailProblem = $policy->mailProblem()) !== null) {
            CLI::write('     ' . $mailProblem, 'yellow');
        }

        CLI::write('  2. Sign in at /login. A 6-digit code is emailed to ' . $user->email . '; enter it to finish.');

        return EXIT_SUCCESS;
    }

    /** Where this command runs decides which accounts it accepts; tests substitute it. */
    protected function policy(): AdminAccountPolicy
    {
        return new AdminAccountPolicy();
    }

    /**
     * Asks twice and returns the password, or null if the two entries differ.
     * The typing is hidden where the terminal supports it (Linux/macOS);
     * on Windows consoles it falls back to a visible prompt.
     */
    private function askPassword(): ?string
    {
        $first  = $this->promptHidden('Password (at least ' . SystemAdminService::MIN_PASSWORD_LENGTH . ' characters)');
        $second = $this->promptHidden('Repeat the password');

        return $first === $second ? $first : null;
    }

    private function promptHidden(string $label): string
    {
        if (! $this->canHideInput()) {
            return CLI::prompt($label);
        }

        CLI::write($label . ': ', null, null);

        return $this->readSecret();
    }

    /** Hiding needs a real Unix terminal; pipes and Windows consoles cannot. */
    protected function canHideInput(): bool
    {
        return DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
    }

    /** Reads one line with the terminal's echo switched off, and always switches it back on. */
    protected function readSecret(): string
    {
        shell_exec('stty -echo');

        try {
            return trim((string) fgets(STDIN));
        } finally {
            shell_exec('stty echo');
            CLI::newLine();
        }
    }
}
