<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;

/**
 * A person's Messages preferences (spec section 4 `message_preferences`, 6.3):
 * whether new messages are also emailed, and, for instructors, chairpersons
 * and deans, the quiet hours during which nothing is notified or emailed. The
 * messages and the unread badge are never affected by quiet hours.
 */
class MessagePreferences
{
    /** Roles that are offered quiet hours (spec call 6). */
    public const QUIET_ROLES = ['instructor', 'chairperson', 'dean'];

    /** Which new messages are also emailed (D18); in-app notices are always sent. */
    public const SCOPES = ['off', 'everyone', 'talked'];

    /**
     * Times are `HH:MM`; defaults are no quiet hours and emails from everyone. `email_scope` is `off` whenever
     * `email_alerts` is 0, else the stored scope.
     *
     * @return array{quiet_start: ?string, quiet_end: ?string, email_alerts: bool, email_scope: string}
     */
    public function get(int $userId): array
    {
        $row = db_connect()->table('message_preferences')->where('user_id', $userId)->get()->getRowArray();

        return [
            'quiet_start'  => $row === null || $row['quiet_start'] === null ? null : substr((string) $row['quiet_start'], 0, 5),
            'quiet_end'    => $row === null || $row['quiet_end'] === null ? null : substr((string) $row['quiet_end'], 0, 5),
            'email_alerts' => $row === null ? true : (bool) $row['email_alerts'],
            'email_scope'  => $row === null ? 'everyone' : ((int) $row['email_alerts'] === 0 ? 'off' : (string) $row['email_scope']),
        ];
    }

    /** Whether this person is offered quiet hours at all: by any role they hold, whichever one they are using. */
    public function quietHoursAllowed(User $user): bool
    {
        return $user->inGroup(...self::QUIET_ROLES);
    }

    /**
     * False for a time that is not `HH:MM`, for only one of the two times, or for quiet hours on a role that is
     * not offered them, or for an unknown email scope. The email choice is for everyone; `off` only clears
     * `email_alerts` and leaves the stored scope column as it was.
     */
    public function save(User $user, ?string $quietStart, ?string $quietEnd, string $emailScope): bool
    {
        if (! in_array($emailScope, self::SCOPES, true)) {
            return false;
        }

        $quietStart = $quietStart === '' ? null : $quietStart;
        $quietEnd   = $quietEnd === '' ? null : $quietEnd;

        if (($quietStart === null) !== ($quietEnd === null)) {
            return false;
        }

        if ($quietStart !== null) {
            if (! $this->quietHoursAllowed($user) || ! self::validTime($quietStart) || ! self::validTime((string) $quietEnd)) {
                return false;
            }
        }

        db_connect()->query(
            'INSERT INTO message_preferences (user_id, quiet_start, quiet_end, email_alerts, email_scope) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quiet_start = VALUES(quiet_start), quiet_end = VALUES(quiet_end), email_alerts = VALUES(email_alerts), email_scope = IF(VALUES(email_alerts) = 0, email_scope, VALUES(email_scope))',
            [(int) $user->id, $quietStart, $quietEnd, $emailScope === 'off' ? 0 : 1, $emailScope === 'off' ? 'everyone' : $emailScope],
        );

        return true;
    }

    /** Inside the quiet hours at $now. Hours may span midnight; equal start and end means never quiet. */
    public function isQuiet(int $userId, DateTimeImmutable $now): bool
    {
        $prefs = $this->get($userId);

        if ($prefs['quiet_start'] === null || $prefs['quiet_end'] === null || $prefs['quiet_start'] === $prefs['quiet_end']) {
            return false;
        }

        $time = $now->format('H:i');

        return $prefs['quiet_start'] < $prefs['quiet_end']
            ? $time >= $prefs['quiet_start'] && $time < $prefs['quiet_end']
            : $time >= $prefs['quiet_start'] || $time < $prefs['quiet_end'];
    }

    private static function validTime(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }
}
