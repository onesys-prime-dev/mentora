<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Admin\PlatformSettings;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * A person's Messages preferences (spec section 4 `message_preferences`, 6.3): whether new messages are also emailed,
 * and for everyone the quiet hours during which nothing is notified or emailed: the school default (never set),
 * their own times, or none. The messages and the unread badge are never affected by quiet hours.
 */
class MessagePreferences
{
    /** Which new messages are also emailed (D18); in-app notices are always sent. */
    public const SCOPES = ['off', 'everyone', 'talked'];

    /** `default` is stored as NULL (never set). */
    public const QUIET_MODES = ['default', 'own', 'none'];

    /**
     * Times are `HH:MM` and are the ones in force: their own for `own`, the school default for `default`, none for
     * `none`. `email_scope` is `off` whenever `email_alerts` is 0, else the stored scope.
     *
     * @return array{quiet_mode: string, quiet_start: ?string, quiet_end: ?string, email_alerts: bool, email_scope: string}
     */
    public function get(int $userId, ?int $institutionId = null): array
    {
        $row  = db_connect()->table('message_preferences')->where('user_id', $userId)->get()->getRowArray();
        $mode = $row === null || ! in_array($row['quiet_mode'] ?? null, ['own', 'none'], true) ? 'default' : (string) $row['quiet_mode'];
        $time = static fn ($value): ?string => $value === null || $value === '' ? null : substr((string) $value, 0, 5);

        [$start, $end] = match ($mode) {
            'own'   => [$time($row['quiet_start']), $time($row['quiet_end'])],
            'none'  => [null, null],
            default => $this->schoolDefault($userId, $institutionId),
        };

        return [
            'quiet_mode'   => $mode,
            'quiet_start'  => $start,
            'quiet_end'    => $end,
            'email_alerts' => $row === null ? true : (bool) $row['email_alerts'],
            'email_scope'  => $row === null ? 'everyone' : ((int) $row['email_alerts'] === 0 ? 'off' : (string) $row['email_scope']),
        ];
    }

    /**
     * False for a time that is not `HH:MM`, for `own` without both times, for an unknown quiet mode or an unknown
     * email scope. Times are kept only for `own`.
     * `off` only clears `email_alerts` and leaves the stored scope column as it was.
     */
    public function save(User $user, ?string $quietStart, ?string $quietEnd, string $emailScope, string $quietMode): bool
    {
        $quietStart = $quietStart === '' ? null : $quietStart;
        $quietEnd   = $quietEnd === '' ? null : $quietEnd;

        if (! in_array($emailScope, self::SCOPES, true) || ! in_array($quietMode, self::QUIET_MODES, true)) {
            return false;
        }

        if ($quietMode === 'own') {
            if ($quietStart === null || $quietEnd === null || ! self::validTime($quietStart) || ! self::validTime($quietEnd)) {
                return false;
            }
        } else {
            $quietStart = $quietEnd = null;
        }

        db_connect()->query(
            'INSERT INTO message_preferences (user_id, quiet_mode, quiet_start, quiet_end, email_alerts, email_scope) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quiet_mode = VALUES(quiet_mode), quiet_start = VALUES(quiet_start), quiet_end = VALUES(quiet_end), email_alerts = VALUES(email_alerts), email_scope = IF(VALUES(email_alerts) = 0, email_scope, VALUES(email_scope))',
            [(int) $user->id, $quietMode === 'default' ? null : $quietMode, $quietStart, $quietEnd, $emailScope === 'off' ? 0 : 1, $emailScope === 'off' ? 'everyone' : $emailScope],
        );

        return true;
    }

    /**
     * Inside the quiet hours in force at $now, read in the platform timezone. Hours may span midnight; equal start
     * and end means never quiet.
     */
    public function isQuiet(int $userId, DateTimeImmutable $now, ?int $institutionId = null): bool
    {
        $prefs = $this->get($userId, $institutionId);

        if ($prefs['quiet_start'] === null || $prefs['quiet_end'] === null || $prefs['quiet_start'] === $prefs['quiet_end']) {
            return false;
        }

        try {
            $now = $now->setTimezone(new DateTimeZone((string) (new PlatformSettings())->get('timezone')));
        } catch (Throwable) {
            // An unusable timezone setting falls back to the time as given.
        }

        $time = $now->format('H:i');

        return $prefs['quiet_start'] < $prefs['quiet_end']
            ? $time >= $prefs['quiet_start'] && $time < $prefs['quiet_end']
            : $time >= $prefs['quiet_start'] || $time < $prefs['quiet_end'];
    }

    /** @return array{0: ?string, 1: ?string} the institution's default times (both or neither) */
    public function schoolDefault(int $userId, ?int $institutionId = null): array
    {
        $institutionId ??= (new PlanLimits())->institutionOf($userId);
        $settings        = new PlatformSettings();
        $start           = $settings->get('defaultQuietStart', $institutionId);
        $end             = $settings->get('defaultQuietEnd', $institutionId);

        return $start === null || $end === null || $start === '' || $end === '' ? [null, null] : [(string) $start, (string) $end];
    }

    private static function validTime(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }
}
