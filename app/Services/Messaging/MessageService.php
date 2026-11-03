<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;

/**
 * Sending, paging, reading and hiding messages for the acting user.
 *
 * A conversation the user is not an active participant of is "not found" in
 * every method, never "forbidden". Text is encrypted before it is stored and
 * decrypted only when a page is built for a participant; it is never logged.
 */
class MessageService
{
    public const PAGE = 30;

    public const PAGE_MAX = 100;

    private ConversationService $conversations;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    private MessageCipher $cipher;

    /** @var array<int, array{name: string, role: string}> */
    private array $senders = [];

    public function __construct(?MessageCipher $cipher = null, ?ConversationService $conversations = null, ?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->cipher        = $cipher ?? new MessageCipher();
        $this->resolver      = $resolver ?? new MessagingContextResolver();
        $this->conversations = $conversations ?? new ConversationService($this->resolver);
        $this->policy        = $policy ?? new MessagingPolicy();
    }

    /**
     * @return array{ok: bool, reason?: 'not_found'|'invalid_body'|'not_allowed'|'unavailable', message?: array<string, mixed>}
     */
    public function send(User $user, int $conversationId, string $body, string $clientUuid): array
    {
        $conversation = $this->conversations->find($user, $conversationId);

        if ($conversation === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $userId = (int) $user->id;
        $db     = db_connect();

        if (preg_match('/^[A-Za-z0-9-]{8,36}$/', $clientUuid) !== 1) {
            return ['ok' => false, 'reason' => 'invalid_body'];
        }

        // A retry of a send that already went through returns that message.
        $existing = $this->byUuid($conversationId, $userId, $clientUuid);

        if ($existing !== null) {
            return ['ok' => true, 'message' => $this->present($existing, $userId)];
        }

        $clean = MessageBody::clean($body);

        if ($clean === null) {
            return ['ok' => false, 'reason' => 'invalid_body'];
        }

        if (! $this->policy->canPost($this->resolver->forPost($user, $conversation))) {
            return ['ok' => false, 'reason' => 'not_allowed'];
        }

        if (! $this->cipher->isUsable()) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        $now = date('Y-m-d H:i:s');

        $db->transStart();
        $db->table('messages')->ignore(true)->insert([
            'conversation_id' => $conversationId, 'sender_id' => $userId,
            'body_enc' => $this->cipher->encrypt($clean, $conversationId, $userId),
            'client_uuid' => $clientUuid, 'created_at' => $now,
        ]);
        $inserted = $db->affectedRows() > 0;
        $row      = $this->byUuid($conversationId, $userId, $clientUuid);

        if ($inserted) {
            $db->table('conversations')->where('id', $conversationId)->update(['last_message_id' => $row['id'], 'last_message_at' => $now, 'updated_at' => $now]);
            $this->advanceRead($userId, $conversationId, (int) $row['id']);
        }

        $db->transComplete();

        return ['ok' => true, 'message' => $this->present($row, $userId)];
    }

    /**
     * Oldest first. Without a cursor the newest page; `$beforeId` the page
     * before that message; `$afterId` what came after it (for polling).
     * Null when the conversation is not the user's.
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool}|null
     */
    public function page(User $user, int $conversationId, ?int $beforeId = null, ?int $afterId = null, int $limit = self::PAGE): ?array
    {
        if ($this->conversations->find($user, $conversationId) === null) {
            return null;
        }

        $userId = (int) $user->id;
        $limit  = max(1, min(self::PAGE_MAX, $limit));
        $first  = (int) db_connect()->table('conversation_participants')->select('first_visible_message_id')
            ->where(['conversation_id' => $conversationId, 'user_id' => $userId])->get()->getRow('first_visible_message_id');

        $builder = db_connect()->table('messages m')->select('m.*')
            ->where('m.conversation_id', $conversationId)->where('m.id >=', $first)
            ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $userId . ')', null, false)
            ->limit($limit + 1);

        if ($afterId !== null) {
            $rows = $builder->where('m.id >', $afterId)->orderBy('m.id', 'ASC')->get()->getResultArray();
        } else {
            if ($beforeId !== null) {
                $builder->where('m.id <', $beforeId);
            }

            $rows = $builder->orderBy('m.id', 'DESC')->get()->getResultArray();
        }

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        if ($afterId === null) {
            $rows = array_reverse($rows);
        }

        return ['messages' => array_map(fn (array $row): array => $this->present($row, $userId), $rows), 'has_more' => $more];
    }

