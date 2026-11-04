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

    /** How long after sending the author can still delete a message for everyone. */
    public const DELETE_EVERYONE_MINUTES = 15;

    private ConversationService $conversations;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    private MessageCipher $cipher;

    /** The message service is accepted for the actions that later tasks add (bump, forward). */
    public function __construct(?MessageService $messages = null, ?ConversationService $conversations = null, ?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null, ?MessageCipher $cipher = null)
    {
        $this->cipher        = $cipher ?? new MessageCipher();
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

            if ($target === null) {
                $db->table('message_reactions')->where(['message_id' => $messageId, 'user_id' => $userId])->delete();
            } else {
                // One statement, so two quick requests end with the last emoji chosen rather than a duplicate-key error.
                $db->query(
                    'INSERT INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = VALUES(created_at)',
                    [$messageId, $userId, $target, $now],
                );
            }

            $db->table('messages')->where('id', $messageId)->update(['changed_at' => $now]);
            $db->transComplete();

            if (! $db->transStatus()) {
                log_message('error', 'Reaction could not be saved for message ' . $messageId . '.');

                return ['ok' => false, 'reason' => 'not_found'];
            }
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

        // Counted in PHP: the connection collation treats different non-BMP emoji as equal, so SQL would merge groups.
        $rows   = db_connect()->table('message_reactions')->select('message_id, emoji, user_id')->whereIn('message_id', $messageIds)->get()->getResultArray();
        $counts = [];
        $mine   = [];

        foreach ($rows as $row) {
            $key          = (int) $row['message_id'] . '|' . $row['emoji'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $mine[$key]   = ($mine[$key] ?? false) || (int) $row['user_id'] === $userId;
        }

        $out = [];

        foreach ($messageIds as $messageId) {
            foreach (self::PALETTE as $emoji) {
                $key = $messageId . '|' . $emoji;

                if (isset($counts[$key])) {
                    $out[$messageId][] = ['emoji' => $emoji, 'count' => $counts[$key], 'mine' => $mine[$key]];
                }
            }
        }

        return $out;
    }

    /**
     * Whether the viewer may still delete this message for everyone: their own, not yet deleted, not removed,
     * inside the window, and the chat's switch on. The one place the rule lives (payload flag and the action).
     *
     * @param array<string, mixed> $row a `messages` row
     */
    public static function canDeleteEveryone(array $row, int $viewerId, bool $switchOn): bool
    {
        return $switchOn
            && (int) $row['sender_id'] === $viewerId
            && ($row['deleted_everyone_at'] ?? null) === null
            && ($row['removed_at'] ?? null) === null
            && ! self::pastDeleteWindow((string) $row['created_at']);
    }

    private static function pastDeleteWindow(string $createdAt): bool
    {
        $at = strtotime($createdAt);

        return $at === false || time() - $at > self::DELETE_EVERYONE_MINUTES * 60;
    }

    /**
     * Erases the text of the caller's own message for everyone, within DELETE_EVERYONE_MINUTES of sending.
     * The row stays (replies and reactions keep pointing at it); only the ciphertext is overwritten with an
     * empty one. Report snapshots are separate copies and stay readable. Repeating it changes nothing.
     *
     * @return array{ok: bool, reason?: 'not_found'|'not_allowed'|'switched_off'|'too_late'}
     */
    public function deleteForEveryone(User $user, int $conversationId, int $messageId): array
    {
        $conversation = $this->conversations->find($user, $conversationId);
        $message      = $conversation === null ? null : $this->message($conversationId, $messageId);

        if ($conversation === null || $message === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $userId = (int) $user->id;

        if ((int) $message['sender_id'] !== $userId) {
            return ['ok' => false, 'reason' => 'not_allowed'];
        }

        if ($message['deleted_everyone_at'] !== null) {
            return ['ok' => true];
        }

        if (! ChatControls::allows($conversation, 'delete_everyone')) {
            return ['ok' => false, 'reason' => 'switched_off'];
        }

        if (self::pastDeleteWindow((string) $message['created_at'])) {
            return ['ok' => false, 'reason' => 'too_late'];
        }

        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->table('messages')->where(['id' => $messageId, 'deleted_everyone_at' => null])->update([
            'body_enc'            => $this->cipher->encrypt('', $conversationId, $userId),
            'deleted_everyone_at' => $now,
            'changed_at'          => $now,
        ]);

        if ($db->affectedRows() > 0) {
            // Ids and the action only, never the text.
            service('auditLogger')->log('messages.message_deleted_everyone', $user, [
                'target_type'    => 'message',
                'target_id'      => $messageId,
                'institution_id' => (int) $conversation['institution_id'],
                'new'            => ['conversation_id' => $conversationId],
            ]);
        }

        return ['ok' => true];
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
