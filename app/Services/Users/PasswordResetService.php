<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\Academic\InstitutionModel;
use App\Services\Admin\PlatformSettings;
use App\Services\Concerns\InTransaction;
use App\Services\Institutions\InvitationStatus;
use App\Services\Security\PasswordPolicy;
use App\Services\Institutions\InvitationToken;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Email as EmailConfig;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Password resets, started by a system administrator (SA5).
 *
 * The administrator never sees or chooses the new password: the person is
 * emailed a link, valid for a limited time and usable once, and chooses it
 * themselves. Using the link also ends every session the account has, so
 * whoever may have had access with the old password is out.
 *
 * Like invitations, only the link secret's hash is stored. Only one link is
 * open per person: sending another cancels the one before it.
 *
 * Every refusal is an InvalidArgumentException whose message is fit to show.
 * A failed email is not an exception: the result says so and the reset can be
 * sent again.
 */
class PasswordResetService
{
    use InTransaction;

    public const LINK_INVALID = 'This password reset link is not valid. Check that the whole link was copied, or ask for a new one.';

    public const LINK_USED = 'This password reset link has already been used. If you set a new password, sign in with it.';

    public const LINK_REPLACED = 'This password reset link was replaced by a newer one. Use the link in the most recent email.';

    public const LINK_EXPIRED = 'This password reset link has expired. Ask your administrator to send a new one.';

    public const LINK_DEACTIVATED = 'This account has been deactivated, so its password cannot be reset. Contact your administrator.';

    public const SEND_FAILED = 'The reset link was saved, but the email could not be sent. Check the mail settings, then send it again.';

    /**
     * Sends a person a link to choose a new password.
     *
     * @return array{email: string, expires_at: string, email_error: ?string}
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is saved then
     */
    public function send(int $userId, ?User $actor = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $user = model(UserModel::class)->findById($userId)
            ?? throw new InvalidArgumentException('That user does not exist.');

        if ((new AccountControl())->isDeactivated($userId) || ! $user->isActivated()) {
            throw new InvalidArgumentException('This account is deactivated. Reactivate it before sending a password reset.');
        }

        $email = (string) $user->email;

        if ($email === '') {
            throw new InvalidArgumentException('This account has no email address to send the link to.');
        }

        $secret = InvitationToken::generate();
        $stamp  = $now->format('Y-m-d H:i:s');
        $hours  = (int) (new PlatformSettings())->get('passwordResetValidityHours');
        $expiry = $now->modify("+{$hours} hours")->format('Y-m-d H:i:s');
        $by     = $actor ?? auth()->user();

        $id = $this->inTransaction(function () use ($userId, $secret, $stamp, $expiry, $by, $email): int {
            $db = db_connect();

            $db->table('password_resets')
                ->where('user_id', $userId)
                ->where('used_at IS NULL', null, false)
                ->where('revoked_at IS NULL', null, false)
                ->update(['revoked_at' => $stamp, 'updated_at' => $stamp]);

            $db->table('password_resets')->insert([
                'user_id'              => $userId,
                'token_hash'           => $secret['hash'],
                'expires_at'           => $expiry,
                'requested_by_user_id' => $by?->id,
                'requested_by_label'   => $by?->email ?: $by?->username,
                'created_at'           => $stamp,
                'updated_at'           => $stamp,
            ]);

            $id = (int) $db->insertID();

            service('auditLogger')->log('user.password_reset_sent', $by, [
                'target_type'    => 'user',
                'target_id'      => $userId,
                'institution_id' => $this->institutionOf($userId),
                'new'            => ['email' => $email, 'expires_at' => $expiry],
            ]);

            return $id;
        });

        return ['email' => $email, 'expires_at' => $expiry, 'email_error' => $this->deliver($id, $email, $secret['token'], $expiry, $now)];
    }

    /**
     * The reset behind a link that can be used now.
     *
     * @return array<string, mixed> the reset row, with the account's `email`
     *
     * @throws InvalidArgumentException saying why the link cannot be used
     */
    public function open(string $token, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $row = InvitationToken::looksValid($token)
            ? db_connect()->table('password_resets')->where('token_hash', InvitationToken::hash($token))->get()->getRowArray()
            : null;

        if ($row === null) {
            throw new InvalidArgumentException(self::LINK_INVALID);
        }

        $status = InvitationStatus::of(
            ['accepted_at' => $row['used_at'], 'revoked_at' => $row['revoked_at'], 'expires_at' => $row['expires_at']],
            $now,
        );

        $user = model(UserModel::class)->findById((int) $row['user_id']);

        if ($user === null) {
            throw new InvalidArgumentException(self::LINK_INVALID);
        }

        // A link sent before the account was switched off does not bring it back to life.
        if ((new AccountControl())->isDeactivated((int) $user->id) || ! $user->isActivated()) {
            throw new InvalidArgumentException(self::LINK_DEACTIVATED);
        }

        return match (true) {
            $status === InvitationStatus::ACCEPTED => throw new InvalidArgumentException(self::LINK_USED),
            $status === InvitationStatus::REVOKED  => throw new InvalidArgumentException(self::LINK_REPLACED),
            $status === InvitationStatus::EXPIRED  => throw new InvalidArgumentException(self::LINK_EXPIRED),
            default                                => $row + ['email' => (string) $user->email],
        };
    }

