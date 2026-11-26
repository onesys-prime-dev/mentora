<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Profiles\AvatarData;
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

    /** @var list<int>|null conversations to notify once sendFirst has committed; null outside sendFirst */
    private ?array $held = null;

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
     * The first message to someone: the conversation, its participants and the message are created together,
     * or not at all. If the pair already has a thread (the other side may just have written first) it is used and
     * not counted as a new one. Returns send's shape plus `conversation_id`.
     *
     * @return array{ok: bool, reason?: string, message?: array<string, mixed>, conversation_id?: int}
     *
     * @throws RateLimitedException
     */
    public function sendFirst(User $sender, int $recipientId, ?int $subjectStudentId, string $body, string $clientUuid): array
    {
        // Refused before anything is created, so a bad send leaves no empty thread behind.
        if (preg_match('/^[A-Za-z0-9-]{8,36}$/', $clientUuid) !== 1 || MessageBody::clean($body) === null) {
            return ['ok' => false, 'reason' => 'invalid_body'];
        }

        // Everything that only reads happens before the transaction opens (see ConversationService::planDirect).
        $plan = $this->conversations->planDirect($sender, $recipientId, $subjectStudentId);

        if ($plan === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $db         = db_connect();
        $this->held = [];
        $db->transStart();

        try {
            $conversation = $this->conversations->createDirect($sender, $recipientId, $plan);
            $result       = $conversation === null
                ? ['ok' => false, 'reason' => 'not_found']
                : $this->send($sender, (int) $conversation['id'], $body, $clientUuid);
        } catch (\Throwable $e) {
            $db->transRollback();
            $this->held = null;

            throw $e;
        }

        if (! $result['ok']) {
            $db->transRollback();
            $this->held = null;

            return $result;
        }

        // A query that failed inside (and was caught) rolls the whole send back: that is not a success.
        $held       = $this->held;
        $this->held = null;

        if (! $db->transComplete()) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        // Told only now that the thread and message are committed.
        array_map($this->notify(...), $held);

        return $result + ['conversation_id' => (int) $conversation['id']];
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
            $this->decideFirstContact($conversation, $userId);
            $db->table('conversations')->where('id', $conversationId)->update(['last_message_id' => $row['id'], 'last_message_at' => $now, 'updated_at' => $now]);
            $this->advanceRead($userId, $conversationId, (int) $row['id']);

            // The recipient's first reply ends the first-contact line; the starter's own messages never do.
            if (($conversation['type'] ?? '') === 'direct') {
                $db->query(
                    'UPDATE conversations SET first_contact_cleared_at = ? WHERE id = ? AND first_contact_cleared_at IS NULL AND created_by <> ?',
                    [$now, $conversationId, $userId],
                );
            }
            // A new message brings an archived chat back for everyone who has not muted it.
            $db->query(
                'UPDATE conversation_participants SET archived_at = NULL WHERE conversation_id = ? AND user_id <> ? AND is_muted = 0',
                [$conversationId, $userId],
            );
        }

        $db->transComplete();

        if ($inserted && $this->held !== null) {
            $this->held[] = $conversationId;
        } elseif ($inserted) {
            $this->notify($conversationId);
        }

        return ['ok' => true, 'message' => $this->presentRows([$row], $user, $conversation)[0]];
    }

    /**
     * The first message ever in a direct thread decides whether it is a first contact and who started it. A thread
     * opened before Phase 3 stands empty with the default (not a first contact) until someone writes; the same
     * rule applies to it as to a thread made by sendFirst.
     *
     * @param array<string, mixed> $conversation a `conversations` row
     */
    private function decideFirstContact(array $conversation, int $senderId): void
    {
        if (($conversation['type'] ?? '') !== 'direct' || $conversation['last_message_id'] !== null || (int) $conversation['first_contact'] === 1) {
            return;
        }

        $db    = db_connect();
        $other = $db->table('conversation_participants')->select('user_id')->where('conversation_id', (int) $conversation['id'])->where('user_id !=', $senderId)->get()->getRowArray();

        if ($other === null) {
            return;
        }

        $first = ! $this->resolver->talkedBefore($senderId, (int) $other['user_id']);
        $db->query(
            'UPDATE conversations SET first_contact = ?, created_by = ? WHERE id = ? AND last_message_id IS NULL',
            [$first ? 1 : 0, $senderId, (int) $conversation['id']],
        );
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
     * @return array{messages: list<array<string, mixed>>, has_more: bool, pinned: list<array{id: int, sender_name: string, text: string}>, my_last: array{id: int, seen: int, others: int}|null}|null
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

        $pins    = $this->pinnedRows($userId, $conversationId, $first);
        // One lookup for the page: the messages' senders, the quoted ones and the pinned ones (FR2, FR5).
        $avatars = $this->avatarsFor($user, [...$rows, ...$pins]);

        return [
            'messages' => $this->presentRows($rows, $user, $conversation, $avatars),
            'has_more' => $more,
            'pinned'   => $this->pinnedPreviews($pins, $conversationId, $avatars),
            'my_last'  => $this->myLastSeen($userId, $conversationId),
        ];
    }

    /**
     * The viewer's newest message that still stands, and how many of the others in the chat have read up to it.
     * Counts only; never who. Null when the viewer has sent nothing here.
     *
     * @return array{id: int, seen: int, others: int}|null
     */
    private function myLastSeen(int $userId, int $conversationId): ?array
    {
        $db   = db_connect();
        $last = $db->table('messages')->selectMax('id', 'id')
            ->where(['conversation_id' => $conversationId, 'sender_id' => $userId, 'removed_at' => null, 'deleted_everyone_at' => null])->get()->getRowArray();
        $id   = (int) ($last['id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        $others = $db->table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id <>', $userId)->where('left_at', null);
        $total  = $others->countAllResults(false);
        $seen   = $others->where('last_read_message_id >=', $id)->countAllResults();

        return ['id' => $id, 'seen' => $seen, 'others' => $total];
    }

    /**
     * Messages of a chat changed (reacted to, pinned, erased) after `$since`, for polling. The same visibility as
     * page(). `server_time` is the next `since` to send: the database clock minus two seconds, so a change committed in
     * the same second as that clock is delivered again rather than missed; the client merges changed messages by id
     * (replace in place), which makes the repeat harmless. When more than PAGE_MAX changed, the oldest PAGE_MAX come
     * back, `more` is true and `server_time` is the last returned change time, so the next call continues from there.
     * An unreadable `$since` counts as now, so nothing comes back. Null when the conversation is not the user's.
     *
     * @return array{messages: list<array<string, mixed>>, server_time: string, more: bool}|null
     */
    public function changedSince(User $user, int $conversationId, string $since): ?array
    {
        $conversation = $this->conversations->find($user, $conversationId);

        if ($conversation === null) {
            return null;
        }

        $db     = db_connect();
        $clock  = ['now' => date('Y-m-d H:i:s'), 'lagged' => date('Y-m-d H:i:s', time() - 2)];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $since);
        $since  = $parsed !== false && $parsed->format('Y-m-d H:i:s') === $since ? $since : (string) $clock['now'];
        $userId = (int) $user->id;

        $rows = $db->table('messages m')->select('m.*')
            ->where('m.conversation_id', $conversationId)->where('m.id >=', $this->visibleFloor($userId, $conversationId))
            ->where('m.changed_at >', $since)
            ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $userId . ')', null, false)
            ->orderBy('m.changed_at', 'ASC')->orderBy('m.id', 'ASC')->limit(self::PAGE_MAX + 1)->get()->getResultArray();

        $more = count($rows) > self::PAGE_MAX;
        $rows = array_slice($rows, 0, self::PAGE_MAX);

        return [
            'messages'    => $this->presentRows($rows, $user, $conversation),
            'server_time' => $more ? (string) $rows[array_key_last($rows)]['changed_at'] : (string) $clock['lagged'],
            'more'        => $more,
        ];
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
        $db->table('messages')->where(['id' => $messageId, 'removed_at' => null])->update(['removed_at' => date('Y-m-d H:i:s'), 'removed_by' => (int) $user->id, 'changed_at' => date('Y-m-d H:i:s')]);

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

    /** Only an administrator ever removes a direct message, so a removal there is theirs. */
    private function isDirect(int $conversationId): bool
    {
        return db_connect()->table('conversations')->where(['id' => $conversationId, 'type' => 'direct'])->countAllResults() > 0;
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
     * @param list<array<string, mixed>>                $rows         `messages` rows
     * @param array<string, mixed>                      $conversation a `conversations` row
     * @param array<int, array<string, mixed>>|null     $avatars      avatarsFor() of these rows when the caller has it
     *
     * @return list<array<string, mixed>>
     */
    private function presentRows(array $rows, User $user, array $conversation, ?array $avatars = null): array
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
            'avatars'   => $avatars ?? $this->avatarsFor($user, $rows),
        ];

        return array_map(fn (array $row): array => $this->present($row, $userId, $view), $rows);
    }

    /**
     * @param array<string, mixed>                                                                                                  $row  a `messages` row
     * @param array{canPin?: bool, canReact?: bool, canReply?: bool, reactions?: array<int, list<array<string, mixed>>>, avatars?: array<int, array<string, mixed>>} $view what the viewer may do, the reactions and the senders' avatars of the page
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
            'sender_name' => $view['avatars'][(int) $row['sender_id']]['name'] ?? $sender['name'], 'sender_role' => $sender['role'],
            'avatar' => (int) $row['sender_id'] === $userId ? null : ($view['avatars'][(int) $row['sender_id']] ?? null),
            'body' => $body, 'removed' => $removed, 'created_at' => $row['created_at'],
            'removed_text' => $removed && $row['removed_by'] !== null && $this->isDirect((int) $row['conversation_id']) ? 'Message removed by an administrator' : 'Message removed',
            'reply' => $this->quote($row['reply_to_message_id'] ?? null, $userId, $view['avatars'] ?? []),
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
     * Everyone who wrote these messages or the messages they quote, the viewer included (their own quotes and pins
     * show their preferred name), as this viewer may see them (FR2): one forViewerJson lookup, whose names are the
     * gated ones (FR5).
     *
     * @param list<array<string, mixed>> $rows `messages` rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function avatarsFor(User $user, array $rows): array
    {
        $quoted = array_values(array_filter(array_map('intval', array_column($rows, 'reply_to_message_id'))));
        $ids    = array_column($rows, 'sender_id');

        if ($quoted !== []) {
            array_push($ids, ...array_column(db_connect()->table('messages')->select('sender_id')->whereIn('id', $quoted)->get()->getResultArray(), 'sender_id'));
        }

        return (new AvatarData())->forViewerJson($user, array_values(array_unique(array_map('intval', $ids))));
    }

    /**
     * The pinned messages the viewer can see, newest pin first.
     *
     * @return list<array<string, mixed>> `messages` rows
     */
    private function pinnedRows(int $userId, int $conversationId, int $floor): array
    {
        return db_connect()->table('messages m')->select('m.*')
            ->where(['m.conversation_id' => $conversationId, 'm.removed_at' => null, 'm.deleted_everyone_at' => null])
            ->where('m.pinned_at IS NOT NULL', null, false)->where('m.id >=', $floor)
            ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $userId . ')', null, false)
            ->orderBy('m.pinned_at', 'DESC')->orderBy('m.id', 'DESC')->get()->getResultArray();
    }

    /**
     * The pinned messages: sender and the first 80 characters, decrypted here.
     *
     * @param list<array<string, mixed>>       $rows    pinnedRows()
     * @param array<int, array<string, mixed>> $avatars the page's avatarsFor()
     *
     * @return list<array{id: int, sender_name: string, text: string}>
     */
    private function pinnedPreviews(array $rows, int $conversationId, array $avatars): array
    {
        $out = [];

        foreach ($rows as $row) {
            $plain = $this->cipher->decrypt((string) $row['body_enc'], $conversationId, (int) $row['sender_id']) ?? 'Message unavailable';
            $out[] = ['id' => (int) $row['id'], 'sender_name' => $avatars[(int) $row['sender_id']]['name'] ?? $this->sender((int) $row['sender_id'])['name'], 'text' => self::preview($plain)];
        }

        return $out;
    }

    /**
     * The quoted original of a reply: who wrote it and its first 80 characters, decrypted here on the server.
     *
     * @param array<int, array<string, mixed>> $avatars the page's avatarsFor(), for the gated sender name
     *
     * @return array{id: int, sender_name: string, text: string}|null
     */
    private function quote(mixed $originalId, int $viewerId, array $avatars = []): ?array
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

        return ['id' => (int) $orig['id'], 'sender_name' => $avatars[(int) $orig['sender_id']]['name'] ?? $this->sender((int) $orig['sender_id'])['name'], 'text' => $text];
    }

    /**
     * The sender's name and top role, for the label above their message.
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
                'role' => $user === null ? '' : (\App\Services\Profiles\RoleRank::highest($this->resolver->roles($user)) ?? ''),
            ];
        }

        return $this->senders[$senderId];
    }
}
