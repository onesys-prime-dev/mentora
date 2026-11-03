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

    private MessagingContextResolver $resolver;

    public function __construct(?MessagingContextResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
    }

    /**
     * Times are `HH:MM`; defaults are no quiet hours and emails on.
     *
     * @return array{quiet_start: ?string, quiet_end: ?string, email_alerts: bool}
     */
    public function get(int $userId): array
    {
        $row = db_connect()->table('message_preferences')->where('user_id', $userId)->get()->getRowArray();

        return [
            'quiet_start'  => $row === null || $row['quiet_start'] === null ? null : substr((string) $row['quiet_start'], 0, 5),
            'quiet_end'    => $row === null || $row['quiet_end'] === null ? null : substr((string) $row['quiet_end'], 0, 5),
            'email_alerts' => $row === null ? true : (bool) $row['email_alerts'],
        ];
    }

    /** Whether this person is offered quiet hours at all. */
    public function quietHoursAllowed(User $user): bool
    {
        return in_array($this->resolver->activeRole($user), self::QUIET_ROLES, true);
    }

    /**
     * False for a time that is not `HH:MM`, for only one of the two times, or for quiet hours on a role that is
     * not offered them. The email switch is for everyone.
     */
    public function save(User $user, ?string $quietStart, ?string $quietEnd, bool $emailAlerts): bool
    {
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
            'INSERT INTO message_preferences (user_id, quiet_start, quiet_end, email_alerts) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quiet_start = VALUES(quiet_start), quiet_end = VALUES(quiet_end), email_alerts = VALUES(email_alerts)',
            [(int) $user->id, $quietStart, $quietEnd, $emailAlerts ? 1 : 0],
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
