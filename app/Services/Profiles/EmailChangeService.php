<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Concerns\InTransaction;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * A verified change of sign-in email (D17): the new address gets a link good for 48 hours, the old address is told,
 * and the email changes only when the link is opened, once, by its owner (or signed out), while the address is free.
 * Only the token's SHA-256 hash is stored.
 */
class EmailChangeService
{
    use InTransaction;

    public const HOURS = 48;

    public const IN_USE = 'That email address is already in use.';

    /** Whether another account already signs in with this address. */
    public static function taken(string $email, int $userId): bool
    {
        return db_connect()->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->where('user_id !=', $userId)
            ->countAllResults() > 0;
    }

    /** @throws InvalidArgumentException when the account does not exist */
    public function start(int $userId, string $newEmail, ?int $requestId): void
    {
        $user = model(UserModel::class)->findById($userId) ?? throw new InvalidArgumentException('That user does not exist.');

        $token = bin2hex(random_bytes(32));
        $now   = date('Y-m-d H:i:s');

        $this->inTransaction(function () use ($userId, $newEmail, $requestId, $token, $now): void {
            $db   = db_connect();
            $open = static fn () => $db->table('pending_email_changes')->where('user_id', $userId)->where('confirmed_at IS NULL', null, false)->where('expires_at >', $now);

            // A newer link replaces the older one: its request can no longer complete.
            $this->expireRequests(array_filter(array_map('intval', array_column($open()->select('request_id')->get()->getResultArray(), 'request_id')), static fn (int $id): bool => $id > 0 && $id !== $requestId));
            $open()->update(['expires_at' => $now]);

            $db->table('pending_email_changes')->insert([
                'user_id'    => $userId,
                'request_id' => $requestId,
                'new_email'  => $newEmail,
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', strtotime($now) + self::HOURS * 3600),
                'created_at' => $now,
            ]);
        });

        SecurityNotice::sendTo($newEmail, $userId, 'Confirm your new email address', ($requestId === null ? 'You asked for' : 'Your school approved') . " changing your Mentora sign-in email to this address.\n\n"
            . 'Open this link within ' . self::HOURS . " hours to confirm it:\n" . site_url('email-change/' . $token) . "\n\n"
            . "Until you do, you keep signing in with your current address. If you did not ask for this, ignore this email.\n");

        SecurityNotice::sendTo((string) $user->email, $userId, 'A change to your Mentora email address was requested', "Someone asked to change your Mentora sign-in email to {$newEmail}. It changes only if the link sent to that address is opened.\n\n"
            . "If this wasn't you, contact your school.\n");
    }

    /** The address a link would confirm, while it is still open (not used, not expired); null otherwise. Changes nothing. */
    public function peek(string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $row = db_connect()->table('pending_email_changes')->select('new_email')->where('token_hash', hash('sha256', $token))
            ->where('confirmed_at IS NULL', null, false)->where('expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();

        return $row === null ? null : (string) $row['new_email'];
    }

    /**
     * @return bool true when the email was changed; false for a link that is wrong, used, expired, someone else's, or whose address is taken now
     *
     * @throws ProfileEditException only when another request wins a race at the same moment (the link opened twice, or the address taken)
     */
    public function confirm(string $token, ?User $signedIn): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return false;
        }

        $hash = hash('sha256', $token);
        $row  = db_connect()->table('pending_email_changes')->where('token_hash', $hash)->get()->getRowArray();

        if ($row === null || ! hash_equals((string) $row['token_hash'], $hash) || $row['confirmed_at'] !== null) {
            return false;
        }

        $userId = (int) $row['user_id'];

        if (strtotime((string) $row['expires_at']) <= time()) {
            $this->expireRequests($row['request_id'] === null ? [] : [(int) $row['request_id']]);

            return false;
        }

        if ($signedIn !== null && (int) $signedIn->id !== $userId) {
            return false;
        }

        $owner = model(UserModel::class)->findById($userId);

        if ($owner === null || self::taken((string) $row['new_email'], $userId)) {
            return false;
        }

        $old = (string) $owner->email;

        $this->inTransaction(function () use ($row, $userId, $owner): void {
            $db    = db_connect();
            $stamp = date('Y-m-d H:i:s');

            $db->table('pending_email_changes')->where('id', $row['id'])->where('confirmed_at IS NULL', null, false)->update(['confirmed_at' => $stamp]);

            if ($db->affectedRows() !== 1) {
                throw new ProfileEditException('This link was already used.'); // opened twice at once
            }

            // The same write the People and Users screens use for a sign-in email. The unique (type, secret) key
            // refuses an address someone took after the check above.
            try {
                $saved = $db->table('auth_identities')->where('user_id', $userId)->where('type', 'email_password')->update(['secret' => $row['new_email'], 'updated_at' => $stamp]);
            } catch (DatabaseException) {
                $saved = false;
            }

            if ($saved === false) {
                throw new ProfileEditException(self::IN_USE, 'new_email');
            }

            service('auditLogger')->log('profile.email_changed', $owner, ['target_type' => 'user', 'target_id' => $userId, 'new' => ['request_id' => $row['request_id'] === null ? null : (int) $row['request_id']]]);
        });

        SecurityNotice::sendTo($old, $userId, 'Your Mentora email address was changed', "Your Mentora sign-in email was changed to {$row['new_email']}. From now on, sign in with that address.\n\n"
            . "If this wasn't you, contact your school.\n");

        return true;
    }

    /** @param list<int>|array<int, int> $requestIds approved email corrections whose link can no longer be used */
    private function expireRequests(array $requestIds): void
    {
        if ($requestIds === []) {
            return;
        }

        db_connect()->table('profile_correction_requests')->whereIn('id', array_values($requestIds))->where('field', 'email')->where('status', 'approved')
            ->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
