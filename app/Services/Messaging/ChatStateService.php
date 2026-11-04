<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;

/**
 * The person's own state on a chat: pinned, muted, archived, marked unread and
 * deleted for them. Every change touches only the caller's own participant row,
 * and a chat that is not theirs is "not found", exactly like one that does not exist.
 */
class ChatStateService
{
    public const MAX_PINNED = 5;

    private ConversationService $conversations;

    public function __construct(?ConversationService $conversations = null)
    {
        $this->conversations = $conversations ?? new ConversationService();
    }

    /** @return array{ok: bool, reason?: 'limit'|'not_allowed'|'not_found'} */
    public function pin(User $user, int $conversationId): array
    {
        $chat = $this->conversations->find($user, $conversationId);

        if ($chat === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ($chat['type'] === 'course') {
            return ['ok' => false, 'reason' => 'not_allowed'];
        }

        $db  = db_connect();
        $row = $db->table('conversation_participants')->select('pinned_at')
            ->where('conversation_id', $conversationId)->where('user_id', (int) $user->id)->get()->getRowArray();

        if ($row !== null && $row['pinned_at'] !== null) {
            return ['ok' => true];
        }

        $pinned = $db->table('conversation_participants')->where('user_id', (int) $user->id)->where('left_at IS NULL', null, false)
            ->where('pinned_at IS NOT NULL', null, false)->countAllResults();

        if ($pinned >= self::MAX_PINNED) {
            return ['ok' => false, 'reason' => 'limit'];
        }

        return $this->update($user, $conversationId, ['pinned_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array{ok: bool, reason?: 'not_allowed'|'not_found'} */
    public function unpin(User $user, int $conversationId): array
    {
        return $this->personal($user, $conversationId, ['pinned_at' => null], true);
    }

    /** @return array{ok: bool, reason?: 'not_found'} */
    public function setMuted(User $user, int $conversationId, bool $muted): array
    {
        return $this->personal($user, $conversationId, ['is_muted' => $muted ? 1 : 0]);
    }

    /** @return array{ok: bool, reason?: 'not_found'} */
    public function setArchived(User $user, int $conversationId, bool $archived): array
    {
        return $this->personal($user, $conversationId, ['archived_at' => $archived ? date('Y-m-d H:i:s') : null]);
    }

    /** @return array{ok: bool, reason?: 'not_found'} */
    public function markUnread(User $user, int $conversationId): array
    {
        return $this->personal($user, $conversationId, ['marked_unread' => 1]);
    }

    /** @return array{ok: bool, reason?: 'not_allowed'|'not_found'} */
    public function deleteForMe(User $user, int $conversationId): array
    {
        $chat = $this->conversations->find($user, $conversationId);

        if ($chat === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ($chat['type'] === 'course') {
            return ['ok' => false, 'reason' => 'not_allowed'];
        }

        return $this->update($user, $conversationId, [
            'cleared_before_message_id' => (int) ($chat['last_message_id'] ?? 0),
            'pinned_at'                 => null,
            'archived_at'               => null,
        ]);
    }

    /**
     * @param array<string, int|string|null> $set
     *
     * @return array{ok: bool, reason?: 'not_allowed'|'not_found'}
     */
    private function personal(User $user, int $conversationId, array $set, bool $notForCourse = false): array
    {
        $chat = $this->conversations->find($user, $conversationId);

        if ($chat === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ($notForCourse && $chat['type'] === 'course') {
            return ['ok' => false, 'reason' => 'not_allowed'];
        }

        return $this->update($user, $conversationId, $set);
    }

    /**
     * @param array<string, int|string|null> $set
     *
     * @return array{ok: true}
     */
    private function update(User $user, int $conversationId, array $set): array
    {
        db_connect()->table('conversation_participants')
            ->where('conversation_id', $conversationId)->where('user_id', (int) $user->id)->update($set);

        return ['ok' => true];
    }
}
