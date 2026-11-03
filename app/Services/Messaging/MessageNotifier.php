<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Notifications\NotificationService;
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

        foreach ($members as $member) {
            $userId = (int) $member['user_id'];
            $from   = max((int) ($member['last_notified_message_id'] ?? 0), (int) ($member['last_read_message_id'] ?? 0), (int) $member['first_visible_message_id'] - 1);
            $unseen = $db->table('messages m')->select('m.id, m.sender_id')
                ->where('m.conversation_id', $conversationId)->where('m.id >', $from)->where('m.sender_id !=', $userId)->where('m.removed_at', null)
                ->where('NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ' . $userId . ')', null, false)
                ->orderBy('m.id', 'DESC')->limit(1)->get()->getRowArray();

            // Nothing new for them, or they cannot be told right now: a deactivated account, or quiet hours (held, not dropped).
            if ($unseen === null || ! $this->resolver->accountActive($userId) || $this->prefs->isQuiet($userId, $now)) {
                continue;
            }

            $maxId = (int) $unseen['id'];

            if (! $this->hasUnreadNoticeForThread($userId, $conversationId)) {
                $name ??= $this->chatName($chat);
                $this->tell($userId, $chat, (int) $unseen['sender_id'], $name, $maxId, $now);
                $told++;
            }

            $db->table('conversation_participants')->where('id', (int) $member['id'])->update(['last_notified_message_id' => $maxId]);
        }

        return $told;
    }

    /**
     * @param array<string, mixed> $chat a `conversations` row
     */
    private function tell(int $userId, array $chat, int $senderId, ?string $chatName, int $maxId, DateTimeImmutable $now): void
    {
        $conversationId = (int) $chat['id'];
        $sender         = ConversationPresenter::names([$senderId])[$senderId] ?? 'Someone';
        $body           = $chat['type'] === 'direct' || $chatName === null ? "{$sender} sent you a message." : "{$sender} sent you a message in {$chatName}.";
        $emails         = $this->prefs->get($userId)['email_alerts'] && ! $this->emailedRecently($userId, $conversationId, $now);

        // Never any message text: who, where, and a link.
        $this->notes->send($userId, 'message', 'New message', $body, $emails, "msg:{$conversationId}:{$maxId}", (int) $chat['institution_id'], 'messages/' . $conversationId, $now);
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
            return (string) $chat['title'];
        }

        if ($chat['type'] !== 'course') {
            return null;
        }

        $row = db_connect()->table('course_offerings co')->select('c.course_code, c.course_title')->join('courses c', 'c.id = co.course_id')->where('co.id', (int) $chat['offering_id'])->get()->getRowArray();

        return $row === null ? 'a course chat' : trim($row['course_code'] . ' · ' . $row['course_title'], ' ·');
    }
}
