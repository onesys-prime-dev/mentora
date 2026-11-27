<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Notifications\NotificationService;
use App\Services\Profiles\ProfileContextResolver;
use DateInterval;
use DateTimeImmutable;

/**
 * Tells people about new messages without ever carrying the text (spec 5.3, 6.3).
 *
 * One call per conversation: each active, unmuted member who has messages from
 * others they have not been told about (and have not read) gets one in-app
 * notification for the thread, "{name} sent you a message in {chat}", linking to
 * it. While they already have an unread notification for the thread they are
 * not given another. It is emailed when their switch is on and no earlier one
 * for the thread was emailed in the last hour. Inside their quiet hours nothing
 * is created or sent; the same call after the hours end releases it. The message
 * itself and the Messages unread count are never held.
 *
 * Idempotent: `last_notified_message_id` remembers how far each member has been
 * told, and notifications are keyed by thread and message, so a retry does
 * nothing more.
 */
class MessageNotifier
{
    /** A send tells the other members at once only in chats this small; the scheduled sweep covers the rest. */
    public const IMMEDIATE_MAX_MEMBERS = 30;

    private NotificationService $notes;

    private MessagePreferences $prefs;

    private MessagingContextResolver $resolver;

    public function __construct(?NotificationService $notes = null, ?MessagePreferences $prefs = null, ?MessagingContextResolver $resolver = null)
    {
        $this->notes    = $notes ?? new NotificationService();
        $this->prefs    = $prefs ?? new MessagePreferences();
        $this->resolver = $resolver ?? new MessagingContextResolver();
    }

    /**
     * Whether a send should tell the conversation's members straight away.
     */
    public function isSmall(int $conversationId): bool
    {
        return db_connect()->table('conversation_participants')->where('conversation_id', $conversationId)->where('left_at', null)->countAllResults() <= self::IMMEDIATE_MAX_MEMBERS;
    }