    /**
     * Uses the link: sets the password and ends the account's other sessions.
     *
     * @throws InvalidArgumentException a bad link, or a password that is not accepted (the link stays usable then)
     * @throws Throwable                whatever else stopped it; nothing is saved then
     */
    public function complete(string $token, string $password, ?DateTimeImmutable $now = null): User
    {
        $now ??= new DateTimeImmutable();

        $reset = $this->open($token, $now);

        /** @var User $user */
        $user = model(UserModel::class)->findById((int) $reset['user_id']);

        $problem = (new PasswordPolicy())->problem($password, $user);

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        return $this->inTransaction(function () use ($reset, $user, $password, $now): User {
            $stamp = $now->format('Y-m-d H:i:s');
            $db    = db_connect();

            // Claim the link first, in one statement: of two people using it at once, one wins.
            $db->table('password_resets')
                ->where('id', $reset['id'])
                ->where('used_at IS NULL', null, false)
                ->where('revoked_at IS NULL', null, false)
                ->where('expires_at >', $stamp)
                ->update(['used_at' => $stamp, 'updated_at' => $stamp]);

            if ($db->affectedRows() !== 1) {
                throw new InvalidArgumentException(self::LINK_USED);
            }

            $user->password = $password;

            if (! model(UserModel::class)->save($user)) {
                throw new RuntimeException('The password could not be saved.');
            }

            // Whoever had a session under the old password is out.
            (new AccountControl())->signOutEverywhere((int) $user->id, $now);

            service('auditLogger')->log('user.password_reset', $user, [
                'target_type'    => 'user',
                'target_id'      => (int) $user->id,
                'institution_id' => $this->institutionOf((int) $user->id),
            ]);

            return $user;
        });
    }

    /** Whether the development conveniences apply (the link is also logged). Never true in production or staging. */
    protected function isDevelopment(): bool
    {
        return ENVIRONMENT === 'development';
    }

    private function deliver(int $resetId, string $to, string $token, string $expires, DateTimeImmutable $now): ?string
    {
        $link = site_url('reset-password/' . $token);

        if ($this->isDevelopment()) {
            log_message('warning', 'DEVELOPMENT ONLY - password reset link for {email}: {link}', ['email' => $to, 'link' => $link]);
        }

        try {
            $config = config(EmailConfig::class);
            $email  = service('email');

            $email->clear();
            $email->setFrom($config->fromEmail, $config->fromName);
            $email->setTo($to);
            $email->setSubject('Reset your Mentora password');
            $email->setMessage(
                "Hello,\n\n"
                . "An administrator has sent you a link to choose a new password for your Mentora account:\n{$link}\n\n"
                . 'The link works once and expires on ' . substr($expires, 0, 16) . ".\n\n"
                . "If you were not expecting this, you can ignore this email: your password stays as it is unless the link is used.\n",
            );

            $sent = $email->send();
        } catch (Throwable $e) {
            log_message('error', 'Password reset email to {email} failed: {message}', ['email' => $to, 'message' => $e->getMessage()]);

            return self::SEND_FAILED;
        }

        if ($sent !== true) {
            log_message('error', 'Password reset email to {email} was not accepted by the mail server.', ['email' => $to]);

            return self::SEND_FAILED;
        }

        db_connect()->table('password_resets')->where('id', $resetId)->update(['sent_at' => $now->format('Y-m-d H:i:s')]);

        return null;
    }

    /** The institution a user belongs to, for the audit entry (null when none or several). */
    private function institutionOf(int $userId): ?int
    {
        $rows = db_connect()->table('institution_users')->select('institution_id')->where('user_id', $userId)->get()->getResultArray();

        if (count($rows) !== 1) {
            return null;
        }

        $id = (int) $rows[0]['institution_id'];

        return (new InstitutionModel())->withDeleted()->find($id) === null ? null : $id;
    }
}
