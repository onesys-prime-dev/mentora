<?php

declare(strict_types=1);

namespace App\Auth\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Shield\Authentication\Actions\ConditionalActionInterface;
use CodeIgniter\Shield\Authentication\Actions\Email2FA;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * Shield's email two-factor step, but only for system administrators.
 *
 * Shield applies a login action to everyone, and the other roles must keep
 * signing in with a password alone. Until the emailed code is entered the
 * administrator is only "pending": loggedIn() stays false, so the group:admin
 * filter keeps them out of the admin area.
 *
 * On a developer's machine there is usually no working mail server, so in the
 * `development` environment only, the code is also written to the log file
 * and a failed send does not stop the sign-in. Neither happens anywhere else.
 */
class AdminEmail2FA extends Email2FA implements ConditionalActionInterface
{
    public function appliesTo(User $user): bool
    {
        return $user->inGroup('admin');
    }

    public function handle(IncomingRequest $request)
    {
        if (! $this->isDevelopment()) {
            return parent::handle($request);
        }

        $this->logCode((string) $request->getPost('email'));

        try {
            return parent::handle($request);
        } catch (RuntimeException $e) {
            // Shield's "Cannot send email" (no mail server here). Anything else is a real problem.
            if (! str_starts_with($e->getMessage(), 'Cannot send email')) {
                throw $e;
            }

            return $this->view(setting('Auth.views')['action_email_2fa_verify']);
        }
    }

    /** Whether the development conveniences above apply. Never true in production or staging. */
    protected function isDevelopment(): bool
    {
        return ENVIRONMENT === 'development';
    }

    /** Writes the pending administrator's code to the log, if the address typed is theirs. */
    private function logCode(string $typedEmail): void
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null || $typedEmail === '' || $typedEmail !== $user->email) {
            return;
        }

        $identity = model(UserIdentityModel::class)->getIdentityByType($user, Session::ID_TYPE_EMAIL_2FA);

        if ($identity !== null) {
            log_message('warning', 'DEVELOPMENT ONLY - sign-in code for {email}: {code}', ['email' => $user->email, 'code' => $identity->secret]);
        }
    }
}