    /**
     * Conversations with at least one member who has something they have not been told about, after `$afterId`.
     *
     * @return list<int>
     */
    public function pendingConversationIds(int $afterId = 0, int $limit = 200): array
    {
        $rows = db_connect()->query(
            'SELECT DISTINCT p.conversation_id FROM conversation_participants p
             JOIN messages m ON m.conversation_id = p.conversation_id
              AND m.id > GREATEST(COALESCE(p.last_notified_message_id, 0), COALESCE(p.last_read_message_id, 0), CAST(p.first_visible_message_id AS SIGNED) - 1)
              AND m.sender_id <> p.user_id AND m.removed_at IS NULL
             WHERE p.left_at IS NULL AND p.is_muted = 0 AND p.conversation_id > ?
             ORDER BY p.conversation_id LIMIT ' . max(1, $limit),
            [$afterId],
        )->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['conversation_id'], $rows);
    }

    /**
     * @return int how many people were notified
     */
    public function notifyConversation(int $conversationId, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $db   = db_connect();
        $chat = $db->table('conversations')->where('id', $conversationId)->get()->getRowArray();

        if ($chat === null) {
            return 0;
        }

        $members = $db->table('conversation_participants')->where(['conversation_id' => $conversationId, 'is_muted' => 0, 'left_at' => null])->get()->getResultArray();
        $told    = 0;
        $name    = null;
        $due     = [];

        foreach ($members as $member) {
            $userId = (int) $member['user_id'];
            $from   = max((int) ($member['last_notified_message_id'] ?? 0), (int) ($member['last_read_message_id'] ?? 0), (int) $member['first_visible_message_id'] - 1);
            $unseen = $db->table('messages m')->select('m.id, m.sender_id')
                ->where('m.conversation_id', $conversationId)->where('m.id >', $from)->where('m.sender_id !=', $userId)->where('m.removed_at', null)
                ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $userId . ')', null, false)
                ->orderBy('m.id', 'DESC')->limit(1)->get()->getRowArray();

            // Nothing new for them, or they cannot be told right now: a deactivated account, or quiet hours (held, not dropped).
            if ($unseen === null || ! $this->resolver->accountActive($userId) || $this->prefs->isQuiet($userId, $now, (int) $chat['institution_id'])) {
                continue;
            }

            $due[] = ['row' => (int) $member['id'], 'user' => $userId, 'sender' => (int) $unseen['sender_id'], 'max' => (int) $unseen['id'], 'tell' => ! $this->hasUnreadNoticeForThread($userId, $conversationId)];
        }

        // The senders' names for everyone told, worked out once for the whole send.
        $wanted = [];
        foreach ($due as $d) {
            if ($d['tell']) {
                $wanted[$d['sender']][] = $d['user'];
            }
        }
        $names = $this->namesFor($wanted);

        foreach ($due as $d) {
            if ($d['tell']) {
                $name ??= $this->chatName($chat);
                $this->tell($d['user'], $chat, $d['sender'], $names[$d['sender']][$d['user']] ?? 'Someone', $name, $d['max'], $now);
                $told++;
            }

            $db->table('conversation_participants')->where('id', $d['row'])->update(['last_notified_message_id' => $d['max']]);
        }

        return $told;
    }

    /**
     * @param array<string, mixed> $chat a `conversations` row
     */
    private function tell(int $userId, array $chat, int $senderId, string $sender, ?string $chatName, int $maxId, DateTimeImmutable $now): void
    {
        $conversationId = (int) $chat['id'];
        $body          = $chat['type'] === 'direct' || $chatName === null ? "{$sender} sent you a message." : "{$sender} sent you a message in {$chatName}.";
        $scope          = $this->prefs->get($userId, (int) $chat['institution_id'])['email_scope'];
        $emails         = ($scope === 'everyone' || ($scope === 'talked' && $this->talked($chat, $senderId, $userId))) && ! $this->emailedRecently($userId, $conversationId, $now);

        // Never any message text: who, where, and a link.
        $this->notes->send($userId, 'message', 'New message', $body, $emails, "msg:{$conversationId}:{$maxId}", (int) $chat['institution_id'], 'messages/' . $conversationId, $now);
    }

    /**
     * Whether the recipient has talked with the sender (D18). In a direct thread the thread's own first-contact
     * fact answers it, because by now the sender's message is in the thread and talkedBefore() would count it.
     *
     * @param array<string, mixed> $chat
     */
    private function talked(array $chat, int $senderId, int $userId): bool
    {
        if ($chat['type'] === 'course') {
            return true; // course chats are unchanged (D18)
        }

        if ($chat['type'] === 'direct') {
            return (int) $chat['first_contact'] === 0 || $chat['first_contact_cleared_at'] !== null;
        }

        return $this->resolver->talkedBefore($senderId, $userId);
    }

    /**
     * Tells a message's author that someone reacted: at most one reaction notice per message, whether or not it was
     * read (the notification service dedupes on the key); never for their own reaction or in a chat they muted. Who and where only, never the text.
     */
    public function notifyReaction(int $messageId, int $reactorId): void
    {
        $db  = db_connect();
        $row = $db->table('messages m')->select('m.sender_id, c.id AS conversation_id, c.institution_id')
            ->join('conversations c', 'c.id = m.conversation_id')->where('m.id', $messageId)->get()->getRowArray();

        if ($row === null || (int) $row['sender_id'] === $reactorId) {
            return;
        }

        $authorId = (int) $row['sender_id'];
        $member   = $db->table('conversation_participants')->where(['conversation_id' => (int) $row['conversation_id'], 'user_id' => $authorId, 'left_at' => null])->get()->getRowArray();

        if ($member === null || (int) $member['is_muted'] === 1 || ! $this->resolver->accountActive($authorId)) {
            return;
        }

        $key = "react:{$messageId}";

        $name = $this->namesFor([$reactorId => [$authorId]])[$reactorId][$authorId] ?? 'Someone';

        $this->notes->send($authorId, 'message', 'New reaction', "{$name} reacted to your message.", false, $key, (int) $row['institution_id'], 'messages/' . $row['conversation_id']);
    }

    /**
     * The words are stored when sent, so each recipient gets the name they may see then (FR2, R20): the preferred
     * first name only for recipients who may see the person, otherwise the legal name. One profile lookup for all
     * the people, and a visibility check only for someone who has a preferred name: one call per person, whatever
     * the number of recipients.
     *
     * @param array<int, list<int>> $wanted person id => the recipients to be told about them
     *
     * @return array<int, array<int, string>> person id => recipient id => name
     */
    private function namesFor(array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }

        $rows = db_connect()->table('user_profiles')->select('user_id, first_name, last_name, preferred_first_name')->whereIn('user_id', array_keys($wanted))->get()->getResultArray();
        $out  = [];

        foreach ($rows as $r) {
            $id        = (int) $r['user_id'];
            $last      = trim((string) $r['last_name']);
            $legal     = trim(trim((string) $r['first_name']) . ' ' . $last);
            $preferred = trim((string) $r['preferred_first_name']);
            $sees      = $preferred === '' ? [] : array_flip((new ProfileContextResolver())->viewersWhoSee($id, $wanted[$id]));

            foreach ($wanted[$id] as $recipient) {
                $out[$id][$recipient] = isset($sees[$recipient]) ? trim($preferred . ' ' . $last) : $legal;
            }
        }

        return $out;
    }

    private function hasUnreadNoticeForThread(int $userId, int $conversationId): bool
    {
        return db_connect()->table('notifications')->where('user_id', $userId)->like('dedupe_key', "msg:{$conversationId}:", 'after')->where('read_at', null)->countAllResults() > 0;
    }

    private function emailedRecently(int $userId, int $conversationId, DateTimeImmutable $now): bool
    {
        $since = $now->sub(new DateInterval('PT1H'))->format('Y-m-d H:i:s');

        return db_connect()->table('notifications')->where('user_id', $userId)->like('dedupe_key', "msg:{$conversationId}:", 'after')->where('emailed_at >', $since)->countAllResults() > 0;
    }

    /**
     * The chat's name for the words of a notification: a course code and title, or a group's title; null for a direct thread.
     *
     * @param array<string, mixed> $chat
     */
    private function chatName(array $chat): ?string
    {
        if ($chat['type'] === 'group') {
            return $chat['title'] === null ? 'a group' : (string) $chat['title'];
        }

        if ($chat['type'] !== 'course') {
            return null;
        }

        $row = db_connect()->table('course_offerings co')->select('c.course_code, c.course_title')->join('courses c', 'c.id = co.course_id')->where('co.id', (int) $chat['offering_id'])->get()->getRowArray();

        return $row === null ? 'a course chat' : trim($row['course_code'] . ' · ' . $row['course_title'], ' ·');
    }
}
