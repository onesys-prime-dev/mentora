<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use CodeIgniter\Shield\Entities\User;
use Config\Email as EmailConfig;
use Throwable;

/**
 * The email that tells the owner about a security change made from Settings (D16): a password change, two-step
 * sign-in turned on or off, other devices signed out. A failed email is logged and never undoes the change.
 */
final class SecurityNotice
{
    /** @param string $body the text after "Hello," */
    public static function send(User $user, string $subject, string $body): void
    {
        $to = (string) $user->email;

        if ($to === '') {
            return;
        }

        try {
            $config = config(EmailConfig::class);
            $email  = service('email');

            $email->clear();
            $email->setFrom($config->fromEmail, $config->fromName);
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage("Hello,\n\n" . $body);

            if ($email->send() !== true) {
                log_message('error', 'Security notice "{subject}" to user {user} was not accepted by the mail server.', ['subject' => $subject, 'user' => $user->id]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Security notice "{subject}" to user {user} failed: {message}', ['subject' => $subject, 'user' => $user->id, 'message' => $e->getMessage()]);
        }
    }

    /** The closing lines for a change the owner may not have made. */
    public static function ifNotYou(): string
    {
        return "If this was you, there is nothing else to do.\n\n"
            . "If this wasn't you, reset your password straight away: ask an administrator to send you a reset link, then sign in at " . site_url('login') . ".\n";
    }
}
