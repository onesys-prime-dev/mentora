<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Email as EmailConfig;
use Throwable;

/**
 * Sends one test email with the app's real mail settings and says plainly
 * whether it worked, so SMTP can be set up and checked without waiting for
 * someone to sign in. The password is never printed, not even on a failure.
 *
 *   php spark mail:test you@yourdomain.com
 *
 * See docs/mail.md for the settings.
 */
class MailTest extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'mail:test';
    protected $description = 'Sends a test email to check the mail settings.';
    protected $usage       = 'mail:test <to>';
    protected $arguments   = [
        'to' => 'The address to send the test email to',
    ];

    public function run(array $params)
    {
        $to = trim((string) ($params[0] ?? CLI::prompt('Send the test email to')));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            CLI::error("\"{$to}\" is not a valid email address.");

            return EXIT_USER_INPUT;
        }

        $config = config(EmailConfig::class);

        CLI::write('Mail settings in use:');
        foreach ($this->summary($config) as $label => $value) {
            CLI::write(sprintf('  %-10s %s', $label, $value));
        }

        CLI::newLine();

        if ($config->protocol === 'mail') {
            CLI::write('Note: the protocol is "mail" (PHP\'s built-in mail()). That rarely delivers anything. See docs/mail.md to set up SMTP.', 'yellow');
        }

        try {
            $email = service('email');
            $email->setFrom($config->fromEmail, $config->fromName);
            $email->setTo($to);
            $email->setSubject('Mentora mail test');
            $email->setMessage("This is a test message from Mentora.\n\nIf you can read it, the mail settings work. Sent " . date('Y-m-d H:i:s') . '.');

            $sent = $email->send(false);
        } catch (Throwable $e) {
            CLI::error('Could not send: ' . $this->redact($e->getMessage(), $config));

            return EXIT_ERROR;
        }

        if ($sent === false) {
            CLI::error("The test email to {$to} was NOT sent. What the mail server said:");
            CLI::write($this->redact($this->serverMessages($email), $config));

            return EXIT_ERROR;
        }

        CLI::write("Sent a test email to {$to}. Check that inbox (and the spam folder).", 'green');

        return EXIT_SUCCESS;
    }

    /**
     * The settings that matter, for display. The password only ever shows as set or not set.
     *
     * @return array<string, string>
     */
    private function summary(EmailConfig $config): array
    {
        $summary = ['protocol' => $config->protocol];

        if ($config->protocol === 'smtp') {
            $summary['server']   = $config->SMTPHost === '' ? '(not set)' : "{$config->SMTPHost}:{$config->SMTPPort}" . ($config->SMTPCrypto !== '' ? " ({$config->SMTPCrypto})" : '');
            $summary['user']     = $config->SMTPUser === '' ? '(not set)' : $config->SMTPUser;
            $summary['password'] = $config->SMTPPass === '' ? '(not set)' : '(set)';
        }

        $summary['from'] = "{$config->fromName} <{$config->fromEmail}>";

        return $summary;
    }

    /** The mail library's own record of the conversation with the server, without the message itself. */
    private function serverMessages(object $email): string
    {
        ob_start();
        $email->printDebugger([]);

        return trim(strip_tags((string) ob_get_clean()));
    }

    /** Takes the password out of any text before it is shown. */
    private function redact(string $text, EmailConfig $config): string
    {
        return $config->SMTPPass === '' ? $text : str_replace($config->SMTPPass, '********', $text);
    }
}
