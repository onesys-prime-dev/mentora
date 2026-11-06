<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use DateInterval;
use DateTimeImmutable;

/**
 * The per-person limits of spec 5.3: 30 messages and 60 reactions a minute, 10 new threads
 * (direct or group) an hour, 20 reports a day. It counts the rows the person
 * has already created in the window, so the answer is exact and the same for
 * every server. Callers ask just before they would insert a row; an action that
 * creates nothing (a retried send, an existing thread) is never asked about.
 */
final class MessageRateLimiter
{
    public const MESSAGES_PER_MINUTE = 30;

    public const REACTIONS_PER_MINUTE = 60;

    public const THREADS_PER_HOUR = 10;

    public const REPORTS_PER_DAY = 20;

    /** @var array<string, array{int, int, string}> kind => limit, window in seconds, the sentence shown */
    private const LIMITS = [
        'message'  => [self::MESSAGES_PER_MINUTE, 60, "You're sending messages too fast. Wait a moment and try again."],
        'reaction' => [self::REACTIONS_PER_MINUTE, 60, "You're reacting too fast. Wait a moment and try again."],
        'thread'   => [self::THREADS_PER_HOUR, 3600, "You've started a lot of conversations recently. Try again later."],
        'report'   => [self::REPORTS_PER_DAY, 86400, "You've sent a lot of reports today. Try again tomorrow."],
    ];

    /**
     * @param 'message'|'reaction'|'thread'|'report' $kind
     *
     * @throws RateLimitedException when the person is already at the limit
     */
    public function check(string $kind, int $userId, ?DateTimeImmutable $now = null): void
    {
        [$limit, $window, $sentence] = self::LIMITS[$kind];

        $since = ($now ?? new DateTimeImmutable())->sub(new DateInterval('PT' . $window . 'S'))->format('Y-m-d H:i:s');
        $db    = db_connect();

        $count = match ($kind) {
            'message'  => $db->table('messages')->where('sender_id', $userId)->where('created_at >', $since)->countAllResults(),
            'reaction' => $db->table('message_reactions')->where('user_id', $userId)->where('created_at >', $since)->countAllResults(),
            'thread'   => $db->table('conversations')->where('created_by', $userId)->whereIn('type', ['direct', 'group'])->where('created_at >', $since)->countAllResults(),
            'report'   => $db->table('message_reports')->where('reporter_id', $userId)->where('created_at >', $since)->countAllResults(),
        };

        if ($count >= $limit) {
            throw new RateLimitedException($kind, $sentence);
        }
    }
}
