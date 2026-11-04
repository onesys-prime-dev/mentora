<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;

/**
 * What a participant can do to one message beyond reading it: react (this class grows with the other actions).
 * A conversation or message that is not the person's is "not_found", never "forbidden". No text is logged or notified.
 */
class MessageActions
{
    /** The only reactions accepted, in display order; the first five are the quick row. */
    public const PALETTE = ['👍', '❤️', '😂', '😮', '😢', '🙏', '👏', '🎉', '🔥', '💯', '✅', '❌', '😀', '😍', '🤔', '😅', '😭', '😡', '👀', '💪', '🙌', '👌', '🤝', '⭐'];

    public const QUICK = ['👍', '❤️', '😂', '😮', '😢'];

    private ConversationService $conversations;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    /** The message service and cipher are accepted for the actions that later tasks add (bump, forward, delete). */
    public function __construct(?MessageService $messages = null, ?ConversationService $conversations = null, ?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null, ?MessageCipher $cipher = null)
    {
        $this->resolver      = $resolver ?? new MessagingContextResolver();
        $this->conversations = $conversations ?? new ConversationService($this->resolver);
        $this->policy        = $policy ?? new MessagingPolicy();
    }

    /**
     * Sets, replaces or removes the caller's reaction. `$emoji` null, or the one already there, removes it.
     *
     * @return array{ok: bool, reason?: 'not_found'|'not_allowed'|'invalid'|'switched_off', reactions?: list<array{emoji: string, count: int, mine: bool}>}
     *
     * @throws RateLimitedException when the person has reacted too often in the last minute
     */
    public function react(User $user, int $conversationId, int $messageId, ?string $emoji): array
    {
        $conversation = $this->conversations->find($user, $conversationId);
        $message      = $conversation === null ? null : $this->message($conversationId, $messageId);

        if ($conversation === null || $message === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ($emoji !== null && ! in_array($emoji, self::PALETTE, true)) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        if (! $this->policy->canReact($this->resolver->forPost($user, $conversation))) {
            return ['ok' => false, 'reason' => 'not_allowed'];
        }

        if (! ChatControls::allows($conversation, 'reactions')) {
            return ['ok' => false, 'reason' => 'switched_off'];
        }

        $userId   = (int) $user->id;
        $db       = db_connect();
        $current  = $db->table('message_reactions')->where(['message_id' => $messageId, 'user_id' => $userId])->get()->getRowArray();
        $previous = $current === null ? null : (string) $current['emoji'];
        $target   = $emoji === $previous ? null : $emoji;

        if ($target !== null) {
            (new MessageRateLimiter())->check('reaction', $userId);
        }

        if ($previous !== null || $target !== null) {
            $now = date('Y-m-d H:i:s');

            $db->transStart();
            $db->table('message_reactions')->where(['message_id' => $messageId, 'user_id' => $userId])->delete();

            if ($target !== null) {
                $db->table('message_reactions')->insert(['message_id' => $messageId, 'user_id' => $userId, 'emoji' => $target, 'created_at' => $now]);
            }

            $db->table('messages')->where('id', $messageId)->update(['changed_at' => $now]);
            $db->transComplete();
        }

        if ($target !== null && $previous === null) {
            try {
                (new MessageNotifier())->notifyReaction($messageId, $userId);
            } catch (\Throwable) {
                log_message('error', 'Reaction notification failed for message ' . $messageId . '.');
            }
        }

        return ['ok' => true, 'reactions' => self::summary([$messageId], $userId)[$messageId] ?? []];
    }

    /**
     * Who reacted with what, by display name. Null when the conversation or message is not the person's.
     *
     * @return array<string, list<string>>|null
     */
    public function reactors(User $user, int $conversationId, int $messageId): ?array
    {
        if ($this->conversations->find($user, $conversationId) === null || $this->message($conversationId, $messageId) === null) {
            return null;
        }

        $rows  = db_connect()->table('message_reactions')->where('message_id', $messageId)->orderBy('created_at')->orderBy('id')->get()->getResultArray();
        $names = ConversationPresenter::names(array_map(static fn (array $r): int => (int) $r['user_id'], $rows));
        $out   = [];

        foreach (self::PALETTE as $emoji) {
            foreach ($rows as $row) {
                if ($row['emoji'] === $emoji) {
                    $out[$emoji][] = $names[(int) $row['user_id']] ?? 'Former member';
                }
            }
        }

        return $out;
    }

    /**
     * Counts per emoji, in palette order, for each message.
     *
     * @param list<int> $messageIds
     *
     * @return array<int, list<array{emoji: string, count: int, mine: bool}>>
     */
    public static function summary(array $messageIds, int $userId): array
    {
        if ($messageIds === []) {
            return [];
        }

        $rows = db_connect()->table('message_reactions')->select('message_id, emoji, COUNT(*) AS n, MAX(user_id = ' . $userId . ') AS mine', false)
            ->whereIn('message_id', $messageIds)->groupBy(['message_id', 'emoji'])->get()->getResultArray();
        $out  = [];

        foreach (self::PALETTE as $emoji) {
            foreach ($rows as $row) {
                if ($row['emoji'] === $emoji) {
                    $out[(int) $row['message_id']][] = ['emoji' => $emoji, 'count' => (int) $row['n'], 'mine' => (int) $row['mine'] === 1];
                }
            }
        }

        return $out;
    }

    /**
     * A live message of this conversation, or null.
     *
     * @return array<string, mixed>|null
     */
    private function message(int $conversationId, int $messageId): ?array
    {
        if ($messageId <= 0) {
            return null;
        }

        return db_connect()->table('messages')->where(['id' => $messageId, 'conversation_id' => $conversationId, 'removed_at' => null])->get()->getRowArray();
    }
}