    /** Moves the user's read mark forward to this message; never back. */
    public function markRead(User $user, int $conversationId, int $messageId): bool
    {
        if ($this->conversations->find($user, $conversationId) === null || ! $this->inConversation($conversationId, $messageId)) {
            return false;
        }

        $this->advanceRead((int) $user->id, $conversationId, $messageId);

        return true;
    }

    /** Hides a message for this user only (spec 3.3 rule 9). */
    public function hideForMe(User $user, int $conversationId, int $messageId): bool
    {
        if ($this->conversations->find($user, $conversationId) === null || ! $this->inConversation($conversationId, $messageId)) {
            return false;
        }

        db_connect()->table('message_hidden')->ignore(true)->insert(['message_id' => $messageId, 'user_id' => (int) $user->id]);

        return true;
    }

    /**
     * A moderator blanks a message for everyone (spec 3.3 rule 8). The ciphertext
     * stays as evidence but is no longer served. False when the conversation is
     * not the user's, the message is not in it, or the user may not moderate it;
     * removing twice changes nothing.
     */
    public function remove(User $user, int $conversationId, int $messageId): bool
    {
        $conversation = $this->conversations->find($user, $conversationId);

        if ($conversation === null || ! $this->inConversation($conversationId, $messageId) || ! $this->conversations->canModerate($user, $conversation)) {
            return false;
        }

        $db = db_connect();
        $db->table('messages')->where(['id' => $messageId, 'removed_at' => null])->update(['removed_at' => date('Y-m-d H:i:s'), 'removed_by' => (int) $user->id]);

        if ($db->affectedRows() > 0) {
            // Ids and the action only, never the text.
            service('auditLogger')->log('messages.message_removed', $user, [
                'target_type'    => 'message',
                'target_id'      => $messageId,
                'institution_id' => (int) $conversation['institution_id'],
                'new'            => ['conversation_id' => $conversationId],
            ]);
        }

        return true;
    }

    private function inConversation(int $conversationId, int $messageId): bool
    {
        return $messageId > 0 && db_connect()->table('messages')->where(['id' => $messageId, 'conversation_id' => $conversationId])->countAllResults() > 0;
    }

    private function advanceRead(int $userId, int $conversationId, int $messageId): void
    {
        db_connect()->query(
            'UPDATE conversation_participants SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?) WHERE conversation_id = ? AND user_id = ?',
            [$messageId, $conversationId, $userId],
        );
    }

    /** @return array<string, mixed>|null */
    private function byUuid(int $conversationId, int $senderId, string $uuid): ?array
    {
        return db_connect()->table('messages')->where(['conversation_id' => $conversationId, 'sender_id' => $senderId, 'client_uuid' => $uuid])->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $row a `messages` row
     *
     * @return array<string, mixed>
     */
    private function present(array $row, int $userId): array
    {
        $removed = $row['removed_at'] !== null;
        $body    = null;

        if (! $removed) {
            $body = $this->cipher->decrypt((string) $row['body_enc'], (int) $row['conversation_id'], (int) $row['sender_id']);

            if ($body === null) {
                // The id only: the text, or anything derived from it, is never logged.
                log_message('warning', 'Messages: message {id} could not be decrypted.', ['id' => (int) $row['id']]);
                $body = 'Message unavailable';
            }
        }

        $sender = $this->sender((int) $row['sender_id']);

        return [
            'id' => (int) $row['id'], 'sender_id' => (int) $row['sender_id'], 'mine' => (int) $row['sender_id'] === $userId,
            'sender_name' => $sender['name'], 'sender_role' => $sender['role'],
            'body' => $body, 'removed' => $removed, 'created_at' => $row['created_at'],
        ];
    }

    /**
     * The sender's name and active role, for the label above their message.
     * Looked up once per sender per request.
     *
     * @return array{name: string, role: string}
     */
    private function sender(int $senderId): array
    {
        if (! isset($this->senders[$senderId])) {
            $user = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($senderId);

            $this->senders[$senderId] = [
                'name' => ConversationPresenter::names([$senderId])[$senderId] ?? 'Former member',
                'role' => $user === null ? '' : $this->resolver->activeRole($user),
            ];
        }

        return $this->senders[$senderId];
    }
}
