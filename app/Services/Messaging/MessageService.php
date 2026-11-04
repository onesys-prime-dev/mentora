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
     * @return array{ok: bool, reason?: 'not_found'|'invalid_body'|'not_allowed'|'unavailable'|'switched_off'|'invalid_reply', message?: array<string, mixed>}
     *
     * @throws RateLimitedException when the sender has sent too many messages in the last minute
     */
    public function send(User $user, int $conversationId, string $body, string $clientUuid, ?int $replyTo = null): array
    {
        $conversation = $this->conversations->find($user, $conversationId);

        if ($conversation === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $userId = (int) $user->id;

        if (preg_match('/^[A-Za-z0-9-]{8,36}$/', $clientUuid) !== 1) {
            return ['ok' => false, 'reason' => 'invalid_body'];
        }

        // A retry of a send that already went through returns that message.
        $existing = $this->byUuid($conversationId, $userId, $clientUuid);

        if ($existing !== null) {
            return ['ok' => true, 'message' => $this->presentRows([$existing], $user, $conversation)[0]];
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

        $extra = [];

        if ($replyTo !== null) {
            if (! ChatControls::allows($conversation, 'replies')) {
                return ['ok' => false, 'reason' => 'switched_off'];
            }

            if (! $this->replyable($userId, $conversationId, $replyTo)) {
                return ['ok' => false, 'reason' => 'invalid_reply'];
            }

            $extra['reply_to_message_id'] = $replyTo;
        }

        // Only a send that is about to create a row counts; the retry above has already returned.
        (new MessageRateLimiter())->check('message', $userId);

        return $this->post($user, $conversation, $clean, $clientUuid, $extra);
    }

    /**
     * Inserts the message, advances the sender's read mark, brings the chat back for the others and notifies.
     * The one insert path; `$extra` holds more `messages` columns (reply, forward, bump).
     *
     * @param array<string, mixed> $conversation a `conversations` row
     * @param array<string, mixed> $extra
     *
     * @return array{ok: bool, message: array<string, mixed>}
     */
    public function post(User $user, array $conversation, string $clean, string $clientUuid, array $extra = []): array
    {
        $conversationId = (int) $conversation['id'];
        $userId         = (int) $user->id;
        $db             = db_connect();
        $now            = date('Y-m-d H:i:s');

        $db->transStart();
        $db->table('messages')->ignore(true)->insert([
            'conversation_id' => $conversationId, 'sender_id' => $userId,
            'body_enc' => $this->cipher->encrypt($clean, $conversationId, $userId),
            'client_uuid' => $clientUuid, 'created_at' => $now,
        ] + $extra);
        $inserted = $db->affectedRows() > 0;
        $row      = $this->byUuid($conversationId, $userId, $clientUuid);

        if ($inserted) {
            $db->table('conversations')->where('id', $conversationId)->update(['last_message_id' => $row['id'], 'last_message_at' => $now, 'updated_at' => $now]);
            $this->advanceRead($userId, $conversationId, (int) $row['id']);
            // A new message brings an archived chat back for everyone who has not muted it.
            $db->query(
                'UPDATE conversation_participants SET archived_at = NULL WHERE conversation_id = ? AND user_id <> ? AND is_muted = 0',
                [$conversationId, $userId],
            );
        }

        $db->transComplete();

        if ($inserted) {
            $this->notify($conversationId);
        }

        return ['ok' => true, 'message' => $this->presentRows([$row], $user, $conversation)[0]];
    }

    /**
     * Tells the other members now, in a chat small enough to do it inside a send; the scheduled sweep tells the
     * rest. A failure to notify never fails the send and logs the conversation id only.
     */
    private function notify(int $conversationId): void
    {
        try {
            $notifier = new MessageNotifier();

            if ($notifier->isSmall($conversationId)) {
                $notifier->notifyConversation($conversationId);
            }
        } catch (\Throwable) {
            log_message('error', 'Message notification failed for conversation ' . $conversationId . '.');
        }
    }

    /**
     * Oldest first. Without a cursor the newest page; `$beforeId` the page
     * before that message; `$afterId` what came after it (for polling).
     * Null when the conversation is not the user's.
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool, pinned: list<array{id: int, sender_name: string, text: string}>}|null
     */
    public function page(User $user, int $conversationId, ?int $beforeId = null, ?int $afterId = null, int $limit = self::PAGE): ?array
    {
        $conversation = $this->conversations->find($user, $conversationId);

        if ($conversation === null) {
            return null;
        }

        $userId = (int) $user->id;
        $limit  = max(1, min(self::PAGE_MAX, $limit));
        $first  = $this->visibleFloor($userId, $conversationId);

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

        return [
            'messages' => $this->presentRows($rows, $user, $conversation),
            'has_more' => $more,
            'pinned'   => $this->pinnedPreviews($userId, $conversationId, $first),
        ];
    }

    /**
     * Messages of a chat changed (reacted to, pinned, erased) after `$since`, for polling. The same visibility as
     * page(). `server_time` is the database clock to send back as the next `since`; an unreadable `$since` counts as
     * now, so nothing comes back. Null when the conversation is not the user's.
     *
     * @return array{messages: list<array<string, mixed>>, server_time: string}|null
     */
    public function changedSince(User $user, int $conversationId, string $since): ?array
    {
        $conversation = $this->conversations->find($user, $conversationId);

        if ($conversation === null) {
            return null;
        }

        $db     = db_connect();
        $now    = (string) $db->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS t")->getRowArray()['t'];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $since);
        $since  = $parsed !== false && $parsed->format('Y-m-d H:i:s') === $since ? $since : $now;
        $userId = (int) $user->id;

        $rows = $db->table('messages m')->select('m.*')
            ->where('m.conversation_id', $conversationId)->where('m.id >=', $this->visibleFloor($userId, $conversationId))
            ->where('m.changed_at >', $since)
            ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $userId . ')', null, false)
            ->orderBy('m.id', 'ASC')->limit(self::PAGE_MAX)->get()->getResultArray();

        return ['messages' => $this->presentRows($rows, $user, $conversation), 'server_time' => $now];
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
            'UPDATE conversation_participants SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?), marked_unread = 0 WHERE conversation_id = ? AND user_id = ?',
            [$messageId, $conversationId, $userId],
        );

        // Having read the thread, its message notification has done its job. Left unread it would stop the next
        // message from notifying (one notification per thread while one is unread, MessageNotifier).
        db_connect()->table('notifications')->where('user_id', $userId)->like('dedupe_key', "msg:{$conversationId}:", 'after')->where('read_at', null)
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed>|null */
    private function byUuid(int $conversationId, int $senderId, string $uuid): ?array
    {
        return db_connect()->table('messages')->where(['conversation_id' => $conversationId, 'sender_id' => $senderId, 'client_uuid' => $uuid])->get()->getRowArray();
    }

    /**
     * Presents rows of one chat for the viewer: what they may do is worked out once for the chat, and the reactions
     * of all the rows are loaded in one query.
     *
     * @param list<array<string, mixed>> $rows         `messages` rows
     * @param array<string, mixed>       $conversation a `conversations` row
     *
     * @return list<array<string, mixed>>
     */
    private function presentRows(array $rows, User $user, array $conversation): array
    {
        if ($rows === []) {
            return [];
        }

        $userId  = (int) $user->id;
        $context = $this->resolver->forPost($user, $conversation);
        $view    = [
            'canPin'    => $this->policy->canPinMessage((string) $conversation['type'], $this->conversations->canModerate($user, $conversation)),
            'canReact'  => $this->policy->canReact($context) && ChatControls::allows($conversation, 'reactions'),
            'canReply'  => $this->policy->canPost($context) && ChatControls::allows($conversation, 'replies'),
            'reactions' => MessageActions::summary(array_map(static fn (array $r): int => (int) $r['id'], $rows), $userId),
        ];

        return array_map(fn (array $row): array => $this->present($row, $userId, $view), $rows);
    }

    /**
     * @param array<string, mixed>                                                                                                  $row  a `messages` row
     * @param array{canPin?: bool, canReact?: bool, canReply?: bool, reactions?: array<int, list<array<string, mixed>>>} $view what the viewer may do and the reactions of the page
     *
     * @return array<string, mixed>
     */
    private function present(array $row, int $userId, array $view = []): array
    {
        $canPin = $view['canPin'] ?? false;
        $removed = $row['removed_at'] !== null;
        $erased  = ($row['deleted_everyone_at'] ?? null) !== null;
        $body    = null;

        if (! $removed && ! $erased) {
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
            'reply' => $this->quote($row['reply_to_message_id'] ?? null, $userId),
            'forwarded' => (int) ($row['forwarded'] ?? 0) === 1,
            'bumped' => ($row['bumped_from_message_id'] ?? null) !== null,
            'can_bump'            => MessageActions::canBump($row, $userId, $this->switchOn((int) $row['conversation_id'], 'bump')),
            'can_forward'         => MessageActions::canForward($row, $this->switchOn((int) $row['conversation_id'], 'forwarding'), $this->isParentThread((int) $row['conversation_id'])),
            'reactions'           => $view['reactions'][(int) $row['id']] ?? [],
            'can_react'           => ($view['canReact'] ?? false) && ! $removed && ! $erased,
            'can_reply'           => ($view['canReply'] ?? false) && ! $removed && ! $erased,
            'pinned'              => ($row['pinned_at'] ?? null) !== null,
            'can_pin'             => $canPin && ! $removed && ! $erased,
            'deleted_everyone'    => $erased,
            'can_delete_everyone' => MessageActions::canDeleteEveryone($row, $userId, $this->switchOn((int) $row['conversation_id'], 'delete_everyone')),
        ];
    }

    /** @var array<string, bool> */
    private array $switches = [];

    /** Whether one of the chat's switches is on; looked up once per chat per switch per request. */
    private function switchOn(int $conversationId, string $switch): bool
    {
        $key = $conversationId . '|' . $switch;

        if (! isset($this->switches[$key])) {
            $chat = db_connect()->table('conversations')->select('allow_' . $switch)->where('id', $conversationId)->get()->getRowArray();

            $this->switches[$key] = $chat !== null && ChatControls::allows($chat, $switch);
        }

        return $this->switches[$key];
    }

    /** @var array<int, bool> */
    private array $parentThreads = [];

    /** Whether the chat is a parent-instructor thread about a student; looked up once per chat per request. */
    private function isParentThread(int $conversationId): bool
    {
        if (! isset($this->parentThreads[$conversationId])) {
            $chat = db_connect()->table('conversations')->select('subject_student_id')->where('id', $conversationId)->get()->getRowArray();

            $this->parentThreads[$conversationId] = $chat !== null && $chat['subject_student_id'] !== null;
        }

        return $this->parentThreads[$conversationId];
    }

    /** Whether the sender may reply to this message: in this chat, not before their first visible one, not hidden by them. */
    private function replyable(int $userId, int $conversationId, int $messageId): bool
    {
        if ($messageId < 1 || ! $this->inConversation($conversationId, $messageId)) {
            return false;
        }

        return $this->visibleTo($userId, $conversationId, $messageId);
    }

    /** The lowest message id the user can see in a chat: after joining and after any "delete for me". */
    private function visibleFloor(int $userId, int $conversationId): int
    {
        $mine = db_connect()->table('conversation_participants')->select('first_visible_message_id, cleared_before_message_id')
            ->where(['conversation_id' => $conversationId, 'user_id' => $userId])->get()->getRowArray();

        return max((int) ($mine['first_visible_message_id'] ?? 0), (int) ($mine['cleared_before_message_id'] ?? 0) + 1);
    }

    public function visibleTo(int $userId, int $conversationId, int $messageId): bool
    {
        return $messageId >= $this->visibleFloor($userId, $conversationId)
            && db_connect()->table('message_hidden')->where(['message_id' => $messageId, 'user_id' => $userId])->countAllResults() === 0;
    }

    /** The first 80 characters of a text, with an ellipsis when cut. */
    public static function preview(string $plain): string
    {
        return mb_strlen($plain) > 80 ? mb_substr($plain, 0, 80) . '…' : $plain;
    }

    /**
     * The pinned messages the viewer can see, newest pin first: sender and the first 80 characters, decrypted here.
     *
     * @return list<array{id: int, sender_name: string, text: string}>
     */
    private function pinnedPreviews(int $userId, int $conversationId, int $floor): array
    {
        $rows = db_connect()->table('messages m')->select('m.*')
            ->where(['m.conversation_id' => $conversationId, 'm.removed_at' => null, 'm.deleted_everyone_at' => null])
            ->where('m.pinned_at IS NOT NULL', null, false)->where('m.id >=', $floor)
            ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $userId . ')', null, false)
            ->orderBy('m.pinned_at', 'DESC')->orderBy('m.id', 'DESC')->get()->getResultArray();
        $out = [];

        foreach ($rows as $row) {
            $plain = $this->cipher->decrypt((string) $row['body_enc'], $conversationId, (int) $row['sender_id']) ?? 'Message unavailable';
            $out[] = ['id' => (int) $row['id'], 'sender_name' => $this->sender((int) $row['sender_id'])['name'], 'text' => self::preview($plain)];
        }

        return $out;
    }

    /**
     * The quoted original of a reply: who wrote it and its first 80 characters, decrypted here on the server.
     *
     * @return array{id: int, sender_name: string, text: string}|null
     */
    private function quote(mixed $originalId, int $viewerId): ?array
    {
        if ($originalId === null) {
            return null;
        }

        $orig = db_connect()->table('messages')->where('id', (int) $originalId)->get()->getRowArray();

        if ($orig === null) {
            return null;
        }

        $text = 'Message removed';

        if ($orig['removed_at'] === null && ($orig['deleted_everyone_at'] ?? null) === null && $this->visibleTo($viewerId, (int) $orig['conversation_id'], (int) $orig['id'])) {
            $plain = $this->cipher->decrypt((string) $orig['body_enc'], (int) $orig['conversation_id'], (int) $orig['sender_id']) ?? 'Message unavailable';
            $text  = self::preview($plain);
        }

        return ['id' => (int) $orig['id'], 'sender_name' => $this->sender((int) $orig['sender_id'])['name'], 'text' => $text];
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
